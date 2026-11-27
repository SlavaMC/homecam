<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('segments', function (Blueprint $t) {
            // number of key-frame comparisons behind the heat counters (to find cells that change almost always)
            $t->unsignedSmallInteger('heat_frames')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('segments', fn (Blueprint $t) => $t->dropColumn('heat_frames'));
    }
};
