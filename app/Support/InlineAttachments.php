<?php

namespace App\Support;

use App\Models\Card;
use App\Models\ChatMessage;
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
use Illuminate\Support\Facades\DB;
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
        $fileIds = self::referencedFileIds($body);
        $morphClass = $attachable->getMorphClass();
        $attachableId = $attachable->getKey();

        DB::transaction(function () use ($attachable, $project, $uploaderId, $fileIds, $morphClass, $attachableId): void {
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

            $filesById = [];

            foreach (StoredFile::query()->whereIn('id', $fileIds)->lockForUpdate()->get() as $file) {
                $filesById[$file->id] = $file;
            }

            if (count($filesById) !== count($fileIds)) {
                self::throwUnavailable($attachable);
            }

            $inlineFileIds = [];
            $claimableFileIds = [];

            foreach ($fileIds as $fileId) {
                $file = $filesById[$fileId];

                if (! $file->is_inline) {
                    continue;
                }

                $inlineFileIds[] = $fileId;

                if ($file->project_id !== $project->id || $file->preview_status === 'deleting') {
                    self::throwUnavailable($attachable);
                }

                $isAttachedToTarget = $file->attachable_type === $morphClass
                    && $file->attachable_id === $attachableId;

                if ($isAttachedToTarget) {
                    continue;
                }

                $isUnattached = $file->attachable_type === null && $file->attachable_id === null;

                if (! $isUnattached || $file->uploaded_by !== $uploaderId) {
                    self::throwUnavailable($attachable);
                }

                $claimableFileIds[] = $fileId;
            }

            $attachedInlineFiles
                ->whereNotIn('id', $inlineFileIds)
                ->update([
                    'attachable_type' => null,
                    'attachable_id' => null,
                ]);

            if ($claimableFileIds !== []) {
                $claimed = StoredFile::query()
                    ->whereIn('id', $claimableFileIds)
                    ->where('project_id', $project->id)
                    ->where('uploaded_by', $uploaderId)
                    ->where('is_inline', true)
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

                if ($claimed !== count($claimableFileIds)) {
                    self::throwUnavailable($attachable);
                }
            }

            if ($inlineFileIds !== []) {
                $attached = StoredFile::query()
                    ->whereIn('id', $inlineFileIds)
                    ->where('project_id', $project->id)
                    ->where('is_inline', true)
                    ->where('attachable_type', $morphClass)
                    ->where('attachable_id', $attachableId)
                    ->where(function (Builder $query): void {
                        $query->whereNull('preview_status')
                            ->orWhere('preview_status', '!=', 'deleting');
                    })
                    ->count();

                if ($attached !== count($inlineFileIds)) {
                    self::throwUnavailable($attachable);
                }
            }
        });
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

        $cleaned = self::removeFileReferences($body, $file->id);

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
            $attachable instanceof ChatMessage, $attachable instanceof Comment, $attachable instanceof MessageBoardPost => 'body',
            default => null,
        };
    }

    /** @return list<int> */
    private static function referencedFileIds(?string $body): array
    {
        if ($body === null || ! str_contains($body, '/files/')) {
            return [];
        }

        $document = self::loadDocument($body);

        if (! $document instanceof DOMDocument) {
            return [];
        }

        $nodes = (new DOMXPath($document))->query('//*[@href or @src]');

        if ($nodes === false) {
            return [];
        }

        $fileIds = [];

        foreach (iterator_to_array($nodes) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            foreach (['href', 'src'] as $attribute) {
                if (! $node->hasAttribute($attribute)) {
                    continue;
                }

                $fileId = self::fileIdFromReference($node->getAttribute($attribute));

                if ($fileId !== null) {
                    $fileIds[] = $fileId;
                }
            }
        }

        return array_values(array_unique($fileIds));
    }

    private static function fileIdFromReference(string $reference): ?int
    {
        $reference = trim($reference);

        if (! str_starts_with($reference, '/') || str_starts_with($reference, '//')) {
            return null;
        }

        $path = parse_url($reference, PHP_URL_PATH);

        if (! is_string($path)
            || preg_match('~(?:^|/)files/([1-9][0-9]*)/preview/?$~', $path, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private static function throwUnavailable(Model $attachable): never
    {
        throw ValidationException::withMessages([
            (self::bodyAttribute($attachable) ?? 'attachments') => __('One or more inline attachments are no longer available. Remove them and try again.'),
        ]);
    }

    private static function removeFileReferences(string $body, ?int $fileId = null): string
    {
        $document = self::loadDocument($body);

        if (! $document instanceof DOMDocument) {
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

            $matches = false;

            foreach (['href', 'src'] as $attribute) {
                if (! $node->hasAttribute($attribute)) {
                    continue;
                }

                $referencedFileId = self::fileIdFromReference($node->getAttribute($attribute));

                if ($referencedFileId !== null && ($fileId === null || $referencedFileId === $fileId)) {
                    $matches = true;
                    break;
                }
            }

            if (! $matches) {
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

    private static function loadDocument(string $body): ?DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            "<?xml encoding=\"UTF-8\"><body>{$body}</body>",
            LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        return $loaded ? $document : null;
    }
}
