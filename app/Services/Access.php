<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\ShareLink;
use Illuminate\Support\Collection;

/** Who is looking: a logged-in user (full access) or a guest with a temporary link. */
class Access
{
    private static ?ShareLink $share = null;
    private static bool $loaded = false;

    public static function isUser(): bool
    {
        return auth()->check();
    }

    public static function share(): ?ShareLink
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = session('share_id');
            if ($id) {
                $s = ShareLink::find($id);
                self::$share = ($s && $s->isValid()) ? $s : null;
                if (!self::$share) {
                    session()->forget('share_id');
                }
            }
        }
        return self::$share;
    }

    public static function any(): bool
    {
        return self::isUser() || self::share() !== null;
    }

    public static function can(string $what, ?int $cameraId = null): bool
    {
        if (self::isUser()) {
            return true;
        }
        $s = self::share();
        if (!$s) {
            return false;
        }
        if ($what === 'live' && !$s->allow_live) {
            return false;
        }
        if ($what === 'history' && !$s->allow_history) {
            return false;
        }
        return $cameraId === null || $s->allowsCamera($cameraId);
    }

    /** Clamp a history interval to what the share link allows. */
    public static function clampRange(int $from, int $to): array
    {
        $s = self::isUser() ? null : self::share();
        if ($s) {
            if ($s->history_from) {
                $from = max($from, $s->history_from);
            }
            if ($s->history_to) {
                $to = min($to, $s->history_to);
            }
        }
        return [$from, max($from, $to)];
    }

    /** @return Collection<int, Camera> */
    public static function cameras(string $what): Collection
    {
        return Camera::where('enabled', true)->orderBy('sort')->orderBy('id')->get()
            ->filter(fn (Camera $c) => self::can($what, $c->id))->values();
    }
}
