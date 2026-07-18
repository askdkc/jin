<?php

namespace App\Support;

use App\Models\Comment;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Todo;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Eloquent\Model;

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
        if ($body === null || $body === '' || preg_match_all('/\/files\/(\d+)/', $body, $matches) === false) {
            return;
        }

        $fileIds = array_unique(array_map('intval', $matches[1]));

        if ($fileIds === []) {
            return;
        }

        StoredFile::query()
            ->where('project_id', $project->id)
            ->where('uploaded_by', $uploaderId)
            ->whereIn('id', $fileIds)
            ->whereNull('attachable_type')
            ->update([
                'attachable_type' => $attachable->getMorphClass(),
                'attachable_id' => $attachable->getKey(),
            ]);
    }

    public static function removeFromBody(StoredFile $file): void
    {
        $attachable = $file->attachable;
        $attribute = match (true) {
            $attachable instanceof Todo => 'details',
            $attachable instanceof Comment, $attachable instanceof MessageBoardPost => 'body',
            default => null,
        };

        if ($attribute === null) {
            return;
        }

        $body = $attachable->getAttribute($attribute);
        $needle = "/files/{$file->id}/";

        if (! is_string($body) || ! str_contains($body, $needle)) {
            return;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            "<?xml encoding=\"UTF-8\"><body>{$body}</body>",
            LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        if (! $loaded) {
            return;
        }

        $nodes = (new DOMXPath($document))->query('//*[@href or @src]');

        if ($nodes === false) {
            return;
        }

        foreach (iterator_to_array($nodes) as $node) {
            if (! $node instanceof DOMElement
                || ! str_contains($node->getAttribute('href').$node->getAttribute('src'), $needle)) {
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
            return;
        }

        $cleaned = '';

        foreach (iterator_to_array($root->childNodes) as $node) {
            $cleaned .= $document->saveHTML($node);
        }

        $attachable->setAttribute($attribute, $cleaned)->save();
    }
}
