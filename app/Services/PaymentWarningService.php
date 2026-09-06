<?php

namespace App\Services;

// File: app/Services/PaymentWarningService.php

use App\Models\Order;
use App\Models\PaymentWarning;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\Api\V1\Payment\PaymentWarningResource;

class PaymentWarningService
{
    /**
     * ══════════════════════════════════════════════════════════════════════
     * GENERATE WARNINGS — Cron job থেকে প্রতিদিন call হবে
     *
     * Rule 1 (15-day warning):
     *   - Order status = 'confirmed'
     *   - payment_status = 'pending' (কোনো payment record নেই)
     *   - Order confirmed হওয়ার পর ১৫+ দিন পেরিয়ে গেছে
     *   - invoice_status = 'generated' (invoice আছে, কিন্তু payment নেই)
     *
     * Rule 2 (30-day warning):
     *   - payment_status = 'partial'
     *   - শেষ payment record এর পর ৩০+ দিন পেরিয়ে গেছে
     * ══════════════════════════════════════════════════════════════════════
     */
    public function generateWarnings(): array
    {
        $created = ['15_days' => 0, '30_days' => 0];

        DB::transaction(function () use (&$created) {

            // ── Rule 1: confirmed + no payment + 15 days ──────────────────
            $fifteenDayOrders = Order::query()
                ->where('status', 'confirmed')
                ->where('payment_status', 'pending')
                ->where('invoice_status', 'generated')
                ->where('updated_at', '<=', Carbon::now()->subDays(15))
                ->whereDoesntHave('paymentWarnings', fn ($q) =>
                    $q->where('warning_type', '15_days')
                )
                ->with(['customer', 'invoice'])
                ->get();

            foreach ($fifteenDayOrders as $order) {
                PaymentWarning::updateOrCreate(
                    ['order_id' => $order->id, 'warning_type' => '15_days'],
                    [
                        'customer_id'  => $order->customer_id,
                        'sales_rep_id'  => $order->sales_rep_id,
                        // Carbon 3 returns a signed float from diffInDays, so
                        // now()->diffInDays(past) is negative — the warnings table
                        // was storing values like -21.5 and the panel rendered
                        // "-21.50d overdue". Measure forward from the older date
                        // and round to whole days.
                        'days_overdue' => (int) round($order->updated_at->diffInDays(Carbon::now())),
                        'order_total'  => $order->total,
                        'paid_amount'  => 0,
                        'due_amount'   => $order->total,
                        'is_resolved'  => false,
                    ]
                );
                $created['15_days']++;
            }

            // ── Rule 2: partial payment + 30 days since last payment ──────
            $thirtyDayOrders = Order::query()
                ->where('payment_status', 'partial')
                ->whereHas('invoice.payments', fn ($q) =>
                    $q->where('status', 'paid')
                      ->where('created_at', '<=', Carbon::now()->subDays(30))
                )
                ->whereDoesntHave('paymentWarnings', fn ($q) =>
                    $q->where('warning_type', '30_days')->where('is_resolved', false)
                )
                ->with(['customer', 'invoice.payments', 'discounts'])
                ->get();

            foreach ($thirtyDayOrders as $order) {
                $paidAmount  = $this->getPaidAmount($order);
                $dueAmount   = $this->getDueAmount($order, $paidAmount);
                $lastPayment = $order->invoice?->payments
                    ->where('status', 'paid')
                    ->sortByDesc('created_at')
                    ->first();

                $daysOverdue = $lastPayment
                    ? (int) round($lastPayment->created_at->diffInDays(Carbon::now()))
                    : 30;

                PaymentWarning::updateOrCreate(
                    ['order_id' => $order->id, 'warning_type' => '30_days'],
                    [
                        'customer_id'  => $order->customer_id,
                        'sales_rep_id' => $order->sales_rep_id,
                        'days_overdue' => $daysOverdue,
                        'order_total'  => $order->total,
                        'paid_amount'  => $paidAmount,
                        'due_amount'   => $dueAmount,
                        'is_resolved'  => false,
                    ]
                );
                $created['30_days']++;
            }
        });

        return $created;
    }

    /**
     * ══════════════════════════════════════════════════════════════════════
     * GET WARNINGS LIST — Controller এ use হবে
     * ══════════════════════════════════════════════════════════════════════
     */
    public function getWarnings(array $filters = [])
    {
        $query = PaymentWarning::query()
            ->with([
                'order:id,order_id,status,payment_status,total,created_at,updated_at',
                'order.items',
                'customer:id,name,mobile_number,address',
                'sr:id,full_name,mobile_number',
                'noteAddedBy:id,full_name',
            ])
            ->latest();

        // Filter: warning type
        if (!empty($filters['warning_type'])) {
            $query->where('warning_type', $filters['warning_type']);
        }

        // Filter: resolved status (default: unresolved only)
        if (isset($filters['is_resolved'])) {
            $query->where('is_resolved', filter_var($filters['is_resolved'], FILTER_VALIDATE_BOOLEAN));
        } else {
            $query->where('is_resolved', false);
        }

        // Filter: SR can only see their own customers' warnings
        if (!empty($filters['sales_rep_id'])) {
            $query->whereHas('order', fn ($q) =>
                $q->where('sales_rep_id', $filters['sales_rep_id'])
            );
        }

        // Filter: search by customer name / order id
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->whereHas('customer', fn ($c) =>
                    $c->where('name', 'like', "%{$search}%")
                      ->orWhere('mobile_number', 'like', "%{$search}%")
                )
                ->orWhereHas('order', fn ($o) =>
                    $o->where('order_id', 'like', "%{$search}%")
                );
            });
        }

        $perPage = $filters['per_page'] ?? 15;
        $warnings = $query->paginate($perPage);

        return PaymentWarningResource::collection($warnings);
    }

    /**
     * ══════════════════════════════════════════════════════════════════════
     * SUMMARY COUNTS — tab badge numbers এর জন্য
     * ══════════════════════════════════════════════════════════════════════
     */
    public function getSummary(?int $salesRepId = null): array
    {
        $base = PaymentWarning::query()->where('is_resolved', false);

        if ($salesRepId) {
            $base->whereHas('order', fn ($q) =>
                $q->where('sales_rep_id', $salesRepId)
            );
        }

        return [
            'total'    => (clone $base)->count(),
            '15_days'  => (clone $base)->where('warning_type', '15_days')->count(),
            '30_days'  => (clone $base)->where('warning_type', '30_days')->count(),
            'totalDue'=> (clone $base)->sum('due_amount'),
        ];
    }

    /**
     * ══════════════════════════════════════════════════════════════════════
     * ADD NOTE — SR or Admin কথা বলার পর note রাখে
     * ══════════════════════════════════════════════════════════════════════
     */
    public function addNote(int $warningId, string $note, int $userId): PaymentWarning
    {
        $warning = PaymentWarning::findOrFail($warningId);

        $warning->update([
            'note'          => $note,
            'note_added_by' => $userId,
            'note_added_at' => now(),
        ]);

        return $warning->fresh(['noteAddedBy:id,full_name', 'customer:id,name']);
    }

    /**
     * ══════════════════════════════════════════════════════════════════════
     * RESOLVE — Admin action নিলে (payment collected or cancelled)
     * ══════════════════════════════════════════════════════════════════════
     */
    public function resolve(int $warningId, int $userId): PaymentWarning
    {
        $warning = PaymentWarning::findOrFail($warningId);

        $warning->update([
            'is_resolved' => true,
            'resolved_by' => $userId,
            'resolved_at' => now(),
        ]);

        return $warning->fresh();
    }

    // ── Private helpers — matches existing OrderController logic ──────────

    private function getPaidAmount(Order $order): float
    {
        return (float) ($order->invoice?->payments->sum('amount_paid') ?? 0);
    }

    private function getDueAmount(Order $order, float $paidAmount): float
    {
        $total = (float) $order->total;

        $discountAmount = $order->discounts->sum(function ($discount) use ($total) {
            return $discount->type === 'percentage'
                ? ($total * (float) $discount->value) / 100
                : (float) $discount->value;
        });

        return round(max(0, $total - $discountAmount - $paidAmount), 2);
    }
}
