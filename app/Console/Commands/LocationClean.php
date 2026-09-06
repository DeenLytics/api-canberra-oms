<?php

namespace App\Console\Commands;

use App\Models\LocationPoint;
use App\Models\LocationSession;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Prunes location history.
 *
 * The previous version deleted every row in location_points AND every row in
 * location_sessions, on a schedule that ran at 23:59 — so it wiped the day that
 * had just been recorded, and the admin's location report was empty every
 * morning for the day before. Nothing was ever retained.
 *
 * Two different things are stored, and they have different lifetimes:
 *
 *   location_points   raw GPS fixes, one every 30 seconds per rep — roughly a
 *                     thousand rows per rep per working day. This is what draws
 *                     the map track and the heat map, and it is the volume.
 *                     Pruned on a rolling 24-hour window.
 *
 *   location_sessions one row per rep per day: start, end, active minutes, the
 *                     areas visited. This IS the report. Kept.
 *
 * Runs hourly, so "older than 24 hours" is a real rolling window rather than a
 * once-a-day cliff.
 */
class LocationClean extends Command
{
    protected $signature = 'location:clean
                            {--hours=24 : Age in hours beyond which raw points are pruned}
                            {--session-days=90 : Age in days beyond which daily summaries are pruned}';

    protected $description = 'Prune raw location points older than 24 hours, keeping the daily session summaries';

    public function handle(): int
    {
        // The scheduler runs as a long-lived daemon; a stale connection here
        // fails the whole command silently on the next tick.
        DB::purge();
        DB::reconnect();

        $hours       = max(1, (int) $this->option('hours'));
        $sessionDays = max(1, (int) $this->option('session-days'));

        $pointCutoff   = Carbon::now()->subHours($hours);
        $sessionCutoff = Carbon::now()->subDays($sessionDays)->toDateString();

        try {
            // Deleted in chunks rather than one statement: a single DELETE over
            // a day's worth of points holds a long transaction and locks the
            // table the reps are still writing to.
            $points = 0;
            do {
                $deleted = LocationPoint::where('recorded_at', '<', $pointCutoff)
                    ->limit(5000)
                    ->delete();
                $points += $deleted;
            } while ($deleted > 0);

            $sessions = LocationSession::where('date', '<', $sessionCutoff)->delete();

            $summary = sprintf(
                'Pruned %d point(s) older than %dh; %d session(s) older than %dd. Sessions inside the window are kept — they are the report.',
                $points, $hours, $sessions, $sessionDays
            );

            $this->info($summary);
            Log::info("location:clean — {$summary}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $message = "location:clean failed: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}";
            $this->error($message);
            Log::error($message);

            return self::FAILURE;
        }
    }
}
