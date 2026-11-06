<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ParcelRequest extends FormRequest
{
    /** Existing middleware checks rider role/ability; services resolve every owned parent. */
    public function authorize(): bool
    {
        return true;
    }

    /** Allowlists intake, list filters, outcomes or a separately reviewed delivery-verified location. */
    public function rules(): array
    {
        $action = $this->route()->getActionMethod();
        if ($action === 'recoverOutcome') {
            $action = 'outcome';
        }
        if ($action === 'location') {
            return [
                ...MapRequest::readingRules(),
                'gps_latitude' => ['required', 'numeric', 'between:-90,90'],
                'gps_longitude' => ['required', 'numeric', 'between:-180,180'],
                'review_confirmed' => ['required', 'accepted'],
                'replace_confirmed' => ['sometimes', 'boolean'],
                'expected_version' => ['required', 'integer', 'min:0'],
                'user_id' => ['prohibited'], 'address_id' => ['prohibited'], 'received_at' => ['prohibited'],
            ];
        }
        $protected = array_fill_keys(['user_id', 'rider_id', 'id', 'receipt', 'received_at', 'customer_total', 'seller_share', 'pricing', 'latitude', 'longitude'], ['prohibited']);
        if ($action === 'index') {
            return [
                'q' => ['sometimes', 'nullable', 'string', 'max:100'],
                'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
                'batch_id' => ['sometimes', 'integer', 'min:1'],
                'status' => ['sometimes', Rule::in(['open', 'ready', 'all', 'pending', 'held', 'failed', 'delivered', 'returned'])],
                'method' => ['sometimes', Rule::in(['all', 'cash', 'gcash'])],
            ];
        }
        if ($action === 'outcome') {
            $outcome = $this->input('outcome');

            return [
                ...$protected, 'operation_id' => ['required', 'uuid'],
                'parcel_ids' => ['required', 'array', 'min:1', 'max:100'],
                'parcel_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
                'outcome' => ['required', Rule::in(['delivered', 'held', 'failed', 'pending', 'returned'])],
                'note' => ['required', 'string', 'min:3', 'max:500'],
                'retry_at' => $outcome === 'held'
                    ? ['required', 'date_format:Y-m-d\TH:i:sP'] : ['prohibited'],
                'receipt_confirmed' => $outcome === 'delivered' ? ['required', 'accepted'] : ['prohibited'],
                'handover_confirmed' => $outcome === 'returned' ? ['required', 'accepted'] : ['prohibited'],
                'receipts' => $outcome === 'delivered' ? ['required', 'array', 'min:1', 'max:100'] : ['prohibited'],
                'receipts.*' => ['array:parcel_id,method,amount,reference'],
                'receipts.*.parcel_id' => ['required', 'integer', 'distinct', 'min:1'],
                'receipts.*.method' => ['required', Rule::in(['cash', 'gcash'])],
                'receipts.*.amount' => ['required', 'integer', 'between:1,1999999998'],
                'receipts.*.reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            ];
        }

        return [
            ...$protected, 'status' => ['prohibited'], 'attempts' => ['prohibited'],
            'operation_id' => ['required', 'uuid'],
            'batch_id' => ['required', 'integer', 'min:1'], 'customer_id' => ['required', 'integer', 'min:1'],
            'address_id' => ['required', 'integer', 'min:1'], 'seller_id' => ['required', 'integer', 'min:1'],
            'description' => ['sometimes', 'nullable', 'string', 'max:150'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'quote' => ['required', 'integer', 'between:1,999999999'],
            'fee' => ['required', 'integer', 'between:0,999999999', Rule::when($this->input('mode') === 'included', ['lte:quote'])],
            'mode' => ['required', Rule::in(['included', 'excluded'])],
            'expected_method' => ['required', Rule::in(['cash', 'gcash'])],
            'override_reason' => ['sometimes', 'nullable', 'string', 'min:3', 'max:400'],
        ];
    }

    /** Trims human text, never numeric types, timestamps or owner identifiers. */
    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['q', 'description', 'note', 'override_reason'] as $field) {
            if (is_string($this->input($field))) {
                $values[$field] = trim($this->input($field));
            }
        }
        $this->merge($values);
    }
}
