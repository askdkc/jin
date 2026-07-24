<?php

namespace App\Http\Requests\Phase4;

use App\Models\Project;
use App\Models\StoredFile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\File;

class ReplaceProjectFileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');
        $storedFile = $this->route('stored_file');

        if (! $project instanceof Project || ! Gate::allows('view', $project)) {
            return false;
        }

        return $storedFile instanceof StoredFile
            && ($storedFile->uploaded_by === $this->user()->id || Gate::allows('update', $project));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $fileTypes = ['pdf', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'webp', 'mp4', 'webm', 'mov', 'm4v', 'doc', 'docx', 'xls', 'xlsx', 'xlsm', 'ods', 'odt', 'ppt', 'pptx', 'odp', 'zip'];
        $storedFile = $this->route('stored_file');
        $extension = $storedFile instanceof StoredFile
            ? strtolower(pathinfo($storedFile->original_name, PATHINFO_EXTENSION))
            : '';

        return [
            'file' => [
                'required',
                File::types($fileTypes)->max((int) config('uploads.max_file_megabytes') * 1024),
                'extensions:'.$extension,
            ],
        ];
    }
}
