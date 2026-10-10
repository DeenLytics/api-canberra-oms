<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\SiteSetting;

/**
 * The kill switch both apps check on every page load.
 *
 * This used to be one public GET that both read AND wrote: a request to
 * /site-setting?suspended=false turned the whole product back on, with no
 * sign-in, no role check, and no record of who did it. Anyone who had ever
 * watched the network tab — or guessed the parameter — could take both apps
 * down or bring them back from a browser address bar.
 *
 * Read stays public, because the apps call it before anyone has signed in.
 * Writing is a POST behind auth:sanctum and an admin role, and records who.
 */
class SiteSettingController extends Controller
{
    /** GET /site-setting — public, read only. */
    public function isSuspended(): JsonResponse
    {
        $setting = SiteSetting::first();

        return response()->json([
            'success'      => true,
            'suspended'    => $setting?->suspended ?? false,
            'suspended_at' => $setting?->suspended_at,
            'message'      => $setting?->message,
        ]);
    }

    /** POST /site-setting — admin only. */
    public function setSuspended(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->hasAnyRole(['super_admin', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only an administrator can change this.',
            ], 403);
        }

        $data = $request->validate([
            'suspended' => 'required|boolean',
            'message'   => 'nullable|string|max:500',
        ]);

        $setting = SiteSetting::updateOrCreate(
            ['id' => 1],
            [
                'suspended'    => $data['suspended'],
                'message'      => $data['message'] ?? null,
                'suspended_at' => $data['suspended'] ? now() : null,
                'updated_by'   => $user->id,
            ]
        );

        return response()->json([
            'success'      => true,
            'suspended'    => $setting->suspended,
            'suspended_at' => $setting->suspended_at,
            'message'      => $setting->message,
        ]);
    }
}
