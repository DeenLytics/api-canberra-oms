<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\LocationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

// ══════════════════════════════════════════════════════════════════════════════
// LocationTrackController.php
// ════════════════════════════════════
class LocationTrackController extends Controller
{
    public function __construct(private LocationService $locationService) {}

    // ── POST /api/location-points/batch ──────────────────────────────────────
    // SR এর IndexedDB থেকে batch points পাঠায়

    public function batchStore(Request $request): JsonResponse
    {
        $request->validate([
            'points'                       => 'required|array|min:1|max:100',
            'points.*.latitude'            => 'required|numeric|between:-90,90',
            'points.*.longitude'           => 'required|numeric|between:-180,180',
            'points.*.accuracy'            => 'nullable|numeric',
            'points.*.speed'               => 'nullable|numeric',
            'points.*.heading'             => 'nullable|numeric',
            // The app sends batteryLevel/batteryCharging; these rules named the
            // snake_case keys, so they validated fields that never arrive and
            // the real ones went through unchecked.
            'points.*.batteryLevel'        => 'nullable|integer|between:0,100',
            'points.*.batteryCharging'     => 'nullable|boolean',
            'points.*.timestamp'           => 'required|date',
        ]);

        $saved = $this->locationService->saveBatch(
            auth()->id(),
            $request->input('points')
        );

        return response()->json([
            'saved'   => $saved,
            'message' => "{$saved} points saved",
        ], 201);
    }

    // ── GET /api/location-points/live ────────────────────────────────────────
    // Admin — সব SR এর live position

    public function live(): JsonResponse
    {
        // $this->authorize('viewAny', \App\Models\LocationPoint::class);

        $locations = $this->locationService->getLiveLocations();

        return response()->json($locations);
    }

    // ── GET /api/location-points/path ────────────────────────────────────────
    // Admin — একজন SR এর নির্দিষ্ট দিনের full path

    public function path(Request $request): JsonResponse
    {
        // $this->authorize('viewAny', \App\Models\LocationPoint::class);

        $request->validate([
            'salesRepId' => 'required|exists:users,id',
            'date'         => 'required|date',
        ]);

        $path = $this->locationService->getPath(
            (int) $request->salesRepId,
            $request->date
        );

        return response()->json($path);
    }

    // ── GET /api/location-reports ────────────────────────────────────────────
    // Admin — session-based daily report

    public function report(Request $request): JsonResponse
    {
        // $this->authorize('viewAny', \App\Models\LocationSession::class);

        $request->validate([
            'date'         => 'nullable|date',
            'sales_rep_id' => 'nullable|exists:users,id',
        ]);

        $date   = $request->input('date', today()->toDateString());
        $srId   = $request->input('sales_rep_id');

        $report = $this->locationService->getReport(
            $srId ? (int) $srId : null,
            $date
        );

        return response()->json($report);
    }
}


// ══════════════════════════════════════════════════════════════════════════════
// routes/api.php — এই routes add করো existing location এ
// ══════════════════════════════════════════════════════════════════════════════

/*

Route::middleware(['auth:sanctum'])->group(function () {

    // SR — batch location push (SR শুধু নিজের data পাঠাতে পারবে)
    Route::post('location-points/batch', [LocationController::class, 'batchStore']);

    // Admin only
    Route::middleware('role:admin')->group(function () {
        Route::get('location-points/live',   [LocationController::class, 'live']);
        Route::get('location-points/path',   [LocationController::class, 'path']);
        Route::get('location-reports',       [LocationController::class, 'report']);
    });

});

*/


// ══════════════════════════════════════════════════════════════════════════════
// config/services.php — এটা add করো
// ══════════════════════════════════════════════════════════════════════════════

/*

'google_maps' => [
    'api_key' => env('GOOGLE_MAPS_API_KEY'),
],

*/

// .env এ add করো:
// GOOGLE_MAPS_API_KEY=AIzaSyBrYbTWg_tU38j3r8SWHWGSi-0E72TNCWE


// ══════════════════════════════════════════════════════════════════════════════
// app/Console/Kernel.php — Scheduler entries
// ══════════════════════════════════════════════════════════════════════════════

/*

protected function schedule(Schedule $schedule): void
{
    // Offline SR check — every 15 min during working hours
    $schedule->call(function () {
        app(\App\Services\LocationService::class)->checkOfflineSRs();
    })->everyFifteenMinutes()->between('09:00', '18:00');

    // Session cleanup — রাত ১১টায় সব SR এর session finalize করো
    $schedule->call(function () {
        $salesReps = \App\Models\User::where('role', 'sales_rep')->get();
        foreach ($salesReps as $sr) {
            app(\App\Services\LocationService::class)->updateSession($sr->id, today());
        }
    })->dailyAt('23:00');

    // Old location points cleanup — 90 দিনের বেশি পুরনো data delete
    $schedule->call(function () {
        \App\Models\LocationPoint::where('recorded_at', '<', now()->subDays(90))->delete();
    })->weekly();
}

*/
