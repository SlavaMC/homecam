<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Models\ShareLink;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ShareController extends Controller
{
    public function index()
    {
        return view('shares', [
            'links' => ShareLink::latest('id')->get(),
            'cameras' => Camera::orderBy('sort')->orderBy('id')->get()->keyBy('id'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:100',
            'hours' => 'required|numeric|min:0.1|max:8760',
            'cameras' => 'nullable|array',
            'cameras.*' => 'integer',
            'history_from' => 'nullable|integer',
            'history_to' => 'nullable|integer',
        ]);
        $live = $request->boolean('allow_live');
        $history = $request->boolean('allow_history');
        if (!$live && !$history) {
            return back()->withErrors(['allow_live' => 'Разрешите онлайн-просмотр или архив'])->withInput();
        }
        $cams = $request->boolean('all_cameras') ? null : array_values(array_map('intval', $data['cameras'] ?? []));
        if ($cams !== null && !$cams) {
            return back()->withErrors(['cameras' => 'Выберите хотя бы одну камеру'])->withInput();
        }
        $link = ShareLink::create([
            'token' => Str::random(40),
            'name' => $data['name'] ?? null,
            'camera_ids' => $cams,
            'allow_live' => $live,
            'allow_history' => $history,
            'history_from' => $history ? ($data['history_from'] ?? null) : null,
            'history_to' => $history ? ($data['history_to'] ?? null) : null,
            'expires_at' => Carbon::now()->addMinutes((int) round($data['hours'] * 60)),
            'user_id' => auth()->id(),
        ]);
        return redirect('/shares')->with('ok', 'Ссылка создана: ' . $link->url())->with('new_link', $link->url());
    }

    public function destroy(ShareLink $share)
    {
        $share->delete();
        return redirect('/shares')->with('ok', 'Ссылка отозвана');
    }
}
