<?php

namespace App\Console\Commands;

use App\Models\Camera;
use App\Models\User;
use Illuminate\Console\Command;

class Bootstrap extends Command
{
    protected $signature = 'homecam:bootstrap';
    protected $description = 'First start: create the admin user from ADMIN_LOGIN / ADMIN_PASSWORD';

    public function handle(): int
    {
        if (User::count() === 0) {
            $login = config('homecam.admin_login') ?: 'admin';
            $password = config('homecam.admin_password');
            if (!$password) {
                $this->error('ADMIN_PASSWORD is empty in .env, admin user not created');
                return 0;
            }
            User::create(['login' => $login, 'name' => $login, 'password' => $password]);
            $this->info("admin user '{$login}' created");
        }
        return 0;
    }
}
