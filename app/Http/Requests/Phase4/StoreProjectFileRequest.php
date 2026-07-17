<?php

namespace App\Http\Requests\Phase4;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

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

        return [
            'file' => [
                'required',
                File::types(['pdf', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'webp', 'mp4', 'webm', 'mov', 'm4v', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip'])
                    ->max('10mb'),
                'extensions:pdf,txt,csv,jpg,jpeg,png,webp,mp4,webm,mov,m4v,doc,docx,xls,xlsx,ppt,pptx,zip',
            ],
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
}
