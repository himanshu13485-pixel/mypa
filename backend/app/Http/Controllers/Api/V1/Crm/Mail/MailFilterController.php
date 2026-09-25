<?php

namespace App\Http\Controllers\Api\V1\Crm\Mail;

use App\Http\Controllers\Controller;
use App\Models\Crm\MailFilter;
use App\Models\Crm\MailLabel;
use App\Models\Crm\Member;
use App\Services\Mail\MailFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Standing instructions about arriving mail.
 *
 * A rule says what to look for - who it came from, what the subject or the
 * body says, whether it carries a file - and what to do when it is found,
 * which is usually "wear this label". The OTP that arrives eleven times a
 * day then files itself.
 *
 * A rule belongs to a mailbox, like the label it files into. Writing one
 * offers to run it over the mail already sitting there, because a new OTP
 * label is no use while two hundred OTPs remain unlabelled in the Inbox.
 */
class MailFilterController extends Controller
{
    use ChoosesMailbox;

    public function __construct(private MailFilters $filters) {}

    public function index(Request $request): JsonResponse
    {
        $me = $this->member($request);

        $rules = MailFilter::with(['label', 'account'])
            ->where('member_id', $me->id)
            ->whereIn('mail_account_id', $this->mailboxIds($request, $me))
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $rules->map(fn (MailFilter $f) => $this->serialize($f))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $this->member($request);
        $account = $this->oneMailbox($request, $me);
        $data = $this->validated($request, $me, $account->id);

        $filter = MailFilter::create($data + [
            'organization_id' => $me->organization_id,
            'member_id' => $me->id,
            'mail_account_id' => $account->id,
        ]);
        abort_if(! $filter->asks(), 422, 'Give the rule something to look for - a rule with no conditions would catch every mail.');

        $ran = $request->boolean('apply_now') ? $this->filters->backfill($filter) : 0;

        return response()->json([
            'message' => $ran
                ? "Filter saved, and {$ran} " . ($ran === 1 ? 'message' : 'messages') . ' already here matched it.'
                : 'Filter saved. It applies to mail from now on.',
            'data' => $this->serialize($filter->fresh(['label', 'account'])),
        ], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $me = $this->member($request);
        $filter = $this->find($me, $uuid);
        $filter->update($this->validated($request, $me, $filter->mail_account_id));
        abort_if(! $filter->asks(), 422, 'Give the rule something to look for - a rule with no conditions would catch every mail.');

        $ran = $request->boolean('apply_now') ? $this->filters->backfill($filter) : 0;

        return response()->json([
            'message' => $ran ? "Filter saved, and {$ran} matched." : 'Filter saved.',
            'data' => $this->serialize($filter->fresh(['label', 'account'])),
        ]);
    }

    /** Run an existing rule back over the mail already in the mailbox. */
    public function run(Request $request, string $uuid): JsonResponse
    {
        $filter = $this->find($this->member($request), $uuid);
        $matched = $this->filters->backfill($filter);

        return response()->json([
            'message' => $matched
                ? "{$matched} " . ($matched === 1 ? 'message' : 'messages') . ' matched and were filed.'
                : 'Nothing already here matches this rule.',
            'data' => $this->serialize($filter->fresh(['label', 'account'])),
        ]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->find($this->member($request), $uuid)->delete();

        return response()->json(['message' => 'Filter removed. The mail it labelled keeps its label.']);
    }

    private function find(Member $me, string $uuid): MailFilter
    {
        return MailFilter::where('member_id', $me->id)->where('uuid', $uuid)->firstOrFail();
    }

    private function serialize(MailFilter $filter): array
    {
        return [
            'uuid' => $filter->uuid,
            'account' => $filter->account?->uuid,
            'account_label' => $filter->account?->label ?: $filter->account?->email,
            'label' => $filter->label?->uuid,
            'label_name' => $filter->label?->name,
            'label_color' => $filter->label?->color,
            'from_has' => $filter->from_has,
            'to_has' => $filter->to_has,
            'subject_has' => $filter->subject_has,
            'body_has' => $filter->body_has,
            'body_lacks' => $filter->body_lacks,
            'has_attachment' => $filter->has_attachment,
            'size_op' => $filter->size_op,
            'size_kb' => $filter->size_kb,
            'mark_read' => $filter->mark_read,
            'star' => $filter->star,
            'skip_inbox' => $filter->skip_inbox,
            'never_spam' => $filter->never_spam,
            'is_active' => $filter->is_active,
            'matched_count' => $filter->matched_count,
            'last_matched_at' => $filter->last_matched_at?->toIso8601String(),
            'in_words' => $filter->inWords(),
        ];
    }

    private function validated(Request $request, Member $me, int $accountId): array
    {
        $data = $request->validate([
            // The label a match is filed under, which must live in the same
            // mailbox - a rule cannot file ZMA's mail under a GrapOut label.
            'label' => ['nullable', 'string',
                Rule::exists('crm_mail_labels', 'uuid')
                    ->where('member_id', $me->id)
                    ->where('mail_account_id', $accountId)],
            'from_has' => ['nullable', 'string', 'max:320'],
            'to_has' => ['nullable', 'string', 'max:320'],
            'subject_has' => ['nullable', 'string', 'max:255'],
            'body_has' => ['nullable', 'string', 'max:255'],
            'body_lacks' => ['nullable', 'string', 'max:255'],
            'has_attachment' => ['nullable', 'boolean'],
            'size_op' => ['nullable', Rule::in(['gt', 'lt'])],
            'size_kb' => ['nullable', 'integer', 'between:1,102400'],
            'mark_read' => ['boolean'],
            'star' => ['boolean'],
            'skip_inbox' => ['boolean'],
            'never_spam' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $data['mail_label_id'] = filled($data['label'] ?? null)
            ? MailLabel::where('uuid', $data['label'])->value('id')
            : null;
        unset($data['label']);

        // A size test needs both halves or neither: "larger than" nothing is
        // not a question, and a number with no comparison is not either.
        if (blank($data['size_op'] ?? null) || blank($data['size_kb'] ?? null)) {
            $data['size_op'] = null;
            $data['size_kb'] = null;
        }

        return $data;
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('crm_member');
    }
}
