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

use App\Support\PaymentLedger;

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
                // The discount has to be recorded before the ledger is read,
                // otherwise the status is derived against the undiscounted
                // total and an order settled in full reads `partial`.
                if (!empty($data['discountType']) && !empty($data['discountValue']) && $data['discountValue'] > 0) {
                    Discount::create([
                        "type" => $data['discountType'],
                        "value" => $data['discountValue'],
                        "order_id" => $data['orderId']
                    ]);

                    $order->load('discounts');
                }

                // One source of truth — see App\Support\PaymentLedger. This
                // used to sum every payment row regardless of `status`, so a
                // payment the rep had only just recorded marked the order paid
                // before any administrator had seen it, and a payment the
                // administrator had rejected stayed counted as collected.
                $ledger = PaymentLedger::sync($order);

                \Log::info('Payment recorded', [
                    'order_id'  => $order->id,
                    'invoice_id'=> $invoice->id,
                    'payment_id'=> $payment->id,
                    'ledger'    => $ledger,
                ]);
            }

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
            $before = PaymentLedger::for($order);

            DB::transaction(function () use ($payment, $data, $order) {
                $payment->status = $data['status'];
                $payment->save();

                // A decision that does not reach the order's books is not a
                // decision. This line is why Reject now means something:
                // rejected money goes back into the due.
                PaymentLedger::sync($order->refresh());
            });

            $order->load(['customer', 'salesRep', 'items.product:id,thumbnail', 'discounts', 'invoice.payments']);

            $after = PaymentLedger::for($order);

            return ResponseHelper::success(
                [
                    'order'  => new OrderResource($order),
                    'before' => $before,
                    'after'  => $after,
                ],
                $data['status'] === 'paid'
                    ? ($after['fullyApproved'] ? 'Approved — this invoice is now settled in full' : 'Approved')
                    : 'Rejected — the amount has gone back into the due',
                201
            );

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
            $result = DB::transaction(function () use ($data) {
                // Which orders the decision touches, read before the update.
                $orderIds = Payment::whereIn('id', $data['ids'])
                    ->distinct()
                    ->pluck('order_id');

                $updated = Payment::whereIn('id', $data['ids'])->update([
                    'status'     => $data['status'],
                    'updated_at' => now(),
                ]);

                /* Every order the batch touched has to have its books rewritten,
                 * exactly as a single decision does. A bulk endpoint that skips
                 * this is how a queue of 258 payments gets signed off without
                 * one invoice changing state. */
                $ledgers = [];

                foreach (Order::whereIn('id', $orderIds)->with(['invoice', 'discounts', 'payments'])->get() as $order) {
                    $ledger = PaymentLedger::sync($order);

                    $ledgers[] = [
                        'orderId'        => $order->order_id,
                        'outlet'         => $order->customer?->name,
                        'total'          => $ledger['total'],
                        'approved'       => $ledger['approved'],
                        'due'            => $ledger['due'],
                        'paymentStatus'  => $ledger['paymentStatus'],
                        'approvalStatus' => $ledger['approvalStatus'],
                        'fullyApproved'  => $ledger['fullyApproved'],
                    ];
                }

                return ['updated' => $updated, 'orders' => $ledgers];
            });

            $settled = collect($result['orders'])->where('fullyApproved', true)->count();

            return ResponseHelper::success(
                [
                    'updated' => $result['updated'],
                    'status'  => $data['status'],
                    // So the screen can report what the batch did per invoice,
                    // not just how many rows it touched.
                    'orders'  => $result['orders'],
                ],
                $data['status'] === 'paid'
                    ? "{$result['updated']} payment(s) approved" . ($settled ? ", {$settled} invoice(s) settled in full" : '')
                    : "{$result['updated']} payment(s) rejected — the amounts are back in the due"
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
