<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, array{string, string}> */
    private const RICH_TEXT_ATTACHABLES = [
        'App\\Models\\Document' => ['documents', 'body'],
        'App\\Models\\ScheduleEvent' => ['schedule_events', 'details'],
        'App\\Models\\Card' => ['cards', 'description'],
        'App\\Models\\CheckInPrompt' => ['check_in_prompts', 'answer'],
        'App\\Models\\Todo' => ['todos', 'details'],
        'App\\Models\\Comment' => ['comments', 'body'],
        'App\\Models\\MessageBoardPost' => ['message_board_posts', 'body'],
    ];

    public function up(): void
    {
        foreach (self::RICH_TEXT_ATTACHABLES as $morphClass => [$table, $attribute]) {
            DB::table('stored_files')
                ->where('attachable_type', $morphClass)
                ->whereNotNull('attachable_id')
                ->where('is_inline', false)
                ->orderBy('id')
                ->chunkById(500, function (Collection $files) use ($table, $attribute): void {
                    $bodies = DB::table($table)
                        ->whereIn('id', $files->pluck('attachable_id')->unique())
                        ->pluck($attribute, 'id');

                    $inlineFileIds = $files
                        ->filter(fn (object $file): bool => str_contains(
                            (string) $bodies->get($file->attachable_id),
                            "/files/{$file->id}/",
                        ))
                        ->pluck('id');

                    if ($inlineFileIds->isNotEmpty()) {
                        DB::table('stored_files')
                            ->whereIn('id', $inlineFileIds)
                            ->update(['is_inline' => true]);
                    }
                });
        }

        // Neutral legacy rows are ambiguous: both ordinary and abandoned inline uploads used
        // /attachments/. Keep them ordinary so the migration never hides a user's file.
    }

    public function down(): void
    {
        // Data classification is intentionally retained; the preceding schema migration removes it.
    }
};
