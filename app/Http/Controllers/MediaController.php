<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Models\Segment;
use App\Services\Access;
use App\Services\Storage;
use Illuminate\Http\Request;

class MediaController extends Controller
{
    private function range(Request $request): array
    {
        $from = (int) $request->query('from', time() - 3600);
        $to = (int) $request->query('to', time());
        if ($to <= $from) {
            abort(422, 'Неверный интервал');
        }
        if ($to - $from > 7 * 86400) {
            abort(422, 'Интервал не больше 7 суток');
        }
        return Access::clampRange($from, $to);
    }

    /** HLS playlist made of the recorded segments of one camera. */
    public function playlist(Request $request, Camera $camera)
    {
        abort_unless(Access::can('history', $camera->id), 403);
        [$from, $to] = $this->range($request);
        $segs = Storage::segments($camera->id, $from, $to);

        $max = (int) ceil($segs->max('duration') ?: 60);
        $lines = ['#EXTM3U', '#EXT-X-VERSION:3', '#EXT-X-PLAYLIST-TYPE:VOD', "#EXT-X-TARGETDURATION:{$max}", '#EXT-X-MEDIA-SEQUENCE:0'];
        foreach ($segs as $i => $s) {
            if ($i > 0) {
                $lines[] = '#EXT-X-DISCONTINUITY';
            }
            $lines[] = '#EXT-X-PROGRAM-DATE-TIME:' . gmdate('Y-m-d\TH:i:s', $s->start_ts) . '.000Z';
            $lines[] = '#EXTINF:' . number_format($s->duration, 3, '.', '') . ',';
            $lines[] = '/rec/' . $s->path;
        }
        $lines[] = '#EXT-X-ENDLIST';

        return response(implode("\n", $lines) . "\n", 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Time intervals with recordings, per camera. */
    public function coverage(Request $request)
    {
        abort_unless(Access::can('history'), 403);
        [$from, $to] = $this->range($request);
        $out = [];
        foreach (Access::cameras('history') as $cam) {
            $out[$cam->id] = Storage::coverage($cam->id, $from, $to);
        }
        return response()->json(['from' => $from, 'to' => $to, 'cameras' => $out]);
    }

    /** A segment file, served by nginx after the access check. */
    public function segment(string $path)
    {
        abort_if(str_contains($path, '..'), 404);
        $seg = Segment::where('path', $path)->first();
        abort_unless($seg, 404);
        abort_unless(Access::can('history', $seg->camera_id), 403);
        if (!Access::isUser()) {
            [$from, $to] = Access::clampRange($seg->start_ts, (int) ($seg->start_ts + $seg->duration));
            abort_if($to <= $from, 403);
        }
        return response('', 200, [
            'Content-Type' => 'video/mp2t',
            'X-Accel-Redirect' => '/_protected/rec/' . $path,
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
        ]);
    }

    /** nginx auth_request for the live relay (go2rtc). */
    public function liveAuth(Request $request)
    {
        $uri = (string) $request->header('X-Original-URI', '');
        $query = [];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $src = $query['src'] ?? null;

        if (Access::isUser()) {
            return response('', 204);
        }
        if ($src !== null) {
            if (!preg_match('/^cam_(\d+)$/', (string) $src, $m)) {
                return response('', 403);
            }
            return Access::can('live', (int) $m[1]) ? response('', 204) : response('', 403);
        }
        // HLS fallback of the live player (iOS) uses session ids instead of stream names
        if (str_starts_with((string) parse_url($uri, PHP_URL_PATH), '/live/api/hls/')) {
            return Access::can('live') ? response('', 204) : response('', 403);
        }
        return response('', 403);
    }
}
