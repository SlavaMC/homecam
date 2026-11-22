<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\Export;
use App\Models\Segment;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recording files layout (self-describing, can be copied anywhere and played with any player):
 *   <rec_dir>/<camera-slug>/<YYYY-MM-DD>/<HH>/<camera-slug>_<YYYYMMDD>_<HHMMSS>.ts
 * Every file is a standalone MPEG-TS video of about one segment length.
 */
class Storage
{
    public const NAME_RE = '/_(\d{8})_(\d{6})\.ts$/';

    public static function parseStart(string $path): ?int
    {
        if (!preg_match(self::NAME_RE, $path, $m)) {
            return null;
        }
        $dt = Carbon::createFromFormat('YmdHis', $m[1] . $m[2], config('app.timezone'));
        return $dt ? $dt->getTimestamp() : null;
    }

    public static function probeDuration(string $file): ?float
    {
        $out = [];
        $code = 0;
        exec('ffprobe -v error -show_entries format=duration -of default=nw=1:nk=1 ' . escapeshellarg($file) . ' 2>/dev/null', $out, $code);
        $d = isset($out[0]) ? (float) $out[0] : 0.0;
        return ($code === 0 && $d > 0) ? $d : null;
    }

    /** Make sure directories for the current and the next few minutes exist (ffmpeg does not create them). */
    public static function ensureDirs(Camera $cam): void
    {
        foreach ([0, 120] as $offset) {
            $t = time() + $offset;
            $dir = $cam->recDir() . '/' . date('Y-m-d', $t) . '/' . date('H', $t);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }

    /**
     * Add finished segment files to the database.
     * @param array<string,bool> $active absolute paths being written right now (skipped)
     * @param int|null $sinceHours only scan hour-directories of the last N hours (null = everything)
     */
    public static function index(array $active = [], ?int $sinceHours = 3): int
    {
        $rec = rtrim(config('homecam.rec_dir'), '/');
        $added = 0;
        foreach (Camera::all() as $cam) {
            $base = $cam->recDir();
            if (!is_dir($base)) {
                continue;
            }
            $dirs = [];
            if ($sinceHours === null) {
                foreach (glob($base . '/*/*', GLOB_ONLYDIR) ?: [] as $d) {
                    $dirs[] = $d;
                }
            } else {
                for ($h = $sinceHours; $h >= 0; $h--) {
                    $t = time() - $h * 3600;
                    $dirs[] = $base . '/' . date('Y-m-d', $t) . '/' . date('H', $t);
                }
                $dirs = array_unique($dirs);
            }

            foreach ($dirs as $dir) {
                $files = glob($dir . '/*.ts') ?: [];
                if (!$files) {
                    continue;
                }
                $rel = array_map(fn ($f) => substr($f, strlen($rec) + 1), $files);
                $known = Segment::whereIn('path', $rel)->pluck('path')->flip();
                foreach ($files as $i => $file) {
                    $path = $rel[$i];
                    if (isset($known[$path]) || isset($active[$file]) || @filemtime($file) > time() - 5) {
                        continue; // already indexed or still being written
                    }
                    $start = self::parseStart($path);
                    $size = @filesize($file) ?: 0;
                    if ($start === null) {
                        continue;
                    }
                    $dur = self::probeDuration($file);
                    if ($dur === null) {
                        // broken or empty file (e.g. camera dropped right after a cut)
                        if ($size < 64 * 1024 && filemtime($file) < time() - 60) {
                            @unlink($file);
                        }
                        continue;
                    }
                    Segment::create([
                        'camera_id' => $cam->id,
                        'path' => $path,
                        'start_ts' => $start,
                        'duration' => round($dur, 3),
                        'size' => $size,
                    ]);
                    $added++;
                }
            }
        }
        return $added;
    }

    public static function limitBytes(): int
    {
        return (int) round((float) Setting::get('storage_limit_gb') * 1e9);
    }

    public static function usedBytes(): int
    {
        return (int) Segment::sum('size');
    }

    /** Delete oldest segments until the total size is under the limit and the disk has enough free space. */
    public static function rotate(): int
    {
        $limit = self::limitBytes();
        $minFree = (int) round((float) Setting::get('min_free_gb') * 1e9);
        // reserve: files being written right now are not in the DB yet
        $reserve = (int) (Camera::where('enabled', true)->where('record', true)->count() * 100e6);
        $deleted = 0;

        for ($guard = 0; $guard < 1000; $guard++) {
            $used = self::usedBytes() + $reserve;
            $free = @disk_free_space(config('homecam.rec_dir'));
            $overLimit = $used > $limit;
            $lowDisk = $free !== false && $free < $minFree;
            if (!$overLimit && !$lowDisk) {
                break;
            }
            $batch = Segment::orderBy('start_ts')->limit(50)->get();
            if ($batch->isEmpty()) {
                break;
            }
            $need = $overLimit ? $used - $limit : PHP_INT_MAX;
            $freed = 0;
            $ids = [];
            foreach ($batch as $seg) {
                $file = $seg->absPath();
                @unlink($file);
                self::removeEmptyDirs(dirname($file));
                $ids[] = $seg->id;
                $freed += $seg->size;
                $deleted++;
                if (!$lowDisk && $freed >= $need) {
                    break;
                }
            }
            Segment::whereIn('id', $ids)->delete();
            if (!$overLimit) {
                break; // low disk only: free gradually, one batch per run
            }
        }
        if ($deleted) {
            Log::info("rotation: deleted {$deleted} segments");
        }
        return $deleted;
    }

    /** Remove database rows of files which were deleted from disk by hand. */
    public static function pruneMissing(): int
    {
        $n = 0;
        Segment::orderBy('id')->chunkById(2000, function ($segs) use (&$n) {
            $ids = [];
            foreach ($segs as $s) {
                if (!is_file($s->absPath())) {
                    $ids[] = $s->id;
                }
            }
            if ($ids) {
                Segment::whereIn('id', $ids)->delete();
                $n += count($ids);
            }
        });
        return $n;
    }

    public static function removeEmptyDirs(string $dir): void
    {
        $rec = rtrim(config('homecam.rec_dir'), '/');
        for ($i = 0; $i < 3 && str_starts_with($dir, $rec . '/'); $i++) {
            if (!@rmdir($dir)) {
                return; // not empty
            }
            $dir = dirname($dir);
        }
    }

    public static function cleanupExports(): void
    {
        $ttl = (int) Setting::get('export_ttl_hours');
        foreach (Export::where('created_at', '<', now()->subHours($ttl))->get() as $e) {
            if ($p = $e->absPath()) {
                @unlink($p);
            }
            $e->delete();
        }
    }

    /**
     * Segments of a camera which overlap [from, to).
     * @return \Illuminate\Support\Collection<int, Segment>
     */
    public static function segments(int $cameraId, int $from, int $to)
    {
        return Segment::where('camera_id', $cameraId)
            ->where('start_ts', '<', $to)
            ->where('start_ts', '>', $from - 3600)
            ->orderBy('start_ts')
            ->get()
            ->filter(fn (Segment $s) => $s->start_ts + $s->duration > $from)
            ->values();
    }

    /** Merged [start, end] intervals with recordings, for the timeline. */
    public static function coverage(int $cameraId, int $from, int $to): array
    {
        $out = [];
        foreach (self::segments($cameraId, $from, $to) as $s) {
            $a = $s->start_ts;
            $b = $s->start_ts + $s->duration;
            $last = count($out) - 1;
            if ($last >= 0 && $a - $out[$last][1] <= 3) {
                $out[$last][1] = max($out[$last][1], $b);
            } else {
                $out[] = [$a, $b];
            }
        }
        return array_map(fn ($i) => [(int) $i[0], (int) ceil($i[1])], $out);
    }

    public static function stats(): array
    {
        $rows = DB::table('segments')
            ->selectRaw('camera_id, COUNT(*) as n, SUM(size) as bytes, MIN(start_ts) as first, MAX(start_ts) as last')
            ->groupBy('camera_id')->get()->keyBy('camera_id');
        return [
            'per_camera' => $rows,
            'used' => (int) $rows->sum('bytes'),
            'limit' => self::limitBytes(),
            'disk_free' => (int) (@disk_free_space(config('homecam.rec_dir')) ?: 0),
            'disk_total' => (int) (@disk_total_space(config('homecam.rec_dir')) ?: 0),
        ];
    }
}
