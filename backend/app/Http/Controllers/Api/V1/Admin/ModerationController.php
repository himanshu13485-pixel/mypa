<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Report;
use App\Notifications\SocialNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ModerationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Report::with([
            'reporter:id,uuid,name,username',
            'reportedUser:id,uuid,name,username,status',
            'message:id,uuid,body,type,deleted_at',
            'reviewer:id,uuid,name',
        ]);

        $query->where('status', $request->query('status', 'open'));

        return response()->json($query->oldest()->paginate(30));
    }

    /** Act on a report: dismiss, warn, delete the message, or suspend the user. */
    public function act(Request $request, Report $report): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:dismiss,warn,delete_message,suspend'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        abort_unless($report->status === 'open', 409, 'This report has already been handled.');

        // delete/suspend need delete rights for subadmins; warn/dismiss need edit.
        $ability = in_array($data['action'], ['delete_message', 'suspend']) ? 'delete' : 'edit';
        abort_unless($request->user()->canModule('moderation', $ability), 403,
            "You do not have {$ability} rights for moderation.");

        $target = $report->reportedUser;

        switch ($data['action']) {
            case 'dismiss':
                break;

            case 'warn':
                $target->notify(new SocialNotification(
                    'moderation_warning',
                    'A moderator reviewed a report about your activity. Please follow the community rules — repeated violations can suspend your account.'
                        . ($data['note'] ? ' Note: ' . $data['note'] : ''),
                ));
                break;

            case 'delete_message':
                abort_unless($report->message_id, 422, 'This report is not about a message.');
                $message = $report->message;
                if ($message && ! $message->trashed()) {
                    foreach ($message->attachments as $attachment) {
                        Storage::disk('local')->delete($attachment->path);
                    }
                    $message->attachments()->delete();
                    $message->update(['body' => null]);
                    $message->delete();
                }
                break;

            case 'suspend':
                abort_if($target->isSuperAdmin(), 403, 'Super admins cannot be suspended.');
                $target->update(['status' => 'suspended']);
                $target->tokens()->delete();
                break;
        }

        $report->update([
            'status' => $data['action'] === 'dismiss' ? 'dismissed' : 'actioned',
            'action_taken' => $data['action'] === 'dismiss' ? 'dismissed'
                : ($data['action'] === 'warn' ? 'warned'
                : ($data['action'] === 'delete_message' ? 'message_deleted' : 'suspended')),
            'action_note' => $data['note'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        // Close sibling open reports about the same message automatically.
        if ($report->message_id) {
            Report::where('message_id', $report->message_id)
                ->where('status', 'open')
                ->update([
                    'status' => $report->status,
                    'action_taken' => $report->action_taken,
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                ]);
        }

        AuditLog::record($request->user(), 'moderation.' . $data['action'], $target, [
            'report' => $report->uuid,
            'note' => $data['note'] ?? null,
        ]);

        // Tell the reporter their report was handled.
        $report->reporter->notify(new SocialNotification(
            'report_resolved',
            'Thanks for your report — a moderator has reviewed it and taken appropriate action.',
        ));

        return response()->json(['message' => 'Report ' . $report->fresh()->status . '.', 'data' => $report->fresh()]);
    }
}
