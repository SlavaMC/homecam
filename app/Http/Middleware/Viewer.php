<?php

namespace App\Http\Middleware;

use App\Services\Access;
use Closure;
use Illuminate\Http\Request;

/** Allows logged-in users and guests with a valid temporary link. */
class Viewer
{
    public function handle(Request $request, Closure $next)
    {
        if (!Access::any()) {
            if ($request->expectsJson() || $request->is('api/*')) {
                abort(401);
            }
            return redirect()->guest(route('login'));
        }
        return $next($request);
    }
}
