<?php

namespace App\Console\Commands;

use App\Models\Camera;
use App\Models\Segment;
use App\Services\Motion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Long-running: analyses freshly recorded segments for motion. */
class MotionDetector extends Command
{
    protected $signature = 'homecam:motion';
    protected $description = 'Detect motion in new recordings (key frames only)';

    private bool $stop = false;

    public function handle(): int
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stop = true);
        pcntl_signal(SIGINT, fn () => $this->stop = true);
        $motion = new Motion();
        $this->info('motion detector started');
        $lastCleanup = 0;

        while (!$this->stop) {
            try {
                // recordings older than 2 hours are not analysed (e.g. after a long downtime)
                Segment::where('motion_checked', false)->where('start_ts', '<', time() - 7200)
                    ->update(['motion_checked' => true]);

                $segs = Segment::where('motion_checked', false)->orderBy('start_ts')->limit(20)->get();
                $cams = Camera::whereIn('id', $segs->pluck('camera_id')->unique())->get()->keyBy('id');
                foreach ($segs as $seg) {
                    if ($this->stop) {
                        break;
                    }
                    $cam = $cams[$seg->camera_id] ?? null;
                    if (!$cam) {
                        continue;
                    }
                    $motion->process($seg, $cam);
                }
                if (time() - $lastCleanup > 600) {
                    Motion::cleanup();
                    $lastCleanup = time();
                }
                if ($segs->isEmpty()) {
                    sleep(5);
                }
            } catch (\Throwable $e) {
                Log::error('motion: ' . $e->getMessage());
                $this->error($e->getMessage());
                sleep(10);
            }
        }
        return 0;
    }
}
