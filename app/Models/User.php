<?php

namespace App\Models;

use App\Concerns\HasTeams;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Appends;
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
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $job_title
 * @property string|null $location
 * @property string|null $status
 * @property string|null $avatar_path
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property array<string, mixed>|null $notification_preferences
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $avatar
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, CalendarSubscription> $calendarSubscriptions
 * @property-read Collection<int, Project> $favoriteProjects
 * @property-read Collection<int, CheckIn> $bookmarkedCheckIns
 * @property-read Collection<int, TimeEntry> $timeEntries
 * @property-read Collection<int, PersonalNote> $personalNotes
 * @property-read Collection<int, PersonalNoteAttachment> $personalNoteAttachments
 * @property-read Collection<int, BubbleUp> $bubbleUps
 * @property-read Collection<int, DirectMessage> $sentDirectMessages
 * @property-read Collection<int, DirectMessage> $receivedDirectMessages
 */
#[Fillable(['name', 'job_title', 'location', 'status', 'avatar_path', 'email', 'password', 'current_team_id', 'notification_preferences'])]
#[Hidden(['password', 'avatar_path', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
#[Appends(['avatar'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @var array<string, bool|string|array<int, int>> */
    public const DEFAULT_NOTIFICATION_PREFERENCES = [
        'enabled' => true,
        'scope' => 'everything',
        'event_reminders' => true,
        'assignment_reminders' => true,
        'in_app' => true,
        'email' => true,
        'show_unread_count' => true,
        'schedule' => 'always',
        'work_days' => [1, 2, 3, 4, 5],
        'work_start' => '09:00',
        'work_end' => '17:00',
        'timezone' => 'Asia/Tokyo',
    ];

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }

            $user->personalNoteAttachments()
                ->each(fn (PersonalNoteAttachment $attachment) => $attachment->delete());
        });
    }

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

    /** @return BelongsToMany<CheckIn, $this> */
    public function bookmarkedCheckIns(): BelongsToMany
    {
        return $this->belongsToMany(CheckIn::class, 'check_in_bookmarks')->withTimestamps();
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

    /** @return HasMany<PersonalNoteAttachment, $this> */
    public function personalNoteAttachments(): HasMany
    {
        return $this->hasMany(PersonalNoteAttachment::class, 'uploaded_by');
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

    /** @return array<string, mixed> */
    public function notificationPreferences(): array
    {
        return array_replace(
            self::DEFAULT_NOTIFICATION_PREFERENCES,
            $this->notification_preferences ?? [],
        );
    }

    /**
     * Resolve the channels for an application notification.
     *
     * @return array<int, string>
     */
    public function notificationChannels(string $category = 'activity'): array
    {
        $preferences = $this->notificationPreferences();

        if (! $preferences['enabled']) {
            return [];
        }

        if ($preferences['scope'] === 'mentions' && $category !== 'mention') {
            return [];
        }

        if ($category === 'assignment_reminder' && ! $preferences['assignment_reminders']) {
            return [];
        }

        $channels = $preferences['in_app'] ? ['database'] : [];

        if ($preferences['email'] && $this->isWithinNotificationWindow()) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function showsUnreadNotificationCount(): bool
    {
        return (bool) $this->notificationPreferences()['show_unread_count'];
    }

    public function wantsEventReminders(): bool
    {
        $preferences = $this->notificationPreferences();

        return (bool) $preferences['enabled'] && (bool) $preferences['event_reminders'];
    }

    public function isWithinNotificationWindow(): bool
    {
        $preferences = $this->notificationPreferences();

        if ($preferences['schedule'] === 'always') {
            return true;
        }

        $now = now((string) $preferences['timezone']);
        $workDays = array_map('intval', (array) $preferences['work_days']);

        if (! in_array($now->dayOfWeekIso, $workDays, true)) {
            return false;
        }

        $time = $now->format('H:i');
        $start = (string) $preferences['work_start'];
        $end = (string) $preferences['work_end'];

        return $start <= $end
            ? $time >= $start && $time < $end
            : $time >= $start || $time < $end;
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => strtolower(trim($value)),
        );
    }

    /** @return Attribute<string|null, never> */
    protected function avatar(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->avatar_path
                ? Storage::disk('public')->url($this->avatar_path)
                : null,
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
            'notification_preferences' => 'array',
        ];
    }
}
