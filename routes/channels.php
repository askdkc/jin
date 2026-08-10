<?php

use App\Broadcasting\ProjectChatChannel;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('projects.{project}.chat', ProjectChatChannel::class);
Broadcast::channel('App.Models.User.{id}', fn (User $authenticatedUser, int $id): bool => $authenticatedUser->id === $id);
Broadcast::channel('users.{user}.pings', fn (User $authenticatedUser, User $user): bool => $authenticatedUser->is($user));
