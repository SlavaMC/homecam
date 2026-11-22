<?php

namespace App\Jobs;

use App\Models\Export;
use App\Services\Storage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Glue the segments of an interval into one MP4 file (no re-encoding). */
class BuildExport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(public int $exportId)
    {
    }

    public function handle(): void
    {
        $e = Export::with('camera')->find($this->exportId);
        if (!$e || !$e->camera) {
            return;
        }
        $e->update(['status' => 'running']);

        $segs = Storage::segments($e->camera_id, $e->from_ts, $e->to_ts);
        if ($segs->isEmpty()) {
            $e->update(['status' => 'failed', 'error' => 'За этот интервал нет записи']);
            return;
        }

        $dir = config('homecam.exports_dir');
        @mkdir($dir, 0775, true);
        $name = sprintf('%s_%s_%s_%d.mp4', $e->camera->slug, date('Ymd-His', $e->from_ts), date('Ymd-His', $e->to_ts), $e->id);
        $list = $dir . '/.list-' . $e->id . '.txt';

        $lines = ['ffconcat version 1.0'];
        $last = $segs->count() - 1;
        foreach ($segs as $i => $s) {
            $lines[] = "file '" . str_replace("'", "'\\''", $s->absPath()) . "'";
            if ($i === 0 && $e->from_ts > $s->start_ts) {
                $lines[] = 'inpoint ' . ($e->from_ts - $s->start_ts);
            }
            if ($i === $last && $e->to_ts < $s->start_ts + $s->duration) {
                $lines[] = 'outpoint ' . max(1, $e->to_ts - $s->start_ts);
            }
        }
        file_put_contents($list, implode("\n", $lines) . "\n");

        $tmp = $dir . '/.tmp-' . $name;
        $cmd = sprintf(
            'ffmpeg -hide_banner -loglevel error -nostdin -y -f concat -safe 0 -i %s -map 0 -c copy -avoid_negative_ts make_zero -movflags +faststart %s 2>&1',
            escapeshellarg($list),
            escapeshellarg($tmp)
        );
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        @unlink($list);

        if ($code !== 0 || !is_file($tmp) || filesize($tmp) < 1024) {
            @unlink($tmp);
            $e->update(['status' => 'failed', 'error' => mb_substr(implode("\n", array_slice($out, -10)), 0, 2000) ?: 'ffmpeg error']);
            return;
        }
        rename($tmp, $dir . '/' . $name);
        $e->update(['status' => 'done', 'file' => $name, 'size' => filesize($dir . '/' . $name), 'error' => null]);
    }

    public function failed(\Throwable $ex): void
    {
        Export::whereKey($this->exportId)->update(['status' => 'failed', 'error' => mb_substr($ex->getMessage(), 0, 2000)]);
    }
}
