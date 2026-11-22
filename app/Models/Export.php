<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Export extends Model
{
    protected $fillable = ['camera_id', 'from_ts', 'to_ts', 'status', 'file', 'size', 'error', 'user_id', 'share_link_id'];

    protected function casts(): array
    {
        return ['from_ts' => 'integer', 'to_ts' => 'integer', 'size' => 'integer'];
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    public function absPath(): ?string
    {
        return $this->file ? rtrim(config('homecam.exports_dir'), '/') . '/' . $this->file : null;
    }
}
