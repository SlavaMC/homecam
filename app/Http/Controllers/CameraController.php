<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Models\MotionEvent;
use App\Models\Segment;
use App\Services\Go2rtc;
use App\Services\Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CameraController extends Controller
{
    public function index()
    {
        return view('cameras.index', [
            'cameras' => Camera::orderBy('sort')->orderBy('id')->get(),
            'stats' => Storage::stats(),
        ]);
    }

    public function create()
    {
        return view('cameras.form', ['camera' => new Camera(['transport' => 'tcp', 'enabled' => true, 'record' => true, 'segment_seconds' => 60,
            'motion_enabled' => true, 'motion_threshold' => 0.5, 'notify_enabled' => false, 'notify_threshold' => 3, 'notify_cooldown' => 300])]);
    }

    public function edit(Camera $camera)
    {
        return view('cameras.form', [
            'camera' => $camera,
            'events' => MotionEvent::where('camera_id', $camera->id)->where('start_ts', '>', time() - 86400)
                ->orderByDesc('peak')->limit(12)->get(),
        ]);
    }

    /** Current picture of the camera (first frame of the latest recording). */
    public function snapshot(Camera $camera)
    {
        $seg = Segment::where('camera_id', $camera->id)->orderByDesc('start_ts')->first();
        abort_unless($seg && is_file($seg->absPath()), 404);
        $jpg = (string) shell_exec('ffmpeg -v error -i ' . escapeshellarg($seg->absPath()) . ' -frames:v 1 -vf scale=800:-2 -q:v 4 -f image2 -c:v mjpeg - 2>/dev/null');
        abort_if($jpg === '', 404);
        return response($jpg, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'no-store']);
    }

    private function validated(Request $request, ?Camera $camera = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'rtsp_url' => ['required', 'string', 'max:500', 'regex:#^(rtsp|rtsps|rtmp|http|https)://#i'],
            'username' => 'nullable|string|max:100',
            'password' => 'nullable|string|max:200',
            'transport' => 'required|in:tcp,udp',
            'segment_seconds' => 'required|integer|min:10|max:600',
            'sort' => 'nullable|integer|min:0|max:1000',
            'motion_threshold' => 'nullable|numeric|min:0.05|max:100',
            'notify_threshold' => 'nullable|numeric|min:0.05|max:100',
            'notify_cooldown_min' => 'nullable|numeric|min:0|max:1440',
            'motion_mask' => ['nullable', 'string', 'regex:/^[01]{768}$/'],
            'notify_mask' => ['nullable', 'string', 'regex:/^[01]{768}$/'],
        ]);
        $data['motion_enabled'] = $request->boolean('motion_enabled');
        $data['notify_enabled'] = $request->boolean('notify_enabled');
        $data['motion_threshold'] = (float) ($data['motion_threshold'] ?? 0.5);
        $data['notify_threshold'] = (float) ($data['notify_threshold'] ?? 3);
        $data['notify_cooldown'] = (int) round(((float) ($data['notify_cooldown_min'] ?? 5)) * 60);
        unset($data['notify_cooldown_min']);
        foreach (['motion_mask', 'notify_mask'] as $m) {
            // a mask with every cell enabled is the same as "whole frame"
            if (empty($data[$m]) || !str_contains($data[$m], '0')) {
                $data[$m] = null;
            }
        }
        $data['enabled'] = $request->boolean('enabled');
        $data['record'] = $request->boolean('record');
        $data['audio'] = $request->boolean('audio');
        $data['sort'] = (int) ($data['sort'] ?? 0);
        // empty password field on edit = keep the old one
        if ($camera && ($data['password'] ?? '') === '' && !$request->boolean('clear_password')) {
            unset($data['password']);
        }
        return $data;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        if (!$data['sort']) {
            $data['sort'] = (int) Camera::max('sort') + 1;
        }
        Camera::create($data);
        $this->syncLive();
        return redirect('/cameras')->with('ok', 'Камера добавлена. Запись начнётся в течение нескольких секунд.');
    }

    public function update(Request $request, Camera $camera)
    {
        $camera->update($this->validated($request, $camera));
        $this->syncLive();
        return redirect('/cameras')->with('ok', 'Настройки камеры сохранены');
    }

    public function destroy(Request $request, Camera $camera)
    {
        $deleteFiles = $request->boolean('delete_files');
        $dir = $camera->recDir();
        DB::transaction(fn () => $camera->delete());
        if ($deleteFiles && is_dir($dir) && str_starts_with($dir, rtrim(config('homecam.rec_dir'), '/') . '/')) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        $this->syncLive();
        return redirect('/cameras')->with('ok', 'Камера удалена' . ($deleteFiles ? ' вместе с записями' : '. Файлы записей остались на диске.'));
    }

    /** Check that the camera answers: returns stream info from ffprobe. */
    public function test(Request $request)
    {
        $cam = $request->filled('id') ? Camera::findOrFail($request->integer('id')) : new Camera();
        $cam->rtsp_url = $request->input('rtsp_url', $cam->rtsp_url);
        $cam->username = $request->input('username', $cam->username);
        if ($request->filled('password')) {
            $cam->password = $request->input('password');
        }
        $cam->transport = $request->input('transport', $cam->transport ?: 'tcp');

        $cmd = 'timeout 15 ffprobe -v error ' . ($cam->transport === 'udp' ? '-rtsp_transport udp ' : '-rtsp_transport tcp ')
            . '-show_entries stream=codec_type,codec_name,width,height,avg_frame_rate -of json ' . escapeshellarg($cam->sourceUrl()) . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $text = implode("\n", $out);
        if ($code !== 0) {
            return response()->json(['ok' => false, 'error' => $text ?: 'Камера не ответила за 15 секунд']);
        }
        $json = json_decode($text, true);
        $streams = array_map(function ($s) {
            $label = $s['codec_type'] . ': ' . $s['codec_name'];
            if (!empty($s['width'])) {
                $label .= ' ' . $s['width'] . '×' . $s['height'];
            }
            return $label;
        }, $json['streams'] ?? []);
        return response()->json(['ok' => true, 'streams' => $streams]);
    }

    private function syncLive(): void
    {
        try {
            Go2rtc::sync();
        } catch (\Throwable) {
            // the worker retries every 30 seconds
        }
    }
}
