<?php

namespace App\Http\Controllers;

use App\Events\DrawingEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class DrawingController extends Controller
{
    /**
     * Show the drawing canvas page.
     */
    public function index(): View
    {
        return view('drawing.index');
    }

    /**
     * Broadcast a drawing event.
     */
    public function broadcast(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|string',
            'data' => 'required|array',
        ]);

        Log::info('Broadcasting drawing event', [
            'type' => $validated['type'],
            'user_id' => auth()->id(),
            'data_size' => strlen(json_encode($validated['data']))
        ]);

        broadcast(new DrawingEvent(
            $validated['type'],
            $validated['data'],
            auth()->id()
        ));

        return response()->json(['status' => 'success']);
    }
}
