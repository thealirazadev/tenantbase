<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $project = $this->route('project');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                // Row-level security scopes this lookup to the active tenant,
                // so the same name in another workspace is not a conflict.
                Rule::unique('projects', 'name')
                    ->ignore($project instanceof Project ? $project->getKey() : null),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A project with that name already exists in this workspace.',
        ];
    }
}
