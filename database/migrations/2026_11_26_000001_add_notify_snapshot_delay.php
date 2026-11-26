<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $t) {
            // seconds after the start of motion when the Telegram picture is taken
            $t->float('notify_snapshot_delay')->default(2);
        });
    }

    public function down(): void
    {
        Schema::table('cameras', fn (Blueprint $t) => $t->dropColumn('notify_snapshot_delay'));
    }
};
