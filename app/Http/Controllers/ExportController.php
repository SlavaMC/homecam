<?php

namespace App\Http\Controllers;

use App\Jobs\BuildExport;
use App\Models\Camera;
use App\Models\Export;
use App\Services\Access;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    private function query()
    {
        $q = Export::with('camera:id,name')->latest('id');
        if (!Access::isUser()) {
            $q->where('share_link_id', Access::share()?->id ?? -1);
        }
        return $q;
    }

    public function index()
    {
        return view('exports');
    }

    public function list()
    {
        return response()->json($this->query()->limit(100)->get()->map(fn (Export $e) => [
            'id' => $e->id,
            'camera' => $e->camera?->name,
            'from' => $e->from_ts,
            'to' => $e->to_ts,
            'status' => $e->status,
            'size' => $e->size,
            'error' => $e->error,
            'url' => $e->status === 'done' ? url('/exports/' . $e->id . '/download') : null,
            'created' => $e->created_at?->getTimestamp(),
        ]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'from' => 'required|integer',
            'to' => 'required|integer|gt:from',
            'cameras' => 'required|array|min:1',
            'cameras.*' => 'integer',
        ]);
        if ($data['to'] - $data['from'] > 24 * 3600) {
            abort(422, 'Экспорт не длиннее 24 часов за раз');
        }
        [$from, $to] = Access::clampRange((int) $data['from'], (int) $data['to']);
        abort_if($to <= $from, 422, 'Интервал вне разрешённого диапазона');

        $created = [];
        foreach (Camera::whereIn('id', $data['cameras'])->get() as $cam) {
            if (!Access::can('history', $cam->id)) {
                continue;
            }
            $e = Export::create([
                'camera_id' => $cam->id,
                'from_ts' => $from,
                'to_ts' => $to,
                'user_id' => auth()->id(),
                'share_link_id' => Access::isUser() ? null : Access::share()?->id,
            ]);
            BuildExport::dispatch($e->id);
            $created[] = $e->id;
        }
        abort_if(!$created, 403);
        return response()->json(['ids' => $created]);
    }

    public function download(Export $export)
    {
        if (!Access::isUser()) {
            abort_unless($export->share_link_id && $export->share_link_id === Access::share()?->id, 403);
        }
        abort_unless($export->status === 'done' && $export->file && is_file($export->absPath()), 404);
        return response('', 200, [
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => 'attachment; filename="' . $export->file . '"',
            'X-Accel-Redirect' => '/_protected/exports/' . rawurlencode($export->file),
        ]);
    }

    public function destroy(Export $export)
    {
        if ($p = $export->absPath()) {
            @unlink($p);
        }
        $export->delete();
        return response()->json(['ok' => true]);
    }
}
