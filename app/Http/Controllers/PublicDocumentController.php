<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Inertia\Inertia;
use Inertia\Response;

class PublicDocumentController extends Controller
{
    public function __invoke(Document $document): Response
    {
        abort_unless($document->published_at !== null, 404);

        $document->loadMissing('project:id,name');

        return Inertia::render('public/DocumentShow', [
            'document' => [
                'title' => $document->title,
                'body' => $document->body,
                'projectName' => $document->project->name,
                'updatedAt' => $document->updated_at?->toISOString(),
            ],
        ]);
    }
}
