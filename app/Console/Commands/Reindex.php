<?php

namespace App\Console\Commands;

use App\Services\Storage;
use Illuminate\Console\Command;

class Reindex extends Command
{
    protected $signature = 'homecam:reindex';
    protected $description = 'Rebuild the recordings index from the files on disk';

    public function handle(): int
    {
        $removed = Storage::pruneMissing();
        $added = Storage::index([], null);
        $this->info("added {$added}, removed {$removed} segments");
        return 0;
    }
}
