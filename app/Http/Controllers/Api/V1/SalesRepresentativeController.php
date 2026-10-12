<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\{
    User,
    SalesRepresentative,
    Order,
};
use App\Helpers\{
    FileHelper,
    ResponseHelper
};
use App\Http\Resources\Api\V1\SalesRepresentative\{
    SalesRepresentativeCollectionResource,
    SalesRepresentativeResource,
};

use App\Http\Resources\Api\V1\Order\{
    OrderCollectionResource,
    OrderResource,
};

use App\Http\Requests\Api\V1\SalesRepresentative\{
    CreateSalesRepresentativeRequest,
    EditSalesRepresentativeRequest,
};


use DB;

class SalesRepresentativeController extends Controller
{

    public function index()
    {
        $salesRepresentatives = User::with('salesRepresentative')->orderBy('id', 'desc')->get();
        return ResponseHelper::success(SalesRepresentativeCollectionResource::collection($salesRepresentatives), 'Categories retrieved successfully');
    }

    public function store(CreateSalesRepresentativeRequest $request)
    {

        DB::beginTransaction();

        try {
            $userData = [
                'full_name' => $request->fullName,
                'email' => $request->email,
                'mobile_number' => $request->mobileNumber,
                'password' => bcrypt($request->mobileNumber ?? 'password'),
            ];

            $user = User::create($userData);

            // Assign role
            $user->assignRole('sales_representative');

            $salesRepresentative = null;

            $salesRepresentativeData = [
                'employee_code' => generate_employee_code(),
                'territory' => $request->territory
            ];

            $salesRepresentative = $user->salesRepresentative()->create($salesRepresentativeData);

            DB::commit();

            $user->load('salesRepresentative');

            // Check if sales representative was created
            return ResponseHelper::success(new SalesRepresentativeResource($user), 'Sales Representative created successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return ResponseHelper::error($e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        $salesRepresentative = User::where('id', $id)->with('salesRepresentative')->first();

        if (!$salesRepresentative) return ResponseHelper::error('Sales representative not found', 404);

        return ResponseHelper::success(new SalesRepresentativeResource($salesRepresentative), 'Sales representative retrieved successfully');
    }

    public function update(EditSalesRepresentativeRequest $request, $id)
    {

        $user = User::find($id);

        if (!$user) return ResponseHelper::error('Sales Representative not found', 404);


        DB::beginTransaction();

        try {
            // Update user basic info
            $user->update([
                'full_name'     => $request->fullName,
                'email'         => $request->email,
                'mobile_number' => $request->mobileNumber,
            ]);

            // Ensure role is always correct
            $user->syncRoles(['sales_representative']);

            // Always ensure sales representative exists
            $salesRepresentative = $user->salesRepresentative()->updateOrCreate(
                ['user_id' => $id],
                [
                    'employee_code' => $user->salesRepresentative->employee_code ?? generate_employee_code(),
                    'territory'     => $request->territory
                ]
            );

            DB::commit();

            $user->load('salesRepresentative');

            return ResponseHelper::success(
                new SalesRepresentativeResource($user),
                'Sales Representative updated successfully',
                201
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return ResponseHelper::error($e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        $user = User::find($id);
        if (!$user) return ResponseHelper::error('Sales Representative not found', 404);

        $user->status = "banned";
        $user->update();
        return ResponseHelper::success('SalesRepresentative deleted successfully');
    }

    public function getMyRecentOrders(Request $request){

        $orderTake = $request->take ?? 5;

        $salesRepId = auth()->user()->id;

        $orders = Order::where('sales_rep_id', $salesRepId)
            // the resource computes the ledger per row — load it with the list
            ->with(['items', 'customer', 'items.product:id,thumbnail', 'invoice', 'payments', 'discounts'])
            ->orderBy('id', 'desc')->take($orderTake)->get() ?? [];

        if (!count($orders)) return ResponseHelper::error('Orders not found', 404);

        return ResponseHelper::success(OrderCollectionResource::collection($orders), 'Orders retrieved successfully');
    }

    public function getMyOrders(Request $request){

        $query = $this->buildQuery($request);

        // Get paginated results based on current page
        $perPage = $request->per_page ?? 10;
        $page = $request->page ?? 1;

        // Manually paginate for export
        $orders = $query->paginate($perPage, ['*'], 'page', $page);

        $stats = Order::selectRaw("
            COUNT(*) as all_count,
            SUM(status = 'pending') as pending,
            SUM(status = 'confirmed') as confirmed,
            SUM(status = 'cancelled') as cancelled,
            SUM(status = 'ready_to_ship') as ready_to_ship,
            SUM(status = 'shipped') as shipped,
            SUM(status = 'delivered') as delivered,
            SUM(payment_status = 'pending') as paymentPending,
            SUM(payment_status = 'partial') as paymentPartial,
            SUM(payment_status = 'paid') as paymentPaid
        ")->first();

        $dataCount = [
            ['id' => 'all',              'label' => 'All Orders',        'value' => $stats->all_count],
            ['id' => 'pending',          'label' => 'Pending Orders',    'value' => $stats->pending],
            ['id' => 'confirmed',        'label' => 'Confirmed Orders',  'value' => $stats->confirmed],
            ['id' => 'ready_to_ship',    'label' => 'Ready To Ship',  'value' => $stats->ready_to_ship],
            ['id' => 'shipped',          'label' => 'Shipped',   'value' => $stats->shipped],
            ['id' => 'delivered',        'label' => 'Delivered', 'value' => $stats->delivered],
            ['id' => 'paymentPending',   'label' => 'Payment Pending',   'value' => $stats->paymentPending],
            ['id' => 'paymentPartial',   'label' => 'Partial Payment',   'value' => $stats->paymentPartial],
            ['id' => 'paymentPaid',      'label' => 'Paid Payment',      'value' => $stats->paymentPaid],
            ['id' => 'cancelled',        'label' => 'Cancelled Orders',  'value' => $stats->cancelled],
        ];

        return ResponseHelper::success(OrderCollectionResource::collection($orders), 'Orders retrieved successfully', 200, ['counts' => $dataCount]);
    }

    private function buildQuery(Request $request)
    {
        $salesRepId = auth()->user()->id;
        return Order::where('sales_rep_id', $salesRepId)
        ->with(['items', 'customer', 'items.product:id,thumbnail', 'invoice', 'payments', 'discounts'])
        ->when($request->search, fn ($q) =>
            $q->where('order_id', 'like', "%".$request->search."%")
        )
        ->when($request->status === 'pending', fn ($q) =>
            $q->where('status', 'pending')
        )
        ->when($request->status === 'confirmed', fn ($q) =>
            $q->where('status', 'confirmed')
        )
        ->when($request->status === 'cancelled', fn ($q) =>
            $q->where('status', 'cancelled')
        )
        ->when($request->status === 'paymentPending', fn ($q) =>
            $q->where('payment_status', 'pending')
        )
        ->when($request->status === 'paymentPaid', fn ($q) =>
            $q->where('payment_status', 'paid')
        )
        ->when($request->status === 'paymentPartial', fn ($q) =>
            $q->where('payment_status', 'partial')
        )
        ->when($request->status === 'ready_to_ship', fn ($q) =>
            $q->where('status', 'ready_to_ship')
        )
        ->when($request->status === 'shipped', fn ($q) =>
            $q->where('status', 'shipped')
        )
        ->when($request->status === 'delivered', fn ($q) =>
            $q->where('status', 'delivered')
        )
        ->orderBy('id', 'desc');
    }
}
