<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who signed a payment off, and when.
 *
 * `payments.created_by` already records the rep who collected the cash. There
 * was nothing recording who approved it — so the one control the "Waiting
 * Approval" queue exists to provide, that the person who takes the money is not
 * the person who confirms it, could not actually be checked after the fact.
 * 945 payments are already marked paid and there is no way to learn who marked
 * them.
 *
 * Nullable, and it stays null while a payment is waiting: an undecided payment
 * has no decider. Rejecting also writes these — the pair answers "who last
 * decided this payment's fate and when", and `status` says which way they
 * decided. Putting a payment back in the queue clears them again.
 *
 * `onDelete('set null')` matches created_by: deleting a user must not take the
 * payment history with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('approved_by')
                ->nullable()
                ->after('created_by')
                ->constrained('users')
                ->onDelete('set null');

            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['approved_by', 'approved_at']);
        });
    }
};
