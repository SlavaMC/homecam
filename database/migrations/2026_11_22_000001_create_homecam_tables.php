<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cameras', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('rtsp_url');
            $t->string('username')->nullable();
            $t->text('password')->nullable();          // encrypted
            $t->string('transport', 8)->default('tcp');
            $t->boolean('enabled')->default(true);
            $t->boolean('record')->default(true);
            $t->boolean('audio')->default(false);
            $t->unsignedSmallInteger('segment_seconds')->default(60);
            $t->unsignedInteger('sort')->default(0);
            $t->string('status', 16)->default('unknown'); // online | offline | unknown
            $t->timestamp('status_changed_at')->nullable();
            $t->timestamp('last_segment_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
        });

        Schema::create('segments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('camera_id')->constrained()->cascadeOnDelete();
            $t->string('path')->unique();                // relative to rec dir
            $t->unsignedBigInteger('start_ts');          // unix seconds
            $t->float('duration');
            $t->unsignedBigInteger('size');
            $t->index(['camera_id', 'start_ts']);
            $t->index('start_ts');
        });

        Schema::create('share_links', function (Blueprint $t) {
            $t->id();
            $t->string('token', 64)->unique();
            $t->string('name')->nullable();
            $t->json('camera_ids')->nullable();          // null = all cameras
            $t->boolean('allow_live')->default(true);
            $t->boolean('allow_history')->default(false);
            $t->unsignedBigInteger('history_from')->nullable();
            $t->unsignedBigInteger('history_to')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('last_used_at')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('exports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('camera_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('from_ts');
            $t->unsignedBigInteger('to_ts');
            $t->string('status', 16)->default('pending'); // pending | running | done | failed
            $t->string('file')->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->text('error')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('share_link_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('exports');
        Schema::dropIfExists('share_links');
        Schema::dropIfExists('segments');
        Schema::dropIfExists('cameras');
    }
};
