<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $t) {
            $t->boolean('motion_enabled')->default(true);
            $t->float('motion_threshold')->default(0.5);      // % of zone area that must change
            $t->text('motion_mask')->nullable();              // 32x24 grid of 0/1, null = whole frame
            $t->boolean('notify_enabled')->default(false);
            $t->float('notify_threshold')->default(3);        // % of notify zone
            $t->text('notify_mask')->nullable();
            $t->unsignedInteger('notify_cooldown')->default(300); // seconds between alerts
            $t->timestamp('notified_at')->nullable();
        });

        Schema::table('segments', function (Blueprint $t) {
            $t->boolean('motion_checked')->default(false);
            $t->float('motion_peak')->nullable();
        });
        // only new recordings are analysed
        DB::table('segments')->update(['motion_checked' => true]);

        Schema::create('motion_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('camera_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('start_ts');
            $t->unsignedBigInteger('end_ts');
            $t->float('peak');                 // max % of the detection zone changed
            $t->float('notify_peak')->nullable(); // max % of the notification zone changed
            $t->boolean('notified')->default(false);
            $t->index(['camera_id', 'start_ts']);
            $t->index('end_ts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motion_events');
        Schema::table('segments', fn (Blueprint $t) => $t->dropColumn(['motion_checked', 'motion_peak']));
        Schema::table('cameras', fn (Blueprint $t) => $t->dropColumn([
            'motion_enabled', 'motion_threshold', 'motion_mask', 'notify_enabled',
            'notify_threshold', 'notify_mask', 'notify_cooldown', 'notified_at',
        ]));
    }
};
