<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Support\PaymentLedger;
use Illuminate\Console\Command;

/**
 * Recompute every order's payment_status from its payment rows.
 *
 * Needed because `payments.status` was never consulted when the status was
 * written. Two separate faults left rows behind:
 *
 *   - PaymentController::updateStatus wrote `payments.status` and stopped, so a
 *     rejected payment never reached the order. ORD-1478 (7,911.90) and
 *     ORD-1496 (1,488.00) still read `paid` with nothing due after an
 *     administrator had refused the money.
 *   - Some of the earliest orders carry zero-amount payment rows marked paid,
 *     which is how they came to read `paid` with no money against them.
 *
 * Dry run by default. This rewrites a client's books, so it prints what it
 * would change and exits; pass --write to commit.
 */
class PaymentsResync extends Command
{
    protected $signature = 'payments:resync
                            {--write : Commit the changes instead of only reporting them}
                            {--order= : Limit to one order_id, e.g. ORD-1478}';

    protected $description = 'Recompute order and invoice payment status from approved payments';

    public function handle(): int
    {
        $query = Order::with(['invoice', 'discounts', 'payments']);

        if ($id = $this->option('order')) {
            $query->where('order_id', $id);
        }

        $changes = [];
        $moved = 0.0;

        $query->chunkById(200, function ($orders) use (&$changes, &$moved) {
            foreach ($orders as $order) {
                $ledger = PaymentLedger::for($order);
                $was = (string) $order->payment_status;
                $now = $ledger['paymentStatus'];

                if ($was === $now) {
                    continue;
                }

                $changes[] = [
                    $order->order_id,
                    $was,
                    $now,
                    number_format($ledger['total'], 2),
                    number_format($ledger['approved'], 2),
                    number_format($ledger['awaiting'], 2),
                    number_format($ledger['rejected'], 2),
                    number_format($ledger['due'], 2),
                ];

                $moved += $ledger['due'];

                if ($this->option('write')) {
                    PaymentLedger::sync($order);
                }
            }
        });

        if (! $changes) {
            $this->info('Nothing to change — every order already agrees with its payments.');
            return self::SUCCESS;
        }

        $this->table(
            ['Order', 'Was', 'Becomes', 'Total', 'Approved', 'Awaiting', 'Rejected', 'Due'],
            $changes
        );

        $this->newLine();
        $this->line(sprintf('%d order(s) disagree with their payment rows.', count($changes)));
        $this->line(sprintf('Total that moves back into the due: %s', number_format($moved, 2)));

        if (! $this->option('write')) {
            $this->newLine();
            $this->warn('Dry run — nothing was written. Re-run with --write to commit.');
        } else {
            $this->newLine();
            $this->info('Written.');
        }

        return self::SUCCESS;
    }
}
