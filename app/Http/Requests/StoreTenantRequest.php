<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['slug' => $this->string('slug')->lower()->trim()->toString()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'min:'.config('tenantbase.slug.min'),
                'max:'.config('tenantbase.slug.max'),
                'regex:'.config('tenantbase.slug.pattern'),
                'not_in:'.implode(',', config('tenantbase.slug.reserved')),
                'unique:tenants,slug',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Use lowercase letters, digits and hyphens only, starting with a letter or digit.',
            'slug.not_in' => 'That address is reserved. Please pick another one.',
            'slug.unique' => 'That address is already taken.',
        ];
    }
}
