<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Payment;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\Discount;
use App\Helpers\{
    ResponseHelper,
    PaymentHelper
};
use App\Http\Requests\Api\V1\Payment\{
    CreatePaymentRequest,
    EditPaymentRequest,
    EditPaymentStatusRequest
};

use App\Http\Resources\Api\V1\Order\{
    OrderResource,
};

use DB;

class PaymentController extends Controller
{
    public function index()
    {
        // $payments = Payment::get();
        return ResponseHelper::success(null, 'Payments retrieved successfully');
    }

    public function store(CreatePaymentRequest $request)
    {

        $data = $request->validated();

        $order = Order::find($data['orderId']);
        if (!$order) return ResponseHelper::error('Order not found', 404);

        $invoice = Invoice::where('order_id', $order->id)->first();
        if (!$invoice) return ResponseHelper::error('Invoice not found', 404);

        DB::beginTransaction();

        try {
            // Prepare payment data
            $paymentData = [
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'customer_id' => $order->customer_id,
                'method' => $data['method'],
                'amount' => $invoice->total,
                'amount_paid' => $data['amount'],
                'description' => $data['description'],
                'payment_date' => $data['date'],
            ];

            // Create payment
            $payment = Payment::create($paymentData);

            if ($payment) {
                // Total paid so far
                $totalPaid = Payment::where('invoice_id', $invoice->id)
                    ->sum('amount_paid');

                $totalPaid = round($totalPaid, 2);

                // -------------------------
                // Calculate discount
                // -------------------------
                $discountAmount = Discount::where('order_id', $order->id)
                    ->get()
                    ->sum(function ($discount) use ($invoice) {
                        return $discount->type === 'percentage'
                            ? ($invoice->total * $discount->value) / 100
                            : $discount->value;
                    });

                $discountAmount = round($discountAmount, 2);

                // -------------------------
                // Final total
                // -------------------------
                $finalTotal = round(max(0, $invoice->total - $discountAmount), 2);

                // -------------------------
                // Determine status (reusable logic)
                // -------------------------
                $status = function ($paid, $total) {
                    if ($paid <= 0) {
                        return 'pending';
                    }

                    if ($paid < $total) {
                        return 'partial';
                    }

                    return 'paid';
                };

                // -------------------------
                // Update invoice
                // -------------------------
                $invoice->status = $status($totalPaid, $finalTotal);
                $invoice->save();

                // -------------------------
                // Update order
                // -------------------------
                $order->payment_status = $status($totalPaid, $finalTotal);
                $order->save();


                if (!empty($data['discountType']) && !empty($data['discountValue']) && $data['discountValue'] > 0) {
                    Discount::create([
                        "type" => $data['discountType'],
                        "value" => $data['discountValue'],
                        "order_id" => $data['orderId']
                    ]);
                }
            }

            \Log::info('Payment Status Debug', [
                'invoice_id' => $invoice->id,
                'order_id' => $order->id,
                'invoice_total' => $invoice->total,
                'total_paid' => $totalPaid,
                'discount_amount' => $discountAmount,
                'final_total' => $finalTotal,
                'difference' => round($finalTotal - $totalPaid, 6),
            ]);

            DB::commit();

            $order->load(['customer', 'salesRep', 'items.product:id,thumbnail', 'discounts']);

            return ResponseHelper::success(new OrderResource($order), 'Payment successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return ResponseHelper::error($e->getMessage(), 500);
        }

    }

    /**
     * Approving a payment is the one place in this system where separation of
     * duties matters: the rep collects the cash, somebody else signs it off.
     *
     * There is no role middleware anywhere in this API, EditPaymentStatusRequest
     * authorized everything, and the only guard on the route was auth:sanctum —
     * so the sales representative who recorded a payment could mark it approved
     * himself, from the SR app's own token. That is the whole control the
     * "Waiting Approval" queue exists to provide.
     */
    private function assertMayApprove(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->hasAnyRole(['super_admin', 'admin'])) {
            return ResponseHelper::error('Only an administrator can approve a payment.', 403);
        }

        return null;
    }

    public function updateStatus(EditPaymentStatusRequest $request, $id){
        if ($denied = $this->assertMayApprove($request)) return $denied;

        $data = $request->validated();

        $payment = Payment::find($id);
        if (!$payment) return ResponseHelper::error('Payment not found', 404);

        $order = Order::where('id', $payment->order_id)->first();
        if (!$order) return ResponseHelper::error('Order not found', 404);

        try {
            $payment->status = $data['status'];
            $payment->save();

            $order->load(['customer', 'salesRep', 'items.product:id,thumbnail']);

            return ResponseHelper::success(new OrderResource($order), 'Payment approved', 201);

        } catch (\Exception $e) {
            return ResponseHelper::error($e->getMessage(), 500);
        }
    }

    /**
     * Approve or reject several payments at once.
     *
     * The queue routinely holds a couple of hundred payments and the only way
     * through it was one request per payment, ten rows to a page. That is 258
     * clicks across 26 pages for a single day's sign-off, so the work did not
     * get done and the queue grew.
     */
    public function bulkUpdateStatus(Request $request)
    {
        if ($denied = $this->assertMayApprove($request)) return $denied;

        $data = $request->validate([
            'ids'    => 'required|array|min:1|max:500',
            'ids.*'  => 'integer',
            'status' => 'required|in:pending,processing,partial,paid,failed',
        ]);

        try {
            $updated = DB::transaction(function () use ($data) {
                return Payment::whereIn('id', $data['ids'])->update([
                    'status'     => $data['status'],
                    'updated_at' => now(),
                ]);
            });

            return ResponseHelper::success(
                ['updated' => $updated, 'status' => $data['status']],
                "{$updated} payment(s) updated"
            );

        } catch (\Exception $e) {
            return ResponseHelper::error($e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        // $order = Payment::with(['items', 'customer', 'items.product:id,thumbnail'])->find($id);
        // if (!$order) return ResponseHelper::error('Payment not found', 404);
        // return ResponseHelper::success(new PaymentResource($order), 'Payment retrieved successfully');
    }

    public function update(EditPaymentRequest $request, $id)
    {
        // $order = Payment::find($id);

        // if (!$order) return ResponseHelper::error('Payment not found', 404);


        // DB::beginTransaction();

        // try {

        //     $data = $request->validated();

        //     // Prepare order data
        //     $orderData = [
        //         'subtotal' => $data['subtotal'],
        //         'tax' => $data['tax'] ?? 0,
        //         'total' => $data['total'],
        //         'modified_by_id' => auth()->user()->id,
        //     ];

        //     if($data['saveChange'] !== true){
        //         $orderData['status'] = 'confirmed';
        //     }

        //     // Payment update
        //     $order->update($orderData);

        //     // Update order items
        //     foreach ($data['items'] as $itemData) {
        //         $order->items()->updateOrCreate(
        //             ['id' => $itemData['id']],
        //             [
        //                 'price' => $itemData['salePrice'],
        //                 'quantity' => $itemData['quantity'],
        //                 'total' => (float) $itemData['salePrice'] * (int)$itemData['quantity'],
        //             ]
        //         );
        //     }

        //     DB::commit();

        //     $order->load(['customer', 'salesRep', 'items.product:id,thumbnail']);

        //     return ResponseHelper::success(new PaymentResource($order), 'Payment confirmed successfully');

        // } catch (\Exception $e) {
        //     DB::rollBack();
        //     return ResponseHelper::error($e->getMessage(), 500);
        // }
    }

    public function destroy($id)
    {
        // $order = Payment::find($id);
        // if (!$order) return ResponseHelper::error('Payment not found', 404);

        // $order->delete();
        // return ResponseHelper::success('Payment deleted successfully');
    }
}
