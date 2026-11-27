<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\MotionEvent;
use App\Models\Segment;
use Illuminate\Support\Facades\Log;

/**
 * Cheap motion detection: only key frames of each recorded segment are decoded,
 * scaled down to 128x96 grey and compared with the previous key frame.
 * Strength = % of the zone (detection or notification) whose pixels changed noticeably.
 */
class Motion
{
    public const W = 128;
    public const H = 96;
    public const GRID_W = 32;          // mask grid, each cell = 4x4 pixels
    public const GRID_H = 24;
    public const PIXEL_DIFF = 20;      // brightness change (0..255) that counts as "changed"
    public const LIGHT_CHANGE = 45.0;  // % of the whole frame: lights/IR switch, not motion
    public const EVENT_GAP = 10;       // seconds: motions closer than this form one event
    public const NOTIFY_MAX_AGE = 600; // do not alert about old video (backlog after restart)

    /** @var array<int, array{frame: string, ts: float}> last key frame per camera */
    private array $prev = [];
    /** @var array<int, array{id: int, last: float}> open event per camera */
    private array $open = [];
    /** @var int[] pixel index -> mask cell */
    private array $cellOf = [];

    public function __construct()
    {
        for ($y = 0; $y < self::H; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                $this->cellOf[$y * self::W + $x] = intdiv($y, 4) * self::GRID_W + intdiv($x, 4);
            }
        }
    }

    /** Mask string -> list of enabled cells (null = all). */
    public static function cells(?string $mask): ?array
    {
        if ($mask === null || strlen($mask) !== self::GRID_W * self::GRID_H || !str_contains($mask, '0')) {
            return null;
        }
        $out = [];
        for ($i = 0, $n = strlen($mask); $i < $n; $i++) {
            if ($mask[$i] === '1') {
                $out[$i] = true;
            }
        }
        return $out;
    }

    /** Decode key frames of a file as raw 128x96 grey frames. */
    public static function keyframes(string $file): array
    {
        $cmd = sprintf(
            'nice -n 15 ffmpeg -v error -threads 1 -skip_frame nokey -skip_loop_filter all -i %s -fps_mode passthrough -an -vf scale=%d:%d:flags=area,format=gray -f rawvideo - 2>/dev/null',
            escapeshellarg($file), self::W, self::H
        );
        $raw = (string) shell_exec($cmd);
        $size = self::W * self::H;
        $frames = [];
        for ($o = 0; $o + $size <= strlen($raw); $o += $size) {
            $frames[] = substr($raw, $o, $size);
        }
        return $frames;
    }

    /** Analyse one segment, create/extend events, send alerts. */
    public function process(Segment $seg, Camera $cam): void
    {
        $file = $seg->absPath();
        $heatOn = (bool) ($cam->heatmap_enabled ?? false);
        if ((!$cam->motion_enabled && !$heatOn) || !is_file($file)) {
            $seg->forceFill(['motion_checked' => true])->save();
            return;
        }
        $frames = self::keyframes($file);
        $n = count($frames);
        $detCells = self::cells($cam->motion_mask);
        $notCells = self::cells($cam->notify_mask);
        $detTotal = $detCells === null ? self::GRID_W * self::GRID_H : count($detCells);
        $notTotal = $notCells === null ? self::GRID_W * self::GRID_H : count($notCells);
        $peakSeg = 0.0;
        $prev = $this->prev[$cam->id] ?? null;
        $heat = $heatOn ? array_fill(0, self::GRID_W * self::GRID_H, 0) : null;
        $heatMin = max(1, (int) ceil(16 * (float) ($cam->heatmap_threshold ?? 25) / 100));

        foreach ($frames as $i => $frame) {
            $ts = $seg->start_ts + ($n > 0 ? $i * $seg->duration / $n : 0);
            if ($prev === null || $ts - $prev['ts'] > 15 || $ts <= $prev['ts']) {
                $prev = ['frame' => $frame, 'ts' => $ts];
                continue;
            }
            // changed pixels per mask cell (each cell has 16 pixels)
            $cellHits = [];
            $changed = 0;
            $a = $prev['frame'];
            for ($k = 0, $len = self::W * self::H; $k < $len; $k++) {
                $d = ord($frame[$k]) - ord($a[$k]);
                if ($d > self::PIXEL_DIFF || $d < -self::PIXEL_DIFF) {
                    $changed++;
                    $c = $this->cellOf[$k];
                    $cellHits[$c] = ($cellHits[$c] ?? 0) + 1;
                }
            }
            $prev = ['frame' => $frame, 'ts' => $ts];
            if ($changed * 100 / (self::W * self::H) >= self::LIGHT_CHANGE) {
                continue; // the whole picture changed: IR switch, lights, camera re-exposure
            }
            if ($heat !== null) {
                foreach ($cellHits as $c => $h) {
                    if ($h >= $heatMin && $heat[$c] < 255) {
                        $heat[$c]++;
                    }
                }
            }
            if (!$cam->motion_enabled) {
                continue;
            }
            $det = $this->score($cellHits, $detCells, $detTotal);
            $not = $this->score($cellHits, $notCells, $notTotal);
            $peakSeg = max($peakSeg, $det);

            if ($det >= $cam->motion_threshold) {
                $event = $this->track($cam, $ts, $det);
                if ($cam->notify_enabled && $not >= $cam->notify_threshold) {
                    $event->notify_peak = max((float) $event->notify_peak, $not);
                    $event->save();
                    $this->notify($cam, $event, $seg, $i, $ts, $not, $cellHits, $notCells);
                }
            }
        }
        $this->prev[$cam->id] = $prev;
        $seg->forceFill([
            'motion_checked' => true,
            'motion_peak' => $cam->motion_enabled ? round($peakSeg, 2) : null,
            'heat' => $heat !== null ? gzcompress(pack('C*', ...$heat), 6) : null,
        ])->save();
    }

    /**
     * Heat map of a camera over [from, to): per cell, how many key frames had motion.
     * @return array{counts: int[], max: int, segments: int}
     */
    public static function heatmap(int $cameraId, int $from, int $to): array
    {
        $acc = array_fill(0, self::GRID_W * self::GRID_H, 0);
        $n = 0;
        Segment::where('camera_id', $cameraId)->where('start_ts', '>=', $from - 600)->where('start_ts', '<', $to)
            ->whereNotNull('heat')->select(['id', 'start_ts', 'duration', 'heat'])
            ->chunkById(2000, function ($segs) use (&$acc, &$n, $from) {
                foreach ($segs as $s) {
                    if ($s->start_ts + $s->duration <= $from) {
                        continue;
                    }
                    $raw = @gzuncompress($s->heat);
                    if ($raw === false || strlen($raw) !== count($acc)) {
                        continue;
                    }
                    $i = 0;
                    foreach (unpack('C*', $raw) as $v) {
                        $acc[$i++] += $v;
                    }
                    $n++;
                }
            });
        return ['counts' => $acc, 'max' => max($acc), 'segments' => $n];
    }

    /** % of the zone's pixels that changed. */
    private function score(array $cellHits, ?array $cells, int $totalCells): float
    {
        if ($totalCells === 0) {
            return 0.0;
        }
        $sum = 0;
        foreach ($cellHits as $c => $hits) {
            if ($cells === null || isset($cells[$c])) {
                $sum += $hits;
            }
        }
        return round($sum * 100 / ($totalCells * 16), 2);
    }

    private function track(Camera $cam, float $ts, float $score): MotionEvent
    {
        $open = $this->open[$cam->id] ?? null;
        $event = null;
        if ($open && $ts - $open['last'] <= self::EVENT_GAP) {
            $event = MotionEvent::find($open['id']);
        }
        if (!$event) {
            $event = MotionEvent::create([
                'camera_id' => $cam->id,
                'start_ts' => (int) floor($ts),
                'end_ts' => (int) ceil($ts + 1),
                'peak' => $score,
            ]);
        } else {
            $event->end_ts = max($event->end_ts, (int) ceil($ts + 1));
            $event->peak = max($event->peak, $score);
            $event->save();
        }
        $this->open[$cam->id] = ['id' => $event->id, 'last' => $ts];
        return $event;
    }

    private function notify(Camera $cam, MotionEvent $event, Segment $seg, int $index, float $ts, float $score, array $cellHits, ?array $cells): void
    {
        if ($event->notified || time() - $ts > self::NOTIFY_MAX_AGE) {
            return;
        }
        if ($cam->notified_at && time() - $cam->notified_at->getTimestamp() < $cam->notify_cooldown) {
            return;
        }
        $cam->forceFill(['notified_at' => now()])->save();
        $event->forceFill(['notified' => true])->save();

        // bounding box of the changed cells inside the notification zone
        $x0 = self::GRID_W; $y0 = self::GRID_H; $x1 = -1; $y1 = -1;
        foreach ($cellHits as $c => $hits) {
            if ($hits < 4 || ($cells !== null && !isset($cells[$c]))) {
                continue;
            }
            $cx = $c % self::GRID_W; $cy = intdiv($c, self::GRID_W);
            $x0 = min($x0, $cx); $y0 = min($y0, $cy); $x1 = max($x1, $cx); $y1 = max($y1, $cy);
        }
        $delay = max(0.0, (float) ($cam->notify_snapshot_delay ?? 2));
        $vf = 'scale=960:-2';
        // the red frame marks where motion was detected; with a delayed picture it is only drawn for short delays
        if ($x1 >= 0 && $delay <= 1) {
            $vf .= sprintf(',drawbox=x=iw*%.4f:y=ih*%.4f:w=iw*%.4f:h=ih*%.4f:color=red@0.9:t=4',
                $x0 / self::GRID_W, $y0 / self::GRID_H, ($x1 - $x0 + 1) / self::GRID_W, ($y1 - $y0 + 1) / self::GRID_H);
        }
        $jpg = sys_get_temp_dir() . '/motion-' . $event->id . '.jpg';
        $this->snapshot($cam, $seg, $ts + $delay, $vf, $jpg);
        if (!is_file($jpg)) {
            $this->grab($seg->absPath(), max(0, $ts - $seg->start_ts), $vf, $jpg); // fallback: the moment of detection
        }

        $from = (int) $ts - 30;
        $link = rtrim((string) config('app.url'), '/') . '/history?' . http_build_query(['from' => $from, 'to' => $from + 300, 'cams' => $cam->id]);
        $caption = sprintf("🚶 Движение: <b>%s</b>\n%s, сила %s%%\n<a href=\"%s\">Открыть архив</a>",
            e($cam->name), date('d.m.Y H:i:s', (int) $ts), rtrim(rtrim(number_format($score, 1, '.', ''), '0'), '.'), e($link));
        $err = is_file($jpg) ? Telegram::sendPhoto($jpg, $caption) : Telegram::send($caption);
        @unlink($jpg);
        if ($err) {
            Log::warning("motion alert for camera #{$cam->id} failed: {$err}");
        }
    }

    /**
     * Picture at wall-clock time $at. It may be in the next segment, which is still being
     * written (MPEG-TS can be read while recording), so wait a little for the data to arrive.
     */
    private function snapshot(Camera $cam, Segment $seg, float $at, string $vf, string $jpg): void
    {
        if ($at < $seg->start_ts + $seg->duration - 0.2) {
            $this->grab($seg->absPath(), $at - $seg->start_ts, $vf, $jpg);
            return;
        }
        for ($try = 0; $try < 6 && !is_file($jpg); $try++) {
            $best = null;
            foreach ([0, -3600] as $off) {
                $t = (int) $at + $off;
                foreach (glob($cam->recDir() . '/' . date('Y-m-d', $t) . '/' . date('H', $t) . '/*.ts') ?: [] as $f) {
                    $start = Storage::parseStart($f);
                    if ($start !== null && $start > $seg->start_ts && $start <= $at && (!$best || $start > $best[1])) {
                        $best = [$f, $start];
                    }
                }
            }
            if ($best) {
                $this->grab($best[0], $at - $best[1], $vf, $jpg);
            }
            if (!is_file($jpg)) {
                sleep(3);
            }
        }
    }

    private function grab(string $file, float $offset, string $vf, string $jpg): void
    {
        @unlink($jpg);
        exec(sprintf('ffmpeg -v error -y -ss %.2f -i %s -frames:v 1 -vf %s -q:v 4 %s 2>&1',
            max(0, $offset), escapeshellarg($file), escapeshellarg($vf), escapeshellarg($jpg)));
        if (is_file($jpg) && filesize($jpg) < 1000) {
            @unlink($jpg);
        }
    }

    /** Remove events older than the oldest recording of the camera. */
    public static function cleanup(): void
    {
        foreach (Camera::pluck('id') as $id) {
            $min = Segment::where('camera_id', $id)->min('start_ts');
            $q = MotionEvent::where('camera_id', $id);
            if ($min !== null) {
                $q->where('end_ts', '<', $min);
            }
            $q->delete();
        }
    }
}
