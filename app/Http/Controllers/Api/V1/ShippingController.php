<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Shipping;
use App\Http\Requests\Api\V1\Shipping\{
    CreateShippingRequest,
};
use App\Http\Resources\Api\V1\Order\{
    OrderCollectionResource,
    OrderResource
};

use App\Helpers\{
    ResponseHelper
};

use DB;

class ShippingController extends Controller
{
    public function index(Request $request)
    {
        // $orders = Order::whereIn('status',  [
        //     'confirmed',
        //     'ready_to_ship',
        //     'shipped',
        //     'delivered',
        // ])->with(['customer', 'items.product:id,thumbnail'])->orderBy('id', 'desc')->get();
        // return ResponseHelper::success(OrderCollectionResource::collection($orders), 'Orders retrieved successfully');


        $query = $this->buildQuery($request);

        // Get paginated results based on current page
        $perPage = $request->per_page ?? 10;
        $page = $request->page ?? 1;

        // Manually paginate for export
        $orders = $query->paginate($perPage, ['*'], 'page', $page);

        $stats = Order::selectRaw("
            SUM(status IN ('confirmed','ready_to_ship','shipped','delivered')) as all_count,
            SUM(status = 'confirmed') as confirmed,
            SUM(status = 'ready_to_ship') as ready_to_ship,
            SUM(status = 'shipped') as shipped,
            SUM(status = 'delivered') as delivered,
            SUM(status = 'cancelled') as cancelled
        ")->first();

        $dataCount = [
            ['id' => 'all',              'label' => 'All Orders',        'value' => $stats->all_count],
            ['id' => 'confirmed',        'label' => 'Confirmed Orders',  'value' => $stats->confirmed],
            ['id' => 'ready_to_ship',    'label' => 'Ready To Ship',  'value' => $stats->ready_to_ship],
            ['id' => 'shipped',          'label' => 'Shipped',   'value' => $stats->shipped],
            ['id' => 'delivered',        'label' => 'Delivered', 'value' => $stats->delivered],
            ['id' => 'cancelled',        'label' => 'Cancelled', 'value' => $stats->cancelled]
        ];

        return ResponseHelper::success(OrderCollectionResource::collection($orders), 'Orders retrieved successfully', 200, ['counts' => $dataCount]);
    }

    public function readyToShip($id)
    {
        $order = Order::find($id);

        if (!$order) return ResponseHelper::error('Order not found', 404);
        if ($order->status != 'confirmed') {
            return ResponseHelper::error('Order is not confirmed', 400);
        }

        try {
            $order->status = 'ready_to_ship';
            $order->save();
            return ResponseHelper::success(new OrderResource($order), 'Order status updated to ready to ship successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error($e->getMessage(), 500);
        }
    }

    public function store(CreateShippingRequest $request, $id)
    {
        $order = Order::find($id);

        if (!$order) return ResponseHelper::error('Order not found', 404);
        if ($order->status != 'ready_to_ship') {
            return ResponseHelper::error('Order is not ready to ship', 400);
        }

        try {
            Shipping::create([
                'estimated_delivery_date' => $request->date,
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'created_by' => auth()->user()->id,
                'note' => $request->note,
                'status' => 'shipped',
            ]);
            $order->status = 'shipped';
            $order->save();

            return ResponseHelper::success(new OrderResource($order), 'Order shipped successfully');

        } catch (\Exception $e) {
            return ResponseHelper::error($e->getMessage(), 500);
        }
    }

    public function delivered($id)
    {
        $order = Order::find($id);

        if (!$order) {
            return ResponseHelper::error('Order not found', 404);
        }

        if ($order->status !== 'shipped') {
            return ResponseHelper::error('Order is not shipped yet', 400);
        }

        if (!$order->shipping) {
            return ResponseHelper::error('Shipping record not found for this order', 404);
        }

        DB::beginTransaction();

        try {
            $order->status = 'delivered';
            $order->save();

            $order->shipping->status = 'delivered';
            $order->shipping->delivery_date = now()->toDateString();
            $order->shipping->save();

            DB::commit();

            return ResponseHelper::success(
                new OrderResource($order),
                'Order status updated to delivered successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ResponseHelper::error($e->getMessage(), 500);
        }
    }

    private function buildQuery(Request $request)
    {
        return Order::whereIn('status',  [
            'confirmed',
            'ready_to_ship',
            'shipped',
            'delivered',
            'cancelled',
        ])
        ->when($request->search, fn ($q) =>
            $q->where('order_id', 'like', "%".$request->search."%")
        )
        ->when($request->status === 'confirmed', fn ($q) =>
            $q->where('status', 'confirmed')
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
        ->when($request->status === 'cancelled', fn ($q) =>
            $q->where('status', 'cancelled')
        )
        ->with(['customer', 'items.product:id,thumbnail', 'invoice', 'payments', 'discounts'])
        ->orderBy('id', 'desc');
    }

}
