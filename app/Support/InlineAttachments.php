<?php

namespace App\Support;

use App\Models\Project;
use App\Models\StoredFile;
use Illuminate\Database\Eloquent\Model;

class InlineAttachments
{
    /**
     * Link any project files dropped into a rich text body to the model that now references them.
     */
    public static function syncFromBody(Model $attachable, Project $project, ?string $body): void
    {
        if ($body === null || $body === '' || preg_match_all('/\/files\/(\d+)/', $body, $matches) === false) {
            return;
        }

        $fileIds = array_unique(array_map('intval', $matches[1] ?? []));

        if ($fileIds === []) {
            return;
        }

        StoredFile::query()
            ->where('project_id', $project->id)
            ->whereIn('id', $fileIds)
            ->whereNull('attachable_type')
            ->update([
                'attachable_type' => $attachable->getMorphClass(),
                'attachable_id' => $attachable->getKey(),
            ]);
    }
}
