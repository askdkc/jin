<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AdminlandController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\BubbleUpController;
use App\Http\Controllers\CalendarSubscriptionController;
use App\Http\Controllers\CardTableController;
use App\Http\Controllers\ChatChannelController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DirectMessageController;
use App\Http\Controllers\EverythingController;
use App\Http\Controllers\LineupController;
use App\Http\Controllers\MessageBoardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationReplyController;
use App\Http\Controllers\OnlyOfficeFileController;
use App\Http\Controllers\OnlyOfficePreviewController;
use App\Http\Controllers\PersonalNoteAttachmentController;
use App\Http\Controllers\PersonalNoteController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectDocumentController;
use App\Http\Controllers\ProjectFavoriteController;
use App\Http\Controllers\ProjectFileController;
use App\Http\Controllers\ProjectFolderController;
use App\Http\Controllers\ProjectMaterialController;
use App\Http\Controllers\ProjectTimeEntryController;
use App\Http\Controllers\ProjectTodoController;
use App\Http\Controllers\PublicDocumentController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ScheduleEventController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\WorkspaceCalendarController;
use App\Http\Middleware\EnsureProjectToolIsEnabled;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::get('language/{locale}', function (Request $request, string $locale) {
    $supportedLocales = config('app.supported_locales', []);

    abort_unless(is_array($supportedLocales) && array_key_exists($locale, $supportedLocales), 404);

    app()->setLocale($locale);
    $request->session()->put('locale', $locale);

    $previousUrl = $request->headers->get('referer');
    $hasSafeSyntax = is_string($previousUrl)
        && $previousUrl !== ''
        && ! str_contains($previousUrl, '\\');
    $sameOrigin = $hasSafeSyntax
        && str_starts_with($previousUrl, '/')
        && ! str_starts_with($previousUrl, '//');

    if ($hasSafeSyntax && ! $sameOrigin) {
        try {
            $parts = parse_url($previousUrl);
        } catch (ValueError) {
            $parts = false;
        }

        if (is_array($parts)) {
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));
            $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
            $sameOrigin = in_array($scheme, ['http', 'https'], true)
                && $scheme === strtolower($request->getScheme())
                && $host === strtolower($request->getHost())
                && $port === $request->getPort()
                && ! isset($parts['user'])
                && ! isset($parts['pass']);
        }
    }

    return $sameOrigin
        ? redirect()->to($previousUrl)
        : redirect()->route('home');
})->name('language.switch');

Route::get('calendar-subscriptions/{calendar_subscription}.ics', [CalendarSubscriptionController::class, 'show'])
    ->name('calendar-subscriptions.show');
Route::get('shared/documents/{document:public_token}', PublicDocumentController::class)
    ->name('public-documents.show');
Route::get('dashboard', [DashboardController::class, 'redirectToCurrentTeam'])
    ->middleware('auth');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('adminland', AdminlandController::class)->name('adminland.index');
        Route::get('activity', ActivityController::class)->name('activity.index');
        Route::get('calendar', WorkspaceCalendarController::class)->name('calendar.index');
        Route::get('everything', EverythingController::class)->name('everything.index');
        Route::get('reports', ReportsController::class)->name('reports.index');
        Route::get('lineup', LineupController::class)->name('lineup.index');
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::patch('notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');
        Route::post('notifications/{notification}/reply', [NotificationReplyController::class, 'store'])->name('notifications.reply');
        Route::get('notes', [PersonalNoteController::class, 'index'])->name('notes.index');
        Route::post('notes/attachments', [PersonalNoteAttachmentController::class, 'upload'])
            ->name('personal-note-attachments.store');
        Route::get('notes/attachments/{personal_note_attachment}/preview', [PersonalNoteAttachmentController::class, 'preview'])
            ->name('personal-note-attachments.preview');
        Route::scopeBindings()->group(function () {
            Route::post('projects/{project}/inline-attachments', [AttachmentController::class, 'uploadInline'])
                ->name('projects.inline-attachments.store');
        });
        Route::scopeBindings()->middleware(EnsureProjectToolIsEnabled::class)->group(function () {
            Route::post('notes', [PersonalNoteController::class, 'store'])->name('notes.store');
            Route::patch('notes/{personal_note}', [PersonalNoteController::class, 'update'])->name('notes.update');
            Route::delete('notes/{personal_note}', [PersonalNoteController::class, 'destroy'])->name('notes.destroy');
            Route::post('bubble-ups', [BubbleUpController::class, 'store'])->name('bubble-ups.store');
            Route::patch('bubble-ups/{bubble_up}/complete', [BubbleUpController::class, 'complete'])->name('bubble-ups.complete');
            Route::post('pings', [DirectMessageController::class, 'store'])->name('pings.store');
            Route::patch('pings/read', [DirectMessageController::class, 'markRead'])->name('pings.read');
            Route::post('project-folders', [ProjectFolderController::class, 'store'])->name('project-folders.store');
            Route::delete('project-folders/{project_folder}', [ProjectFolderController::class, 'destroy'])->name('project-folders.destroy');
            Route::post('calendar/subscriptions', [CalendarSubscriptionController::class, 'store'])->name('calendar-subscriptions.store');
            Route::delete('calendar/subscriptions/{calendar_subscription}', [CalendarSubscriptionController::class, 'destroy'])->name('calendar-subscriptions.destroy');
            Route::patch('projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
            Route::patch('projects/{project}/favorite', ProjectFavoriteController::class)->name('projects.favorite');
            Route::get('projects/{project}/time-entries', [ProjectTimeEntryController::class, 'index'])->name('projects.time-entries.index');
            Route::post('projects/{project}/time-entries', [ProjectTimeEntryController::class, 'store'])->name('projects.time-entries.store');
            Route::delete('projects/{project}/time-entries/{time_entry}', [ProjectTimeEntryController::class, 'destroy'])->name('projects.time-entries.destroy');
            Route::get('projects/{project}/chat', [ChatController::class, 'show'])->name('projects.chat.show');
            Route::get('projects/{project}/chat/messages', [ChatController::class, 'history'])->name('projects.chat.messages.index');
            Route::post('projects/{project}/chat/messages', [ChatController::class, 'store'])->name('projects.chat.messages.store');
            Route::patch('projects/{project}/chat/messages/{chat_message}', [ChatController::class, 'update'])->name('projects.chat.messages.update');
            Route::delete('projects/{project}/chat/messages/{chat_message}', [ChatController::class, 'destroy'])->name('projects.chat.messages.destroy');
            Route::get('projects/{project}/chat/messages/{chat_message}/replies', [ChatController::class, 'thread'])->name('projects.chat.messages.replies');
            Route::post('projects/{project}/chat/messages/{chat_message}/reaction', [ChatController::class, 'toggleReaction'])->name('projects.chat.messages.reaction.toggle');
            Route::post('projects/{project}/chat/channels', [ChatChannelController::class, 'store'])->name('projects.chat.channels.store');
            Route::patch('projects/{project}/chat/channels/{chat_channel}', [ChatChannelController::class, 'update'])->name('projects.chat.channels.update');
            Route::delete('projects/{project}/chat/channels/{chat_channel}', [ChatChannelController::class, 'destroy'])->name('projects.chat.channels.destroy');

            Route::get('projects/{project}/messages', [MessageBoardController::class, 'index'])->name('projects.messages.index');
            Route::get('projects/{project}/messages/create', [MessageBoardController::class, 'create'])->name('projects.messages.create');
            Route::post('projects/{project}/messages', [MessageBoardController::class, 'store'])->name('projects.messages.store');
            Route::get('projects/{project}/messages/{message_board_post}/edit', [MessageBoardController::class, 'edit'])->name('projects.messages.edit');
            Route::get('projects/{project}/messages/{message_board_post}', [MessageBoardController::class, 'show'])->name('projects.messages.show');
            Route::patch('projects/{project}/messages/{message_board_post}', [MessageBoardController::class, 'update'])->name('projects.messages.update');
            Route::patch('projects/{project}/messages/{message_board_post}/pin', [MessageBoardController::class, 'togglePin'])->name('projects.messages.pin');
            Route::patch('projects/{project}/messages/{message_board_post}/subscription', [MessageBoardController::class, 'toggleSubscription'])->name('projects.messages.subscription');
            Route::post('projects/{project}/messages/{message_board_post}/reaction', [MessageBoardController::class, 'toggleReaction'])->name('projects.messages.reaction.toggle');
            Route::post('projects/{project}/messages/{message_board_post}/attachments', [AttachmentController::class, 'storeMessage'])->name('projects.messages.attachments.store');
            Route::delete('projects/{project}/messages/{message_board_post}', [MessageBoardController::class, 'destroy'])->name('projects.messages.destroy');
            Route::post('projects/{project}/messages/{message_board_post}/comments', [CommentController::class, 'store'])->name('projects.messages.comments.store');
            Route::patch('projects/{project}/messages/{message_board_post}/comments/{comment}', [CommentController::class, 'update'])->name('projects.messages.comments.update');
            Route::delete('projects/{project}/messages/{message_board_post}/comments/{comment}', [CommentController::class, 'destroy'])->name('projects.messages.comments.destroy');

            Route::get('projects/{project}/todos', [ProjectTodoController::class, 'index'])->name('projects.todos.index');
            Route::post('projects/{project}/todo-lists', [ProjectTodoController::class, 'storeList'])->name('projects.todo-lists.store');
            Route::get('projects/{project}/todo-lists/{todo_list}', [ProjectTodoController::class, 'showList'])->name('projects.todo-lists.show');
            Route::patch('projects/{project}/todo-lists/{todo_list}', [ProjectTodoController::class, 'updateList'])->name('projects.todo-lists.update');
            Route::delete('projects/{project}/todo-lists/{todo_list}', [ProjectTodoController::class, 'destroyList'])->name('projects.todo-lists.destroy');
            Route::patch('projects/{project}/todo-lists/{todo_list}/subscription', [ProjectTodoController::class, 'toggleListSubscription'])->name('projects.todo-lists.subscription.toggle');
            Route::post('projects/{project}/todo-lists/{todo_list}/comments', [CommentController::class, 'storeList'])->name('projects.todo-lists.comments.store');
            Route::patch('projects/{project}/todo-lists/{todo_list}/comments/{comment}', [CommentController::class, 'updateList'])->name('projects.todo-lists.comments.update');
            Route::delete('projects/{project}/todo-lists/{todo_list}/comments/{comment}', [CommentController::class, 'destroyList'])->name('projects.todo-lists.comments.destroy');
            Route::post('projects/{project}/todo-lists/{todo_list}/todos', [ProjectTodoController::class, 'storeTodo'])->name('projects.todos.store');
            Route::get('projects/{project}/todo-lists/{todo_list}/todos/{todo}', [ProjectTodoController::class, 'show'])->name('projects.todos.show');
            Route::post('projects/{project}/todo-lists/{todo_list}/todos/{todo}/subtasks', [ProjectTodoController::class, 'storeSubtask'])->name('projects.todos.subtasks.store');
            Route::post('projects/{project}/todo-lists/{todo_list}/todos/{todo}/attachments', [AttachmentController::class, 'storeTodo'])->name('projects.todos.attachments.store');
            Route::patch('projects/{project}/todo-lists/{todo_list}/todos/{todo}', [ProjectTodoController::class, 'updateTodo'])->name('projects.todos.update');
            Route::patch('projects/{project}/todo-lists/{todo_list}/todos/{todo}/toggle', [ProjectTodoController::class, 'toggleTodo'])->name('projects.todos.toggle');
            Route::post('projects/{project}/todo-lists/{todo_list}/todos/{todo}/reaction', [ProjectTodoController::class, 'toggleReaction'])->name('projects.todos.reaction.toggle');
            Route::patch('projects/{project}/todo-lists/{todo_list}/todos/{todo}/move', [ProjectTodoController::class, 'moveTodo'])->name('projects.todos.move');
            Route::delete('projects/{project}/todo-lists/{todo_list}/todos/{todo}', [ProjectTodoController::class, 'destroyTodo'])->name('projects.todos.destroy');
            Route::post('projects/{project}/todo-lists/{todo_list}/todos/{todo}/comments', [CommentController::class, 'storeTodo'])->name('projects.todos.comments.store');
            Route::patch('projects/{project}/todo-lists/{todo_list}/todos/{todo}/comments/{comment}', [CommentController::class, 'updateTodo'])->name('projects.todos.comments.update');
            Route::delete('projects/{project}/todo-lists/{todo_list}/todos/{todo}/comments/{comment}', [CommentController::class, 'destroyTodo'])->name('projects.todos.comments.destroy');

            Route::get('projects/{project}/schedule', [ScheduleEventController::class, 'index'])->name('projects.schedule.index');
            Route::get('projects/{project}/schedule/gantt-window', [ScheduleEventController::class, 'ganttWindow'])->name('projects.schedule.gantt-window');
            Route::post('projects/{project}/schedule', [ScheduleEventController::class, 'store'])->name('projects.schedule.store');
            Route::get('projects/{project}/schedule/{schedule_event}', [ScheduleEventController::class, 'show'])->name('projects.schedule.show');
            Route::post('projects/{project}/schedule/{schedule_event}/reaction', [ScheduleEventController::class, 'toggleReaction'])->name('projects.schedule.reaction.toggle');
            Route::post('projects/{project}/schedule/{schedule_event}/comments', [CommentController::class, 'storeScheduleEvent'])->name('projects.schedule.comments.store');
            Route::patch('projects/{project}/schedule/{schedule_event}', [ScheduleEventController::class, 'update'])->name('projects.schedule.update');
            Route::delete('projects/{project}/schedule/{schedule_event}', [ScheduleEventController::class, 'destroy'])->name('projects.schedule.destroy');

            Route::get('projects/{project}/documents', [ProjectDocumentController::class, 'index'])->name('projects.documents.index');
            Route::post('projects/{project}/documents', [ProjectDocumentController::class, 'store'])->name('projects.documents.store');
            Route::patch('projects/{project}/documents/{document}', [ProjectDocumentController::class, 'update'])->name('projects.documents.update');
            Route::delete('projects/{project}/documents/{document}', [ProjectDocumentController::class, 'destroy'])->name('projects.documents.destroy');
            Route::patch('projects/{project}/documents/{document}/public-link', [ProjectDocumentController::class, 'togglePublicLink'])->name('projects.documents.public-link');

            Route::get('projects/{project}/files', [ProjectMaterialController::class, 'index'])->name('projects.materials.index');
            Route::post('projects/{project}/files', [ProjectFileController::class, 'store'])->name('projects.files.store');
            Route::get('projects/{project}/files/{stored_file}/onlyoffice/config', OnlyOfficePreviewController::class)
                ->name('projects.files.onlyoffice.config');
            Route::get('projects/{project}/files/{stored_file}', [ProjectFileController::class, 'show'])->name('projects.files.show');
            Route::patch('projects/{project}/files/{stored_file}', [ProjectFileController::class, 'update'])->name('projects.files.update');
            Route::post('projects/{project}/files/{stored_file}/replace', [ProjectFileController::class, 'replace'])->name('projects.files.replace');
            Route::get('projects/{project}/files/{stored_file}/preview', [ProjectFileController::class, 'preview'])->name('projects.files.preview');
            Route::get('projects/{project}/files/{stored_file}/thumbnail', [ProjectFileController::class, 'thumbnail'])->name('projects.files.thumbnail');
            Route::get('projects/{project}/files/{stored_file}/download', [ProjectFileController::class, 'download'])->name('projects.files.download');
            Route::post('projects/{project}/files/{stored_file}/reaction', [ProjectFileController::class, 'toggleReaction'])->name('projects.files.reaction.toggle');
            Route::delete('projects/{project}/files/{stored_file}', [ProjectFileController::class, 'destroy'])->name('projects.files.destroy');
            Route::post('projects/{project}/files/{stored_file}/comments', [CommentController::class, 'storeFile'])->name('projects.files.comments.store');
            Route::patch('projects/{project}/files/{stored_file}/comments/{comment}', [CommentController::class, 'updateFile'])->name('projects.files.comments.update');
            Route::delete('projects/{project}/files/{stored_file}/comments/{comment}', [CommentController::class, 'destroyFile'])->name('projects.files.comments.destroy');

            Route::get('projects/{project}/card-tables', [CardTableController::class, 'index'])->name('projects.card-tables.index');
            Route::post('projects/{project}/card-tables', [CardTableController::class, 'storeTable'])->name('projects.card-tables.store');
            Route::patch('projects/{project}/card-tables/{card_table}', [CardTableController::class, 'updateTable'])->name('projects.card-tables.update');
            Route::delete('projects/{project}/card-tables/{card_table}', [CardTableController::class, 'destroyTable'])->name('projects.card-tables.destroy');
            Route::post('projects/{project}/card-tables/{card_table}/columns', [CardTableController::class, 'storeColumn'])->name('projects.card-columns.store');
            Route::get('projects/{project}/card-tables/{card_table}/columns/{card_column}', [CardTableController::class, 'showColumn'])->name('projects.card-columns.show');
            Route::patch('projects/{project}/card-tables/{card_table}/columns/{card_column}', [CardTableController::class, 'updateColumn'])->name('projects.card-columns.update');
            Route::patch('projects/{project}/card-tables/{card_table}/columns/{card_column}/move', [CardTableController::class, 'moveColumn'])->name('projects.card-columns.move');
            Route::patch('projects/{project}/card-tables/{card_table}/columns/{card_column}/collapse', [CardTableController::class, 'toggleColumn'])->name('projects.card-columns.collapse');
            Route::delete('projects/{project}/card-tables/{card_table}/columns/{card_column}', [CardTableController::class, 'destroyColumn'])->name('projects.card-columns.destroy');
            Route::post('projects/{project}/card-tables/{card_table}/columns/{card_column}/comments', [CommentController::class, 'storeCardColumn'])->name('projects.card-columns.comments.store');
            Route::patch('projects/{project}/card-tables/{card_table}/columns/{card_column}/comments/{comment}', [CommentController::class, 'updateCardColumn'])->name('projects.card-columns.comments.update');
            Route::delete('projects/{project}/card-tables/{card_table}/columns/{card_column}/comments/{comment}', [CommentController::class, 'destroyCardColumn'])->name('projects.card-columns.comments.destroy');
            Route::post('projects/{project}/card-tables/{card_table}/columns/{card_column}/cards', [CardTableController::class, 'storeCard'])->name('projects.cards.store');
            Route::patch('projects/{project}/card-tables/{card_table}/columns/{card_column}/cards/{card}', [CardTableController::class, 'updateCard'])->name('projects.cards.update');
            Route::patch('projects/{project}/card-tables/{card_table}/columns/{card_column}/cards/{card}/move', [CardTableController::class, 'moveCard'])->name('projects.cards.move');
            Route::delete('projects/{project}/card-tables/{card_table}/columns/{card_column}/cards/{card}', [CardTableController::class, 'destroyCard'])->name('projects.cards.destroy');

            Route::get('projects/{project}/check-ins', [CheckInController::class, 'index'])->name('projects.check-ins.index');
            Route::post('projects/{project}/check-ins', [CheckInController::class, 'store'])->name('projects.check-ins.store');
            Route::get('projects/{project}/check-ins/{check_in}', [CheckInController::class, 'show'])->name('projects.check-ins.show');
            Route::patch('projects/{project}/check-ins/{check_in}', [CheckInController::class, 'update'])->name('projects.check-ins.update');
            Route::patch('projects/{project}/check-ins/{check_in}/pause', [CheckInController::class, 'togglePause'])->name('projects.check-ins.pause');
            Route::patch('projects/{project}/check-ins/{check_in}/bookmark', [CheckInController::class, 'toggleBookmark'])->name('projects.check-ins.bookmark');
            Route::delete('projects/{project}/check-ins/{check_in}', [CheckInController::class, 'destroy'])->name('projects.check-ins.destroy');
            Route::post('projects/{project}/check-ins/{check_in}/answer', [CheckInController::class, 'respond'])->name('projects.check-ins.respond');
            Route::post('projects/{project}/check-ins/{check_in}/prompts/{prompt}/answer', [CheckInController::class, 'answer'])->name('projects.check-ins.answer');
            Route::patch('projects/{project}/check-ins/{check_in}/prompts/{prompt}/answer', [CheckInController::class, 'updateAnswer'])->name('projects.check-ins.answers.update');
            Route::post('projects/{project}/check-ins/{check_in}/prompts/{prompt}/comments', [CommentController::class, 'storeCheckInAnswer'])->name('projects.check-ins.comments.store');
            Route::post('projects/{project}/check-ins/{check_in}/prompts/{prompt}/reaction', [CheckInController::class, 'toggleAnswerReaction'])->name('projects.check-ins.reactions.toggle');

            Route::post('projects/{project}/material-folders', [ProjectMaterialController::class, 'storeFolder'])->name('projects.material-folders.store');
            Route::delete('projects/{project}/material-folders/{material_folder}', [ProjectMaterialController::class, 'destroyFolder'])->name('projects.material-folders.destroy');
            Route::post('projects/{project}/external-links', [ProjectMaterialController::class, 'storeLink'])->name('projects.external-links.store');
            Route::delete('projects/{project}/external-links/{external_link}', [ProjectMaterialController::class, 'destroyLink'])->name('projects.external-links.destroy');
            Route::patch('projects/{project}/materials/documents/{document}/folder', [ProjectMaterialController::class, 'moveDocument'])->name('projects.materials.documents.move');
            Route::patch('projects/{project}/materials/files/{stored_file}/folder', [ProjectMaterialController::class, 'moveFile'])->name('projects.materials.files.move');
            Route::resource('projects', ProjectController::class)->except('destroy');
        });
    });

Route::get('internal/onlyoffice/files/{file}', OnlyOfficeFileController::class)
    ->middleware(ValidateSignature::relative())
    ->name('files.onlyoffice.internal-download');

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
