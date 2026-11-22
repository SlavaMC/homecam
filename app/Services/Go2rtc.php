<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/** Keeps go2rtc (live view relay) streams in sync with the cameras table. */
class Go2rtc
{
    private static function url(string $path, array $query = []): string
    {
        return rtrim(config('homecam.go2rtc_url'), '/') . $path . ($query ? '?' . http_build_query($query) : '');
    }

    public static function sync(): void
    {
        $r = Http::timeout(5)->get(self::url('/api/streams'));
        if (!$r->successful()) {
            throw new \RuntimeException('go2rtc unavailable: HTTP ' . $r->status());
        }
        $existing = $r->json() ?: [];
        // what we configured last time (go2rtc forgets everything on restart)
        $pushed = json_decode((string) Setting::query()->find('go2rtc_streams')?->value, true) ?: [];

        $wanted = [];
        foreach (Camera::where('enabled', true)->get() as $cam) {
            $src = $cam->sourceUrl();
            if ($cam->transport === 'udp') {
                $src .= '#transport=udp';
            }
            $wanted[$cam->streamName()] = $src;
        }

        foreach (array_keys($existing) as $name) {
            if (str_starts_with($name, 'cam_') && !isset($wanted[$name])) {
                Http::timeout(5)->delete(self::url('/api/streams', ['src' => $name]));
            }
        }
        foreach ($wanted as $name => $src) {
            $hash = md5($src);
            if (!isset($existing[$name]) || ($pushed[$name] ?? null) !== $hash) {
                Http::timeout(5)->put(self::url('/api/streams', ['name' => $name, 'src' => $src]))->throw();
                $pushed[$name] = $hash;
            }
        }
        $pushed = array_intersect_key($pushed, $wanted);
        Setting::put('go2rtc_streams', json_encode($pushed));
    }
}
