<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Camera extends Model
{
    protected $fillable = [
        'name', 'slug', 'rtsp_url', 'username', 'password', 'transport',
        'enabled', 'record', 'audio', 'segment_seconds', 'sort',
        'motion_enabled', 'motion_threshold', 'motion_mask',
        'notify_enabled', 'notify_threshold', 'notify_mask', 'notify_cooldown', 'notify_snapshot_delay',
        'heatmap_enabled', 'heatmap_threshold',
    ];

    protected $hidden = ['password', 'username'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'enabled' => 'boolean',
            'record' => 'boolean',
            'audio' => 'boolean',
            'segment_seconds' => 'integer',
            'status_changed_at' => 'datetime',
            'last_segment_at' => 'datetime',
            'motion_enabled' => 'boolean',
            'motion_threshold' => 'float',
            'notify_enabled' => 'boolean',
            'notify_threshold' => 'float',
            'notify_cooldown' => 'integer',
            'notify_snapshot_delay' => 'float',
            'heatmap_enabled' => 'boolean',
            'heatmap_threshold' => 'float',
            'notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Camera $c) {
            if (!$c->slug) {
                $base = Str::slug($c->name) ?: 'cam';
                $slug = $base;
                $i = 2;
                while (static::where('slug', $slug)->exists()) {
                    $slug = $base . '-' . $i++;
                }
                $c->slug = $slug;
            }
        });
    }

    public function segments(): HasMany
    {
        return $this->hasMany(Segment::class);
    }

    /** RTSP URL with credentials embedded (never shown to users). */
    public function sourceUrl(): string
    {
        $url = $this->rtsp_url;
        if ($this->username === null || $this->username === '') {
            return $url;
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return $url;
        }
        $auth = rawurlencode($this->username) . ':' . rawurlencode((string) $this->password) . '@';
        $scheme = ($parts['scheme'] ?? 'rtsp') . '://';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        return $scheme . $auth . $parts['host'] . $port . $path . $query;
    }

    public function streamName(): string
    {
        return 'cam_' . $this->id;
    }

    /** Hash of everything that requires restarting the recorder. */
    public function recorderHash(): string
    {
        return md5(json_encode([$this->sourceUrl(), $this->transport, $this->audio, $this->segment_seconds, $this->slug]));
    }

    public function recDir(): string
    {
        return rtrim(config('homecam.rec_dir'), '/') . '/' . $this->slug;
    }
}
