<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MapRequest extends FormRequest
{
    /** Middleware enforces Bearer, rider role and token abilities. */
    public function authorize(): bool
    {
        return true;
    }

    /** Validate a fresh origin or bounded tile; targets/profiles belong to the server. */
    public function rules(): array
    {
        if ($this->route()->getActionMethod() === 'tile') {
            $zoom = min(18, max(0, (int) $this->route('z')));

            return ['z' => ['required', 'integer', 'between:0,18'],
                'x' => ['required', 'integer', 'between:0,'.((2 ** $zoom) - 1)],
                'y' => ['required', 'integer', 'between:0,'.((2 ** $zoom) - 1)]];
        }

        return [...self::readingRules(), 'mode' => ['prohibited'], 'vehicle' => ['prohibited'],
            'targets' => ['prohibited'], 'sources' => ['prohibited'], 'apiKey' => ['prohibited']];
    }

    /** Reuse bounds/freshness for temporary rider GPS and delivery pin review. */
    public static function readingRules(): array
    {
        return ['latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'between:0,10000'],
            'captured_at' => ['required', 'date', 'after_or_equal:'.now()->subMinutes(5)->toIso8601String(), 'before_or_equal:'.now()->addSeconds(15)->toIso8601String()]];
    }

    /** Path coordinates always win over conflicting query parameters. */
    protected function prepareForValidation(): void
    {
        if ($this->route()->getActionMethod() === 'tile') {
            $this->merge(['z' => $this->route('z'), 'x' => $this->route('x'), 'y' => $this->route('y')]);
        }
    }
}
