<?php

namespace App\Http\Controllers\Api\V1\Crm\Mail;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\MailAccount;
use App\Models\Crm\Member;
use App\Services\Mail\MailConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Forwarding, to addresses that said yes.
 *
 * Adding an address sends it a six-figure code; nothing is forwarded there
 * until somebody types that code back. It is the difference between a
 * mailbox that copies its mail to a colleague and one that copies a
 * company's mail to a typo for a year without anybody noticing.
 *
 * The code is kept hashed, expires in fifteen minutes, and gives up after
 * five wrong tries - the same rules as any other code worth having.
 */
class MailForwardController extends Controller
{
    public const MAX_FORWARDS = 5;

    public function __construct(private MailConnector $connector)
    {
    }

    /** Add an address and send it a code. */
    public function store(Request $request, MailAccount $account): JsonResponse
    {
        $me = $this->manageable($request, $account);
        $data = $request->validate(['address' => ['required', 'email', 'max:255']]);
        $address = strtolower(trim($data['address']));

        abort_if($address === strtolower($account->email), 422, 'That is this mailbox - forwarding it to itself would loop.');

        $forwards = $this->indexed($account);
        abort_if(count($forwards) >= self::MAX_FORWARDS && ! isset($forwards[$address]), 422,
            'A mailbox can forward to at most ' . self::MAX_FORWARDS . ' addresses.');

        // Re-adding an address that was verified sends a fresh code and takes
        // the tick away until it answers again.
        $code = (string) random_int(100000, 999999);
        $forwards[$address] = [
            'address' => $address,
            'code' => Hash::make($code),
            'code_sent_at' => now()->toIso8601String(),
            'tries' => 0,
            'verified_at' => null,
        ];

        $this->keep($account, $forwards);

        try {
            $this->sendCode($account, $address, $code, $me);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Could not send the code: ' . MailConnector::plain($e),
                'data' => $account->fresh()->serialize(),
            ], 422);
        }

        return response()->json([
            'message' => "A six-figure code has been sent to {$address}. It is good for fifteen minutes.",
            'data' => $account->fresh()->serialize(),
        ]);
    }

    /** Type the code back, and the address starts receiving. */
    public function verify(Request $request, MailAccount $account): JsonResponse
    {
        $me = $this->manageable($request, $account);
        $data = $request->validate([
            'address' => ['required', 'email'],
            'code' => ['required', 'string', 'max:10'],
        ]);
        $address = strtolower(trim($data['address']));

        $forwards = $this->indexed($account);
        $entry = $forwards[$address] ?? null;
        abort_unless($entry, 404, 'That address is not on this mailbox.');

        abort_if(($entry['tries'] ?? 0) >= 5, 422, 'Too many wrong codes. Send a new one.');
        // Signed in Carbon 3, so the sign is taken out rather than trusted.
        $age = empty($entry['code_sent_at']) ? PHP_INT_MAX : abs(now()->diffInMinutes($entry['code_sent_at']));
        abort_if($age > 15, 422, 'That code has expired. Send a new one.');

        if (! Hash::check(trim($data['code']), (string) ($entry['code'] ?? ''))) {
            $entry['tries'] = (int) ($entry['tries'] ?? 0) + 1;
            $forwards[$address] = $entry;
            $this->keep($account, $forwards);

            abort(422, 'That code does not match.');
        }

        $forwards[$address] = ['address' => $address, 'verified_at' => now()->toIso8601String(), 'code' => null, 'tries' => 0];
        $this->keep($account, $forwards);

        ActivityLog::record($me, $me->organization_id, 'mail_forward.verified', $account, ['to' => $address]);

        return response()->json([
            'message' => "{$address} is verified. New mail will be forwarded there.",
            'data' => $account->fresh()->serialize(),
        ]);
    }

    /** Stop forwarding to an address. */
    public function destroy(Request $request, MailAccount $account): JsonResponse
    {
        $me = $this->manageable($request, $account);
        $address = strtolower(trim((string) $request->validate(['address' => ['required', 'email']])['address']));

        $forwards = collect((array) $account->forwards)
            ->reject(fn ($f) => strtolower((string) ($f['address'] ?? '')) === $address)
            ->values()->all();

        $account->forceFill(['forwards' => $forwards])->save();
        // The older single address is the same setting by another name.
        if (strtolower((string) $account->forward_to) === $address) {
            $account->forceFill(['forward_to' => null])->save();
        }

        ActivityLog::record($me, $me->organization_id, 'mail_forward.removed', $account, ['to' => $address]);

        return response()->json(['message' => "Stopped forwarding to {$address}.", 'data' => $account->fresh()->serialize()]);
    }

    /** The addresses on this mailbox, keyed by address, as plain arrays. */
    private function indexed(MailAccount $account): array
    {
        $out = [];
        foreach ((array) $account->forwards as $entry) {
            $address = strtolower((string) ($entry['address'] ?? ''));
            if ($address !== '') {
                $out[$address] = $entry;
            }
        }

        return $out;
    }

    private function keep(MailAccount $account, array $forwards): void
    {
        $account->forceFill(['forwards' => array_values($forwards)])->save();
    }

    /** The code itself, sent from the mailbox that wants to forward. */
    private function sendCode(MailAccount $account, string $address, string $code, Member $me): void
    {
        abort_unless($account->canSend(), 422, 'This mailbox has no outgoing (SMTP) server, so it cannot send the code.');

        $body = '<p>' . e($me->user?->name ?: 'Somebody') . ' would like <b>' . e($account->email)
            . '</b> to forward its mail to this address.</p>'
            . '<p style="font-size:24px;letter-spacing:4px;font-weight:700">' . $code . '</p>'
            . '<p>Type this code back in Netvork Mails to allow it. It is good for fifteen minutes.</p>'
            . '<p style="color:#64748b">If you were not expecting this, ignore it - nothing is forwarded until the code is typed back.</p>';

        $this->connector->smtp($account)->send(
            ['html' => new HtmlString($body), 'text' => new HtmlString(strip_tags(str_replace('</p>', "\n\n", $body)))],
            [],
            function ($message) use ($account, $address) {
                $sender = $account->sender();
                $message->from($sender['address'], $sender['name'])
                    ->to($address)
                    ->subject('Code to forward mail from ' . $account->email);
            }
        );
    }

    private function manageable(Request $request, MailAccount $account): Member
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $mine = $account->member_id === $me->id
            || ($me->crm_role === 'admin' && $account->organization_id === $me->organization_id);
        abort_unless($mine, 404);

        return $me;
    }
}
