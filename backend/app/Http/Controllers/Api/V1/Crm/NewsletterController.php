<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\Newsletter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Newsletters: an HTML mail to a chosen audience (active clients, all
 * clients, leads, or a pasted list). Recipients are resolved at send time,
 * deduplicated, and the send is counted address by address so the stats
 * are honest about failures.
 */
class NewsletterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        $rows = Newsletter::with('creator:id,name')
            ->where('organization_id', $org->id)
            ->orderByDesc('id')
            ->paginate(20);
        $rows->getCollection()->transform(fn ($n) => $this->serialize($n));

        return response()->json(['audiences' => $this->audienceCounts($org->id)] + $rows->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $newsletter = Newsletter::create($this->validateNewsletter($request) + [
            'organization_id' => $org->id,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Draft saved.', 'data' => $this->serialize($newsletter)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $newsletter = $this->find($request, $uuid);
        if ($newsletter->status === 'sent') {
            abort(422, 'A sent newsletter cannot be edited.');
        }

        $newsletter->update($this->validateNewsletter($request));

        return response()->json(['message' => 'Draft saved.', 'data' => $this->serialize($newsletter->fresh())]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $newsletter = $this->find($request, $uuid);
        if ($newsletter->status === 'sent') {
            abort(422, 'Sent newsletters are history and cannot be deleted.');
        }
        $newsletter->delete();

        return response()->json(['message' => 'Draft deleted.']);
    }

    public function send(Request $request, string $uuid): JsonResponse
    {
        $newsletter = $this->find($request, $uuid);
        if ($newsletter->status === 'sent') {
            abort(422, 'Already sent.');
        }

        $recipients = $this->resolveRecipients($newsletter);
        if ($recipients->isEmpty()) {
            abort(422, 'The chosen audience has no email addresses.');
        }

        /*
         * Out of the company's own mailbox, not the platform's.
         *
         * A newsletter belongs to no single issuing company, and that used to
         * mean it left as Netvork — from an address the recipient does not
         * recognise, and failing the SPF and DKIM records of the domain it
         * claims to be from. Resolved once, outside the loop: it is the same
         * mailbox for every recipient.
         */
        $resolved = (new \App\Services\Crm\CompanyMailer($newsletter->organization))->resolve(null);

        $sent = 0;
        $failed = 0;
        foreach ($recipients as $email) {
            try {
                $resolved['mailer']->html($newsletter->body, function ($message) use ($email, $newsletter, $resolved) {
                    $message->from($resolved['address'], $resolved['name'])
                        ->to($email)->subject($newsletter->subject);
                });
                $sent++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        $newsletter->update([
            'status' => 'sent',
            'sent_at' => now(),
            'sent_count' => $sent,
            'failed_count' => $failed,
        ]);

        return response()->json([
            'message' => 'Sent to ' . $sent . ' recipients' . ($failed ? " ({$failed} failed)" : '') . '.',
            'data' => $this->serialize($newsletter->fresh()),
        ]);
    }

    // ---- Helpers -----------------------------------------------------------

    private function find(Request $request, string $uuid): Newsletter
    {
        return Newsletter::where('organization_id', $request->attributes->get('crm_org')->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function validateNewsletter(Request $request): array
    {
        return $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:200000'],
            'audience' => ['required', Rule::in(Newsletter::AUDIENCES)],
            'custom_recipients' => ['nullable', 'array', 'max:2000'],
            'custom_recipients.*' => ['email'],
        ]);
    }

    private function audienceCounts(int $orgId): array
    {
        return [
            'active_clients' => Client::where('organization_id', $orgId)->where('status', 'active')->whereNotNull('email')->where('email', '!=', '')->count(),
            'all_clients' => Client::where('organization_id', $orgId)->whereNotNull('email')->where('email', '!=', '')->count(),
            'leads' => Lead::where('organization_id', $orgId)->whereNotNull('email')->where('email', '!=', '')->count(),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    private function resolveRecipients(Newsletter $n)
    {
        $orgId = $n->organization_id;

        $emails = match ($n->audience) {
            'active_clients' => Client::where('organization_id', $orgId)->where('status', 'active')
                ->whereNotNull('email')->where('email', '!=', '')->pluck('email'),
            'all_clients' => Client::where('organization_id', $orgId)
                ->whereNotNull('email')->where('email', '!=', '')->pluck('email'),
            'leads' => Lead::where('organization_id', $orgId)
                ->whereNotNull('email')->where('email', '!=', '')->pluck('email'),
            'custom' => collect($n->custom_recipients ?? []),
        };

        return $emails->map(fn ($e) => mb_strtolower(trim($e)))->filter()->unique()->values();
    }

    private function serialize(Newsletter $n): array
    {
        return [
            'uuid' => $n->uuid,
            'subject' => $n->subject,
            'body' => $n->body,
            'audience' => $n->audience,
            'custom_recipients' => $n->custom_recipients,
            'status' => $n->status,
            'sent_at' => $n->sent_at?->toDateTimeString(),
            'sent_count' => $n->sent_count,
            'failed_count' => $n->failed_count,
            'created_by' => $n->creator?->name,
            'created_at' => $n->created_at?->toDateTimeString(),
        ];
    }
}
