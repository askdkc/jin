<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasTeams;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, CalendarSubscription> $calendarSubscriptions
 * @property-read Collection<int, Project> $favoriteProjects
 * @property-read Collection<int, TimeEntry> $timeEntries
 * @property-read Collection<int, PersonalNote> $personalNotes
 * @property-read Collection<int, BubbleUp> $bubbleUps
 * @property-read Collection<int, DirectMessage> $sentDirectMessages
 * @property-read Collection<int, DirectMessage> $receivedDirectMessages
 * @property-read Collection<int, VoiceNote> $voiceNotes
 */
#[Fillable(['name', 'email', 'password', 'current_team_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get calendar subscriptions issued by the user.
     *
     * @return HasMany<CalendarSubscription, $this>
     */
    public function calendarSubscriptions(): HasMany
    {
        return $this->hasMany(CalendarSubscription::class);
    }

    /** @return BelongsToMany<Project, $this> */
    public function favoriteProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_favorites')->withTimestamps();
    }

    /** @return HasMany<TimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /** @return HasMany<PersonalNote, $this> */
    public function personalNotes(): HasMany
    {
        return $this->hasMany(PersonalNote::class);
    }

    /** @return HasMany<BubbleUp, $this> */
    public function bubbleUps(): HasMany
    {
        return $this->hasMany(BubbleUp::class);
    }

    /** @return HasMany<DirectMessage, $this> */
    public function sentDirectMessages(): HasMany
    {
        return $this->hasMany(DirectMessage::class, 'sender_id');
    }

    /** @return HasMany<DirectMessage, $this> */
    public function receivedDirectMessages(): HasMany
    {
        return $this->hasMany(DirectMessage::class, 'recipient_id');
    }

    /** @return HasMany<VoiceNote, $this> */
    public function voiceNotes(): HasMany
    {
        return $this->hasMany(VoiceNote::class);
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => strtolower(trim($value)),
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
