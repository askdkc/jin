<?php

use App\Broadcasting\ProjectChatChannel;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('projects.{project}.chat', ProjectChatChannel::class);
Broadcast::channel('users.{user}.pings', fn (User $authenticatedUser, User $user): bool => $authenticatedUser->is($user));
