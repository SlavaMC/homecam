<?php

namespace App\Services;

use App\Models\Camera;
use Illuminate\Support\Facades\Http;

/** Keeps go2rtc (live view relay) streams in sync with the cameras table. */
class Go2rtc
{
    private static function url(string $path): string
    {
        return rtrim(config('homecam.go2rtc_url'), '/') . $path;
    }

    public static function sync(): void
    {
        $r = Http::timeout(5)->get(self::url('/api/streams'));
        if (!$r->successful()) {
            throw new \RuntimeException('go2rtc unavailable: HTTP ' . $r->status());
        }
        $existing = $r->json() ?: [];

        $wanted = [];
        foreach (Camera::where('enabled', true)->get() as $cam) {
            $src = $cam->sourceUrl();
            if ($cam->transport === 'udp') {
                $src .= '#transport=udp';
            }
            $wanted[$cam->streamName()] = $src;
        }

        foreach ($existing as $name => $info) {
            if (str_starts_with($name, 'cam_') && !isset($wanted[$name])) {
                Http::timeout(5)->delete(self::url('/api/streams') . '?' . http_build_query(['src' => $name]));
            }
        }
        foreach ($wanted as $name => $src) {
            $current = $existing[$name]['producers'][0]['url'] ?? null;
            if ($current !== $src) {
                Http::timeout(5)->put(self::url('/api/streams') . '?' . http_build_query(['name' => $name, 'src' => $src]));
            }
        }
    }
}
