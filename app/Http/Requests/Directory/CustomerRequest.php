<?php

declare(strict_types=1);

namespace App\Http\Requests\Directory;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

class CustomerRequest extends FormRequest
{
    /** Middleware checks role/ability; services scope every customer and address to its rider. */
    public function authorize(): bool
    {
        return true;
    }

    /** Allows customer identity or address metadata according to the requested action. */
    public function rules(): array
    {
        $action = $this->route()->getActionMethod();
        if ($action === 'index') {
            return [
                'q' => ['sometimes', 'nullable', 'string', 'max:100'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'between:1,100'],
                'archived' => ['sometimes', 'boolean'],
            ];
        }

        $readOnly = [
            'id' => ['prohibited'], 'user_id' => ['prohibited'], 'rider_id' => ['prohibited'],
            'customer_id' => ['prohibited'], 'addresses' => ['prohibited'],
            'latitude' => ['prohibited'], 'longitude' => ['prohibited'], 'verified_at' => ['prohibited'],
            'deleted_at' => ['prohibited'], 'archived_at' => ['prohibited'],
        ];
        $presence = in_array($action, ['update', 'updateAddress'], true) ? ['sometimes', 'required'] : ['required'];

        if ($action === 'storeAddress' || $action === 'updateAddress') {
            return [
                ...$readOnly,
                'label' => [...$presence, 'string', 'max:50'],
                'text' => ['sometimes', 'nullable', 'string', 'max:300'],
            ];
        }

        $rules = [
            ...$readOnly,
            'name' => [...$presence, 'string', 'min:2', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ];

        if ($action === 'store') {
            $rules += [
                'address' => ['sometimes', 'array:label,text', 'min:1'],
                'address.label' => ['required_with:address', 'string', 'max:50'],
                'address.text' => ['sometimes', 'nullable', 'string', 'max:300'],
            ];
        } else {
            $rules['address'] = ['prohibited'];
        }

        return $rules;
    }

    /** Trims directory inputs while preserving empty phone/address values as null. */
    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['name', 'phone', 'label', 'text', 'q'] as $field) {
            if (is_string($this->input($field))) {
                $trimmed = trim($this->input($field));
                $values[$field] = $trimmed === '' && $field !== 'name' && $field !== 'label' ? null : $trimmed;
            }
        }
        if (is_string($this->input('phone'))) {
            $values['phone'] = Customer::normalizePhone($this->input('phone'));
        }
        $address = $this->input('address');
        if (is_array($address)) {
            foreach (['label', 'text'] as $field) {
                if (isset($address[$field]) && is_string($address[$field])) {
                    $address[$field] = trim($address[$field]);
                    if ($field === 'text' && $address[$field] === '') {
                        $address[$field] = null;
                    }
                }
            }
            $values['address'] = $address;
        }
        $this->merge($values);
    }
}
