<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\PaymentReminder;
use App\Services\Crm\CompanyMailer;
use App\Services\Crm\TdsCertificateComposer;
use App\Support\TextCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Chasing the certificate for tax the client already deducted.
 *
 * The money left our invoice and went to the government on our behalf; the
 * certificate is the only proof we have of it, and without it the deduction
 * is ours to absorb. So this is the same machinery as chasing a payment -
 * the same table, the same trail, the same "we wrote to you on the 3rd" -
 * pointed at a different thing that is owed.
 *
 * Open to anybody who can see the invoice: the person who notices a missing
 * certificate is usually whoever raised the bill, not the Admin.
 */
class TdsCertificateController extends Controller
{
    /**
     * Every invoice with tax deducted on it, and whether the certificate
     * has come in. Pending first, because that is the working list.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $query = Invoice::with([
            'client:id,uuid,company_name,contact_person,email,mobile',
            'issuingCompany:id,name',
            'member.user:id,name',
            'certifier.user:id,name',
        ])
            ->where('organization_id', $org->id)
            ->visibleTo($me)
            ->where('kind', 'invoice')
            ->where('status', '!=', 'cancelled')
            ->where('tds', '>', 0);

        if ($request->query('state') === 'received') {
            $query->whereNotNull('tds_certificate_at');
        } elseif ($request->query('state') !== 'all') {
            $query->whereNull('tds_certificate_at');
        }
        if ($member = $request->query('member')) {
            $query->whereHas('member', fn ($m) => $m->where('uuid', $member));
        }
        if ($client = $request->query('client')) {
            $query->whereHas('client', fn ($c) => $c->where('uuid', $client));
        }
        if ($company = $request->query('issuing_company_id')) {
            $query->where('issuing_company_id', $company);
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('number', 'like', "%{$search}%")
                ->orWhereHas('client', fn ($c) => $c->where('company_name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")));
        }
        if ($from = $request->query('from')) {
            $query->whereDate('invoice_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('invoice_date', '<=', $to);
        }

        $invoices = $query->orderBy('invoice_date')->get();

        // Every TDS chase against these invoices, in one lookup.
        $chases = PaymentReminder::where('kind', 'tds')
            ->whereIn('invoice_id', $invoices->pluck('id'))
            ->with('member.user:id,name')
            ->orderByDesc('id')
            ->get()
            ->groupBy('invoice_id');

        $rows = $invoices->map(function (Invoice $invoice) use ($chases) {
            $mine = $chases[$invoice->id] ?? collect();
            $last = $mine->first();

            return [
                'uuid' => $invoice->uuid,
                'number' => $invoice->number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'total' => $invoice->total,
                'tds' => $invoice->tds,
                'currency' => $invoice->currency ?: 'INR',
                'client' => $invoice->client ? [
                    'uuid' => $invoice->client->uuid,
                    'company_name' => $invoice->client->company_name,
                    'contact_person' => $invoice->client->contact_person,
                    'email' => $invoice->client->email,
                    'mobile' => $invoice->client->mobile,
                ] : null,
                'issuing_company' => $invoice->issuingCompany?->name,
                'salesperson' => $invoice->member?->user?->name,
                'certificate_at' => $invoice->tds_certificate_at?->toDateTimeString(),
                'certificate_by' => $invoice->certifier?->user?->name,
                'chased' => $mine->count(),
                'last_chased_at' => $last?->created_at?->toDateTimeString(),
                'last_chased_by' => $last?->member?->user?->name,
                'last_status' => $last?->status,
            ];
        });

        return response()->json([
            'data' => $rows,
            'totals' => [
                'count' => $rows->count(),
                'tds' => round($invoices->sum(fn (Invoice $i) => (float) $i->tds), 2),
                'pending' => $invoices->whereNull('tds_certificate_at')->count(),
            ],
        ]);
    }

    /** What the letter would say, and who it would go to, before it goes. */
    public function draft(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $groups = $this->chosen($request);

        $drafts = $groups->map(fn (Collection $invoices) => [
            'client' => $invoices->first()->client?->company_name,
            'to_email' => $invoices->first()->client?->email,
            'invoices' => $invoices->map(fn (Invoice $i) => $i->number)->values(),
            // Offered, not imposed: the screen shows these ticked and the
            // person sending decides who actually gets a copy.
            'cc' => $this->defaultCc($invoices, $org),
            'reply_to' => $org->tdsAccountsEmail(),
        ] + app(TdsCertificateComposer::class)->draft($invoices, $me))->values();

        return response()->json([
            'data' => $drafts,
            'accounts_email' => $org->tdsAccountsEmail(),
        ]);
    }

    /**
     * Ask for the certificates.
     *
     * One or many invoices in, one letter per client out, one row of trail
     * per invoice - so the list can say "chased twice, last on the 3rd" for
     * each bill even though the client only ever got two e-mails.
     */
    public function remind(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $data = $request->validate([
            'invoice_uuids' => ['required', 'array', 'min:1', 'max:100'],
            'invoice_uuids.*' => ['string'],
            'channel' => ['nullable', 'in:email,note'],
            // A wording typed once and used for every client in the batch.
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:20000'],
            'next_follow_up' => ['nullable', 'date'],
            /*
             * Who it goes to, beyond the address on the client.
             *
             * Sent to one client at a time these are exact; sent to several
             * at once, `to` is refused - one letter naming one client's
             * invoices should not be addressed to another's accounts desk.
             */
            'to' => ['nullable', 'array', 'max:10'],
            'to.*' => ['email'],
            'cc' => ['nullable', 'array', 'max:10'],
            'cc.*' => ['email'],
            // Where the certificate should come back to. The letter still
            // leaves from the issuing company's mailbox.
            'reply_to' => ['nullable', 'email'],
        ]);

        $channel = $data['channel'] ?? 'email';
        $groups = $this->chosen($request);
        $composer = app(TdsCertificateComposer::class);

        abort_if(
            $groups->count() > 1 && ! empty($data['to']),
            422,
            'Those invoices belong to more than one client, so each letter has to go to its own client.',
        );

        $replyTo = $data['reply_to'] ?? $org->tdsAccountsEmail();

        $sent = [];
        $refused = [];

        foreach ($groups as $invoices) {
            $first = $invoices->first();
            $draft = $composer->draft($invoices, $me);
            $subject = $data['subject'] ?? $draft['subject'];
            $body = $data['body'] ?? $draft['body'];

            $status = 'logged';
            $error = null;
            $to = null;

            if ($channel === 'email') {
                // Whoever was named, or the address on the client.
                $recipients = collect($data['to'] ?? [])
                    ->push(empty($data['to']) ? $first->client?->email : null)
                    ->map(fn ($a) => TextCase::email($a))
                    ->filter()
                    ->unique(fn ($a) => mb_strtolower($a))
                    ->values();

                $to = $recipients->first();

                // Nobody twice, and no copy to an address already being written to.
                $cc = collect($data['cc'] ?? $this->defaultCc($invoices, $org))
                    ->map(fn ($a) => TextCase::email($a))
                    ->filter()
                    ->unique(fn ($a) => mb_strtolower($a))
                    ->reject(fn ($a) => $recipients->contains(fn ($r) => strcasecmp($a, $r) === 0))
                    ->values()
                    ->all();

                if (blank($to)) {
                    $refused[] = ($first->client?->company_name ?? 'A client') . ' has no e-mail address on file.';
                    $status = 'failed';
                    $error = 'No e-mail address on file.';
                } else {
                    // The invoice's own company mailbox first, as everywhere.
                    $resolved = (new CompanyMailer($org))->resolve($first->issuing_company_id, 'dues');
                    try {
                        $resolved['mailer']->html(nl2br(e($body)), function ($message) use ($recipients, $cc, $replyTo, $subject, $resolved) {
                            $message->to($recipients->all())
                                ->cc($cc)
                                // Out of the company's mailbox, back to accounts.
                                ->replyTo($replyTo)
                                ->from($resolved['address'], $resolved['name'])
                                ->subject($subject);
                        });
                        $status = 'sent';
                        $sent[] = $recipients->implode(', ');
                    } catch (\Throwable $e) {
                        // An honest failure beats a log that claims it went out.
                        $status = 'failed';
                        $error = mb_substr($e->getMessage(), 0, 500);
                        $refused[] = ($first->client?->company_name ?? 'A client') . ': the mail could not be sent.';
                    }
                }
            }

            foreach ($invoices as $invoice) {
                PaymentReminder::create([
                    'organization_id' => $org->id,
                    'invoice_id' => $invoice->id,
                    'member_id' => $me->id,
                    'kind' => 'tds',
                    'channel' => $channel,
                    'to_email' => $to,
                    'subject' => $channel === 'note' ? null : $subject,
                    'body' => $body,
                    'status' => $status,
                    'error' => $error,
                    // What was owed as a certificate, so the trail reads true
                    // after the certificate arrives.
                    'balance' => (float) $invoice->tds,
                    'next_follow_up' => $data['next_follow_up'] ?? null,
                    'sent_at' => $status === 'sent' ? now() : null,
                ]);

                ActivityLog::record($me, $org->id, 'tds.reminder', $invoice, array_filter([
                    'number' => $invoice->number,
                    'client' => $invoice->client?->company_name,
                    'channel' => $channel,
                    'to' => $to,
                    'cc' => $channel === 'email' ? implode(', ', $cc ?? []) : null,
                    'reply_to' => $channel === 'email' ? $replyTo : null,
                    'status' => $status,
                    'tds' => (float) $invoice->tds,
                    'next_follow_up' => $data['next_follow_up'] ?? null,
                ]));
            }
        }

        $clients = $groups->count();

        return response()->json([
            'message' => $channel === 'note'
                ? 'Noted against ' . $groups->flatten()->count() . ' invoice(s).'
                : count($sent) . ' of ' . $clients . ' client(s) written to.',
            'data' => ['sent' => $sent, 'refused' => $refused],
        ], 201);
    }

    /** The whole trail for one invoice, and what the letter would say. */
    public function history(Request $request, string $invoiceUuid): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $invoice = $this->invoice($request, $invoiceUuid);

        $reminders = PaymentReminder::where('kind', 'tds')
            ->where('invoice_id', $invoice->id)
            ->with('member.user:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PaymentReminder $r) => [
                'uuid' => $r->uuid,
                'channel' => $r->channel,
                'to_email' => $r->to_email,
                'subject' => $r->subject,
                'body' => $r->body,
                'status' => $r->status,
                'error' => $r->error,
                'by' => $r->member?->user?->name,
                'at' => $r->created_at?->toDateTimeString(),
                'next_follow_up' => $r->next_follow_up?->toDateString(),
            ]);

        return response()->json([
            'data' => $reminders,
            'draft' => app(TdsCertificateComposer::class)->draft(collect([$invoice]), $me),
            'certificate_at' => $invoice->tds_certificate_at?->toDateTimeString(),
        ]);
    }

    /**
     * The address certificates should come back to.
     *
     * Set once for the company rather than typed into every letter, and
     * still changeable on any one of them. Kept here rather than in the
     * Communication screen because it is not a sender - it is where the
     * answer should land.
     */
    public function accountsEmail(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        abort_unless(in_array($me->crm_role, ['admin', 'subadmin'], true), 403,
            'Only an Admin can change where certificates come back to.');

        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        $settings = $org->settings ?? [];
        $settings['tds'] = array_merge($settings['tds'] ?? [], [
            'accounts_email' => TextCase::email($data['email']),
        ]);
        $org->update(['settings' => $settings]);

        ActivityLog::record($me, $org->id, 'settings.tds', $org, [
            'accounts_email' => $settings['tds']['accounts_email'],
        ]);

        return response()->json([
            'message' => 'Certificates will be asked to come back to ' . $settings['tds']['accounts_email'] . '.',
            'data' => ['accounts_email' => $settings['tds']['accounts_email']],
        ]);
    }

    /** The certificate came in - or it did not, after all. */
    public function received(Request $request, string $invoiceUuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $invoice = $this->invoice($request, $invoiceUuid);

        abort_if((float) $invoice->tds <= 0, 422, 'No tax was deducted on ' . $invoice->number . '.');

        $received = $invoice->tds_certificate_at === null;

        $invoice->update([
            'tds_certificate_at' => $received ? now() : null,
            'tds_certificate_by' => $received ? $me->id : null,
        ]);

        ActivityLog::record($me, $org->id, $received ? 'tds.certificate_received' : 'tds.certificate_cleared', $invoice, [
            'number' => $invoice->number,
            'client' => $invoice->client?->company_name,
            'tds' => (float) $invoice->tds,
        ]);

        return response()->json([
            'message' => $received
                ? 'Certificate recorded for ' . $invoice->number . '.'
                : 'Marked as still awaited for ' . $invoice->number . '.',
            'data' => ['certificate_at' => $invoice->tds_certificate_at?->toDateTimeString()],
        ]);
    }

    // ---- Helpers -----------------------------------------------------------

    /**
     * Who is copied unless somebody says otherwise.
     *
     * The salesperson, because it is their client and their figure; and
     * accounts, because they are the ones who will file the certificate
     * when it arrives. Both are offered ticked and both can be unticked.
     *
     * @param  Collection<int, Invoice>  $invoices
     */
    private function defaultCc(Collection $invoices, $org): array
    {
        return $invoices
            ->map(fn (Invoice $i) => $i->member?->user?->email)
            ->push($org->tdsAccountsEmail())
            ->map(fn ($a) => TextCase::email($a))
            ->filter()
            ->unique(fn ($a) => mb_strtolower($a))
            ->values()
            ->all();
    }

    /**
     * The chosen invoices, grouped by the client they will be asked of.
     *
     * Refuses anything with no tax deducted rather than quietly dropping it:
     * a person who ticked ten rows and got four letters deserves to know
     * which six were not sent and why.
     *
     * @return Collection<int|string, Collection<int, Invoice>>
     */
    private function chosen(Request $request): Collection
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $uuids = (array) $request->input('invoice_uuids', []);
        abort_if($uuids === [], 422, 'Choose at least one invoice.');

        $invoices = Invoice::with(['client', 'issuingCompany', 'member.user:id,name,email'])
            ->where('organization_id', $org->id)
            ->visibleTo($me)
            ->where('kind', 'invoice')
            ->whereIn('uuid', $uuids)
            ->get();

        abort_if($invoices->isEmpty(), 404, 'None of those invoices could be found.');

        $withoutTds = $invoices->filter(fn (Invoice $i) => (float) $i->tds <= 0);
        abort_if(
            $withoutTds->isNotEmpty(),
            422,
            'No tax was deducted on ' . $withoutTds->map(fn ($i) => $i->number)->implode(', ') . '.',
        );

        // Grouped by client so each is asked once. An invoice with no client
        // on it still gets its own group rather than being lumped in.
        return $invoices->groupBy(fn (Invoice $i) => $i->client_id ?? 'invoice-' . $i->id);
    }

    /** The same ledger window as everywhere else. */
    private function invoice(Request $request, string $uuid): Invoice
    {
        return Invoice::with(['client', 'issuingCompany'])
            ->where('organization_id', $request->attributes->get('crm_org')->id)
            ->visibleTo($request->attributes->get('crm_member'))
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
