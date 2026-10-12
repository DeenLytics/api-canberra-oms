<?php

namespace App\Http\Resources\Api\V1\Payment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use Carbon\Carbon;

class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // return parent::toArray($request);
        return [
            'id' => $this->id,
            'transactionNumber' => $this->transaction_number,
            'amount' => round((float) $this->amount_paid,2),
            'method' => $this->method,
            'status' => $this->status,
            'date' => $this->payment_date
                ? Carbon::parse($this->payment_date)->format('d M, Y')
                : null,
            'description' => $this->description,
            // The approval queue has to say who collected this and what
            // reference the shop was given — without them an admin is approving
            // a number with no provenance.
            'ref' => $this->ref,
            'collectedBy' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->full_name),
            'recordedAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
