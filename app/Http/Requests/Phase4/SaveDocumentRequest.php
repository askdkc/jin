<?php

namespace App\Http\Requests\Phase4;

use App\Models\Document;
use App\Models\Project;
use App\Support\RichText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');
        $document = $this->route('document');

        if (! $project instanceof Project || ! Gate::allows('view', $project)) {
            return false;
        }

        return ! $document instanceof Document
            || $document->created_by === $this->user()->id
            || Gate::allows('update', $project);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $project = $this->route('project');

        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:50000'],
            'material_folder_id' => [
                'nullable',
                'integer',
                Rule::exists('material_folders', 'id')->where(
                    'project_id',
                    $project instanceof Project ? $project->id : 0,
                ),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['body' => RichText::sanitizeInput($this->input('body'))]);
    }
}
