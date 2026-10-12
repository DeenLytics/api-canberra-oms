<?php

namespace App\Http\Resources\Api\V1\Order;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\V1\SalesRepresentative\{
    SalesRepresentativeResource
};

use App\Http\Resources\Api\V1\Customer\{
    CustomerResource
};

use App\Http\Resources\Api\V1\Order\{
    OrderItemCollectionResource
};

use App\Http\Resources\Api\V1\Shipping\{
    ShippingResource
};

use App\Http\Resources\Api\V1\Invoice\{
    InvoiceResource
};

use App\Http\Resources\Api\V1\Payment\{
    PaymentResource
};

use App\Support\PaymentLedger;
use Carbon\Carbon;

class OrderCollectionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $discountAmount = $this->discounts->sum(function($discount) {
            if ($discount->type === 'percentage') {
                return ((float) $this->total * (float) $discount->value) / 100;
            }
            return (float) $discount->value;
        });

        $totalAfterDiscount = round(max(0, $this->total - $discountAmount + $this->tax), 2);

        $ledger = PaymentLedger::for($this->resource);

        return [
            "id" => $this->id,
            "orderId" => $this->order_id,
            "email" => $this->email,
            "mobileNumber" => $this->mobile_number,
            "tax" => (float) $this->tax,
            "subtotal" => round((float) $this->subtotal,2),
            "discountAmount" => $discountAmount,
            "total" => $totalAfterDiscount,
            "status" => status_label($this->status),
            "paymentStatus" => $this->payment_status,
            "invoiceStatus" => $this->invoice_status,
            "invoiceGenerated" => $this->invoice_status === 'generated' ? true : false,
            'invoice'  => $this->invoice ? new InvoiceResource($this->invoice) : null,
            "customer" => new CustomerResource($this->customer),
            "salesRepresentative" => new SalesRepresentativeResource($this->salesRep),
            "items" => OrderItemCollectionResource::collection($this->items),
            'date' => $this->created_at
                ? Carbon::parse($this->created_at)->format('d M, Y h:i A')
                : null,
            /* Collected and due are derived from the payment rows, not from
             * every row's amount blindly summed.
             *
             * `payments->sum('amount_paid')` counted a rejected payment as
             * money in hand, so ORD-1478 (7,911.90) and ORD-1496 (1,488.00)
             * read paid with 0 due after an administrator had refused them,
             * and it counted a payment the rep had only just recorded as
             * collected before anyone had seen it. See App\Support\PaymentLedger. */
            'paidAmount' => round(max(0, $ledger['collected'] - $discountAmount), 2),
            'dueAmount'  => $this->invoice ? $ledger['due'] : null,

            /* The sign-off state, which is a different question from
             * paymentStatus: an order can be collected in full and entirely
             * unapproved. The approval screen reports against these. */
            'ledger' => [
                'total'          => $ledger['total'],
                'discount'       => $ledger['discount'],
                'approved'       => $ledger['approved'],
                'awaiting'       => $ledger['awaiting'],
                'rejected'       => $ledger['rejected'],
                'collected'      => $ledger['collected'],
                'due'            => $ledger['due'],
                'approvedDue'    => $ledger['approvedDue'],
                'paymentStatus'  => $ledger['paymentStatus'],
                'approvalStatus' => $ledger['approvalStatus'],
                'fullyApproved'  => $ledger['fullyApproved'],
            ],
            'payments' => $this->invoice
            ? PaymentResource::collection($this->invoice->payments)
            : [],
            'shipping' => new ShippingResource($this->shipping),
            'note' => $this->note,
        ];
    }
}
