<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Services\NotificationPreferenceService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * An admin's own database notifications and preferences.
 *
 * Every route this controller serves is authenticated as `admin-api` and
 * scoped to `$request->user('admin-api')`'s own notifications — see
 * NotificationController's class docblock for why that scoping is not
 * optional. There is no cross-admin view here; "everyone's alerts" is not a
 * question this controller answers, because Laravel's notifications table has
 * no query that means that safely without leaking who saw what.
 */
final class NotificationController extends Controller
{
    use ApiResponse;

    /**
     * GET /admin/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $admin = $request->user('admin-api');

        $notifications = $admin->notifications()
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
     * GET /admin/notifications/unread-count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->successResponse(
            data: ['count' => $request->user('admin-api')->unreadNotifications()->count()],
            message: 'Unread count retrieved.',
        );
    }

    /**
     * PATCH /admin/notifications/{notification}/read
     */
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $record = $request->user('admin-api')->notifications()->whereKey($notification)->first();

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
     * POST /admin/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user('admin-api')->unreadNotifications()->update(['read_at' => now()]);

        return $this->successResponse(message: 'All notifications marked as read.');
    }

    /**
     * GET /admin/notifications/preferences
     */
    public function preferences(Request $request, NotificationPreferenceService $preferences): JsonResponse
    {
        return $this->successResponse(
            data: $preferences->forAccount($request->user('admin-api')),
            message: 'Notification preferences retrieved.',
        );
    }

    /**
     * PATCH /admin/notifications/preferences
     */
    public function updatePreference(Request $request, NotificationPreferenceService $preferences): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(NotificationType::values())],
            'channel' => ['required', Rule::in(['mail', 'database'])],
            'is_enabled' => ['required', 'boolean'],
        ]);

        $type = NotificationType::from($validated['type']);
        $admin = $request->user('admin-api');

        if ($validated['is_enabled']) {
            $preferences->enable($admin, $type, $validated['channel']);
        } else {
            $preferences->disable($admin, $type, $validated['channel']);
        }

        return $this->successResponse(
            data: $preferences->forAccount($admin),
            message: 'Notification preference updated.',
        );
    }
}
