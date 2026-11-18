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
        'approved_by',
        'approved_at',
        'status',
    ];

    /** The user who recorded this payment — the rep who collected the cash.
     *  The approval queue shows it, and it is what makes "somebody else signs
     *  it off" checkable. */
    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The administrator who decided this payment — approved it or refused it.
     *  Null while it is still waiting. */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** The control the approval queue exists to provide: the person who took
     *  the money must not be the person who confirmed it. */
    public function isSelfApproved(): bool
    {
        return $this->created_by !== null
            && $this->approved_by !== null
            && $this->created_by === $this->approved_by;
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
