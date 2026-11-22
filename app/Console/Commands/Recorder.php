<?php

namespace App\Console\Commands;

use App\Models\Camera;
use App\Models\Setting;
use App\Services\Go2rtc;
use App\Services\Storage;
use App\Services\Telegram;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Long-running supervisor: one ffmpeg per camera writing standalone segment files,
 * plus indexing, rotation, health checks with Telegram alerts and go2rtc sync.
 */
class Recorder extends Command
{
    protected $signature = 'homecam:recorder';
    protected $description = 'Run continuous recording, rotation and camera health checks';

    /** @var array<int, array> camera id => process state */
    private array $procs = [];
    /** @var array<int, array> camera id => restart backoff state */
    private array $backoff = [];
    private bool $stop = false;
    private array $timers = [];

    public function handle(): int
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stop = true);
        pcntl_signal(SIGINT, fn () => $this->stop = true);

        @mkdir(config('homecam.rec_dir'), 0775, true);
        @mkdir(config('homecam.exports_dir'), 0775, true);
        $this->info('recorder started');

        // full scan once: picks up files that were written while the worker was down
        $this->safe('initial index', fn () => Storage::index([], null));
        $this->safe('prune', fn () => Storage::pruneMissing());

        while (!$this->stop) {
            $this->every('cameras', 5, fn () => $this->syncProcesses());
            $this->every('watchdog', 2, fn () => $this->watchdog());
            $this->every('index', 10, fn () => Storage::index($this->activeFiles()));
            $this->every('health', 10, fn () => $this->health());
            $this->every('rotate', 60, fn () => Storage::rotate());
            $this->every('exports', 600, fn () => Storage::cleanupExports());
            $this->every('go2rtc', 30, fn () => Go2rtc::sync());
            sleep(1);
        }

        foreach (array_keys($this->procs) as $id) {
            $this->stopProcess($id);
        }
        $this->safe('final index', fn () => Storage::index([]));
        $this->info('recorder stopped');
        return 0;
    }

    private function every(string $name, int $seconds, callable $fn): void
    {
        $now = microtime(true);
        if (($this->timers[$name] ?? 0) + $seconds > $now) {
            return;
        }
        $this->timers[$name] = $now;
        $this->safe($name, $fn);
    }

    private function safe(string $name, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::error("recorder {$name}: " . $e->getMessage());
            $this->error("{$name}: " . $e->getMessage());
        }
    }

    private function syncProcesses(): void
    {
        $cams = Camera::where('enabled', true)->where('record', true)->get()->keyBy('id');

        foreach ($this->procs as $id => $p) {
            $cam = $cams[$id] ?? null;
            if (!$cam || $cam->recorderHash() !== $p['hash']) {
                $this->line("stop recording camera #{$id}");
                $this->stopProcess($id);
            }
        }

        foreach ($cams as $id => $cam) {
            Storage::ensureDirs($cam);
            if (isset($this->procs[$id])) {
                continue;
            }
            $b = $this->backoff[$id] ?? ['next' => 0, 'delay' => 0];
            if (time() < $b['next']) {
                continue;
            }
            $this->startProcess($cam);
        }
    }

    private function ffmpegArgs(Camera $cam): array
    {
        $out = $cam->recDir() . '/%Y-%m-%d/%H/' . $cam->slug . '_%Y%m%d_%H%M%S.ts';
        $args = ['ffmpeg', '-hide_banner', '-nostdin', '-loglevel', 'warning'];
        if (str_starts_with($cam->rtsp_url, 'rtsp')) {
            $args = array_merge($args, ['-rtsp_transport', $cam->transport === 'udp' ? 'udp' : 'tcp', '-timeout', '10000000']);
        }
        $args = array_merge($args, ['-fflags', '+genpts+discardcorrupt', '-i', $cam->sourceUrl(), '-map', '0:v:0', '-c:v', 'copy']);
        if ($cam->audio) {
            $args = array_merge($args, ['-map', '0:a:0?', '-c:a', 'aac', '-b:a', '64k']);
        } else {
            $args[] = '-an';
        }
        return array_merge($args, [
            '-f', 'segment',
            '-segment_time', (string) max(10, $cam->segment_seconds),
            '-segment_atclocktime', '1',
            '-reset_timestamps', '1',
            '-strftime', '1',
            '-segment_format', 'mpegts',
            $out,
        ]);
    }

    private function startProcess(Camera $cam): void
    {
        $log = storage_path('logs/ffmpeg-' . $cam->slug . '.log');
        if (is_file($log) && filesize($log) > 2_000_000) {
            @rename($log, $log . '.1');
        }
        $proc = proc_open($this->ffmpegArgs($cam), [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', $log, 'a'],
        ], $pipes);
        if (!is_resource($proc)) {
            $this->scheduleRestart($cam->id, 'не удалось запустить ffmpeg');
            return;
        }
        $this->line("start recording camera #{$cam->id} {$cam->name}");
        $this->procs[$cam->id] = [
            'proc' => $proc,
            'hash' => $cam->recorderHash(),
            'started' => time(),
            'last_write' => 0,
            'last_size' => -1,
            'last_file' => null,
            'log' => $log,
        ];
    }

    private function stopProcess(int $id): void
    {
        $p = $this->procs[$id] ?? null;
        if (!$p) {
            return;
        }
        $st = proc_get_status($p['proc']);
        if ($st['running']) {
            // SIGINT lets ffmpeg close the current file properly
            posix_kill($st['pid'], SIGINT);
            for ($i = 0; $i < 30 && proc_get_status($p['proc'])['running']; $i++) {
                usleep(100_000);
            }
            if (proc_get_status($p['proc'])['running']) {
                posix_kill($st['pid'], SIGKILL);
            }
        }
        proc_close($p['proc']);
        unset($this->procs[$id]);
    }

    private function scheduleRestart(int $id, string $error): void
    {
        $b = $this->backoff[$id] ?? ['delay' => 0];
        $delay = min(60, max(2, $b['delay'] * 2));
        $this->backoff[$id] = ['next' => time() + $delay, 'delay' => $delay];
        Camera::whereKey($id)->update(['last_error' => mb_substr($error, 0, 2000)]);
        $this->warn("camera #{$id}: {$error}; restart in {$delay}s");
    }

    private function newestFile(Camera $cam): ?string
    {
        $files = [];
        foreach ([0, 3600] as $off) {
            $t = time() - $off;
            foreach (glob($cam->recDir() . '/' . date('Y-m-d', $t) . '/' . date('H', $t) . '/*.ts') ?: [] as $f) {
                $files[] = $f;
            }
        }
        if (!$files) {
            return null;
        }
        sort($files);
        return end($files);
    }

    private function watchdog(): void
    {
        foreach (array_keys($this->procs) as $id) {
            $p = &$this->procs[$id];
            $st = proc_get_status($p['proc']);
            if (!$st['running']) {
                $tail = $this->logTail($p['log']);
                proc_close($p['proc']);
                unset($p, $this->procs[$id]);
                $this->scheduleRestart($id, 'ffmpeg завершился (код ' . $st['exitcode'] . '): ' . $tail);
                continue;
            }
            $cam = Camera::find($id);
            if (!$cam) {
                continue;
            }
            $file = $this->newestFile($cam);
            clearstatcache();
            $size = $file ? (int) @filesize($file) : -1;
            if ($file && filemtime($file) >= $p['started'] - 1 && ($file !== $p['last_file'] || $size !== $p['last_size'])) {
                $p['last_file'] = $file;
                $p['last_size'] = $size;
                $p['last_write'] = time();
                $this->backoff[$id] = ['next' => 0, 'delay' => 0];
            }
            $since = time() - max($p['last_write'], $p['started']);
            if ($since > 30) {
                $this->warn("camera #{$id}: no data for {$since}s, restarting ffmpeg");
                $tail = $this->logTail($p['log']);
                unset($p);
                $this->stopProcess($id);
                $this->scheduleRestart($id, 'нет данных от камеры ' . $since . ' с. ' . $tail);
            }
            unset($p);
        }
    }

    /** @return array<string,bool> files currently being written */
    private function activeFiles(): array
    {
        $out = [];
        foreach ($this->procs as $p) {
            if ($p['last_file']) {
                $out[$p['last_file']] = true;
            }
        }
        return $out;
    }

    private function logTail(string $log): string
    {
        if (!is_file($log)) {
            return '';
        }
        $fh = fopen($log, 'r');
        fseek($fh, max(0, filesize($log) - 600));
        $tail = trim((string) stream_get_contents($fh));
        fclose($fh);
        $lines = array_slice(array_filter(explode("\n", $tail)), -3);
        return implode(' | ', $lines);
    }

    private function health(): void
    {
        $offlineAfter = max(15, (int) Setting::get('offline_after'));
        foreach (Camera::all() as $cam) {
            $p = $this->procs[$cam->id] ?? null;
            if (!$cam->enabled || !$cam->record) {
                if ($cam->status !== 'unknown') {
                    $cam->forceFill(['status' => 'unknown', 'status_changed_at' => now()])->save();
                }
                continue;
            }
            $lastWrite = $p['last_write'] ?? 0;
            if ($lastWrite) {
                $cam->last_segment_at = now()->setTimestamp($lastWrite);
            }
            $ref = max($lastWrite, $cam->last_segment_at?->getTimestamp() ?? 0, $this->startedAt());
            $online = $lastWrite > 0 && time() - $lastWrite <= $offlineAfter;
            $offline = !$online && time() - $ref > $offlineAfter;

            $new = $online ? 'online' : ($offline ? 'offline' : $cam->status);
            if ($new !== $cam->status) {
                $old = $cam->status;
                $cam->status = $new;
                $cam->status_changed_at = now();
                if ($new === 'online') {
                    $cam->last_error = null;
                }
                $this->notify($cam, $old, $new);
            }
            $cam->save();
        }
    }

    private ?int $startedAt = null;

    private function startedAt(): int
    {
        return $this->startedAt ??= time();
    }

    private function notify(Camera $cam, string $old, string $new): void
    {
        $name = e($cam->name);
        if ($new === 'offline') {
            $err = $cam->last_error ? "\n<i>" . e(mb_substr($cam->last_error, 0, 300)) . '</i>' : '';
            $text = "🔴 Камера <b>{$name}</b> недоступна, запись остановлена.{$err}";
        } elseif ($new === 'online' && $old === 'offline') {
            $text = "🟢 Камера <b>{$name}</b> снова работает, запись идёт.";
        } else {
            return;
        }
        Log::warning(strip_tags($text));
        Telegram::send($text);
    }
}
