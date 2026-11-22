<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Services\Storage;
use App\Services\Telegram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SettingsController extends Controller
{
    public function index()
    {
        return view('settings', [
            'limit' => Setting::get('storage_limit_gb'),
            'minFree' => Setting::get('min_free_gb'),
            'offlineAfter' => Setting::get('offline_after'),
            'exportTtl' => Setting::get('export_ttl_hours'),
            'stats' => Storage::stats(),
            'telegram' => Telegram::configured(),
            'users' => User::orderBy('id')->get(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'storage_limit_gb' => 'required|numeric|min:1|max:100000',
            'min_free_gb' => 'required|numeric|min:0|max:10000',
            'offline_after' => 'required|integer|min:15|max:86400',
            'export_ttl_hours' => 'required|integer|min:1|max:8760',
        ]);
        foreach ($data as $k => $v) {
            Setting::put($k, $v);
        }
        return redirect('/settings')->with('ok', 'Настройки сохранены. Ротация применит новый лимит в течение минуты.');
    }

    public function telegramTest()
    {
        $err = Telegram::send('✅ Тестовое сообщение от системы видеонаблюдения');
        return redirect('/settings')->with($err ? 'error' : 'ok', $err ? 'Telegram: ' . $err : 'Сообщение отправлено в Telegram');
    }

    public function password(Request $request)
    {
        $data = $request->validate([
            'current' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);
        $user = $request->user();
        if (!Hash::check($data['current'], $user->password)) {
            return back()->withErrors(['current' => 'Текущий пароль неверный']);
        }
        $user->update(['password' => $data['password']]);
        return redirect('/settings')->with('ok', 'Пароль изменён');
    }

    public function addUser(Request $request)
    {
        $data = $request->validate([
            'login' => 'required|string|max:50|unique:users,login',
            'password' => 'required|string|min:6',
        ]);
        User::create(['login' => $data['login'], 'name' => $data['login'], 'password' => $data['password']]);
        return redirect('/settings')->with('ok', 'Пользователь добавлен');
    }

    public function deleteUser(Request $request, User $user)
    {
        abort_if($user->id === $request->user()->id, 422, 'Нельзя удалить себя');
        $user->delete();
        return redirect('/settings')->with('ok', 'Пользователь удалён');
    }
}
