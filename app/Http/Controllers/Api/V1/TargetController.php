<?php
// ══════════════════════════════════════════════════════════════════════════════
// TargetController.php
// Route: /api/targets
// ══════════════════════════════════════════════════════════════════════════════
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TargetService;
use App\Models\QuarterlyTarget;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class TargetController extends Controller
{
    public function __construct(private TargetService $targetService) {}

    // GET /api/targets
    public function index(Request $request): JsonResponse
    {
        $query = QuarterlyTarget::with('salesRep:id,full_name', 'monthlyTargets')
            ->latest();

        if ($request->filled('salesRepId')) {
            $query->where('sales_rep_id', $request->salesRepId);
        }
        if ($request->filled('targetType')) {
            $query->where('target_type', $request->targetType);
        }

        $targets = $query->get()->map(function ($t) {
            $t->salesRepId      = $t->sales_rep_id;
            $t->salesRepName    = $t->salesRep?->full_name;
            $t->achievedAmount   = $t->achieved_amount;
            $t->achievedPercentage = $t->achieved_percentage;
            $t->quarterlyAmount = $t->quarterly_amount;
            $t->targetType = $t->target_type;
            $t->quarterStartDate = $t->quarterStartDate = !empty($t->quarter_start_date)
            ? Carbon::parse($t->quarter_start_date)->format('Y-m-d')
            : null;;
                    $t->quarterEndDate = $t->quarterEndDate = !empty($t->quarter_end_date)
            ? Carbon::parse($t->quarter_end_date)->format('Y-m-d')
            : null;;
            return $t;
        });

        return response()->json($targets);
    }

    // POST /api/targets
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'salesRepId'        => 'required|exists:users,id',
            'targetType'         => 'required|in:sales,outlet_visit',
            'quarterStartDate'  => 'required|date',
            'quarterEndDate'    => 'required|date|after:quarterStartDate',
            'quarterlyAmount'    => 'required|numeric|min:1',
            'monthlyBreakdown'   => 'nullable|array|max:3',
            'monthlyBreakdown.*.targetAmount' => 'nullable|numeric|min:0',
        ]);

        $target = $this->targetService->createWithSplit($validated);

        return response()->json($target->load('monthlyTargets'), 201);
    }

    // PUT /api/targets/{id}
    public function update(Request $request, QuarterlyTarget $target): JsonResponse
    {
        $validated = $request->validate([
            'salesRepId'        => 'required|exists:users,id',
            'targetType'         => 'required|in:sales,outlet_visit',
            'quarterlyAmount'    => 'sometimes|numeric|min:1',
            'quarterStartDate'  => 'sometimes|date',
            'quarterEndDate'    => 'sometimes|date',
            'monthlyBreakdown'   => 'nullable|array|max:3',
        ]);

        // Monthly breakdown regenerate করো যদি amount বদলায়
        if (isset($validated['quarterlyAmount']) || isset($validated['monthlyBreakdown'])) {
            $target->monthlyTargets()->each(fn($m) => $m->weeklyTargets()->each(fn($w) => $w->dailyTargets()->delete()) && $m->weeklyTargets()->delete());
            $target->monthlyTargets()->delete();
            $target->update($validated);
            $this->targetService->createWithSplit(array_merge($target->toArray(), $validated));
        } else {
            $target->update($validated);
        }

        return response()->json($target->fresh('monthlyTargets'));
    }

    // DELETE /api/targets/{id}
    public function destroy(QuarterlyTarget $target): JsonResponse
    {
        $target->delete();
        return response()->json(['message' => 'Target deleted']);
    }

    // GET /api/targets/achievement
    public function achievement(Request $request): JsonResponse
    {
        $request->validate([
            'period'      => 'required|in:daily,weekly,monthly,quarterly',
            'targetType' => 'required|in:sales,outlet_visit',
            'salesRepId'=> 'nullable|exists:users,id',
            'date'        => 'nullable|date',
        ]);

        $salesRepId = $request->salesRepId ?? auth()->id();

        $result = $this->targetService->getAchievement(
            salesRepId:  $salesRepId,
            period:      $request->period,
            targetType:  $request->targetType,
            date:        $request->date ?? now()->toDateString(),
        );

        return response()->json($result);
    }
}
