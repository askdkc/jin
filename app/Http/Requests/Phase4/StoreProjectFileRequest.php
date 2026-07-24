<?php

namespace App\Http\Requests\Phase4;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class StoreProjectFileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && Gate::allows('view', $project);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        $fileTypes = ['pdf', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'webp', 'mp4', 'webm', 'mov', 'm4v', 'doc', 'docx', 'xls', 'xlsx', 'xlsm', 'ods', 'odt', 'ppt', 'pptx', 'odp', 'zip'];
        $extensions = 'extensions:'.implode(',', $fileTypes);

        return [
            'file' => [
                'required_without:files',
                File::types($fileTypes)->max((int) config('uploads.max_file_megabytes') * 1024),
                $extensions,
            ],
            'files' => ['required_without:file', 'array', 'max:'.(int) config('uploads.max_files_per_request')],
            'files.*' => [
                'required',
                File::types($fileTypes)->max((int) config('uploads.max_file_megabytes') * 1024),
                $extensions,
            ],
            'material_folder_id' => [
                'nullable',
                'integer',
                Rule::exists('material_folders', 'id')->where(
                    'project_id',
                    $project instanceof Project ? $project->id : 0,
                ),
            ],
            'folder_name' => [
                'nullable',
                'string',
                'max:255',
                Rule::prohibitedIf($this->filled('material_folder_id')),
            ],
        ];
    }

    /**
     * Get the after validation callables for the request.
     *
     * @return array<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $files = $this->file('files', []);
                $files = is_array($files) ? $files : [];
                $file = $this->file('file');

                if ($file instanceof UploadedFile) {
                    $files[] = $file;
                }

                $totalBytes = array_reduce(
                    $files,
                    fn (int $total, UploadedFile $upload): int => $total + (int) $upload->getSize(),
                    0,
                );
                $maxRequestMegabytes = (int) config('uploads.max_request_megabytes');

                if ($totalBytes <= $maxRequestMegabytes * 1024 * 1024) {
                    return;
                }

                $validator->errors()->add(
                    $this->hasFile('files') ? 'files' : 'file',
                    __('The total upload size may not be greater than :max megabytes.', [
                        'max' => $maxRequestMegabytes,
                    ]),
                );
            },
        ];
    }
}
