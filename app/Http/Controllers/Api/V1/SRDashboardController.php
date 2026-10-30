<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use App\Helpers\{
    ResponseHelper
};
use App\Models\{
    Order,
    Invoice,
    Payment
};

class SRDashboardController extends Controller
{
    public function index(){
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();
        $salesRepId = auth()->user()->id;

        // -------------------------
        // Orders for this Sales Rep
        // -------------------------
        $orders = Order::where('sales_rep_id', $salesRepId);

        $todayOrders = Order::where('sales_rep_id', $salesRepId)
            ->whereDate('created_at', $today);

        $yesterdayOrders = Order::where('sales_rep_id', $salesRepId)
            ->whereDate('created_at', $yesterday);

        $totalTodayOrders = $todayOrders->count();
        $totalOrders = $orders->count();
        $pendingOrders = (clone $orders)->where('status', 'pending')->count();

        // -------------------------
        // Today's confirmed order sales
        // -------------------------
        $todaySales = (clone $orders)
            // ->where('status', 'confirmed')
            ->whereDate('created_at', $today)
            ->sum('total');

        // -------------------------
        // Yesterday's confirmed order sales
        // -------------------------
        $yesterdaySales = (clone $orders)
            // ->where('status', 'confirmed')
            ->whereDate('created_at', $yesterday)
            ->sum('total');

        // -------------------------
        // Sales % change vs yesterday
        // -------------------------
        $salesChange = $yesterdaySales > 0
            ? round((($todaySales - $yesterdaySales) / $yesterdaySales) * 100, 2)
            : 0;

        // -------------------------
        // Build response
        // -------------------------
        $data = [
            'sales_rep_id'         => $salesRepId,
            'today_sales'          => round($todaySales, 2),
            'sales_change_percent' => $salesChange,      // +15% etc
            'total_orders'         => $totalOrders,
            'today_orders'         => $totalTodayOrders,
            'pending_orders'       => $pendingOrders,
            'periods'              => $this->periods($salesRepId),
        ];

        return ResponseHelper::success($data, 'Today Sales Summary for Sales Representative');
    }

    /**
     * Sales and order count for today, this week and this month so far, each
     * against the same stretch of the period before it, so a Tuesday morning is
     * not measured against the whole of last week. Weeks start where
     * TargetService starts them, so these line up with the target card.
     */
    private function periods(int $salesRepId): array
    {
        $now = Carbon::now();
        $periods = [];

        foreach ([
            'daily'   => [$now->copy()->startOfDay(),   $now->copy()->subDay()->startOfDay()],
            'weekly'  => [$now->copy()->startOfWeek(),  $now->copy()->subWeek()->startOfWeek()],
            'monthly' => [$now->copy()->startOfMonth(), $now->copy()->subMonthNoOverflow()->startOfMonth()],
        ] as $period => [$start, $prevStart]) {
            $prevEnd = $prevStart->copy()->addSeconds((int) $start->diffInSeconds($now))->min($start);

            [$sales, $orders] = $this->salesBetween($salesRepId, $start, $now);
            [$prevSales]      = $this->salesBetween($salesRepId, $prevStart, $prevEnd);

            $periods[$period] = [
                'sales'  => round($sales, 2),
                'orders' => $orders,
                'change' => $prevSales > 0 ? round(($sales - $prevSales) / $prevSales * 100, 1) : null,
            ];
        }

        return $periods;
    }

    private function salesBetween(int $salesRepId, Carbon $start, Carbon $end): array
    {
        $row = Order::where('sales_rep_id', $salesRepId)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(total), 0) as sales, COUNT(*) as orders')
            ->first();

        return [(float) $row->sales, (int) $row->orders];
    }
}
