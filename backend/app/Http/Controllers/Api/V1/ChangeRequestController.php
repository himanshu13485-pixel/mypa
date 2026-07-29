<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\ChangeRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Identity change requests (mobile / email / username). All changes require
 * Admin or Subadmin approval; usernames additionally respect an
 * admin-configured cooldown between changes.
 */
class ChangeRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ChangeRequest::where('user_id', $request->user()->id)
                ->latest()->limit(20)->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:mobile,email,username'],
            'new_value' => ['required', 'string', 'max:255'],
            'country_code' => ['required_if:type,mobile', 'nullable', 'string', 'regex:/^\+[0-9]{1,4}$/'],
        ]);

        $user = $request->user();
        $newValue = trim($data['new_value']);

        // Per-type validation + uniqueness.
        switch ($data['type']) {
            case 'username':
                if (! preg_match('/^[a-zA-Z0-9]{4,20}$/', $newValue)) {
                    throw ValidationException::withMessages([
                        'new_value' => ['Usernames are 4–20 letters and numbers only, no special characters.'],
                    ]);
                }
                $newValue = mb_strtolower($newValue);
                if (User::whereRaw('LOWER(username) = ?', [$newValue])->where('id', '!=', $user->id)->exists()) {
                    throw ValidationException::withMessages(['new_value' => ['This username is taken.']]);
                }
                // Cooldown between username changes (admin-configurable).
                $cooldownDays = (int) AppSetting::get('username_change_days');
                $lastChanged = $user->username_changed_at ?? $user->created_at;
                $eligibleAt = $lastChanged->copy()->addDays($cooldownDays);
                if ($eligibleAt->isFuture()) {
                    throw ValidationException::withMessages([
                        'new_value' => ["Usernames can be changed once every {$cooldownDays} days. You can request a change on {$eligibleAt->toFormattedDateString()}."],
                    ]);
                }
                break;

            case 'email':
                if (! filter_var($newValue, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages(['new_value' => ['Enter a valid email address.']]);
                }
                if (User::where('email', $newValue)->where('id', '!=', $user->id)->exists()) {
                    throw ValidationException::withMessages(['new_value' => ['This email is already in use.']]);
                }
                break;

            case 'mobile':
                if (! preg_match('/^[0-9]{6,14}$/', $newValue)) {
                    throw ValidationException::withMessages(['new_value' => ['Enter the national number, digits only.']]);
                }
                $newValue = $data['country_code'] . $newValue;
                if (User::where('mobile', $newValue)->where('id', '!=', $user->id)->exists()) {
                    throw ValidationException::withMessages(['new_value' => ['This mobile number is already in use.']]);
                }
                break;
        }

        // One pending request per type at a time.
        if (ChangeRequest::where('user_id', $user->id)->where('type', $data['type'])->where('status', 'pending')->exists()) {
            return response()->json([
                'message' => 'You already have a pending ' . $data['type'] . ' change request awaiting approval.',
            ], 409);
        }

        $changeRequest = ChangeRequest::create([
            'user_id' => $user->id,
            'type' => $data['type'],
            'current_value' => $user->{$data['type']},
            'new_value' => $newValue,
            'country_code' => $data['country_code'] ?? null,
        ]);

        return response()->json([
            'message' => ucfirst($data['type']) . ' change requested. An admin will review it shortly.',
            'data' => $changeRequest,
        ], 201);
    }

    // --- Admin / Subadmin review --------------------------------------------

    public function pending(Request $request): JsonResponse
    {
        return response()->json(
            ChangeRequest::with('user:id,uuid,name,username,email,mobile')
                ->where('status', 'pending')
                ->oldest()
                ->paginate(30)
        );
    }

    public function review(Request $request, ChangeRequest $changeRequest): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        abort_unless($changeRequest->status === 'pending', 409, 'This request has already been reviewed.');

        $changeRequest->update([
            'status' => $data['action'] === 'approve' ? 'approved' : 'rejected',
            'reviewed_by' => $request->user()->id,
            'review_note' => $data['note'] ?? null,
            'reviewed_at' => now(),
        ]);

        if ($data['action'] === 'approve') {
            $this->apply($changeRequest);
        }

        \App\Models\AuditLog::record(
            $request->user(),
            'change_request.' . $changeRequest->status,
            $changeRequest->user,
            ['type' => $changeRequest->type, 'new_value' => $changeRequest->new_value],
        );

        $changeRequest->user->notify(
            new \App\Notifications\ChangeRequestDecidedNotification($changeRequest->fresh()),
        );

        return response()->json([
            'message' => 'Request ' . $changeRequest->status . '.',
            'data' => $changeRequest->fresh(),
        ]);
    }

    protected function apply(ChangeRequest $changeRequest): void
    {
        $user = $changeRequest->user;

        switch ($changeRequest->type) {
            case 'username':
                $user->update([
                    'username' => $changeRequest->new_value,
                    'username_changed_at' => now(),
                ]);
                break;

            case 'email':
                // The new address activates only after the user enters the OTP
                // that is emailed to it (proof of ownership).
                app(\App\Services\MobileOtpService::class)
                    ->issueEmail($user, $changeRequest->new_value);
                break;

            case 'mobile':
                $user->update([
                    'country_code' => $changeRequest->country_code ?? $user->country_code,
                    'mobile_verified_at' => null, // must re-verify via in-app OTP
                ]);
                // The OTP carries the NEW number; verifying applies it.
                app(\App\Services\MobileOtpService::class)
                    ->issue($user, $changeRequest->new_value);
                break;
        }
    }
}
