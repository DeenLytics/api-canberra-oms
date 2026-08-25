<?php

namespace App\Http\Controllers;

class HealthController extends Controller
{
    /**
     * Liveness check.
     *
     * Deliberately touches nothing external — no database, no cache, no mail.
     * A 200 here means the framework booted and routing works; that is the
     * whole claim. Pair it with an endpoint that does hit the database when
     * you need to know both, so a failure tells you which layer broke.
     */
    public function __invoke()
    {
        return response()->json([
            'status' => 'ok',
            'app'    => config('app.name'),
            'env'    => config('app.env'),
            'time'   => now()->toIso8601String(),
        ]);
    }
}
