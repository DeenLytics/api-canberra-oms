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

    // GET /api/reports/companies
    public function companies(Request $request): JsonResponse
    {
        // $this->authorize('viewReports');

        $data = $this->reportService->getCompanyReport(
            $request->startDate,
            $request->endDate,
            $request->salesRepId,
            $request->input('period', 'monthly'),
        );

        return response()->json($data);
    }
}
