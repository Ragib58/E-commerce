<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Services\NotificationPreferenceService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A signed-in customer's database notifications and preferences.
 *
 * Everything here reads and writes `$request->user()` — the authenticated
 * customer — and nothing takes an id for whose notifications to act on. That
 * is deliberate rather than an oversight: Laravel's `notifications` table is
 * shared by every notifiable in the application, and an endpoint that
 * accepted an arbitrary notification id without scoping the query to "this
 * user's own" would let one customer mark, or worse read, another's.
 */
final class NotificationController extends Controller
{
    use ApiResponse;

    /**
     * GET /notifications
     *
     * Newest first, paginated. Read and unread are returned together — the
     * `read_at` field is what a client uses to render the distinction — so a
     * single list can show both the notification bell's dropdown and a full
     * history page.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 20));

        return $this->successResponse(
            data: $notifications->through(fn ($notification): array => [
                'id' => $notification->id,
                'type' => class_basename($notification->type),
                'data' => $notification->data,
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
            ]),
            message: 'Notifications retrieved.',
        );
    }

    /**
     * GET /notifications/unread-count
     *
     * A cheap, separate endpoint for a notification bell's badge — polled far
     * more often than the full list is opened, so it must not pull every
     * notification's payload just to report a count.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->successResponse(
            data: ['count' => $request->user()->unreadNotifications()->count()],
            message: 'Unread count retrieved.',
        );
    }

    /**
     * PATCH /notifications/{notification}/read
     *
     * Scoped to the authenticated user's own notifications — see the class
     * docblock. An id belonging to someone else resolves to "not found" rather
     * than a 403, so a client cannot use this endpoint to probe whether a
     * given notification id exists at all.
     */
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->first();

        if ($record === null) {
            return $this->errorResponse(
                message: 'That notification could not be found.',
                status: 404,
                code: 'NOTIFICATION_NOT_FOUND',
            );
        }

        $record->markAsRead();

        return $this->successResponse(message: 'Notification marked as read.');
    }

    /**
     * POST /notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->successResponse(message: 'All notifications marked as read.');
    }

    /**
     * GET /notifications/preferences
     *
     * The toggles an account settings page renders — every mutable
     * notification type this audience can receive, on every channel it
     * defaults to, with the account's current on/off state for each.
     */
    public function preferences(Request $request, NotificationPreferenceService $preferences): JsonResponse
    {
        return $this->successResponse(
            data: $preferences->forAccount($request->user()),
            message: 'Notification preferences retrieved.',
        );
    }

    /**
     * PATCH /notifications/preferences
     *
     * Sets one type/channel pair. Refused for an immutable type at the
     * service layer — see NotificationPreferenceService::disable — rather than
     * here, so the single rule about which types can be muted lives in one
     * place regardless of which surface (this endpoint, a future admin
     * override) tries to change it.
     */
    public function updatePreference(Request $request, NotificationPreferenceService $preferences): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(NotificationType::values())],
            'channel' => ['required', Rule::in(['mail', 'database'])],
            'is_enabled' => ['required', 'boolean'],
        ]);

        $type = NotificationType::from($validated['type']);
        $user = $request->user();

        if ($validated['is_enabled']) {
            $preferences->enable($user, $type, $validated['channel']);
        } else {
            $preferences->disable($user, $type, $validated['channel']);
        }

        return $this->successResponse(
            data: $preferences->forAccount($user),
            message: 'Notification preference updated.',
        );
    }
}
