<?php

namespace App\Http\Controllers;

use App\Models\ShareLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function form()
    {
        if (Auth::check()) {
            return redirect('/');
        }
        return view('login');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['login' => 'required|string', 'password' => 'required|string']);
        $key = 'login:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return back()->withErrors(['login' => 'Слишком много попыток. Подождите ' . RateLimiter::availableIn($key) . ' с.'])->onlyInput('login');
        }
        if (!Auth::attempt(['login' => $data['login'], 'password' => $data['password']], $request->boolean('remember'))) {
            RateLimiter::hit($key, 300);
            return back()->withErrors(['login' => 'Неверный логин или пароль'])->onlyInput('login');
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->forget('share_id');
        return redirect()->intended('/');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }

    /** Entry point of a temporary link. */
    public function share(Request $request, string $token)
    {
        $s = ShareLink::where('token', $token)->first();
        if (!$s || !$s->isValid()) {
            return response()->view('share-expired', [], 410);
        }
        $s->forceFill(['last_used_at' => now()])->save();
        if (!Auth::check()) {
            $request->session()->regenerate();
            $request->session()->put('share_id', $s->id);
        }
        return redirect($s->allow_live ? '/' : '/history');
    }
}
