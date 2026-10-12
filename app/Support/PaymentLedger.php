<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Payment;

/**
 * One place that answers "what has actually been collected against this order".
 *
 * Before this class the question was answered three different ways. The order
 * resource did `invoice->payments->sum('amount_paid')`, PaymentController::store
 * did `Payment::where('invoice_id', …)->sum('amount_paid')`, and
 * PaymentController::updateStatus did not answer it at all. None of the three
 * looked at `payments.status`, which is the column the whole approval queue
 * turns on — so:
 *
 *   - A payment counted as collected the moment the rep recorded it. Measured
 *     on production: 311 payments worth 992,525.83 were still pending approval
 *     while 320 of their orders already read `paid` with nothing due.
 *   - Rejecting a payment did nothing to the books. `updateStatus` wrote
 *     `payments.status = 'failed'` and stopped, so ORD-1478 (7,911.90) and
 *     ORD-1496 (1,488.00) still read `paid` with 0 due after the admin had
 *     refused the money. Reject was decoration.
 *
 * The distinction this class draws:
 *
 *   collected  — cash the shop has handed over (approved + awaiting approval).
 *                This is what the shop owes against, so `due` and the printed
 *                invoice use it. A rejected payment is NOT collected.
 *   approved   — cash an administrator has signed off. This is confirmed
 *                revenue, and it is what the approval screen reports against.
 *
 * Rejected money therefore returns to the due, which is the only honest
 * reading: the admin refused it, so the shop still owes it.
 */
class PaymentLedger
{
    /** Statuses that mean "an administrator has signed this off". */
    public const APPROVED = ['paid'];

    /** Statuses that mean "the shop handed this over, nobody has signed it off". */
    public const AWAITING = ['pending', 'processing', 'partial'];

    /** Statuses that mean "refused — this money was never collected". */
    public const REJECTED = ['failed'];

    /**
     * Rounding slack, in taka.
     *
     * Invoice totals carry paise and cash does not, so a shop that has settled
     * in full is routinely a fraction out. The same tolerance has to govern
     * both the status and the outstanding figure, or a screen says `paid` and
     * `0.01 due` in the same breath.
     */
    public const TOLERANCE = 0.01;

    /** What is left of $total after $paid, with sub-paisa remainders snapped to 0. */
    private static function remainder(float $total, float $paid): float
    {
        $left = $total - $paid;

        return $left <= self::TOLERANCE ? 0.0 : round($left, 2);
    }

    /**
     * The order total the payments are measured against: the stored total less
     * any discount, plus tax. Kept identical to the order resources so the
     * ledger and the list cannot disagree.
     */
    public static function total(Order $order): float
    {
        $discount = self::discount($order);

        return round(max(0, (float) $order->total - $discount + (float) $order->tax), 2);
    }

    public static function discount(Order $order): float
    {
        $discounts = $order->relationLoaded('discounts')
            ? $order->discounts
            : $order->discounts()->get();

        return round($discounts->sum(function ($discount) use ($order) {
            return $discount->type === 'percentage'
                ? ((float) $order->total * (float) $discount->value) / 100
                : (float) $discount->value;
        }), 2);
    }

    /**
     * @return array{
     *   total: float, discount: float, approved: float, awaiting: float,
     *   rejected: float, collected: float, due: float, approvedDue: float,
     *   paymentStatus: string, approvalStatus: string, fullyApproved: bool
     * }
     */
    public static function for(Order $order): array
    {
        $total = self::total($order);

        /* Prefer the already-loaded relation. This runs inside the order
         * resource, which renders 10-50 rows a page, so a query per order
         * would be an N+1 on every list in the app. The list eager-loads
         * `payments`, so the common path does no extra query at all. */
        if ($order->relationLoaded('payments')) {
            $rows = $order->payments
                ->groupBy(fn ($payment) => (string) $payment->status)
                ->map(fn ($group) => $group->sum('amount_paid'));
        } else {
            $rows = Payment::where('order_id', $order->id)
                ->selectRaw('status, COALESCE(SUM(amount_paid), 0) AS amount')
                ->groupBy('status')
                ->pluck('amount', 'status');
        }

        $sum = function (array $statuses) use ($rows): float {
            $out = 0.0;
            foreach ($statuses as $status) {
                $out += (float) ($rows[$status] ?? 0);
            }
            return round($out, 2);
        };

        $approved = $sum(self::APPROVED);
        $awaiting = $sum(self::AWAITING);
        $rejected = $sum(self::REJECTED);

        $collected = round($approved + $awaiting, 2);

        return [
            'total'     => $total,
            'discount'  => self::discount($order),
            'approved'  => $approved,
            'awaiting'  => $awaiting,
            'rejected'  => $rejected,
            'collected' => $collected,

            // What the shop still has to hand over.
            'due' => self::remainder($total, $collected),

            // What is not yet confirmed revenue — the due plus anything sitting
            // in the approval queue. This is the figure a super admin signs
            // against; `due` is the figure the shop is chased for.
            'approvedDue' => self::remainder($total, $approved),

            'paymentStatus'  => self::statusFor($collected, $total),
            'approvalStatus' => self::approvalStatusFor($approved, $awaiting, $total),
            'fullyApproved'  => $total > 0 && $approved + self::TOLERANCE >= $total,
        ];
    }

    /** The order/invoice payment_status, derived from cash in hand. */
    public static function statusFor(float $collected, float $total): string
    {
        // An order with no billable total owes nothing, so it is settled. The
        // alternative reading, `pending`, puts a handful of zero-total rows on
        // the collection screens for money that does not exist.
        if ($total <= 0) {
            return 'paid';
        }

        if ($collected <= 0) {
            return 'pending';
        }

        return $collected + self::TOLERANCE >= $total ? 'paid' : 'partial';
    }

    /**
     * Sign-off state, which is a different question from payment_status: an
     * order can be fully collected and entirely unapproved.
     */
    public static function approvalStatusFor(float $approved, float $awaiting, float $total): string
    {
        if ($awaiting <= 0) {
            return $approved <= 0 ? 'none'
                : ($approved + self::TOLERANCE >= $total ? 'approved' : 'part_approved');
        }

        return $approved > 0 ? 'part_approved' : 'awaiting';
    }

    /**
     * Write the derived status back onto the order and its invoice, and return
     * the ledger. Every path that changes a payment must end here, which is
     * what "reject actually rejects" means in practice.
     */
    public static function sync(Order $order): array
    {
        $ledger = self::for($order);

        if ($order->payment_status !== $ledger['paymentStatus']) {
            $order->payment_status = $ledger['paymentStatus'];
            $order->save();
        }

        $invoice = $order->invoice ?: $order->invoice()->first();

        if ($invoice && $invoice->status !== $ledger['paymentStatus']) {
            $invoice->status = $ledger['paymentStatus'];
            $invoice->save();
        }

        return $ledger;
    }
}
