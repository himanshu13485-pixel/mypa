<?php

namespace App\Http\Controllers\Api\V1\Crm\Mail;

use App\Http\Controllers\Controller;
use App\Models\Crm\MailAccount;
use App\Models\Crm\MailLabel;
use App\Models\Crm\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A mailbox's own labels: made, renamed, recoloured, removed.
 *
 * They belong to the mailbox rather than the person. Somebody holding
 * Company Admin and ZMA files two different sorts of correspondence, and an
 * "Invoices" label meant for one has no business showing while they read the
 * other. All mailboxes shows every label, each named by the mailbox it is in.
 */
class MailLabelController extends Controller
{
    use ChoosesMailbox;

    public function index(Request $request): JsonResponse
    {
        $me = $this->member($request);
        $mailboxes = $this->mailboxes($request, $me);

        $labels = MailLabel::with('filters')
            ->where('member_id', $me->id)
            ->whereIn('mail_account_id', $mailboxes->pluck('id'))
            ->orderBy('name')
            ->get()
            ->map(fn (MailLabel $l) => $this->serialize($l, $mailboxes));

        return response()->json([
            'data' => $labels->values(),
            // What the screen needs to say "4 of 25 used" without counting.
            'cap' => MailAccount::LABEL_CAP,
            'mailboxes' => $mailboxes->map(fn (MailAccount $a) => [
                'uuid' => $a->uuid,
                'label' => $a->label ?: $a->email,
                'email' => $a->email,
                'labels' => $a->labels()->count(),
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $this->member($request);
        $account = $this->oneMailbox($request, $me);
        $data = $this->validated($request, $me, $account);

        abort_if(
            $account->labels()->count() >= MailAccount::LABEL_CAP,
            422,
            'This mailbox already holds ' . MailAccount::LABEL_CAP . ' labels, which is as many as the rail can list. Remove one first.',
        );

        $label = MailLabel::create($data + [
            'organization_id' => $me->organization_id,
            'member_id' => $me->id,
            'mail_account_id' => $account->id,
        ]);

        return response()->json([
            'message' => 'Label created.',
            'data' => $this->serialize($label->fresh('filters'), collect([$account])),
        ], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $me = $this->member($request);
        $label = $this->find($me, $uuid);
        $label->update($this->validated($request, $me, $label->account, $label->id));

        return response()->json([
            'message' => 'Label saved.',
            'data' => $this->serialize($label->fresh('filters'), collect([$label->account])),
        ]);
    }

    /** The label goes, and its rules with it; the mail wearing it stays where it was. */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $label = $this->find($this->member($request), $uuid);
        $label->filters()->delete();
        $label->delete();

        return response()->json(['message' => 'Label removed. The mail it was on is untouched.']);
    }

    private function find(Member $me, string $uuid): MailLabel
    {
        return MailLabel::with('account')->where('member_id', $me->id)->where('uuid', $uuid)->firstOrFail();
    }

    /** @param \Illuminate\Support\Collection<int, MailAccount> $mailboxes */
    private function serialize(MailLabel $label, $mailboxes): array
    {
        $account = $mailboxes->firstWhere('id', $label->mail_account_id);

        return [
            'uuid' => $label->uuid,
            'name' => $label->name,
            'color' => $label->color,
            'account' => $account?->uuid,
            'account_label' => $account?->label ?: $account?->email,
            // The rules that file mail here, so the list can show them inline.
            'filters' => $label->filters->map(fn ($f) => [
                'uuid' => $f->uuid,
                'in_words' => $f->inWords(),
                'is_active' => $f->is_active,
                'matched_count' => $f->matched_count,
            ])->values(),
        ];
    }

    private function validated(Request $request, Member $me, ?MailAccount $account, ?int $ignore = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:64',
                // Unique inside its own mailbox and repeatable across them:
                // two mailboxes may each file something under Invoices.
                Rule::unique('crm_mail_labels', 'name')
                    ->where('member_id', $me->id)
                    ->where('mail_account_id', $account?->id)
                    ->ignore($ignore)],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], ['name.unique' => 'This mailbox already has a label with that name.']);
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('crm_member');
    }
}
