<?php

namespace App\Http\Requests\Api\V1\Payment;

use Illuminate\Foundation\Http\FormRequest;

class EditPaymentStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The column is an enum. 'required' alone let any string through to
            // MySQL, which either rejects it with a driver error or silently
            // truncates depending on strict mode — neither belongs on a payment.
            'status' => 'required|in:pending,processing,partial,paid,failed',
            'note'   => 'nullable|string|max:500',
        ];
    }
}
