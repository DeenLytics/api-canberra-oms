<?php

namespace App\Services;

use App\Models\LocationPoint;
use App\Models\LocationSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LocationService
{
    const INACTIVE_THRESHOLD_MINUTES = 10; // 10 min কোনো point না এলে inactive ধরা হয়
    const OFFLINE_THRESHOLD_MINUTES  = 15; // 15 min পর offline ধরা হয়
    const AREA_CHANGE_METERS         = 200; // 200m move = নতুন area বলে ধরা হয়

    // ══════════════════════════════════════════════════════════════════════
    // BATCH SAVE — IndexedDB থেকে আসা batch points process করা
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Frontend থেকে batch points এসে এই method এ পড়বে।
     *
     * @param int   $salesRepId
     * @param array $points  [{latitude, longitude, accuracy, speed, heading, battery_level, battery_charging, timestamp}]
     */
    public function saveBatch(int $salesRepId, array $points): int
    {
        $saved = 0;

        foreach ($points as $point) {
            // Duplicate check — একই timestamp এর point দুবার save হবে না
            $exists = LocationPoint::where('sales_rep_id', $salesRepId)
                ->where('recorded_at', Carbon::parse($point['timestamp']))
                ->exists();

            if ($exists) continue;

            LocationPoint::create([
                'sales_rep_id'    => $salesRepId,
                'latitude'        => $point['latitude'],
                'longitude'       => $point['longitude'],
                'accuracy'        => $point['accuracy'] ?? null,
                'speed'           => $point['speed'] ?? null,
                'heading'         => $point['heading'] ?? null,
                'battery_level'   => $point['batteryLevel'] ?? null,
                'battery_charging'=> $point['batteryCharging'] ?? null,
                'area'            => $this->reverseGeocode($point['latitude'], $point['longitude']),
                'recorded_at'     => Carbon::parse($point['timestamp']),
            ]);

            $saved++;
        }

        // Session আপডেট করো
        if ($saved > 0) {
            $this->updateSession($salesRepId, Carbon::today());
        }

        return $saved;
    }

    // ══════════════════════════════════════════════════════════════════════
    // REVERSE GEOCODE — lat/lng → area name
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Google Geocoding API দিয়ে area name বের করে।
     * Cache করা হয় — একই coordinate বারবার API call করে না।
     */
    public function reverseGeocode(float $lat, float $lng): ?string
    {
        // Round করে cache key তৈরি — 3 decimal = ~111m accuracy
        $cacheKey = 'geocode:' . round($lat, 3) . ':' . round($lng, 3);

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($lat, $lng) {
            try {
                $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'latlng' => "{$lat},{$lng}",
                    'key'    => config('services.google_maps.api_key'),
                    'result_type' => 'sublocality|neighborhood|political',
                    'language' => 'en',
                ]);

                if ($response->failed()) return null;

                $results = $response->json('results');
                if (empty($results)) return null;

                // Sublocality level 1 খোঁজো (e.g., "Uttara 11 no sector")
                foreach ($results as $result) {
                    foreach ($result['address_components'] as $component) {
                        if (in_array('sublocality_level_1', $component['types'])) {
                            return $component['long_name'];
                        }
                    }
                }

                // Fallback: neighborhood
                foreach ($results as $result) {
                    foreach ($result['address_components'] as $component) {
                        if (in_array('neighborhood', $component['types'])
                            || in_array('sublocality', $component['types'])) {
                            return $component['long_name'];
                        }
                    }
                }

                // Last resort: first formatted_address component
                return $results[0]['address_components'][0]['long_name'] ?? null;

            } catch (\Throwable $e) {
                Log::warning('Geocode failed', ['lat' => $lat, 'lng' => $lng, 'error' => $e->getMessage()]);
                return null;
            }
        });
    }

    // ══════════════════════════════════════════════════════════════════════
    // SESSION UPDATE — batch save এর পর session recalculate করো
    // ══════════════════════════════════════════════════════════════════════

    public function updateSession(int $salesRepId, Carbon $date): LocationSession
    {
        $points = LocationPoint::where('sales_rep_id', $salesRepId)
            ->whereDate('recorded_at', $date)
            ->orderBy('recorded_at')
            ->get();

        if ($points->isEmpty()) {
            return LocationSession::updateOrCreate(
                [
                    'sales_rep_id' => $salesRepId,
                    'date' => $date->toDateString(),
                ],
                [
                    'start_time' => null,
                    'end_time' => null,
                    'total_active_minutes' => 0,
                    'total_inactive_minutes' => 0,
                    'last_seen' => null,
                    'is_online' => false,
                    'battery_level' => null,
                    'battery_charging' => null,
                    'activities' => [],
                ]
            );
        }

        $firstPoint = $points->first();
        $lastPoint  = $points->last();

        $startTime   = $firstPoint->recorded_at;
        $endTime     = $lastPoint->recorded_at;
        $lastSeen    = $lastPoint->recorded_at;

        $lastBattery  = $lastPoint->battery_level;
        $lastCharging = $lastPoint->battery_charging;

        // SAFE calculation (must return integer minutes)
        [$activeMinutes, $inactiveMinutes] = $this->calculateActiveTime($points);

        // HARD SANITIZATION (prevents DB crash)
        $activeMinutes = (int) max(0, floor($activeMinutes));
        $inactiveMinutes = (int) max(0, floor($inactiveMinutes));

        // prevent schema overflow (unsignedSmallInteger max 65535)
        $activeMinutes = min(65535, $activeMinutes);
        $inactiveMinutes = min(65535, $inactiveMinutes);

        // area activities
        $activities = $this->buildAreaActivities($points) ?? [];

        // online status
        // Carbon 3 returns a SIGNED diff, so now()->diffInMinutes(past) is
        // negative — and a negative is always under the threshold, which meant
        // every rep read as online no matter how stale their last point was.
        // Measuring forward from the older timestamp gives a positive age.
        $isOnline = $lastSeen->diffInMinutes(Carbon::now()) < self::OFFLINE_THRESHOLD_MINUTES;

        return LocationSession::updateOrCreate(
            [
                'sales_rep_id' => $salesRepId,
                'date' => $date->toDateString(),
            ],
            [
                'start_time' => $startTime,
                'end_time' => $endTime,
                'total_active_minutes' => $activeMinutes,
                'total_inactive_minutes' => $inactiveMinutes,
                'last_seen' => $lastSeen,
                'is_online' => $isOnline,
                'battery_level' => $lastBattery,
                'battery_charging' => $lastCharging,
                'activities' => $activities,
            ]
        );
    }

    // ── Active / inactive calculation ──────────────────────────────────────

    private function calculateActiveTime($points): array
    {
        $activeMinutes   = 0;
        $inactiveMinutes = 0;

        for ($i = 1; $i < $points->count(); $i++) {
            $prev = $points[$i - 1]->recorded_at;
            $curr = $points[$i]->recorded_at;
            $gap  = $curr->diffInMinutes($prev);

            if ($gap <= self::INACTIVE_THRESHOLD_MINUTES) {
                $activeMinutes += $gap;
            } else {
                $inactiveMinutes += $gap;
            }
        }

        return [$activeMinutes, $inactiveMinutes];
    }

    // ── Area activity builder ──────────────────────────────────────────────

    private function buildAreaActivities($points): array
    {
        $activities  = [];
        $currentArea = null;
        $arrivedAt   = null;

        foreach ($points as $point) {
            if ($point->area === null) continue;

            if ($currentArea !== $point->area) {
                // Close previous area
                if ($currentArea !== null) {
                    $activities[] = [
                        'area'              => $currentArea,
                        'arrived_at'        => $arrivedAt->toIso8601String(),
                        'left_at'           => $point->recorded_at->toIso8601String(),
                        'duration_minutes'  => $point->recorded_at->diffInMinutes($arrivedAt),
                    ];
                }
                $currentArea = $point->area;
                $arrivedAt   = $point->recorded_at;
            }
        }

        // Last area (still there or end of day)
        if ($currentArea !== null) {
            $activities[] = [
                'area'              => $currentArea,
                'arrived_at'        => $arrivedAt->toIso8601String(),
                'left_at'           => null,
                'duration_minutes'  => null,
            ];
        }

        return $activities;
    }

    // ══════════════════════════════════════════════════════════════════════
    // LIVE LOCATIONS — Admin map এর জন্য
    // ══════════════════════════════════════════════════════════════════════

    public function getLiveLocations(): array
    {
        $salesReps = User::role('sales_representative', 'web')
            ->where('status', 'active')
            ->get();

        return $salesReps->map(function (User $sr) {
            // শেষ point
            $lastPoint = LocationPoint::where('sales_rep_id', $sr->id)
                ->latest('recorded_at')
                ->first();

            if (!$lastPoint) {
                return [
                    'salesRepId'   => $sr->id,
                    'salesRepName' => $sr->full_name,
                    'latitude'       => 0,
                    'longitude'      => 0,
                    'accuracy'       => null,
                    'batteryLevel'  => null,
                    'batteryCharging'=> null,
                    'isOnline'      => false,
                    'lastSeen'      => null,
                    'area'           => null,
                    'speed'          => null,
                ];
            }

            // Same signed-diff trap as above: this reported every rep online.
            $isOnline = $lastPoint->recorded_at->diffInMinutes(Carbon::now())
                < self::OFFLINE_THRESHOLD_MINUTES;

            return [
                'salesRepId'    => $sr->id,
                'salesRepName'  => $sr->full_name,
                'latitude'        => $lastPoint->latitude,
                'longitude'       => $lastPoint->longitude,
                'accuracy'        => $lastPoint->accuracy,
                'batteryLevel'   => $lastPoint->battery_level,
                'batteryCharging'=> $lastPoint->battery_charging,
                'isOnline'       => $isOnline,
                'lastSeen'       => $lastPoint->recorded_at?->toIso8601String(),
                'area'            => $lastPoint->area,
                'speed'           => $lastPoint->speed,
            ];
        })->values()->toArray();
    }

    // ══════════════════════════════════════════════════════════════════════
    // PATH — একজন SR এর পুরো দিনের path
    // ══════════════════════════════════════════════════════════════════════

    public function getPath(int $salesRepId, string $date): array
    {
        return LocationPoint::where('sales_rep_id', $salesRepId)
            // ->whereDate('recorded_at', $date)
            ->orderBy('recorded_at')
            ->get(['latitude', 'longitude', 'recorded_at', 'area', 'battery_level'])
            ->toArray();
    }

    // ══════════════════════════════════════════════════════════════════════
    // REPORT — Admin এর জন্য session data
    // ══════════════════════════════════════════════════════════════════════

    public function getReport(?int $salesRepId, string $date): array
    {
        $query = LocationSession::with('salesRep:id,full_name')
            ->where('date', $date);

        if ($salesRepId) {
            $query->where('sales_rep_id', $salesRepId);
        }

        return $query->get()->map(function ($session) {
            return [
                'salesRepId'           => $session->sales_rep_id,
                'salesRepName'         => $session->salesRep?->full_name,
                'date'                   => $session->date->toDateString(),
                'startTime'             => $session->start_time?->toIso8601String(),
                'end_time'               => $session->end_time?->toIso8601String(),
                'totalActiveMinutes'   => $session->total_active_minutes,
                'totalInactiveMinutes' => $session->total_inactive_minutes,
                'lastSeen'              => $session->last_seen?->toIso8601String(),
                'isOnline'              => $session->is_online,
                'batteryLevel'          => $session->battery_level,
                'batteryCharging'       => $session->battery_charging,
                'activities'             => $session->activities ?? [],
            ];
        })->toArray();
    }

    // ══════════════════════════════════════════════════════════════════════
    // OFFLINE ALERT — Cron এ check করো
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Working hours এ offline SR দের খোঁজো এবং admin কে notify করো।
     * Scheduler এ call: $schedule->call(fn() => app(LocationService::class)->checkOfflineSRs())->everyFifteenMinutes()
     */
    public function checkOfflineSRs(): void
    {
        $now  = Carbon::now();
        $hour = $now->hour;

        // Working hours (9AM-6PM) এর বাইরে না
        if ($hour < 9 || $hour >= 18) return;

        $salesReps = User::role('sales_representative', 'web')
            ->where('status', 'active')
            // ->when($salesRepId, function ($q) use ($salesRepId) {
            //     $q->where('id', $salesRepId);
            // })
            ->get();

        foreach ($salesReps as $sr) {
            $lastPoint = LocationPoint::where('sales_rep_id', $sr->id)
                ->whereDate('recorded_at', today())
                ->latest('recorded_at')
                ->first();

            $isOffline = !$lastPoint
                || $now->diffInMinutes($lastPoint->recorded_at) >= self::OFFLINE_THRESHOLD_MINUTES;

            if ($isOffline) {
                // Admin দের notify করো
                $admins = User::role(['super_admin', 'admin'], 'web')->get();
                foreach ($admins as $admin) {
                    // তোমার existing notification system use করো
                    // Notification::send($admin, new SROfflineNotification($sr));
                    Log::info("SR offline alert: {$sr->name} (ID: {$sr->id})");
                }
            }
        }
    }
}
