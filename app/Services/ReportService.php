<?php

namespace App\Services;

use App\Models\IdleEvent;
use App\Models\Order;
use App\Models\OutletVisit;
use App\Models\QuarterlyTarget;
use App\Models\User;
use Carbon\Carbon;
use App\Support\PaymentLedger;
use Illuminate\Support\Facades\DB;

class ReportService
{
    // ══════════════════════════════════════════════════════════════════════
    // SALES REPORT
    // ══════════════════════════════════════════════════════════════════════

    public function getSalesReport(
        string $period,
        ?int $salesRepId = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        // The trend covers the page's window; `period` only decides how it is
        // bucketed. It used to default to its own four-month range, which no
        // other report on the page shared.
        [$start, $end] = $startDate || $endDate
            ? $this->resolveRange($startDate, $endDate, $period)
            : [Carbon::now()->subMonths(3)->startOfMonth(), Carbon::now()->endOfDay()];

        return match ($period) {
            'daily'   => $this->getDailyReport($salesRepId, $start, $end),
            'weekly'  => $this->getWeeklyReport($salesRepId, $start, $end),
            'monthly' => $this->getMonthlyReport($salesRepId, $start, $end),
            default   => $this->getMonthlyReport($salesRepId, $start, $end),
        };
    }

    // ══════════════════════════════════════════════════════════════════════
    // DAILY REPORT
    // ══════════════════════════════════════════════════════════════════════

    private function getDailyReport(?int $salesRepId, Carbon $start, Carbon $end): array
    {
        $items = [];
        $current = $start->copy();

        while ($current->lte($end)) {

            $dayStart = $current->copy()->startOfDay();
            $dayEnd   = $current->copy()->endOfDay();

            $ordersQuery = Order::query()
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->where('status', '!=', 'cancelled');

            if ($salesRepId) {
                $ordersQuery->where('sales_rep_id', $salesRepId);
            }

            $visitsQuery = OutletVisit::query()
                ->whereBetween('visited_at', [$dayStart, $dayEnd]);

            if ($salesRepId) {
                $visitsQuery->where('sales_rep_id', $salesRepId);
            }

            $target = $this->getTargetForRange($salesRepId, $dayStart, $dayEnd);

            $items[] = [
                'label'            => $current->format('d M'),
                'targetAmount'     => $target,
                /* A bucket with no target is not a bucket at 0% — nobody set
                   one. Without this flag the page totals months that have a
                   target with months that do not, and reports 270%. */
                'hasTarget'        => $target > 0,
                'achievedAmount'   => (float) (clone $ordersQuery)->sum('total'),
                'ordersCount'      => (clone $ordersQuery)->count(),
                'outletVisits'     => (clone $visitsQuery)->count(),
            ];

            $current->addDay();
        }

        return $items;
    }

    // ══════════════════════════════════════════════════════════════════════
    // WEEKLY REPORT
    // ══════════════════════════════════════════════════════════════════════

    private function getWeeklyReport(?int $salesRepId, Carbon $start, Carbon $end): array
    {
        $items = [];

        $current = $start->copy()->startOfWeek(Carbon::SATURDAY);

        while ($current->lte($end)) {

            $weekStart = $current->copy()->startOfDay();

            $weekEnd = $current
                ->copy()
                ->endOfWeek(Carbon::FRIDAY)
                ->endOfDay();

            if ($weekEnd->gt($end)) {
                $weekEnd = $end->copy();
            }

            $ordersQuery = Order::query()
                ->whereBetween('created_at', [$weekStart, $weekEnd])
                ->where('status', '!=', 'cancelled');

            if ($salesRepId) {
                $ordersQuery->where('sales_rep_id', $salesRepId);
            }

            $visitsQuery = OutletVisit::query()
                ->whereBetween('visited_at', [$weekStart, $weekEnd]);

            if ($salesRepId) {
                $visitsQuery->where('sales_rep_id', $salesRepId);
            }

            $target = $this->getTargetForRange($salesRepId, $weekStart, $weekEnd);

            $items[] = [
                'label'            => 'W' . $weekStart->format('W') . ' ' . $weekStart->format('M'),
                'targetAmount'     => $target,
                'hasTarget'        => $target > 0,
                'achievedAmount'   => (float) (clone $ordersQuery)->sum('total'),
                'ordersCount'      => (clone $ordersQuery)->count(),
                'outletVisits'     => (clone $visitsQuery)->count(),
            ];

            $current->addWeek();
        }

        return $items;
    }

    // ══════════════════════════════════════════════════════════════════════
    // MONTHLY REPORT
    // ══════════════════════════════════════════════════════════════════════

    private function getMonthlyReport(?int $salesRepId, Carbon $start, Carbon $end): array
    {
        $items = [];

        $current = $start->copy()->startOfMonth();

        while ($current->lte($end)) {

            $monthStart = $current->copy()->startOfMonth()->startOfDay();
            $monthEnd   = $current->copy()->endOfMonth()->endOfDay();

            if ($monthEnd->gt($end)) {
                $monthEnd = $end->copy();
            }

            $ordersQuery = Order::query()
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->where('status', '!=', 'cancelled');

            if ($salesRepId) {
                $ordersQuery->where('sales_rep_id', $salesRepId);
            }

            $visitsQuery = OutletVisit::query()
                ->whereBetween('visited_at', [$monthStart, $monthEnd]);

            if ($salesRepId) {
                $visitsQuery->where('sales_rep_id', $salesRepId);
            }

            $target = $this->getTargetForRange($salesRepId, $monthStart, $monthEnd);

            $items[] = [
                'label'            => $current->format('M Y'),
                'targetAmount'     => $target,
                'hasTarget'        => $target > 0,
                'achievedAmount'   => (float) (clone $ordersQuery)->sum('total'),
                'ordersCount'      => (clone $ordersQuery)->count(),
                'outletVisits'     => (clone $visitsQuery)->count(),
            ];

            $current->addMonth();
        }

        return $items;
    }

    // ══════════════════════════════════════════════════════════════════════
    // SALES REP REPORT
    // ══════════════════════════════════════════════════════════════════════

    public function getSalesRepReport(
        ?int $salesRepId,
        string $period,
        ?string $startDate,
        ?string $endDate
    ): array {

        [$start, $end] = $this->resolveRange($startDate, $endDate, $period);

        $salesReps = User::role('sales_representative', 'web')
            ->where('status', 'active')
            ->when($salesRepId, function ($q) use ($salesRepId) {
                $q->where('id', $salesRepId);
            })
            ->get();

        return $salesReps
            ->map(function (User $sr) use ($start, $end) {

                $ordersQuery = Order::query()
                    ->where('sales_rep_id', $sr->id)
                    ->whereBetween('created_at', [$start, $end])
                    ->where('status', '!=', 'cancelled');

                $sales = (float) (clone $ordersQuery)->sum('total');

                $target = $this->getTargetForRange($sr->id, $start, $end);
                $orders = (clone $ordersQuery)->count();
                $money  = $this->collectionFor(clone $ordersQuery);

                return [
                    'salesRepId'         => $sr->id,
                    'salesRepName'       => $sr->full_name,
                    'totalSales'         => $sales,
                    'targetAmount'       => $target,
                    'achievedPercentage' => $target > 0
                        ? round(($sales / $target) * 100, 2)
                        : 0,
                    /* Without this the panel cannot tell "missed the target"
                       from "no target was ever set", and prints 0% for both. */
                    'hasTarget'          => $target > 0,
                    'ordersCount'        => $orders,

                    // What the orders are actually worth in cash so far.
                    'collectedAmount'    => $money['collected'],
                    'outstandingAmount'  => $money['outstanding'],

                    'avgOrderValue'      => $orders > 0 ? round($sales / $orders, 2) : 0,
                    'outletsServed'      => (clone $ordersQuery)->distinct()->count('customer_id'),

                    'outletVisits' => OutletVisit::where('sales_rep_id', $sr->id)
                        ->whereBetween('visited_at', [$start, $end])
                        ->count(),

                    'idleCount' => IdleEvent::where('sales_rep_id', $sr->id)
                        ->whereBetween('start_time', [$start, $end])
                        ->count(),
                ];
            })
            ->sortByDesc('totalSales')
            ->values()
            ->toArray();
    }

    // ══════════════════════════════════════════════════════════════════════
    // AREA REPORT
    // ══════════════════════════════════════════════════════════════════════

    public function getAreaReport(?int $salesRepId, string $period, ?string $startDate, ?string $endDate): array
    {
        [$start, $end] = $this->resolveRange($startDate, $endDate, $period);

        $areas = OutletVisit::query()
            ->whereBetween('visited_at', [$start, $end])
            ->selectRaw('
                area,
                COUNT(*) as outlet_count,
                COUNT(DISTINCT sales_rep_id) as active_srs
            ')
            ->groupBy('area')
            ->get();

        $rows = $areas
            ->map(function ($areaData) use ($start, $end, $salesRepId) {

                $ordersQuery = Order::query()
                    ->whereHas('customer', function ($q) use ($areaData, $salesRepId) {
                        $q->where('address', $areaData->area)
                        ->when($salesRepId, function ($q) use ($salesRepId) {
                            $q->where('created_by_id', $salesRepId);
                        });
                    })
                    ->whereBetween('orders.created_at', [$start, $end])
                    ->where('orders.status', '!=', 'cancelled');

                $sales  = (float) (clone $ordersQuery)->sum('total');
                $orders = (clone $ordersQuery)->count();
                $money  = $this->collectionFor(clone $ordersQuery);

                return [
                    'area'              => $areaData->area,
                    'totalSales'        => $sales,
                    'ordersCount'       => $orders,
                    'activeSRs'         => (int) $areaData->active_srs,
                    'outletCount'       => (int) $areaData->outlet_count,
                    'collectedAmount'   => $money['collected'],
                    'outstandingAmount' => $money['outstanding'],
                    'avgOrderValue'     => $orders > 0 ? round($sales / $orders, 2) : 0,
                ];
            })
            ->sortByDesc('totalSales')
            ->values();

        // The share of the window's sales, so a bar can be drawn without the
        // panel having to total the rows itself and disagree.
        $grandTotal = (float) $rows->sum('totalSales');

        return $rows
            ->map(function ($row) use ($grandTotal) {
                $row['revenueShare'] = $grandTotal > 0
                    ? round(($row['totalSales'] / $grandTotal) * 100, 2)
                    : 0;

                return $row;
            })
            ->toArray();
    }

    // ══════════════════════════════════════════════════════════════════════
    // COMPANY REPORT
    // ══════════════════════════════════════════════════════════════════════

    /**
     * What sold, by category and by product.
     *
     * This replaces getCompanyReport, which grouped by `products.company`.
     * There is no such column — products carry a category_id and nothing else
     * — so the endpoint threw a 500 on every call, and the panel's "By
     * Company" tab had been commented out rather than fixed. A distributor's
     * question is which lines move, so the answer is the two groupings the
     * schema can actually give: category and product.
     */
    public function getCategoryReport(
        ?string $startDate,
        ?string $endDate,
        ?int $salesRepId = null,
        string $period = 'monthly'
    ): array {
        [$start, $end] = $this->resolveRange($startDate, $endDate, $period);

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.status', '!=', 'cancelled')
            ->when($salesRepId, fn ($q) => $q->where('orders.sales_rep_id', $salesRepId))
            ->selectRaw('
                COALESCE(categories.name, "Uncategorised") as group_name,
                SUM(order_items.total) as totalSales,
                COUNT(DISTINCT orders.id) as ordersCount,
                COUNT(DISTINCT products.id) as productCount,
                SUM(order_items.quantity) as unitsSold
            ')
            ->groupBy('group_name')
            ->orderByDesc('totalSales')
            ->get();

        return $this->withShare($rows, fn ($row) => [
            'name'         => $row->group_name,
            'totalSales'   => (float) $row->totalSales,
            'ordersCount'  => (int) $row->ordersCount,
            'unitsSold'    => (int) $row->unitsSold,
            'productCount' => (int) $row->productCount,
        ]);
    }

    public function getProductReport(
        ?string $startDate,
        ?string $endDate,
        ?int $salesRepId = null,
        string $period = 'monthly',
        int $limit = 25
    ): array {
        [$start, $end] = $this->resolveRange($startDate, $endDate, $period);

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.status', '!=', 'cancelled')
            ->when($salesRepId, fn ($q) => $q->where('orders.sales_rep_id', $salesRepId))
            ->selectRaw('
                /* product_name is denormalised onto the line, so a product that
                   has since been renamed or deleted still reports under the name
                   it was sold as. */
                COALESCE(products.name, order_items.product_name) as group_name,
                COALESCE(categories.name, "Uncategorised") as category_name,
                SUM(order_items.total) as totalSales,
                COUNT(DISTINCT orders.id) as ordersCount,
                SUM(order_items.quantity) as unitsSold
            ')
            ->groupBy('group_name', 'category_name')
            ->orderByDesc('totalSales')
            ->limit($limit)
            ->get();

        return $this->withShare($rows, fn ($row) => [
            'name'        => $row->group_name,
            'category'    => $row->category_name,
            'totalSales'  => (float) $row->totalSales,
            'ordersCount' => (int) $row->ordersCount,
            'unitsSold'   => (int) $row->unitsSold,
        ]);
    }

    /** Each row's share of the set, so the panel does not have to total the
     *  rows itself and end up disagreeing with them. */
    private function withShare($rows, callable $shape): array
    {
        $grandTotal = (float) $rows->sum('totalSales');

        return $rows
            ->map(function ($row) use ($shape, $grandTotal) {
                $out = $shape($row);

                $out['revenueShare'] = $grandTotal > 0
                    ? round(($out['totalSales'] / $grandTotal) * 100, 2)
                    : 0;

                return $out;
            })
            ->values()
            ->toArray();
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The window every report on the page measures.
     *
     * There was no shared resolver, so the four endpoints each invented their
     * own default and the Sales Report page showed three ranges at once under
     * one set of summary cards:
     *
     *   getSalesReport      no dates  → now()->subMonths(3) .. now()   (4 months)
     *   getSalesRepReport   period    → startOfMonth .. endOfMonth     (whole month)
     *   getAreaReport       period    → startOfMonth .. endOfMonth
     *
     * which is how the header read 270% (four months of sales against the one
     * and a bit months that had a target set) directly above a table of 15%
     * and 0%. It also meant October's target was 375,949 in the chart and
     * 888,607 in the table: month-to-date against the full month.
     *
     * A lone startDate used to be ignored entirely, because the branch
     * required both — so the panel sent `startDate=1 Jan` and silently got
     * the current month.
     */
    private function resolveRange(?string $startDate, ?string $endDate, string $period): array
    {
        $now = Carbon::now();

        if ($startDate || $endDate) {
            return [
                $startDate ? Carbon::parse($startDate)->startOfDay() : $now->copy()->startOfYear(),
                $endDate ? Carbon::parse($endDate)->endOfDay() : $now->copy()->endOfDay(),
            ];
        }

        return match ($period) {
            'daily'  => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'weekly' => [$now->copy()->startOfWeek(Carbon::SATURDAY), $now->copy()->endOfDay()],
            // Month to date, not the whole month: a target for days that have
            // not happened yet is not a shortfall.
            default  => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
        };
    }

    /**
     * What has been collected against a set of orders, and what is still owed.
     *
     * Approved and awaiting-approval payments are cash the shop handed over;
     * a rejected payment is not. Mirrors App\Support\PaymentLedger, which is
     * the single definition — a report that disagrees with the order screen is
     * worse than no report.
     */
    private function collectionFor($ordersQuery): array
    {
        $orderIds = (clone $ordersQuery)->pluck('orders.id');

        if ($orderIds->isEmpty()) {
            return ['collected' => 0.0, 'outstanding' => 0.0];
        }

        $collected = (float) DB::table('payments')
            ->whereIn('order_id', $orderIds)
            ->whereIn('status', array_merge(PaymentLedger::APPROVED, PaymentLedger::AWAITING))
            ->sum('amount_paid');

        $billed = (float) (clone $ordersQuery)->sum('total');

        return [
            'collected'   => round($collected, 2),
            'outstanding' => round(max(0, $billed - $collected), 2),
        ];
    }

    private function getTargetForRange(
        ?int $salesRepId,
        Carbon $start,
        Carbon $end
    ): float {

        $query = QuarterlyTarget::query()
            ->where('target_type', 'sales')
            ->whereDate('quarter_start_date', '<=', $end)
            ->whereDate('quarter_end_date', '>=', $start)
            ->with([
                'monthlyTargets.weeklyTargets.dailyTargets' => function ($q) use ($start, $end) {
                    $q->whereBetween('date', [
                        $start->toDateString(),
                        $end->toDateString(),
                    ]);
                }
            ]);

        if ($salesRepId) {
            $query->where('sales_rep_id', $salesRepId);
        }

        return (float) $query->get()->sum(
            fn($quarterlyTarget) => $quarterlyTarget->monthlyTargets->sum(
                fn($monthlyTarget) => $monthlyTarget->weeklyTargets->sum(
                    fn($weeklyTarget) => $weeklyTarget->dailyTargets->sum('target_amount')
                )
            )
        );
    }
}
