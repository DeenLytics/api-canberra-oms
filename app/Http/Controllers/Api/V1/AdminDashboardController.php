<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Helpers\{
    ResponseHelper
};
use App\Models\{
    Order,
    Invoice,
    Customer,
    Product,
    Category,
    SalesRepresentative,
};
use App\Support\PaymentLedger;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

// Total orders, pending orders, total income, due payment, ready to ship, total delivered,
class AdminDashboardController extends Controller
{
    public function index()
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth   = Carbon::now()->endOfMonth();
        $startLastMonth = Carbon::now()->subMonth()->startOfMonth();
        $endLastMonth   = Carbon::now()->subMonth()->endOfMonth();

        // -------------------------
        // Orders
        // -------------------------
        $totalOrders   = Order::count();
        $pendingOrders = Order::where('status', 'pending')->count();
        $delivered     = Order::where('status', 'delivered')->count();
        $readyToShip   = Order::where('status', 'ready_to_ship')->count();
        $todayOrders   = Order::whereDate('created_at', $today)->count();
        $todaySales   = Order::whereDate('created_at', $today)->where('status', '!=', 'cancelled')->sum('total');

        // Last month orders
        $totalOrdersLastMonth = Order::whereBetween('created_at', [$startLastMonth, $endLastMonth])->count();
        $totalOrdersThisMonth = Order::whereBetween('created_at', [$startOfMonth, $endOfMonth])->count();
        // This month against last, not all-time against last month.
        $ordersChange = $totalOrdersLastMonth > 0
            ? round((($totalOrdersThisMonth - $totalOrdersLastMonth) / $totalOrdersLastMonth) * 100, 2)
            : 0;

        // -------------------------
        // Sales Summary
        // ---
        // A cancelled order was never a sale.
        $currentMonthSales = Order::where('status', '!=', 'cancelled')->whereMonth('created_at', now()->month)
        ->whereYear('created_at', now()->year)
        ->sum('total');

        $lastMonthSales = Order::where('status', '!=', 'cancelled')->whereMonth('created_at', now()->subMonth()->month)
        ->whereYear('created_at', now()->subMonth()->year)
        ->sum('total');

        $monthlySales = Order::selectRaw('MONTHNAME(created_at) as month_name, SUM(total) as sales')
        ->where('status', '!=', 'cancelled')
        ->whereYear('created_at', now()->year)
        ->groupBy('month_name')
        ->orderByRaw('MIN(created_at)')
        ->get();

        // -------------------------
        // Sales Representatives
        // -------------------------
        $activeReps = SalesRepresentative::whereHas('user', fn($q) => $q->where('status', 'active'))->count();
        $inactiveReps = SalesRepresentative::whereHas('user', fn($q) => $q->where('status', 'inactive'))->count();

        // -------------------------
        // Customers
        // -------------------------
        $totalCustomers = Customer::count();
        $newCustomers   = Customer::whereDate('created_at', $today)->count();

        // -------------------------
        // Products & Categories
        // -------------------------
        $totalProducts   = Product::count();
        $totalCategories = Category::count();

        // -------------------------
        // Payments
        // -------------------------
        // Income is approved money only; a payment awaiting approval is not
        // revenue yet, and a rejected one never was. Cash collected (below)
        // counts what reps hold, approved or not, but never a rejected row.
        $approved  = fn () => DB::table('payments')->whereIn('status', PaymentLedger::APPROVED);
        $collected = fn () => DB::table('payments')->whereNotIn('status', PaymentLedger::REJECTED);

        $totalIncome = $approved()->sum('amount_paid');

        $totalIncomeThisMonth = $approved()
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->sum('amount_paid');

        // Last month income for comparison
        $totalIncomeLastMonth = $approved()
            ->whereBetween('created_at', [$startLastMonth, $endLastMonth])
            ->sum('amount_paid');

        // This month against last, not all-time against last month.
        $incomeChange = $totalIncomeLastMonth > 0
            ? round((($totalIncomeThisMonth - $totalIncomeLastMonth) / $totalIncomeLastMonth) * 100, 2)
            : 0;

        // Payment collected today
        $paymentCollectedToday = $collected()
            ->whereDate('created_at', $today)
            ->sum('amount_paid');

        // Payment pending (invoice total - payments)
        $paymentPending = Invoice::select(
            DB::raw('SUM(i.total - COALESCE(p.total_paid, 0)) as total_due')
        )
            ->from('invoices as i')
            ->leftJoin(
                DB::raw('(SELECT invoice_id, SUM(amount_paid) as total_paid FROM payments WHERE status <> \'failed\' GROUP BY invoice_id) as p'),
                'i.id',
                '=',
                'p.invoice_id'
            )
            ->value('total_due');

        // Collection rate today
        $todayInvoiceTotal = Invoice::whereDate('created_at', $today)->sum('total');
        $collectionRateToday = $todayInvoiceTotal > 0
        ? min(100, round(($paymentCollectedToday / $todayInvoiceTotal) * 100, 2))
        : 0;

        // -------------------------
        // Pending Collections Orders
        // -------------------------
        $pendingCollectionsOrders = Invoice::select('id')
            ->leftJoin(
                DB::raw('(SELECT invoice_id, SUM(amount_paid) as total_paid FROM payments WHERE status <> \'failed\' GROUP BY invoice_id) as p'),
                'invoices.id',
                '=',
                'p.invoice_id'
            )
            ->whereRaw('invoices.total - COALESCE(p.total_paid,0) > 0')
            ->count();

        // -------------------------
        // Payment Collected Today Orders
        // -------------------------
        $paymentCollectedTodayOrders = $collected()
            ->whereDate('created_at', $today)
            ->distinct('invoice_id')
            ->count('invoice_id');

        // -------------------------
        // Build dashboard array
        // -------------------------
        $data = [
            'total_orders'                    => $totalOrders,
            'pending_orders'                  => $pendingOrders,
            'ready_to_ship'                   => $readyToShip,
            'delivered_orders'                => $delivered,
            'today_orders'                    => $todayOrders,
            'today_sales'                     => $todaySales,
            'total_orders_change'             => $ordersChange,    // % vs last month

            'active_sales_reps'               => $activeReps,
            'inactive_sales_reps'             => $inactiveReps,

            'total_customers'                 => $totalCustomers,
            'new_customers'                   => $newCustomers,

            'total_products'                  => $totalProducts,
            'total_categories'                => $totalCategories,

            'total_income'                    => round($totalIncome, 2),
            'total_income_this_month'         => round($totalIncomeThisMonth, 2),
            'income_change'                   => $incomeChange,    // % vs last month

            'payment_collected_today'         => round($paymentCollectedToday, 2),
            'payment_pending'                 => round($paymentPending, 2),
            'collection_rate_today'           => $collectionRateToday, // %

            'pending_collections_orders'      => $pendingCollectionsOrders,
            'payment_collected_today_orders'  => $paymentCollectedTodayOrders,

            'current_month_sales'               => round($currentMonthSales, 2),
            'last_month_sales'                  => round($lastMonthSales, 2),
            'monthly_sales'                    => $monthlySales,
        ];

        return ResponseHelper::success($data, 'Dashboard Summary');
    }
}
