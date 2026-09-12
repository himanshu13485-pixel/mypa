<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\BirthdayWish;
use App\Models\Crm\Member;
use App\Notifications\CrmNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Wishing somebody a happy birthday, from inside the CRM.
 *
 * The dashboard already said whose birthday it was, and then left everybody
 * to go and ring them or type a message somewhere else. This closes the loop:
 * everyone else is shown who is celebrating with a message ready to send, the
 * wish lands on the birthday person's screen with the sender's name on it,
 * and they can thank each person back. Every wish and every thank-you is kept,
 * with its date and time, in the Birthdays history.
 */
class BirthdayWishController extends Controller
{
    /** What a wish says when nobody writes one. {name} is the birthday person. */
    public const DEFAULT_WISH = 'Happy Birthday, {name}! 🎂🎉 Wishing you a wonderful year ahead.';

    /** What a thank-you says when nobody writes one. {name} is who wished. */
    public const DEFAULT_REPLY = 'Thank you so much for the wishes, {name}! 🙏🎂';

    /** What a wish sent after the day says when nobody writes one. */
    public const DEFAULT_BELATED = 'Belated Happy Birthday, {name}! 🎂 Sorry I missed the day - wishing you a wonderful year ahead.';

    /** How long after a birthday it can still be wished. */
    public const BELATED_DAYS = 7;

    /**
     * Birthdays of the last week, for somebody who missed them.
     *
     * The day has gone, so there is no popup; the Birthdays menu lists who
     * celebrated in the last seven days, each with the wish this person has
     * already sent (if any), and the words a belated wish starts from.
     */
    public function recent(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $today = now()->startOfDay();

        $missed = Member::visible()
            ->with(['user:id,name', 'user.profile:user_id,photo_path,avatar,gender'])
            ->where('organization_id', $org->id)
            ->where('status', 'active')
            ->whereNotNull('dob')
            ->where('id', '!=', $me->id)
            ->get()
            ->map(fn (Member $m) => ['member' => $m, 'on' => $this->lastBirthday($m)])
            ->filter(fn (array $p) => $this->daysLate($p['on']) !== null)
            ->sortByDesc(fn (array $p) => $p['on']->timestamp)
            ->values();

        $sent = BirthdayWish::with(['from.user:id,name', 'to.user:id,name'])
            ->where('from_member_id', $me->id)
            ->whereIn('to_member_id', $missed->pluck('member.id'))
            ->get()
            ->keyBy(fn (BirthdayWish $w) => $w->to_member_id . '-' . $w->birthday_year);

        return response()->json(['data' => [
            'missed' => $missed->map(fn (array $p) => [
                'uuid' => $p['member']->uuid,
                'name' => $p['member']->user?->name,
                'photo_path' => $p['member']->user?->profile?->photo_path,
                'avatar' => $p['member']->user?->profile?->avatar,
                'gender' => $p['member']->gender ?? $p['member']->user?->profile?->gender,
                'birthday_on' => $p['on']->toDateString(),
                'days_ago' => $this->daysLate($p['on']),
                'my_wish' => isset($sent[$p['member']->id . '-' . $p['on']->year])
                    ? $this->serialize($sent[$p['member']->id . '-' . $p['on']->year]) : null,
            ]),
            'default_belated' => \App\Support\Appearance::birthday($me->user, $org)['belated'],
            'window_days' => self::BELATED_DAYS,
        ]]);
    }

    /**
     * Who is celebrating today, and what has already been said.
     *
     * For everybody else: the people whose birthday it is, each with the
     * wish this person already sent (if any), so the popup only asks about
     * the ones not yet wished. For the birthday person: every wish that has
     * reached them, newest first, so they can see who remembered.
     */
    public function today(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $year = (int) now()->format('Y');

        $celebrating = Member::visible()
            ->with(['user:id,name', 'user.profile:user_id,photo_path,avatar,gender'])
            ->where('organization_id', $org->id)
            ->where('status', 'active')
            ->whereNotNull('dob')
            ->get()
            ->filter(fn (Member $m) => $m->dob->isBirthday());

        $sent = BirthdayWish::with(['from.user:id,name', 'to.user:id,name'])
            ->where('from_member_id', $me->id)
            ->where('birthday_year', $year)
            ->whereIn('to_member_id', $celebrating->pluck('id'))
            ->get()
            ->keyBy('to_member_id');

        $mine = $me->dob !== null && $me->dob->isBirthday();

        $received = $mine
            ? BirthdayWish::with('from.user:id,name')
                ->where('to_member_id', $me->id)
                ->where('birthday_year', $year)
                ->orderByDesc('id')
                ->get()
                ->map(fn (BirthdayWish $w) => $this->serialize($w))
            : collect();

        return response()->json(['data' => [
            // Nobody is asked to wish themselves.
            'celebrating' => $celebrating
                ->reject(fn (Member $m) => $m->id === $me->id)
                ->map(fn (Member $m) => [
                    'uuid' => $m->uuid,
                    'name' => $m->user?->name,
                    'photo_path' => $m->user?->profile?->photo_path,
                    'avatar' => $m->user?->profile?->avatar,
                    'gender' => $m->gender ?? $m->user?->profile?->gender,
                    'my_wish' => isset($sent[$m->id]) ? $this->serialize($sent[$m->id]) : null,
                ])
                ->values(),
            'is_my_birthday' => $mine,
            'received' => $received->values(),
            'default_wish' => $this->defaultWish($org, $me),
            'default_reply' => $this->defaultReply($org, $me),
        ]]);
    }

    /**
     * Wish somebody a happy birthday.
     *
     * Only on the day, and never yourself. Sending a second wish replaces
     * the first rather than putting two on somebody's screen - the unique
     * index would refuse a second row anyway, and an updated message is
     * what a person pressing the button twice actually meant.
     */
    public function wish(Request $request, string $memberUuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $target = Member::with('user:id,name')
            ->where('organization_id', $org->id)
            ->where('status', 'active')
            ->where('uuid', $memberUuid)
            ->firstOrFail();

        abort_if($target->id === $me->id, 422, 'Nobody gets to wish themselves a happy birthday - that is what the rest of us are for.');

        /*
         * On the day, or up to a week after it. A late wish belongs to the
         * birthday it is late for - a New Year's Eve birthday wished on
         * 2 January is last year's - and says so on the wish.
         */
        $onTheDay = $target->dob !== null && $target->dob->isBirthday();
        $last = $this->lastBirthday($target);
        $late = $onTheDay ? null : $this->daysLate($last);

        abort_unless(
            $onTheDay || $late !== null,
            422,
            'It is not ' . ($target->user?->name ?? 'their') . "'s birthday today, and it was not in the last "
                . self::BELATED_DAYS . ' days either.',
        );

        $data = $request->validate(['message' => ['nullable', 'string', 'max:1000']]);

        $message = trim((string) ($data['message'] ?? ''))
            ?: str_replace(
                '{name}',
                $target->user?->name ?? 'you',
                $onTheDay ? $this->defaultWish($org, $me) : \App\Support\Appearance::birthday($me->user, $org)['belated'],
            );

        $wish = BirthdayWish::firstOrNew([
            'from_member_id' => $me->id,
            'to_member_id' => $target->id,
            'birthday_year' => $onTheDay ? (int) now()->format('Y') : $last->year,
        ]);
        $wish->fill(['organization_id' => $org->id, 'message' => $message]);
        // Editing a wish sent on the day does not make it late.
        if (! $wish->exists) {
            $wish->belated = ! $onTheDay;
        }
        $wish->save();

        $target->user?->notify(new CrmNotification(
            'crm_birthday',
            ($me->user?->name ?? 'Someone') . ($wish->belated ? ' wished you a belated happy birthday: “' : ' wished you a happy birthday: “')
                . str($message)->limit(120) . '”',
            '/crm/birthdays',
        ));

        ActivityLog::record($me, $org->id, 'birthday.wished', $wish, [
            'to' => $target->user?->name,
            'belated' => $wish->belated,
            'message' => str($message)->limit(200)->toString(),
        ]);

        return response()->json([
            'message' => 'Wish sent to ' . ($target->user?->name ?? 'them') . '. 🎉',
            'data' => $this->serialize($wish->load(['from.user:id,name', 'to.user:id,name'])),
        ], $wish->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * The birthday person says thank you.
     *
     * Only the person the wish was addressed to may answer it, and answering
     * counts as having seen it. Answering again replaces the thank-you.
     */
    public function reply(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $wish = BirthdayWish::with(['from.user:id,name', 'to.user:id,name'])
            ->where('organization_id', $org->id)
            ->where('to_member_id', $me->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        $data = $request->validate(['message' => ['nullable', 'string', 'max:1000']]);

        $reply = trim((string) ($data['message'] ?? ''))
            ?: str_replace('{name}', $wish->from?->user?->name ?? 'you', $this->defaultReply($org, $me));

        $wish->update([
            'reply' => $reply,
            'replied_at' => now(),
            'seen_at' => $wish->seen_at ?? now(),
        ]);

        $wish->from?->user?->notify(new CrmNotification(
            'crm_birthday',
            ($me->user?->name ?? 'The birthday person') . ' thanked you: “' . str($reply)->limit(120) . '”',
            '/crm/birthdays',
        ));

        ActivityLog::record($me, $org->id, 'birthday.replied', $wish, [
            'to' => $wish->from?->user?->name,
            'message' => str($reply)->limit(200)->toString(),
        ]);

        return response()->json([
            'message' => 'Thank-you sent.',
            'data' => $this->serialize($wish->fresh(['from.user:id,name', 'to.user:id,name'])),
        ]);
    }

    /**
     * Every wish and every thank-you, with dates and times.
     *
     * An Admin or Subadmin sees the whole company's; anybody else sees the
     * wishes they sent and the ones they received - a birthday message is
     * between the two people on it.
     */
    public function history(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $manages = in_array($me->crm_role, ['admin', 'subadmin'], true);

        $query = BirthdayWish::with(['from.user:id,name', 'to.user:id,name'])
            ->where('organization_id', $org->id);

        if (! $manages) {
            $query->where(fn ($q) => $q->where('from_member_id', $me->id)->orWhere('to_member_id', $me->id));
        }

        if ($year = $request->query('year')) {
            $query->where('birthday_year', (int) $year);
        }
        match ($request->query('direction')) {
            'sent' => $query->where('from_member_id', $me->id),
            'received' => $query->where('to_member_id', $me->id),
            default => null,
        };
        if ($manages && ($member = $request->query('member'))) {
            $query->where(fn ($q) => $q
                ->whereHas('from', fn ($m) => $m->where('uuid', $member))
                ->orWhereHas('to', fn ($m) => $m->where('uuid', $member)));
        }

        $wishes = $query->orderByDesc('id')->paginate(30);
        $wishes->getCollection()->transform(fn (BirthdayWish $w) => $this->serialize($w));

        return response()->json($wishes->toArray() + [
            'years' => BirthdayWish::where('organization_id', $org->id)
                ->distinct()->orderByDesc('birthday_year')->pluck('birthday_year'),
        ]);
    }

    // ---- Helpers -----------------------------------------------------------

    /*
     * The words start in the voice of whoever is writing: their own default
     * from CRM Theme, else the company's, else Netvork's, else the built-in.
     */
    private function defaultWish($org, Member $me): string
    {
        return \App\Support\Appearance::birthday($me->user, $org)['wish'];
    }

    private function defaultReply($org, Member $me): string
    {
        return \App\Support\Appearance::birthday($me->user, $org)['reply'];
    }

    /**
     * When this person's latest birthday fell - today or before.
     *
     * A 29 February birthday falls on the 28th in other years, which is when
     * everybody who knows them celebrates it anyway.
     */
    private function lastBirthday(Member $m): ?Carbon
    {
        if ($m->dob === null) {
            return null;
        }

        $today = now()->startOfDay();
        $in = function (int $year) use ($m) {
            $day = $m->dob->month === 2 && $m->dob->day === 29 && ! Carbon::create($year)->isLeapYear()
                ? 28 : $m->dob->day;

            return Carbon::create($year, $m->dob->month, $day)->startOfDay();
        };

        $date = $in($today->year);

        return $date->greaterThan($today) ? $in($today->year - 1) : $date;
    }

    /** Days since a birthday, while it can still be wished late; null otherwise. */
    private function daysLate(?Carbon $on): ?int
    {
        if ($on === null) {
            return null;
        }

        $days = (int) round($on->diffInDays(now()->startOfDay()));

        return $days >= 1 && $days <= self::BELATED_DAYS ? $days : null;
    }

    private function serialize(BirthdayWish $w): array
    {
        return [
            'uuid' => $w->uuid,
            'birthday_year' => $w->birthday_year,
            'from' => $w->relationLoaded('from') && $w->from
                ? ['uuid' => $w->from->uuid, 'name' => $w->from->user?->name] : null,
            'to' => $w->relationLoaded('to') && $w->to
                ? ['uuid' => $w->to->uuid, 'name' => $w->to->user?->name] : null,
            'message' => $w->message,
            'reply' => $w->reply,
            'belated' => (bool) $w->belated,
            'sent_at' => $w->created_at?->toDateTimeString(),
            'updated_at' => $w->updated_at?->toDateTimeString(),
            'replied_at' => $w->replied_at?->toDateTimeString(),
        ];
    }
}
