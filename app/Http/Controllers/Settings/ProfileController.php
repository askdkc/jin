<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $oldAvatarPath = $user->avatar_path;
        $newAvatarPath = null;

        $user->fill($request->safe()->except('avatar'));

        if ($request->hasFile('avatar')) {
            $newAvatarPath = $request->file('avatar')->storePublicly("avatars/{$user->id}", 'public');

            if (! $newAvatarPath) {
                throw ValidationException::withMessages([
                    'avatar' => __('The avatar could not be uploaded.'),
                ]);
            }

            $user->avatar_path = $newAvatarPath;
        }

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        try {
            $user->save();
        } catch (\Throwable $exception) {
            if ($newAvatarPath) {
                Storage::disk('public')->delete($newAvatarPath);
            }

            throw $exception;
        }

        if ($newAvatarPath && $oldAvatarPath) {
            Storage::disk('public')->delete($oldAvatarPath);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return back(fallback: route('profile.edit'));
    }

    /**
     * Remove the user's profile avatar.
     */
    public function destroyAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();
        $avatarPath = $user->avatar_path;

        $user->forceFill(['avatar_path' => null])->save();

        if ($avatarPath) {
            Storage::disk('public')->delete($avatarPath);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Avatar removed.')]);

        return back(fallback: route('profile.edit'));
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
