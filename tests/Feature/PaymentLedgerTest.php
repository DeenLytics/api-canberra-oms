<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Support\PaymentLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create(['name' => 'Test Outlet', 'shop_name' => 'Test Shop',
            'mobile_number' => '01700000001', 'address' => 'Dhaka', 'status' => 'active']);
    }

    private function invoicedOrder(): Order
    {
        $order = Order::create(['customer_id' => $this->customer()->id, 'subtotal' => 1000, 'total' => 1000, 'status' => 'delivered', 'invoice_status' => 'generated']);
        Invoice::create(['invoice_number' => 'INV-T-' . $order->id, 'order_id' => $order->id, 'customer_id' => $order->customer_id,
            'subtotal' => 1000, 'tax' => 0, 'discount' => 0, 'total' => 1000, 'issue_date' => now(), 'status' => 'unpaid']);

        return $order;
    }

    private function pay(Order $order, float $amount, string $status): Payment
    {
        return Payment::create(['transaction_number' => 'TRN-' . uniqid(), 'method' => 'cash', 'payment_date' => now(), 'amount' => $order->total,
            'amount_paid' => $amount, 'order_id' => $order->id, 'customer_id' => $order->customer_id, 'invoice_id' => $order->invoice()->value('id'), 'status' => $status]);
    }

    public function test_rejecting_the_only_payment_leaves_the_invoice_unpaid(): void
    {
        $order = $this->invoicedOrder();
        $payment = $this->pay($order, 1000, 'pending');
        PaymentLedger::sync($order->fresh());
        $this->assertSame('paid', $order->invoice()->value('status'));

        $payment->update(['status' => 'failed']);
        $ledger = PaymentLedger::sync($order->fresh());

        // The order reads `pending`; the invoice has no such state.
        $this->assertSame('pending', $ledger['paymentStatus']);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame('unpaid', $order->invoice()->value('status'));
    }

    public function test_a_part_payment_marks_both_partial(): void
    {
        $order = $this->invoicedOrder();
        $this->pay($order, 400, 'paid');
        PaymentLedger::sync($order->fresh());

        $this->assertSame('partial', $order->fresh()->payment_status);
        $this->assertSame('partial', $order->invoice()->value('status'));
    }
}
