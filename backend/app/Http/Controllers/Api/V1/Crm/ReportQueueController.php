<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Member;
use App\Models\Report;
use App\Notifications\SocialNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Reports between two people in the same company, for that company's Admin.
 *
 * One employee reporting another for spamming the office group is the
 * company's business first: it employs both of them, it knows the context,
 * and it has the obvious remedies. Netvork sees these too - the platform does
 * not stop being responsible because a company exists - but the company is
 * who acts.
 *
 * What a company may do stops at what is the company's. It can dismiss a
 * report, warn its employee, and take down the message. It cannot suspend an
 * account, because the account is not the company's: that is escalated to
 * Netvork, with a note saying why.
 */
class ReportQueueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $this->adminOnly($request);

        $status = $request->query('status', 'open');

        $query = Report::with([
            'reporter:id,uuid,name,username',
            'reportedUser:id,uuid,name,username,status',
            'message:id,uuid,body,type,deleted_at',
            'reviewer:id,uuid,name',
            'escalator:id,uuid,name',
        ])->where('organization_id', $org->id);

        if ($status === 'escalated') {
            $query->whereNotNull('escalated_at');
        } else {
            $query->where('status', $status);
        }

        $reports = $query->oldest()->paginate(30);
        $reports->getCollection()->transform(fn (Report $r) => $this->serialize($r));

        return response()->json($reports->toArray() + [
            'counts' => [
                'open' => Report::where('organization_id', $org->id)->where('status', 'open')->whereNull('escalated_at')->count(),
                'escalated' => Report::where('organization_id', $org->id)->where('status', 'open')->whereNotNull('escalated_at')->count(),
            ],
        ]);
    }

    public function act(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $this->adminOnly($request);

        $report = Report::where('organization_id', $org->id)->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'action' => ['required', 'in:dismiss,warn,delete_message,escalate'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        abort_unless($report->status === 'open', 409, 'This report has already been handled.');
        abort_if(
            $report->escalated_at !== null,
            409,
            'This report has been handed to Netvork, and it is theirs to decide now.',
        );

        $target = $report->reportedUser;

        switch ($data['action']) {
            case 'dismiss':
                break;

            case 'warn':
                $target?->notify(new SocialNotification(
                    'moderation_warning',
                    "Your company admin reviewed a report about your activity. Please keep to the company's rules."
                        . (! empty($data['note']) ? ' Note: ' . $data['note'] : ''),
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

            case 'escalate':
                /*
                 * Handed up, not closed.
                 *
                 * It stays open, because nothing has been done about it yet -
                 * and it leaves this queue's power, because two people acting
                 * on one report is how somebody gets warned by the company an
                 * hour after Netvork cleared them.
                 */
                abort_if(blank($data['note'] ?? null), 422, 'Say why this needs Netvork - it is what they read first.');

                $report->update([
                    'escalated_at' => now(),
                    'escalated_by' => $request->user()->id,
                    'escalation_note' => $data['note'],
                ]);

                ActivityLog::record($me, $org->id, 'report.escalated', $report, [
                    'reason' => $report->reason,
                    'note' => $data['note'],
                ]);

                return response()->json([
                    'message' => 'Handed to Netvork.',
                    'data' => $this->serialize($report->fresh(['reporter', 'reportedUser', 'message', 'reviewer', 'escalator'])),
                ]);
        }

        $report->update([
            'status' => $data['action'] === 'dismiss' ? 'dismissed' : 'actioned',
            'action_taken' => match ($data['action']) {
                'dismiss' => 'dismissed',
                'warn' => 'warned',
                default => 'message_deleted',
            },
            'action_note' => $data['note'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        ActivityLog::record($me, $org->id, 'report.' . $data['action'], $report, array_filter([
            'reason' => $report->reason,
            'about' => $target?->name,
            'note' => $data['note'] ?? null,
        ]));

        $report->reporter?->notify(new SocialNotification(
            'report_resolved',
            'Your company admin has reviewed your report and dealt with it.',
        ));

        return response()->json([
            'message' => 'Report ' . $report->fresh()->status . '.',
            'data' => $this->serialize($report->fresh(['reporter', 'reportedUser', 'message', 'reviewer', 'escalator'])),
        ]);
    }

    // ---- Helpers -----------------------------------------------------------

    private function adminOnly(Request $request): Member
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless($me->crm_role === 'admin', 403, 'Reports about your company are for its Admin.');

        return $me;
    }

    private function serialize(Report $r): array
    {
        return [
            'uuid' => $r->uuid,
            'reason' => $r->reason,
            'details' => $r->details,
            'status' => $r->status,
            'action_taken' => $r->action_taken,
            'action_note' => $r->action_note,
            'created_at' => $r->created_at?->toDateTimeString(),
            'reporter' => $r->reporter ? ['uuid' => $r->reporter->uuid, 'name' => $r->reporter->name] : null,
            'reported_user' => $r->reportedUser
                ? ['uuid' => $r->reportedUser->uuid, 'name' => $r->reportedUser->name, 'status' => $r->reportedUser->status]
                : null,
            'message' => $r->message
                ? ['uuid' => $r->message->uuid, 'body' => $r->message->body, 'deleted_at' => $r->message->deleted_at?->toDateTimeString()]
                : null,
            'reviewer' => $r->reviewer?->name,
            'escalated_at' => $r->escalated_at?->toDateTimeString(),
            'escalated_by' => $r->escalator?->name,
            'escalation_note' => $r->escalation_note,
        ];
    }
}
