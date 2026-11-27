<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $t) {
            $t->boolean('heatmap_enabled')->default(true);
            $t->float('heatmap_threshold')->default(25); // % of a cell's pixels that must change
        });
        Schema::table('segments', function (Blueprint $t) {
            // 32x24 uint8 counters (key frames with motion per cell), gzcompress'ed
            $t->binary('heat')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('segments', fn (Blueprint $t) => $t->dropColumn('heat'));
        Schema::table('cameras', fn (Blueprint $t) => $t->dropColumn(['heatmap_enabled', 'heatmap_threshold']));
    }
};
