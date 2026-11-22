<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShareLink extends Model
{
    protected $fillable = [
        'token', 'name', 'camera_ids', 'allow_live', 'allow_history',
        'history_from', 'history_to', 'expires_at', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'camera_ids' => 'array',
            'allow_live' => 'boolean',
            'allow_history' => 'boolean',
            'history_from' => 'integer',
            'history_to' => 'integer',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function isValid(): bool
    {
        return $this->expires_at->isFuture();
    }

    public function allowsCamera(int $cameraId): bool
    {
        return $this->camera_ids === null || in_array($cameraId, array_map('intval', $this->camera_ids), true);
    }

    public function url(): string
    {
        return url('/s/' . $this->token);
    }
}
