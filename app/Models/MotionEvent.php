<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MotionEvent extends Model
{
    public $timestamps = false;
    protected $fillable = ['camera_id', 'start_ts', 'end_ts', 'peak', 'notify_peak', 'notified'];

    protected function casts(): array
    {
        return ['start_ts' => 'integer', 'end_ts' => 'integer', 'peak' => 'float', 'notify_peak' => 'float', 'notified' => 'boolean'];
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }
}
