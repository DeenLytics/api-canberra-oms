<?php

namespace App\Services;

use App\Models\IdleEvent;
use App\Models\Order;
use App\Models\User;
use App\Jobs\SendIdleNotificationJob;
use Carbon\Carbon;

class IdleDetectionService
{
    const IDLE_THRESHOLD_MINUTES = 3;
    const ACTIVE_HOURS_START     = 9;   // 9 AM
    const ACTIVE_HOURS_END       = 18;  // 6 PM

    // ══════════════════════════════════════════════════════════════════════
    // CRON — প্রতি 5 মিনিটে এই method call হবে
    // ══════════════════════════════════════════════════════════════════════

    /**
     * সব active SR দের check করে।
     * Scheduler এ call করো: $schedule->call(fn() => app(IdleDetectionService::class)->checkAllSalesReps())->everyFiveMinutes()
     */
    public function checkAllSalesReps(): void
    {
        $now = Carbon::now();

        // Active hours এর বাইরে check করবো না
        if ($now->hour < self::ACTIVE_HOURS_START || $now->hour >= self::ACTIVE_HOURS_END) {
            return;
        }

        // সব active SR দের নিয়ে আসো
        $salesReps = User::role('sales_representative', 'web')
            ->where('status', 'active')
            ->get();

        foreach ($salesReps as $sr) {
            $this->checkSingleSalesRep($sr);
        }
    }

    /**
     * একজন SR কে check করে।
     */
    public function checkSingleSalesRep(User $sr): void
    {
        // ইতিমধ্যে unresolved idle event আছে কিনা
        $existingIdle = IdleEvent::where('sales_rep_id', $sr->id)
            ->where('is_resolved', false)
            ->latest('start_time')
            ->first();

        if ($existingIdle) {
            // Duration আপডেট করো
            $existingIdle->update([
                // Signed diff under Carbon 3 stored negative durations.
                'duration_minutes' => (int) round($existingIdle->start_time->diffInMinutes(Carbon::now())),
            ]);
            return; // Already notified
        }

        // শেষ order কখন ছিল?
        $lastOrderTime = Order::where('sales_rep_id', $sr->id)
            ->latest('created_at')
            ->value('created_at');

        // আজকে কোনো order না থাকলে login time থেকে count করো
        if (!$lastOrderTime) {
            $lastOrderTime = Carbon::today()->setHour(self::ACTIVE_HOURS_START);
        }

        $minutesSinceLastOrder = (int) round($lastOrderTime->diffInMinutes(Carbon::now()));

        // if ($minutesSinceLastOrder >= self::IDLE_THRESHOLD_MINUTES) {
            // Idle event তৈরি করো
            $idleEvent = IdleEvent::create([
                'sales_rep_id'     => $sr->id,
                'start_time'       => $lastOrderTime->addMinutes(self::IDLE_THRESHOLD_MINUTES),
                'duration_minutes' => $minutesSinceLastOrder,
                'is_resolved'      => false,
            ]);

            // Push notification পাঠাও
            // dispatch(new SendIdleNotificationJob($sr, $idleEvent));
        // }
    }

    // ══════════════════════════════════════════════════════════════════════
    // SR এর current idle status
    // ══════════════════════════════════════════════════════════════════════

    public function getMyStatus(int $salesRepId): array
    {
        $unresolvedIdle = IdleEvent::where('sales_rep_id', $salesRepId)
            ->where('is_resolved', false)
            ->latest('start_time')
            ->first();

        if (!$unresolvedIdle) {
            return ['isIdle' => false];
        }

        return [
            'isIdle'          => true,
            'idleSince'       => $unresolvedIdle->start_time->toIso8601String(),
            'durationMinutes' => (int) round($unresolvedIdle->start_time->diffInMinutes(Carbon::now())),
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // RESOLVE — SR reason submit করলে
    // ══════════════════════════════════════════════════════════════════════

    public function logAndResolve(int $salesRepId, array $data): IdleEvent
    {
        // সবচেয়ে recent unresolved event খোঁজো
        $idleEvent = IdleEvent::where('sales_rep_id', $salesRepId)
            ->where('is_resolved', false)
            ->latest('start_time')
            ->firstOrFail();

        $idleEvent->update([
            'resolved_time'    => Carbon::now(),
            'duration_minutes' => (int) round($idleEvent->start_time->diffInMinutes(Carbon::now())),
            'reason_type'      => $data['reasonType'],
            'reason_note'      => $data['reasonNote'] ?? null,
            'is_resolved'      => true,
        ]);

        return $idleEvent;
    }

    // ══════════════════════════════════════════════════════════════════════
    // ADMIN — idle events list
    // ══════════════════════════════════════════════════════════════════════

    public function getEventsForAdmin(array $filters)
    {
        $query = IdleEvent::with('salesRep:id,full_name')
            ->latest('start_time');

        if (!empty($filters['sales_rep_id'])) {
            $query->where('sales_rep_id', $filters['sales_rep_id']);
        }

        if (!empty($filters['date'])) {
            $query->whereDate('start_time', $filters['date']);
        }

        if (isset($filters['is_resolved'])) {
            $query->where('is_resolved', filter_var($filters['is_resolved'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->get()->map(function ($event) {
            return [
                'id' => $event->id,
                'salesRepId' => $event->salesRep?->id,
                'salesRepName' => $event->salesRep?->full_name,
                'reasonNote' => $event->reason_note, // snake_case to camelCase for frontend
                'reasonType' => $event->reason_type, // snake_case to camelCase for frontend
                'startTime' => $event->start_time, // snake_case to camelCase for frontend
                'durationMinutes' => $event->duration_minutes, // snake_case to camelCase for frontend
                'isResolved' => $event->is_resolved, // snake_case to camelCase for frontend
                'resolvedTime' => $event->resolved_time, // snake_case to camelCase for frontend
            ];
        });
    }
}
