<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Payment extends Model
{
    protected $fillable = [
        'transaction_number',
        'order_id',
        'invoice_id',
        'customer_id',
        'method',
        'amount',
        'amount_paid',
        'currency',
        'description',
        'ref',
        'payment_date',
        'created_by',
        'status',
    ];

    /** The user who recorded this payment — the rep who collected the cash.
     *  The approval queue shows it, and it is what makes "somebody else signs
     *  it off" checkable. */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($payment) {
            if (!$payment->transaction_number) {
                // Use invoice ID instead of date
                $invoiceId = $payment->invoice_id ?? '0';

                // Find last transition number created today
                $lastPayment = self::whereDate('created_at', today())
                    ->latest('id')
                    ->value('transaction_number');

                $sequence = 1;
                if ($lastPayment) {
                    $lastSequence = (int) substr($lastPayment, -3); // last 3 digits
                    $sequence = $lastSequence + 1;
                }

                $sequence = str_pad($sequence, 3, '0', STR_PAD_LEFT);

                $payment->transaction_number = "TRN-{$invoiceId}-{$sequence}";
            }
        });
    }

    // Relationships
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
