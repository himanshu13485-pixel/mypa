<?php

namespace App\Http\Controllers\Api\V1\Crm\Mail;

use App\Http\Controllers\Controller;
use App\Models\Crm\MailLabel;
use App\Models\Crm\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A person's own labels: made, renamed, recoloured, removed. */
class MailLabelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => MailLabel::where('member_id', $this->member($request)->id)
            ->orderBy('name')->get(['uuid', 'name', 'color'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $this->member($request);
        $data = $this->validated($request, $me);
        $label = MailLabel::create($data + ['organization_id' => $me->organization_id, 'member_id' => $me->id]);

        return response()->json(['message' => 'Label created.', 'data' => $label->only(['uuid', 'name', 'color'])], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $me = $this->member($request);
        $label = MailLabel::where('member_id', $me->id)->where('uuid', $uuid)->firstOrFail();
        $label->update($this->validated($request, $me, $label->id));

        return response()->json(['message' => 'Label saved.', 'data' => $label->only(['uuid', 'name', 'color'])]);
    }

    /** The label goes; the mail wearing it stays where it was. */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        MailLabel::where('member_id', $this->member($request)->id)->where('uuid', $uuid)->firstOrFail()->delete();

        return response()->json(['message' => 'Label removed. The mail it was on is untouched.']);
    }

    private function validated(Request $request, Member $me, ?int $ignore = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:64',
                Rule::unique('crm_mail_labels', 'name')->where('member_id', $me->id)->ignore($ignore)],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], ['name.unique' => 'You already have a label with that name.']);
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('crm_member');
    }
}
