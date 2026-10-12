<?php
// ══════════════════════════════════════════════════════════════════════════════
// ReportController.php
// Route: /api/reports
// ══════════════════════════════════════════════════════════════════════════════

namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\ReportService;

class ReportController extends Controller
{
    public function __construct(private ReportService $reportService) {}

    // GET /api/reports/sales
    public function sales(Request $request): JsonResponse
    {
        $request->validate([
            'period'       => 'required|in:daily,weekly,monthly',
            'salesRepId' => 'nullable|exists:users,id',
            'startDate'   => 'nullable|date',
            'endDate'     => 'nullable|date',
        ]);

        $data = $this->reportService->getSalesReport(
            $request->period,
            $request->salesRepId,
            $request->startDate,
            $request->endDate,
        );

        return response()->json($data);
    }

    // GET /api/reports/sales-reps
    public function salesReps(Request $request): JsonResponse
    {
        // $this->authorize('viewReports');

        $data = $this->reportService->getSalesRepReport(
            $request->salesRepId,
            $request->input('period', 'monthly'),
            $request->startDate,
            $request->endDate,
        );

        return response()->json($data);
    }

    // GET /api/reports/areas
    public function areas(Request $request): JsonResponse
    {
        // $this->authorize('viewReports');

        $data = $this->reportService->getAreaReport(
            $request->salesRepId,
            $request->input('period', 'monthly'),
            $request->startDate,
            $request->endDate,
        );

        return response()->json($data);
    }

    /**
     * GET /api/reports/categories
     *
     * Was /companies, grouping by `products.company` — a column that does not
     * exist, so the endpoint threw a 500 on every call and the panel's tab had
     * been commented out instead of fixed.
     */
    public function categories(Request $request): JsonResponse
    {
        $data = $this->reportService->getCategoryReport(
            $request->startDate,
            $request->endDate,
            $request->salesRepId,
            $request->input('period', 'monthly'),
        );

        return response()->json($data);
    }

    // GET /api/reports/products — the lines that move, biggest first
    public function products(Request $request): JsonResponse
    {
        $data = $this->reportService->getProductReport(
            $request->startDate,
            $request->endDate,
            $request->salesRepId,
            $request->input('period', 'monthly'),
            (int) $request->input('limit', 25),
        );

        return response()->json($data);
    }
}
