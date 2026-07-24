<?php

namespace App\Support;

use App\Models\Card;
use App\Models\CheckInPrompt;
use App\Models\Comment;
use App\Models\Document;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\StoredFile;
use App\Models\Todo;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class InlineAttachments
{
    /**
     * Link any project files dropped into a rich text body to the model that now references them.
     */
    public static function syncFromBody(
        Model $attachable,
        Project $project,
        int $uploaderId,
        ?string $body,
    ): void {
        $matches = [];
        preg_match_all('/\/files\/(\d+)/', $body ?? '', $matches);
        $fileIds = array_values(array_unique(array_map('intval', $matches[1])));
        $morphClass = $attachable->getMorphClass();
        $attachableId = $attachable->getKey();

        $attachedInlineFiles = StoredFile::query()
            ->where('attachable_type', $morphClass)
            ->where('attachable_id', $attachableId)
            ->where('is_inline', true);

        if ($fileIds === []) {
            $attachedInlineFiles->update([
                'attachable_type' => null,
                'attachable_id' => null,
            ]);

            return;
        }

        $attachedInlineFiles
            ->whereNotIn('id', $fileIds)
            ->update([
                'attachable_type' => null,
                'attachable_id' => null,
            ]);

        $claimableFileIds = StoredFile::query()
            ->where('project_id', $project->id)
            ->where('uploaded_by', $uploaderId)
            ->where('is_inline', true)
            ->whereIn('id', $fileIds)
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        StoredFile::query()
            ->whereIn('id', $claimableFileIds)
            ->whereNull('attachable_type')
            ->whereNull('attachable_id')
            ->where(function (Builder $query): void {
                $query->whereNull('preview_status')
                    ->orWhere('preview_status', '!=', 'deleting');
            })
            ->update([
                'attachable_type' => $morphClass,
                'attachable_id' => $attachableId,
            ]);

        $attachedFileIds = StoredFile::query()
            ->whereIn('id', $claimableFileIds)
            ->where('attachable_type', $morphClass)
            ->where('attachable_id', $attachableId)
            ->where(function (Builder $query): void {
                $query->whereNull('preview_status')
                    ->orWhere('preview_status', '!=', 'deleting');
            })
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        if (array_diff($claimableFileIds, $attachedFileIds) !== []) {
            throw ValidationException::withMessages([
                (self::bodyAttribute($attachable) ?? 'attachments') => __('One or more inline attachments are no longer available. Remove them and try again.'),
            ]);
        }
    }

    public static function detach(Model $attachable): void
    {
        self::detachMany($attachable->getMorphClass(), [$attachable->getKey()]);
    }

    /** @param iterable<int, int|string|null> $attachableIds */
    public static function detachMany(string $morphClass, iterable $attachableIds): void
    {
        $attachableIds = collect($attachableIds)->filter(fn ($id) => $id !== null)->values();

        if ($attachableIds->isEmpty()) {
            return;
        }

        StoredFile::query()
            ->where('attachable_type', $morphClass)
            ->whereIn('attachable_id', $attachableIds)
            ->update([
                'attachable_type' => null,
                'attachable_id' => null,
            ]);
    }

    public static function removeFromBody(StoredFile $file): void
    {
        $attachable = $file->attachable;
        $attribute = $attachable instanceof Model ? self::bodyAttribute($attachable) : null;

        if ($attribute === null) {
            return;
        }

        $body = $attachable->getAttribute($attribute);
        $needle = "/files/{$file->id}/";

        if (! is_string($body) || ! str_contains($body, $needle)) {
            return;
        }

        $cleaned = self::removeFileReferences($body, $needle);

        if ($cleaned !== $body) {
            $attachable->setAttribute($attribute, $cleaned)->save();
        }
    }

    public static function withoutFileReferences(?string $body): ?string
    {
        if ($body === null || ! str_contains($body, '/files/')) {
            return $body;
        }

        return self::removeFileReferences($body);
    }

    private static function bodyAttribute(Model $attachable): ?string
    {
        return match (true) {
            $attachable instanceof Document => 'body',
            $attachable instanceof ScheduleEvent => 'details',
            $attachable instanceof Card => 'description',
            $attachable instanceof CheckInPrompt => 'answer',
            $attachable instanceof Todo => 'details',
            $attachable instanceof Comment, $attachable instanceof MessageBoardPost => 'body',
            default => null,
        };
    }

    private static function removeFileReferences(string $body, ?string $needle = null): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            "<?xml encoding=\"UTF-8\"><body>{$body}</body>",
            LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        if (! $loaded) {
            return $body;
        }

        $nodes = (new DOMXPath($document))->query('//*[@href or @src]');

        if ($nodes === false) {
            return $body;
        }

        foreach (iterator_to_array($nodes) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $reference = $node->getAttribute('href').$node->getAttribute('src');

            if ($needle === null
                ? preg_match('/\/files\/\d+/', $reference) !== 1
                : ! str_contains($reference, $needle)) {
                continue;
            }

            $removable = $node;

            while ($removable->parentNode instanceof DOMElement
                && (in_array($removable->parentNode->tagName, ['a', 'figure'], true)
                    || ($removable->parentNode->tagName === 'p' && $removable->parentNode->childNodes->count() === 1))) {
                $removable = $removable->parentNode;
            }

            $removable->parentNode?->removeChild($removable);
        }

        $root = $document->getElementsByTagName('body')->item(0);

        if (! $root instanceof DOMElement) {
            return $body;
        }

        $cleaned = '';

        foreach (iterator_to_array($root->childNodes) as $node) {
            $cleaned .= $document->saveHTML($node);
        }

        return $cleaned;
    }
}
