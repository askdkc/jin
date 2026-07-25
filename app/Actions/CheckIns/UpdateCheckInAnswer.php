<?php

namespace App\Actions\CheckIns;

use App\Models\CheckIn;
use App\Models\CheckInPrompt;
use App\Models\Project;
use App\Models\User;
use App\Support\InlineAttachments;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateCheckInAnswer
{
    public function handle(
        Project $project,
        CheckIn $checkIn,
        CheckInPrompt $prompt,
        User $user,
        string $answer,
    ): CheckInPrompt {
        return DB::transaction(function () use ($project, $checkIn, $prompt, $user, $answer): CheckInPrompt {
            $prompt = CheckInPrompt::query()
                ->where('check_in_id', $checkIn->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->findOrFail($prompt->id);

            if ($prompt->answered_at === null) {
                throw ValidationException::withMessages([
                    'answer' => __('This check-in answer has not been submitted yet.'),
                ]);
            }

            $prompt->update(['answer' => $answer]);
            InlineAttachments::syncFromBody($prompt, $project, $user->id, $prompt->answer);

            return $prompt;
        }, attempts: 5);
    }
}
