<?php

namespace App\Console\Commands;

use App\Models\Camera;
use Illuminate\Console\Command;

class CameraAdd extends Command
{
    protected $signature = 'homecam:camera-add {name} {url} {--login=} {--password=} {--udp}';
    protected $description = 'Add a camera (same as the web form)';

    public function handle(): int
    {
        $cam = Camera::create([
            'name' => $this->argument('name'),
            'rtsp_url' => $this->argument('url'),
            'username' => $this->option('login'),
            'password' => $this->option('password'),
            'transport' => $this->option('udp') ? 'udp' : 'tcp',
            'sort' => (int) Camera::max('sort') + 1,
        ]);
        $this->info("camera #{$cam->id} '{$cam->name}' ({$cam->slug}) added");
        return 0;
    }
}
