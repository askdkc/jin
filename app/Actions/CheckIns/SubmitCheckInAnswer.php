<?php

namespace App\Actions\CheckIns;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\CheckIn;
use App\Models\CheckInPrompt;
use App\Models\Project;
use App\Models\User;
use App\Support\InlineAttachments;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitCheckInAnswer
{
    public function handle(
        Project $project,
        CheckIn $checkIn,
        User $user,
        string $answer,
        ?CheckInPrompt $prompt = null,
        string $validationAttribute = 'answer',
    ): CheckInPrompt {
        return DB::transaction(function () use ($project, $checkIn, $user, $answer, $prompt, $validationAttribute): CheckInPrompt {
            $checkIn = CheckIn::query()
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->findOrFail($checkIn->id);

            if ($prompt === null) {
                if (! $checkIn->targets()->whereKey($user->id)->exists()) {
                    throw ValidationException::withMessages([
                        $validationAttribute => __('You are no longer assigned to answer this check-in.'),
                    ]);
                }

                $prompt = $checkIn->prompts()->create([
                    'user_id' => $user->id,
                    'scheduled_for' => now(),
                    'requested_at' => now(),
                ]);
            } else {
                $prompt = CheckInPrompt::query()
                    ->where('check_in_id', $checkIn->id)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->findOrFail($prompt->id);

                if ($prompt->answered_at !== null) {
                    throw ValidationException::withMessages([
                        $validationAttribute => __('This check-in answer has already been submitted.'),
                    ]);
                }
            }

            $prompt->update([
                'answer' => $answer,
                'answered_at' => now(),
            ]);

            InlineAttachments::syncFromBody($prompt, $project, $user->id, $prompt->answer);
            Activity::record(
                $project,
                $user,
                ActivityType::CheckInAnswered,
                $prompt,
                $checkIn->question,
            );

            return $prompt;
        }, attempts: 5);
    }
}
