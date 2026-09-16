<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\{
    Order,
    OrderItem,
    Invoice,
    Product,
    Discount
};

use App\Helpers\{
    ResponseHelper
};
use App\Http\Requests\Api\V1\Order\{
    ProductOrderRequest,
    EditOrderRequest,
    AddMoreProductRequest,
    RemoveOrderItemRequest,
    CancelOrderRequest
};

use App\Http\Resources\Api\V1\Order\{
    OrderCollectionResource,
    OrderResource
};

use Carbon\Carbon;
use DB;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = $this->buildQuery($request);

        // Get paginated results based on current page
        $perPage = $request->per_page ?? 10;
        $page = $request->page ?? 1;

        // Manually paginate for export
        $orders = $query->paginate($perPage, ['*'], 'page', $page);
        $summary = $this->buildSummary($request);
        $totalAmount = $this->buildTotalAmount($request);
        $totalPartialDueAmount = $this->buildTotalDueAmount($request);

        $this->checkAndUpdatePaymentStatus();

        $dataCount = [
            "counts" => $summary,
            "totalAmount" => $totalAmount,
            "totalPartialDueAmount" => $totalPartialDueAmount,
        ];

        return ResponseHelper::success(OrderCollectionResource::collection($orders), 'Orders retrieved successfully', 200, $dataCount);
    }

    public function store(ProductOrderRequest $request)
    {

        $data = $request->validated();

        DB::beginTransaction();

        try {
            // Prepare order data
            $orderData = [
                'customer_id' => $data['customer'] ?? null,
                'sales_rep_id' => auth()->user()->id,
                'subtotal' => $data['subtotal'],
                'tax' => $data['tax'] ?? 0,
                'total' => $data['total'],
                'status' => 'pending'
            ];

            // Create order
            $order = Order::create($orderData);

            // Create order items
            foreach ($data['items'] as $item) {
                $itemData = [
                    'order_id' => $order->id,
                    'product_id' => (int) $item['id'],
                    'product_name' => (string) $item['name'],
                    'price' => (float) $item['salePrice'],
                    'quantity' => (int) $item['quantity'],
                    'total' => (float) $item['salePrice'] * (int) $item['quantity'],
                ];

                OrderItem::create($itemData);
            }

            DB::commit();

            $order->load(['customer', 'salesRep', 'items.product:id,thumbnail']);

            return ResponseHelper::success(new OrderResource($order), 'Order created successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return ResponseHelper::error($e->getMessage(), 500);
        }

    }

    public function show($id)
    {
        $order = Order::with(['items', 'customer', 'items.product:id,thumbnail'])->find($id);
        if (!$order) return ResponseHelper::error('Order not found', 404);
        return ResponseHelper::success(new OrderResource($order), 'Order retrieved successfully');
    }

    public function update(EditOrderRequest $request, $id)
    {
        $order = Order::find($id);

        if (!$order) return ResponseHelper::error('Order not found', 404);


        DB::beginTransaction();

        try {

            $data = $request->validated();

            // Prepare order data
            $orderData = [
                'subtotal' => $data['subtotal'],
                'tax' => $data['tax'] ?? 0,
                'total' => $data['total'],
                'modified_by_id' => auth()->user()->id,
            ];

            if($data['saveChange'] !== true && !$request->filled('isModify')){
                $orderData['status'] = 'confirmed';
            }

            // Order update
            $order->update($orderData);

            // Update order items
            foreach ($data['items'] as $itemData) {
                $order->items()->updateOrCreate(
                    ['id' => $itemData['id']],
                    [
                        'price' => $itemData['salePrice'],
                        'quantity' => $itemData['quantity'],
                        'total' => (float) $itemData['salePrice'] * (int)$itemData['quantity'],
                    ]
                );
            }

            /*
                Generate Invoice
            */
            // Prepare invoice data
            if (!$request->filled('isModify')) {
                $invoiceData = [
                    'order_id' => $order->id,
                    'subtotal' => $order->subtotal,
                    'tax' => $order->tax,
                    'total' => $order->total,
                    'due_date' => Carbon::now(),
                    'customer_id' => $order->customer_id,
                    'created_by_id' => auth()->user()->id,
                ];

                // Create invoice
                Invoice::create($invoiceData);

                // Update order invoice status
                $order->invoice_status = 'generated';
                $order->update();
            }

            if (!empty($data['discount']['type']) && !empty($data['discount']['value'])) {
                Discount::create([
                    "type" => $data['discount']['type'],
                    "value" => $data['discount']['value'],
                    "order_id" => $id
                ]);
            }

            DB::commit();

            $order->load(['customer', 'salesRep', 'items.product:id,thumbnail', 'invoice']);

            return ResponseHelper::success(new OrderResource($order), 'Order confirmed');

        } catch (\Exception $e) {
            DB::rollBack();
            return ResponseHelper::error($e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        $order = Order::find($id);
        if (!$order) return ResponseHelper::error('Order not found', 404);

        $order->delete();
        return ResponseHelper::success([],'Order deleted successfully');
    }

    public function cancelOrder($id, CancelOrderRequest $request){
        $order = Order::find($id);
        if (!$order) return ResponseHelper::error('Order not found', 404);

        try {
            $order->status = 'cancelled';
            $order->note = $request->note;
            $order->update();

            $order->load(['customer', 'salesRep', 'items.product:id,thumbnail']);

            return ResponseHelper::success($order,'Order canceled successfully');
        } catch (\Throwable $th) {
            return ResponseHelper::error($th->getMessage(), 500);
        }
    }

    // Order item
    public function addMoreOrderItem(AddMoreProductRequest $request, $id)
    {
        $order = Order::find($id);

        if (!$order) return ResponseHelper::error('Order not found', 404);

        DB::beginTransaction();

        try {

            $data = $request->validated();

            $product = Product::find($data['productId']);

            if (!$product) return ResponseHelper::error('Product not found', 404);

            $orderItem = [
                'product_id' => (int) $product->id,
                'product_name' => (string) $product->name,
                'price' => $product->sale_price,
                'quantity' => 1,
                'total' => (float) $product->sale_price
            ];

            // New order item add
            $order->items()->create($orderItem);

            DB::commit();

            $order->load(['customer', 'salesRep', 'items.product:id,thumbnail']);

            return ResponseHelper::success(new OrderResource($order), 'Product added to cart');

        } catch (\Exception $e) {
            DB::rollBack();
            return ResponseHelper::error($e->getMessage(), 500);
        }
    }

    public function removeOrderItem(RemoveOrderItemRequest $request, $id)
    {

        $order = Order::find($id);
        if (!$order) return ResponseHelper::error('Order not found', 404);

        $data = $request->validated();

        $orderItem = OrderItem::find($data['itemId']);
        if (!$orderItem) return ResponseHelper::error('Item not found', 404);

        if ((int) $orderItem->order_id !== (int) $order->id) return ResponseHelper::error('Item not found', 404);

        try {
            $orderItem->delete();

             $order->load(['customer', 'salesRep', 'items.product:id,thumbnail']);
            return ResponseHelper::success(new OrderResource($order), 'Product removed from cart');
        } catch (\Exception $e) {
            DB::rollBack();
            return ResponseHelper::error($e->getMessage(), 500);
        }
    }

    private function checkAndUpdatePaymentStatus(): void
    {
        Order::query()
            ->from('orders as o')

            // Paid amount
            ->leftJoinSub(
                DB::table('invoices as i')
                    ->leftJoin('payments as p', 'p.invoice_id', '=', 'i.id')
                    ->selectRaw('i.order_id, COALESCE(SUM(p.amount_paid), 0) as paid_amount')
                    ->groupBy('i.order_id'),
                'pay',
                'pay.order_id',
                '=',
                'o.id'
            )

            // Discount amount (FIXED: percentage + fixed logic)
            ->leftJoinSub(
                DB::table('discounts as d')
                    ->join('orders as o2', 'o2.id', '=', 'd.order_id')
                    ->selectRaw("
                        d.order_id,
                        COALESCE(
                            SUM(
                                CASE
                                    WHEN d.type = 'percentage'
                                        THEN (o2.total * d.value) / 100
                                    ELSE d.value
                                END
                            ), 0
                        ) as discount_amount
                    ")
                    ->groupBy('d.order_id'),
                'dis',
                'dis.order_id',
                '=',
                'o.id'
            )

            // Only partial orders
            ->where('o.payment_status', 'partial')

            // Safe comparison (float-safe)
            ->whereRaw('
                ROUND(
                    COALESCE(pay.paid_amount, 0), 2
                ) >= ROUND(
                    GREATEST(
                        0,
                        o.total - COALESCE(dis.discount_amount, 0) + COALESCE(o.tax, 0)
                    ),
                    2
                )
            ')

            ->update([
                'payment_status' => 'paid',
            ]);
    }

    private function baseQuery(Request $request)
    {
        return Order::query()
            ->with(['customer', 'salesRep', 'items.product:id,thumbnail'])

            ->when($request->search, function ($q) use ($request) {
                $search = $request->search;

                $q->where(function ($query) use ($search) {
                    if (preg_match('/^[1-9]/', $search)) {
                        $query->where('order_id', "ORD-{$search}");
                    } else {
                        $query->whereHas('customer', function ($customerQuery) use ($search) {
                            $customerQuery->where('name', 'like', "%{$search}%")
                                        ->orWhere('mobile_number', 'like', "%{$search}%");
                        });
                    }
                });
            })

            // Order Status
            ->when($request->status === 'pending', fn ($q) => $q->where('status', 'pending'))
            ->when($request->status === 'confirmed', fn ($q) => $q->where('status', 'confirmed'))
            ->when($request->status === 'cancelled', fn ($q) => $q->where('status', 'cancelled'))

            // Invoice Status
            ->when($request->status === 'invoicePending', fn ($q) => $q->where('invoice_status', 'pending'))
            ->when($request->status === 'invoiceGenerated', fn ($q) => $q->where('invoice_status', 'generated'))

            // Payment Status
            ->when($request->status === 'paymentPending',fn ($q) => $q->where('payment_status', 'pending')->where('status', '!=', 'cancelled')->where('status', '!=', 'pending'))
            ->when($request->status === 'paymentPaid', fn ($q) => $q->where('payment_status', 'paid'))
            ->when($request->status === 'paymentPartial', fn ($q) => $q->where('payment_status', 'partial'))

            // Waiting Approval — an order with a payment still awaiting sign-off.
            //
            // This also required payment_status = 'paid', while the counter
            // below counts on the pending payment alone. The tab therefore
            // promised more orders than the table delivered, and the gap was
            // not cosmetic: every one of the missing orders was 'partial' with
            // a real pending payment on it, so those payments could not be
            // reached from anywhere in the panel and sat unapproved.
            //
            // A part-paid order whose payment is pending is waiting approval in
            // exactly the same sense as a fully paid one, so the condition goes
            // and the two now agree.
            ->when($request->status === 'waitingApproval', function ($q) {
                $q->whereHas('payments', fn ($p) => $p->where('status', 'pending'));
            })

            // Date Filters
            ->when($request->fromDate && $request->toDate, fn ($q) =>
                $q->whereBetween('created_at', [
                    $request->fromDate . ' 00:00:00',
                    $request->toDate . ' 23:59:59'
                ])
            )
            ->when($request->fromDate && !$request->toDate, fn ($q) =>
                $q->whereDate('created_at', $request->fromDate)
            )
            ->when($request->toDate && !$request->fromDate, fn ($q) =>
                $q->whereDate('created_at', $request->toDate)
            );
    }

    private function buildQuery(Request $request)
    {
        return $this->baseQuery($request)
        ->orderBy('id', 'desc');
    }

    private function buildSummary(Request $request)
    {

        $query = Order::query();

        if(auth()->user()->hasRole('sales_representative')){
            $query->where('sales_rep_id', auth()->id());
        }

        // Get aggregated stats
        $stats = $query->selectRaw("
            COUNT(*) as all_count,

            SUM(status = 'pending') as pending,
            SUM(status = 'confirmed') as confirmed,
            SUM(status = 'cancelled') as cancelled,

            SUM(invoice_status = 'pending') as invoicePending,
            SUM(invoice_status = 'generated') as invoiceGenerated,

            SUM(payment_status = 'pending' AND status != 'cancelled') as paymentPending,
            SUM(payment_status = 'partial') as paymentPartial,
            SUM(payment_status = 'paid') as paymentPaid,

            SUM(
                EXISTS (
                    SELECT 1
                    FROM payments
                    WHERE payments.order_id = orders.id
                    AND payments.status = 'pending'
                )
            ) as waitingApproval
        ")->first();


        // Build summary array
        return [
            ['id' => 'all',              'label' => 'All Orders',        'value' => $stats->all_count],
            ['id' => 'pending',          'label' => 'Pending Orders',    'value' => $stats->pending],
            ['id' => 'confirmed',        'label' => 'Confirmed Orders',  'value' => $stats->confirmed],
            ['id' => 'invoicePending',   'label' => 'Invoice Pending',   'value' => $stats->invoicePending],
            ['id' => 'invoiceGenerated', 'label' => 'Invoice Generated', 'value' => $stats->invoiceGenerated],
            ['id' => 'paymentPending',   'label' => 'Payment Pending',   'value' => $stats->paymentPending],
            ['id' => 'paymentPartial',   'label' => 'Partial Payment',   'value' => $stats->paymentPartial],
            ['id' => 'paymentPaid',      'label' => 'Paid Payment',      'value' => $stats->paymentPaid],
            ['id' => 'waitingApproval', 'label' => 'Waiting Approval', 'value' => $stats->waitingApproval],
            ['id' => 'cancelled',        'label' => 'Cancelled Orders',  'value' => $stats->cancelled],
        ];
    }

    private function buildTotalAmount(Request $request)
    {
        $total = $this->baseQuery($request)
        ->toBase()
        ->sum('total');

        return money_format_bd(round((float) $total));
    }

    private function buildTotalDueAmount(Request $request)
    {
        $totalDue = $this->baseQuery($request)
            ->where('payment_status', 'partial')
            ->get()
            ->sum(function ($item) {

                $total = (float) $item->total;

                // calculate discount
                $discountAmount = $item->discounts->sum(function ($discount) use ($total) {
                    if ($discount->type === 'percentage') {
                        return ($total * (float) $discount->value) / 100;
                    }
                    return (float) $discount->value;
                });

                $totalAfterDiscount = $total - $discountAmount;

                $paidAmount = $item->invoice
                    ? (float) $item->invoice->payments->sum('amount_paid')
                    : 0;

                return round(max(0, $totalAfterDiscount - $paidAmount), 2);
            });

        return money_format_bd(round($totalDue));
    }

}
