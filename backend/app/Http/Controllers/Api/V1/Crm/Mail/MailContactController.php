<?php

namespace App\Http\Controllers\Api\V1\Crm\Mail;

use App\Http\Controllers\Controller;
use App\Models\Crm\MailContact;
use App\Models\Crm\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The addresses this person writes to.
 *
 * Gathered as mail goes out and comes in rather than typed up: the name
 * beside an address in a mail header is the name that address answers to,
 * and throwing it away is why everybody re-types addresses they have
 * written to a hundred times.
 *
 * Personal, not the company's. One person's contacts are theirs - the Admin
 * does not read them, and they leave with the member row when somebody
 * goes.
 *
 * Kept per mailbox, too. The people Company Admin writes to are not the
 * people ZMA writes to, and one list of both is a list nobody trusts;
 * All mailboxes shows the lot together, each marked with where it came from.
 */
class MailContactController extends Controller
{
    use ChoosesMailbox;

    /** The address book, newest use first, or the answers to a search. */
    public function index(Request $request): JsonResponse
    {
        $me = $this->member($request);
        $term = trim((string) $request->query('q', ''));
        $mailboxes = $this->mailboxes($request, $me);
        $ids = $mailboxes->pluck('id');

        $contacts = MailContact::where('member_id', $me->id)
            ->whereIn('mail_account_id', $ids)
            ->when($request->boolean('suggest'), fn ($q) => $q->where('is_blocked', false))
            ->when($term !== '', function ($query) use ($term) {
                $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
                $query->where(fn ($q) => $q->whereRaw("email LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("name LIKE ? ESCAPE '!'", [$like]));
            })
            // Whoever has been written to most, most recently, first: that is
            // what makes the first suggestion usually the right one.
            ->orderByDesc('sent_count')
            ->orderByDesc('last_used_at')
            ->limit($request->boolean('suggest') ? 8 : 500)
            ->get();

        $named = $mailboxes->keyBy('id');

        return response()->json([
            'data' => $contacts->map(fn (MailContact $c) => $c->serialize() + [
                'account' => $named->get($c->mail_account_id)?->uuid,
                'account_label' => $named->get($c->mail_account_id)?->label ?: $named->get($c->mail_account_id)?->email,
            ])->values(),
            'total' => MailContact::where('member_id', $me->id)->whereIn('mail_account_id', $ids)->count(),
        ]);
    }

    /** A name somebody typed themselves, or a note, or an address struck off. */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $me = $this->member($request);
        $contact = MailContact::where('member_id', $me->id)->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:500'],
            'is_blocked' => ['boolean'],
        ]);

        if (array_key_exists('name', $data)) {
            $contact->name = $data['name'] ?: null;
            // Theirs now, so nothing arriving later overwrites it.
            $contact->name_is_mine = filled($data['name']);
        }
        if (array_key_exists('note', $data)) {
            $contact->note = $data['note'] ?: null;
        }
        if ($request->has('is_blocked')) {
            $contact->is_blocked = $request->boolean('is_blocked');
        }
        $contact->save();

        return response()->json(['message' => 'Saved.', 'data' => $contact->serialize()]);
    }

    /** Add somebody by hand, before any mail has been exchanged. */
    public function store(Request $request): JsonResponse
    {
        $me = $this->member($request);
        $account = $this->oneMailbox($request, $me);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:320'],
            'name' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $contact = MailContact::remember($me, $data['email'], $data['name'] ?? null, false, $account->id);
        abort_unless($contact, 422, 'That is not an address.');

        if (filled($data['name'] ?? null)) {
            $contact->forceFill(['name' => $data['name'], 'name_is_mine' => true])->save();
        }
        if (filled($data['note'] ?? null)) {
            $contact->forceFill(['note' => $data['note']])->save();
        }
        // Added rather than written to: the count should not pretend otherwise.
        $contact->forceFill(['received_count' => max(0, $contact->received_count - 1)])->save();

        return response()->json(['message' => 'Added to your addresses.', 'data' => $contact->fresh()->serialize()], 201);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $me = $this->member($request);
        MailContact::where('member_id', $me->id)->where('uuid', $uuid)->firstOrFail()->delete();

        return response()->json(['message' => 'Removed from your addresses.']);
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('crm_member');
    }
}
