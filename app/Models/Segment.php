<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Segment extends Model
{
    public $timestamps = false;
    protected $fillable = ['camera_id', 'path', 'start_ts', 'duration', 'size'];

    protected function casts(): array
    {
        return ['start_ts' => 'integer', 'duration' => 'float', 'size' => 'integer'];
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    public function absPath(): string
    {
        return rtrim(config('homecam.rec_dir'), '/') . '/' . $this->path;
    }
}
