<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\Invoice;
use App\Models\Crm\InvoiceNote;
use App\Models\Crm\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Internal notes on a document — office talk, never on the paper.
 *
 * Whoever can see the invoice can read and add; that is the whole rule, so
 * the ledger window does the guarding. Deleting is the author's, or a
 * manager's: a note is somebody's word, not anybody's to erase.
 */
class InvoiceNoteController extends Controller
{
    public function index(Request $request, string $invoiceUuid): JsonResponse
    {
        $invoice = $this->invoice($request, $invoiceUuid);

        return response()->json(['data' => $invoice->internalNotes()
            ->with('member.user:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (InvoiceNote $note) => $this->serialize($note, $request)),
        ]);
    }

    public function store(Request $request, string $invoiceUuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $invoice = $this->invoice($request, $invoiceUuid);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $note = $invoice->internalNotes()->create([
            'organization_id' => $org->id,
            'member_id' => $me->id,
            'body' => trim($data['body']),
        ]);

        return response()->json([
            'message' => 'Noted.',
            'data' => $this->serialize($note->load('member.user:id,name'), $request),
        ], 201);
    }

    public function destroy(Request $request, string $invoiceUuid, string $noteUuid): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $invoice = $this->invoice($request, $invoiceUuid);

        $note = $invoice->internalNotes()->where('uuid', $noteUuid)->firstOrFail();

        $isManager = in_array($me->crm_role, ['admin', 'subadmin'], true);
        if ($note->member_id !== $me->id && ! $isManager) {
            abort(403, 'Only whoever wrote a note, or an Admin, can remove it.');
        }

        $note->delete();

        return response()->json(['message' => 'Note removed.']);
    }

    // ---- Helpers -----------------------------------------------------------

    /** The same ledger window as the document itself. */
    private function invoice(Request $request, string $uuid): Invoice
    {
        return Invoice::where('organization_id', $request->attributes->get('crm_org')->id)
            ->visibleTo($request->attributes->get('crm_member'))
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function serialize(InvoiceNote $note, Request $request): array
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $isManager = in_array($me->crm_role, ['admin', 'subadmin'], true);

        return [
            'uuid' => $note->uuid,
            'body' => $note->body,
            'by' => $note->member?->user?->name ?? '—',
            'at' => $note->created_at?->toDateTimeString(),
            'is_mine' => $note->member_id === $me->id,
            'can_delete' => $note->member_id === $me->id || $isManager,
        ];
    }
}
