<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\RecurringInvoice;
use App\Services\Crm\RecurringInvoiceGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Subscriptions: a document told to happen again.
 *
 * A schedule is raised FROM an existing proforma or invoice — that document
 * is the template every cycle copies, so "edit next month's bill" means
 * editing the document, not a second form. The ledger window applies: whose
 * document it is decides who sees the schedule.
 */
class RecurringInvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $query = RecurringInvoice::with([
            'source:id,uuid,number,kind,total,currency',
            'client:id,uuid,company_name',
            'member.user:id,name',
            'lastInvoice:id,uuid,number,invoice_date,payment_status',
        ])->where('organization_id', $org->id);

        // The schedule follows its document's window.
        if (! in_array($me->crm_role, ['admin', 'subadmin'], true)) {
            $team = $me->teamMemberIds();
            $query->where(fn ($q) => $q->whereIn('member_id', $team)
                ->orWhere('created_by', $request->user()->id));
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($client = $request->query('client')) {
            $query->whereHas('client', fn ($c) => $c->where('uuid', $client));
        }

        $all = (clone $query)->get(['id', 'status', 'next_run_on']);
        $summary = [
            'active' => $all->where('status', 'active')->count(),
            'paused' => $all->where('status', 'paused')->count(),
            'due_this_week' => $all->where('status', 'active')
                ->filter(fn ($s) => $s->next_run_on->lte(now()->addDays(7)))->count(),
        ];

        $schedules = $query->orderByRaw("case status when 'active' then 0 when 'paused' then 1 else 2 end")
            ->orderBy('next_run_on')
            ->paginate(25);
        $schedules->getCollection()->transform(fn (RecurringInvoice $s) => $this->serialize($s));

        return response()->json(['summary' => $summary] + $schedules->toArray());
    }

    /** Tell one document to happen again. */
    public function store(Request $request, string $invoiceUuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $source = Invoice::with('client')
            ->where('organization_id', $org->id)
            ->visibleTo($me)
            ->where('uuid', $invoiceUuid)
            ->firstOrFail();

        if ($source->status === 'cancelled') {
            abort(422, 'A cancelled document cannot repeat.');
        }
        $data = $request->validate([
            'frequency' => ['required', Rule::in(RecurringInvoice::FREQUENCIES)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
            'max_occurrences' => ['nullable', 'integer', 'min:1', 'max:120'],
            // A contract usually starts with a document already raised by
            // hand: it is cycle 1, and the schedule owes total − 1 more.
            'counts_source' => ['nullable', 'boolean'],
            'auto_email' => ['nullable', 'boolean'],
            'auto_payment_link' => ['nullable', 'boolean'],
            'show_on_document' => ['nullable', 'boolean'],
        ]);

        // One standing cycle per document — two would double-bill every
        // month. A ONE-TIME copy is a different intent entirely: raising one
        // extra invoice is always allowed, standing schedule or not.
        // The same one-off asked twice is a double-click, not a plan.
        if ($data['frequency'] === 'once'
            && RecurringInvoice::where('source_invoice_id', $source->id)
                ->where('frequency', 'once')
                ->whereIn('status', ['active', 'paused'])
                ->whereDate('starts_on', $data['starts_on'])
                ->exists()) {
            abort(422, 'A one-time copy of ' . $source->number . ' is already scheduled for that date.');
        }

        if ($data['frequency'] !== 'once'
            && RecurringInvoice::where('source_invoice_id', $source->id)
                ->whereIn('status', ['active', 'paused'])
                ->where('frequency', '!=', 'once')
                ->exists()) {
            abort(422, $source->number . ' already repeats — manage it under Recurring.');
        }

        if (($data['counts_source'] ?? false) && ($data['max_occurrences'] ?? null) === null) {
            abort(422, 'Counting this document as cycle 1 needs a contract total.');
        }

        if (($data['auto_email'] ?? false) && blank($source->client?->email)) {
            abort(422, ($source->client?->company_name ?? 'This client')
                . ' has no e-mail on file, so the documents cannot be sent automatically.');
        }

        $schedule = RecurringInvoice::create([
            'organization_id' => $org->id,
            'source_invoice_id' => $source->id,
            'client_id' => $source->client_id,
            'member_id' => $source->member_id,
            'frequency' => $data['frequency'],
            // A one-time repeat IS a 1-run schedule, however the form put it.
            'max_occurrences' => $data['frequency'] === 'once' ? 1 : ($data['max_occurrences'] ?? null),
            'starts_on' => $data['starts_on'],
            'next_run_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'counts_source' => (bool) ($data['counts_source'] ?? false),
            'auto_email' => (bool) ($data['auto_email'] ?? false),
            'auto_payment_link' => (bool) ($data['auto_payment_link'] ?? false),
            'show_on_document' => (bool) ($data['show_on_document'] ?? true),
            'created_by' => $request->user()->id,
        ]);

        ActivityLog::record($me, $org->id, 'invoice.recurring_started', $source, array_filter([
            'number' => $source->number,
            'client' => $source->client?->company_name,
            'frequency' => RecurringInvoice::FREQUENCY_LABELS[$data['frequency']] ?? $data['frequency'],
            'starts_on' => $data['starts_on'],
            'by' => $me->user?->name,
        ]));

        // A date that has already arrived is an instruction for NOW: the
        // document is raised on the spot, not left for tomorrow's timer.
        $raisedNow = 0;
        if (! $schedule->next_run_on->isAfter(now())) {
            try {
                $raisedNow = app(\App\Services\Crm\RecurringInvoiceGenerator::class)->catchUp($schedule);
            } catch (\Throwable $e) {
                $schedule->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
            }
            $schedule->refresh();
        }

        $message = $raisedNow > 0
            ? ($schedule->frequency === 'once'
                ? 'Copy of ' . $source->number . ' raised just now, dated ' . $schedule->starts_on->format('d M Y') . '.'
                : $source->number . ' now repeats — ' . $raisedNow . ' due run' . ($raisedNow === 1 ? '' : 's')
                    . ' raised just now'
                    . ($schedule->status === 'active' ? ', next on ' . $schedule->next_run_on->format('d M Y') : '') . '.')
            : $source->number . ' now repeats — first run ' . $schedule->next_run_on->format('d M Y') . '.';

        return response()->json([
            'message' => $message,
            'data' => $this->serialize($schedule->load(['source:id,uuid,number,kind,total,currency', 'client:id,uuid,company_name'])),
        ], 201);
    }

    /** Pause, resume or cancel — the three verbs a subscription needs. */
    public function decide(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $schedule = $this->find($request, $uuid);

        $data = $request->validate(['action' => ['required', Rule::in(['pause', 'resume', 'cancel'])]]);

        $allowed = match ($data['action']) {
            'pause' => ['active'],
            'resume' => ['paused'],
            'cancel' => ['active', 'paused'],
        };
        if (! in_array($schedule->status, $allowed, true)) {
            abort(422, 'This schedule is ' . $schedule->status . ' — nothing to ' . $data['action'] . '.');
        }

        if ($data['action'] === 'resume') {
            // Missed cycles are not billed in a lump on resume: the schedule
            // picks up from the next date still ahead.
            $next = $schedule->next_run_on;
            while ($next->isPast() && $schedule->hasRunsLeft()) {
                $schedule->occurrences++;
                if (! $schedule->hasRunsLeft()) {
                    break;
                }
                $next = $schedule->runDate($schedule->occurrences);
            }

            if (! $schedule->hasRunsLeft()) {
                $schedule->status = 'completed';
                $schedule->save();

                return response()->json([
                    'message' => 'The schedule had no runs left, so it is complete.',
                    'data' => $this->serialize($schedule->fresh()->load('source:id,uuid,number,kind,total,currency')),
                ]);
            }
            $schedule->next_run_on = $next->toDateString();
        }

        $schedule->status = match ($data['action']) {
            'pause' => 'paused',
            'resume' => 'active',
            'cancel' => 'cancelled',
        };
        $schedule->save();

        ActivityLog::record($me, $org->id, 'invoice.recurring_' . $schedule->status, $schedule->source ?? $schedule, [
            'number' => $schedule->source?->number,
            'by' => $me->user?->name,
        ]);

        return response()->json([
            'message' => 'Schedule ' . $schedule->status . '.',
            'data' => $this->serialize($schedule->fresh()->load('source:id,uuid,number,kind,total,currency')),
        ]);
    }

    /** Run one cycle now, without waiting for the morning. */
    public function run(Request $request, string $uuid, RecurringInvoiceGenerator $generator): JsonResponse
    {
        $schedule = $this->find($request, $uuid);

        if ($schedule->status !== 'active') {
            abort(422, 'Only an active schedule can run.');
        }
        if (! $schedule->hasRunsLeft()) {
            abort(422, 'This schedule has no runs left.');
        }

        $invoice = $generator->generateOne($schedule->load('source.items', 'source.taxes', 'source.client'));

        $schedule = $schedule->fresh();
        if (! $schedule->hasRunsLeft()) {
            $schedule->update(['status' => 'completed']);
        } else {
            $schedule->update(['next_run_on' => $schedule->runDate($schedule->occurrences)->toDateString()]);
        }

        return response()->json([
            'message' => $invoice->number . ' raised.',
            'data' => ['uuid' => $invoice->uuid, 'number' => $invoice->number],
        ], 201);
    }

    // ---- Helpers -----------------------------------------------------------

    private function find(Request $request, string $uuid): RecurringInvoice
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $query = RecurringInvoice::with('source', 'client')
            ->where('organization_id', $request->attributes->get('crm_org')->id)
            ->where('uuid', $uuid);

        if (! in_array($me->crm_role, ['admin', 'subadmin'], true)) {
            $team = $me->teamMemberIds();
            $query->where(fn ($q) => $q->whereIn('member_id', $team)
                ->orWhere('created_by', $request->user()->id));
        }

        return $query->firstOrFail();
    }

    private function serialize(RecurringInvoice $s): array
    {
        return [
            'uuid' => $s->uuid,
            'source' => $s->source ? [
                'uuid' => $s->source->uuid,
                'number' => $s->source->number,
                'kind' => $s->source->kind,
                'total' => $s->source->total,
                'currency' => $s->source->currency,
            ] : null,
            'client' => $s->client ? ['uuid' => $s->client->uuid, 'company_name' => $s->client->company_name] : null,
            'salesperson' => $s->member?->user?->name,
            'frequency' => $s->frequency,
            'frequency_label' => RecurringInvoice::FREQUENCY_LABELS[$s->frequency] ?? $s->frequency,
            'starts_on' => $s->starts_on->toDateString(),
            'next_run_on' => $s->next_run_on->toDateString(),
            'ends_on' => $s->ends_on?->toDateString(),
            'max_occurrences' => $s->max_occurrences,
            'occurrences' => $s->occurrences,
            'counts_source' => $s->counts_source,
            'show_on_document' => $s->show_on_document,
            'auto_email' => $s->auto_email,
            'auto_payment_link' => $s->auto_payment_link,
            'status' => $s->status,
            'last_invoice' => $s->lastInvoice ? [
                'uuid' => $s->lastInvoice->uuid,
                'number' => $s->lastInvoice->number,
                'invoice_date' => $s->lastInvoice->invoice_date?->toDateString(),
                'payment_status' => $s->lastInvoice->payment_status,
            ] : null,
            'last_run_at' => $s->last_run_at?->toDateTimeString(),
            'last_error' => $s->last_error,
            'created_by' => $s->creator?->name,
            'created_at' => $s->created_at?->toDateTimeString(),
        ];
    }
}
