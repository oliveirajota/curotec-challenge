<?php

namespace App\Http\Controllers;

use App\Events\DrawingEvent;
use App\Http\Controllers\Controller;
use App\Models\DrawingStep;
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
     * Broadcast a drawing event and save step.
     */
    public function broadcast(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|string',
            'data' => 'required|array',
            'session_id' => 'required|string',
            'user_id' => 'required'
        ]);

        try {
            // Get the latest step number for this session
            $lastStep = DrawingStep::where('session_id', $validated['session_id'])
                ->max('step') ?? 0;

            // Create new drawing step
            $step = DrawingStep::create([
                'session_id' => $validated['session_id'],
                'timestamp' => now(),
                'step' => $lastStep + 1,
                'content' => $validated['data'],
                'status' => 'active',
                'user_id' => auth()->id()
            ]);

            Log::info('Broadcasting drawing event', [
                'type' => $validated['type'],
                'user_id' => auth()->id(),
                'step' => $step->step,
                'data' => $validated['data']
            ]);

            // Broadcast the event
            broadcast(new DrawingEvent(
                $validated['type'],
                $validated['data'],
                auth()->id()
            ));

            return response()->json(['status' => 'success', 'step' => $step->step]);
        } catch (\Exception $e) {
            Log::error('Error broadcasting drawing:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle undo action
     */
    public function undo(Request $request)
    {
        $validated = $request->validate([
            'session_id' => 'required|string'
        ]);

        // Find the last active step
        $lastStep = DrawingStep::where('session_id', $validated['session_id'])
            ->where('status', 'active')
            ->orderBy('step', 'desc')
            ->first();

        if ($lastStep) {
            // Mark the step as undone
            $lastStep->update(['status' => 'undone']);

            // Broadcast undo event
            broadcast(new DrawingEvent(
                'undo',
                ['step' => $lastStep->step],
                auth()->id()
            ));

            return response()->json(['status' => 'success', 'step' => $lastStep->step]);
        }

        return response()->json(['status' => 'error', 'message' => 'No steps to undo'], 400);
    }

    /**
     * Handle redo action
     */
    public function redo(Request $request)
    {
        $validated = $request->validate([
            'session_id' => 'required|string'
        ]);

        // Find the first undone step
        $stepToRedo = DrawingStep::where('session_id', $validated['session_id'])
            ->where('status', 'undone')
            ->orderBy('step', 'asc')
            ->first();

        if ($stepToRedo) {
            // Mark the step as active
            $stepToRedo->update(['status' => 'active']);

            // Broadcast redo event with the original content
            broadcast(new DrawingEvent(
                'redo',
                array_merge($stepToRedo->content, ['step' => $stepToRedo->step]),
                auth()->id()
            ));

            return response()->json(['status' => 'success', 'step' => $stepToRedo->step]);
        }

        return response()->json(['status' => 'error', 'message' => 'No steps to redo'], 400);
    }

    /**
     * Get drawing history for initial load
     */
    public function getHistory(Request $request)
    {
        $validated = $request->validate([
            'session_id' => 'required|string'
        ]);

        $steps = DrawingStep::where('session_id', $validated['session_id'])
            ->where('status', 'active')
            ->orderBy('step', 'asc')
            ->get();

        return response()->json(['steps' => $steps]);
    }
}
