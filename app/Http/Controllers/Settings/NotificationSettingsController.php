<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateNotificationSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Notifications', [
            'preferences' => $request->user()->notificationPreferences(),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function update(UpdateNotificationSettingsRequest $request): RedirectResponse
    {
        $request->user()->update([
            'notification_preferences' => $request->validated(),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Notification settings updated.'),
        ]);

        return to_route('notification-settings.edit');
    }
}
