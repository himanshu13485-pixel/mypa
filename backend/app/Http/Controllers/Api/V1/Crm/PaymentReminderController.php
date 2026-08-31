<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\PaymentReminder;
use App\Services\Crm\ReminderComposer;
use App\Support\TextCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Money still owed, and the chasing of it.
 *
 * The outstanding list is computed from the invoices themselves — never a
 * stored "balance" that can drift from the receipts — and every reminder is
 * recorded, so two people cannot chase the same client on the same morning
 * and "we wrote to you on the 3rd" stays a fact.
 */
class PaymentReminderController extends Controller
{
    /** The age buckets an outstanding ledger is read in. */
    private const BUCKETS = [
        ['key' => 'not_due', 'label' => 'Not due yet', 'from' => null, 'to' => 0],
        ['key' => '1_30', 'label' => '1–30 days', 'from' => 1, 'to' => 30],
        ['key' => '31_60', 'label' => '31–60 days', 'from' => 31, 'to' => 60],
        ['key' => '61_90', 'label' => '61–90 days', 'from' => 61, 'to' => 90],
        ['key' => 'over_90', 'label' => 'Over 90 days', 'from' => 91, 'to' => null],
    ];

    public function outstanding(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $query = Invoice::with(['client:id,uuid,company_name,contact_person,email,mobile', 'issuingCompany:id,name', 'member.user:id,name'])
            ->withSum('payments as received', 'amount')
            ->where('organization_id', $org->id)
            ->visibleTo($me)
            ->where('kind', 'invoice')
            ->where('status', '!=', 'cancelled')
            ->whereIn('payment_status', ['due', 'partial']);

        // One salesperson's dues out of the ledger.
        if ($member = $request->query('member')) {
            $query->whereHas('member', fn ($m) => $m->where('uuid', $member));
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('number', 'like', "%{$search}%")
                ->orWhereHas('client', fn ($c) => $c->where('company_name', 'like', "%{$search}%")));
        }
        if ($company = $request->query('issuing_company_id')) {
            $query->where('issuing_company_id', $company);
        }
        if ($client = $request->query('client')) {
            $query->whereHas('client', fn ($c) => $c->where('uuid', $client));
        }

        $invoices = $query->orderByRaw('coalesce(due_date, invoice_date)')->get();

        // The last chase per invoice, in one lookup rather than one each.
        $reminders = PaymentReminder::whereIn('invoice_id', $invoices->pluck('id'))
            ->with('member.user:id,name')
            ->orderByDesc('id')
            ->get()
            ->groupBy('invoice_id');

        $today = now()->startOfDay();
        $rows = $invoices->map(function (Invoice $invoice) use ($reminders, $today) {
            $balance = round((float) $invoice->total - (float) ($invoice->received ?? 0), 2);
            $dueOn = $invoice->due_date ?? $invoice->invoice_date;
            $overdue = (int) $today->diffInDays($dueOn->copy()->startOfDay(), false) * -1;
            $last = ($reminders[$invoice->id] ?? collect())->first();
            $chased = ($reminders[$invoice->id] ?? collect())->count();

            return [
                'uuid' => $invoice->uuid,
                'number' => $invoice->number,
                'client' => $invoice->client ? [
                    'uuid' => $invoice->client->uuid,
                    'company_name' => $invoice->client->company_name,
                    'contact_person' => $invoice->client->contact_person,
                    'email' => $invoice->client->email,
                    'mobile' => $invoice->client->mobile,
                ] : null,
                'issuing_company' => $invoice->issuingCompany?->name,
                'salesperson' => $invoice->member?->user?->name,
                'invoice_date' => $invoice->invoice_date->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'currency' => $invoice->currency,
                'total' => (float) $invoice->total,
                'received' => round((float) ($invoice->received ?? 0), 2),
                'balance' => $balance,
                'days_overdue' => max(0, $overdue),
                'bucket' => $this->bucket($overdue),
                'payment_status' => $invoice->payment_status,
                'reminders' => $chased,
                'last_reminder' => $last ? [
                    'at' => $last->sent_at?->toDateTimeString() ?? $last->created_at->toDateTimeString(),
                    'by' => $last->member?->user?->name,
                    'channel' => $last->channel,
                    'status' => $last->status,
                ] : null,
                'next_follow_up' => ($reminders[$invoice->id] ?? collect())
                    ->pluck('next_follow_up')->filter()->max()?->toDateString(),
            ];
        });

        if ($bucket = $request->query('bucket')) {
            $rows = $bucket === 'due_today'
                ? $rows->filter(fn ($r) => $r['next_follow_up'] !== null && $r['next_follow_up'] <= now()->toDateString())
                : $rows->where('bucket', $bucket);
        }
        if ($request->boolean('never_chased')) {
            $rows = $rows->where('reminders', 0);
        }

        $rows = $rows->values();

        return response()->json([
            'data' => $rows,
            'summary' => [
                'count' => $rows->count(),
                'outstanding' => round($rows->sum('balance'), 2),
                'overdue' => round($rows->where('days_overdue', '>', 0)->sum('balance'), 2),
                'never_chased' => $rows->where('reminders', 0)->count(),
                'due_for_follow_up' => $rows->filter(fn ($r) => $r['next_follow_up'] !== null
                    && $r['next_follow_up'] <= now()->toDateString())->count(),
                'by_bucket' => collect(self::BUCKETS)->map(fn ($b) => [
                    'key' => $b['key'],
                    'label' => $b['label'],
                    'count' => $rows->where('bucket', $b['key'])->count(),
                    'amount' => round($rows->where('bucket', $b['key'])->sum('balance'), 2),
                ])->values(),
            ],
        ]);
    }

    /** The chases against one invoice, and a draft of the next one. */
    public function index(Request $request, string $invoiceUuid): JsonResponse
    {
        $invoice = $this->invoice($request, $invoiceUuid);

        $reminders = $invoice->reminders()->with('member.user:id,name')->orderByDesc('id')->get()
            ->map(fn (PaymentReminder $r) => $this->serialize($r));

        return response()->json([
            'data' => $reminders,
            'draft' => $this->draft($invoice, $request->attributes->get('crm_member')),
        ]);
    }

    /**
     * Chase this invoice: an e-mail to the client, or a note that someone
     * rang. Either way it is recorded and a date can be set to look again.
     */
    public function store(Request $request, string $invoiceUuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $invoice = $this->invoice($request, $invoiceUuid);

        $data = $request->validate([
            'channel' => ['nullable', 'in:email,note'],
            'to_email' => ['nullable', 'email', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:20000'],
            'next_follow_up' => ['nullable', 'date'],
        ]);

        $channel = $data['channel'] ?? 'email';
        $draft = $this->draft($invoice, $me);
        $balance = $this->balance($invoice);

        if ($balance <= 0) {
            abort(422, $invoice->number . ' is settled — there is nothing to chase.');
        }

        $reminder = new PaymentReminder([
            'organization_id' => $org->id,
            'invoice_id' => $invoice->id,
            'member_id' => $me->id,
            'channel' => $channel,
            'balance' => $balance,
            'next_follow_up' => $data['next_follow_up'] ?? null,
            'subject' => $data['subject'] ?? $draft['subject'],
            'body' => $data['body'] ?? $draft['body'],
        ]);

        if ($channel === 'note') {
            // Somebody rang, or walked in. Nothing to send.
            $reminder->fill(['status' => 'logged', 'subject' => null, 'to_email' => null]);
            $reminder->body = $data['body'] ?? null;
        } else {
            $to = TextCase::email($data['to_email'] ?? $invoice->client?->email);
            if (blank($to)) {
                abort(422, ($invoice->client?->company_name ?? 'This client')
                    . ' has no e-mail address on file. Add one, or record a note instead.');
            }

            $reminder->to_email = $to;
            // The invoice's own company mailbox first, then the dues
            // sender, then the general one.
            $resolved = (new \App\Services\Crm\CompanyMailer($org))
                ->resolve($invoice->issuing_company_id, 'dues');
            try {
                $resolved['mailer']->html(nl2br(e($reminder->body)), function ($message) use ($to, $reminder, $resolved) {
                    $message->to($to)->from($resolved['address'], $resolved['name'])->subject($reminder->subject);
                });
                $reminder->fill(['status' => 'sent', 'sent_at' => now()]);
            } catch (\Throwable $e) {
                // An honest failure beats a log that claims it went out.
                $reminder->fill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
            }
        }

        $reminder->save();

        ActivityLog::record($me, $org->id, 'payment.reminder', $invoice, array_filter([
            'number' => $invoice->number,
            'client' => $invoice->client?->company_name,
            'channel' => $channel,
            'to' => $reminder->to_email,
            'status' => $reminder->status,
            'balance' => $balance,
            'next_follow_up' => $reminder->next_follow_up?->toDateString(),
        ]));

        $message = match ($reminder->status) {
            'sent' => 'Reminder sent to ' . $reminder->to_email . '.',
            'failed' => 'The reminder could not be sent — it is recorded as failed.',
            default => 'Noted against ' . $invoice->number . '.',
        };

        return response()->json([
            'message' => $message,
            'data' => $this->serialize($reminder->load('member.user:id,name')),
        ], 201);
    }

    // ---- Helpers -----------------------------------------------------------

    /** The same ledger window as everywhere else. */
    private function invoice(Request $request, string $uuid): Invoice
    {
        return Invoice::with('client', 'issuingCompany')
            ->where('organization_id', $request->attributes->get('crm_org')->id)
            ->visibleTo($request->attributes->get('crm_member'))
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function balance(Invoice $invoice): float
    {
        return round((float) $invoice->total - (float) $invoice->payments()->sum('amount'), 2);
    }

    /** The wording lives in ReminderComposer — the schedule sends it too. */
    private function draft(Invoice $invoice, ?Member $me): array
    {
        return app(ReminderComposer::class)->draft($invoice, $me);
    }

    private function bucket(int $overdue): string
    {
        foreach (self::BUCKETS as $bucket) {
            $from = $bucket['from'];
            $to = $bucket['to'];
            if (($from === null || $overdue >= $from) && ($to === null || $overdue <= $to)) {
                return $bucket['key'];
            }
        }

        return 'over_90';
    }

    private function serialize(PaymentReminder $reminder): array
    {
        return [
            'uuid' => $reminder->uuid,
            'channel' => $reminder->channel,
            'to_email' => $reminder->to_email,
            'subject' => $reminder->subject,
            'body' => $reminder->body,
            'status' => $reminder->status,
            'error' => $reminder->error,
            'balance' => $reminder->balance,
            'next_follow_up' => $reminder->next_follow_up?->toDateString(),
            'by' => $reminder->member?->user?->name,
            'at' => ($reminder->sent_at ?? $reminder->created_at)?->toDateTimeString(),
        ];
    }
}
