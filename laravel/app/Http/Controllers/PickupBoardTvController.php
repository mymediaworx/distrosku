<?php

namespace App\Http\Controllers;

use App\Filament\Pages\CounterPickupBoard;
use App\Models\Warehouse;
use App\Sales\PickupBoard;
use Illuminate\Http\Request;

/** 0.20.17: Counter Pickup Board full-screen TV page. No navigation, read-only, auto-refreshes; opened in its own window. */
class PickupBoardTvController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless(CounterPickupBoard::canAccess(), 403);
        $wh = (int) $request->query('wh') ?: null;
        $cols = PickupBoard::columns($wh, '');

        return response()->view('pickup-board-tv', ['cols' => $cols, 'wh' => $wh, 'warehouse' => $wh ? Warehouse::find($wh)?->code : null,
            'refresh' => 30, 'company' => \App\Models\Company::current()])->header('Cache-Control', 'no-store');
    }
}
