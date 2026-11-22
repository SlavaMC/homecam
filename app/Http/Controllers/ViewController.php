<?php

namespace App\Http\Controllers;

use App\Services\Access;

class ViewController extends Controller
{
    public function live()
    {
        if (!Access::can('live')) {
            return redirect('/history');
        }
        return view('live', ['cameras' => Access::cameras('live')]);
    }

    public function history()
    {
        abort_unless(Access::can('history'), 403, 'Просмотр архива по этой ссылке не разрешён');
        $share = Access::isUser() ? null : Access::share();
        return view('history', [
            'cameras' => Access::cameras('history'),
            'limitFrom' => $share?->history_from,
            'limitTo' => $share?->history_to,
        ]);
    }
}
