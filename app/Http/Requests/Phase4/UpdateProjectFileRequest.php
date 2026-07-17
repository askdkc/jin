<?php

namespace App\Http\Requests\Phase4;

use App\Models\Project;
use App\Models\StoredFile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateProjectFileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');
        $storedFile = $this->route('stored_file');

        return $project instanceof Project
            && $storedFile instanceof StoredFile
            && Gate::allows('view', $project)
            && ($storedFile->uploaded_by === $this->user()->id || Gate::allows('update', $project));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'original_name' => ['required', 'string', 'max:255'],
        ];
    }
}
