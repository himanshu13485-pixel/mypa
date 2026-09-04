<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Crm\Client;
use App\Models\Crm\Complaint;
use App\Models\Crm\Lead;
use App\Models\Crm\Member;
use App\Models\PhoneCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Recording the calls that leave the app.
 *
 * Two halves, because two different things are knowable at two different
 * times. Dialling is ours: the app opened the dialler, so the attempt, the
 * number and the moment are all facts. What happened next is not — Android
 * does not let an app watch a cellular call — so the outcome and the length
 * come back afterwards from the person who made it, or never.
 *
 * A call with no outcome is left plainly unanswered rather than assumed
 * failed. "Nobody said" and "nobody picked up" are different facts about a
 * lead, and only one of them is about the lead.
 */
class PhoneCallController extends Controller
{
    /** The kinds of record a call can be attached to. */
    private const SUBJECTS = [
        'lead' => Lead::class,
        'client' => Client::class,
        'complaint' => Complaint::class,
    ];

    /**
     * A call is being placed now.
     *
     * Called as the dialler opens, not after — waiting until afterwards means
     * losing every call where somebody rang, talked, and put the phone down
     * without coming back to the app, which on a sales floor is most of them.
     */
    public function store(Request $request): JsonResponse
    {
        $me = $request->user();

        $data = $request->validate([
            'number' => ['required', 'string', 'max:32'],
            'label' => ['nullable', 'string', 'max:120'],
            'placed_from' => ['nullable', Rule::in(['phone', 'laptop'])],
            'subject_type' => ['nullable', Rule::in(array_keys(self::SUBJECTS))],
            'subject_uuid' => ['nullable', 'uuid', 'required_with:subject_type'],
        ]);

        [$subjectType, $subjectId, $organizationId] = $this->resolveSubject($request, $data);

        $call = PhoneCall::create([
            'user_id' => $me->id,
            'organization_id' => $organizationId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'number' => $data['number'],
            'label' => $data['label'] ?? null,
            'placed_from' => $data['placed_from'] ?? 'phone',
            'placed_at' => now(),
        ]);

        return response()->json(['data' => $call->serialize()], 201);
    }

    /**
     * How it went, said afterwards.
     *
     * Only by the person who made it. Somebody else's account of a call they
     * were not on is not a record, and a manager correcting a salesperson's
     * own log would make the whole trail worth less than it costs to keep.
     */
    public function update(Request $request, PhoneCall $phoneCall): JsonResponse
    {
        abort_unless($phoneCall->user_id === $request->user()->id, 403,
            'Only the person who made a call can say how it went.');

        $data = $request->validate([
            'outcome' => ['required', Rule::in(array_keys(PhoneCall::OUTCOMES))],
            // Minutes are what a person remembers; seconds are what is stored.
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:' . (8 * 3600)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        /*
         * A call nobody spoke on has no length worth keeping. Storing the
         * seconds somebody happened to leave in the box would put "no answer,
         * 4 minutes" on a lead's history.
         */
        if ($data['outcome'] !== 'connected') {
            $data['duration_seconds'] = null;
        }

        $phoneCall->update($data + ['ended_at' => now()]);

        return response()->json(['data' => $phoneCall->fresh()->serialize()]);
    }

    /**
     * What is still unanswered, so the app can ask.
     *
     * Only today's, and only this person's: a prompt about a call from last
     * week is archaeology, and nobody remembers well enough for the answer to
     * be worth having.
     */
    public function pending(Request $request): JsonResponse
    {
        $calls = PhoneCall::where('user_id', $request->user()->id)
            ->whereNull('outcome')
            ->where('placed_at', '>=', now()->subHours(12))
            ->latest('placed_at')
            ->limit(5)
            ->get();

        return response()->json(['data' => $calls->map->serialize()]);
    }

    /**
     * Work out what was rung, and confirm this person may ring it.
     *
     * The check matters: without it the subject is whatever the browser said
     * it was, and a call log becomes a way to write a line into any lead in
     * any company by guessing at uuids.
     *
     * @return array{0: ?string, 1: ?int, 2: ?int}
     */
    protected function resolveSubject(Request $request, array $data): array
    {
        if (empty($data['subject_type'])) {
            return [null, null, null];
        }

        /** @var Member|null $member */
        $member = $request->attributes->get('crm_member');
        abort_unless($member, 403, 'That belongs to a company workspace.');

        $model = self::SUBJECTS[$data['subject_type']];
        $subject = $model::where('uuid', $data['subject_uuid'])
            ->where('organization_id', $member->organization_id)
            ->first();

        abort_unless($subject, 404, 'That record is not here.');

        /*
         * And inside their own ledger window. Somebody who cannot open a lead
         * has no business writing to its call history — which is exactly what
         * an unchecked subject would let them do.
         */
        abort_unless(
            $model::where('id', $subject->id)->visibleTo($member)->exists(),
            403,
            'That record is not yours.',
        );

        return [$model, $subject->id, $member->organization_id];
    }
}
