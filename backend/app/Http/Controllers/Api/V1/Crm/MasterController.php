<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\BankAccount;
use App\Models\Crm\IssuingCompany;
use App\Support\TextCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Billing master data: the companies invoices are issued from (each with its
 * own numbering series) and the bank accounts payments land in.
 */
class MasterController extends Controller
{
    // ---- How payments are handled ------------------------------------------

    /**
     * Two company rules: whether a matched payment settles on the spot or
     * waits for an Admin to check it, and when unpaid invoices are chased.
     */
    public function paymentSettings(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        return response()->json(['data' => [
            'settlement_mode' => $org->settlementMode(),
            'reminders' => $org->reminderSchedule(),
        ]]);
    }

    public function savePaymentSettings(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        $data = $request->validate([
            'settlement_mode' => ['required', 'in:auto,manual'],
            'reminders.enabled' => ['required', 'boolean'],
            // A negative day writes before the due date, 0 on it.
            'reminders.offsets' => ['nullable', 'array', 'max:12'],
            'reminders.offsets.*' => ['integer', 'min:-60', 'max:365'],
            'reminders.stop_after' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $settings = $org->settings ?? [];
        $settings['payments'] = ['settlement_mode' => $data['settlement_mode']];
        $settings['reminders'] = [
            'enabled' => (bool) $data['reminders']['enabled'],
            'offsets' => array_values(array_unique($data['reminders']['offsets'] ?? [])),
            'stop_after' => (int) ($data['reminders']['stop_after'] ?? 4),
        ];
        $org->update(['settings' => $settings]);

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'settings.payments', $org, [
            'settlement_mode' => $data['settlement_mode'],
            'reminders' => $settings['reminders']['enabled'] ? 'on' : 'off',
            'offsets' => implode(', ', $settings['reminders']['offsets']),
        ]);

        return response()->json([
            'message' => 'Payment settings saved.',
            'data' => ['settlement_mode' => $org->settlementMode(), 'reminders' => $org->fresh()->reminderSchedule()],
        ]);
    }

    /** How often the due-lead popup returns when dismissed. Admin's knob. */
    public function leadSettings(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'alert_minutes' => $request->attributes->get('crm_org')->leadAlertMinutes(),
            'new_alert_minutes' => $request->attributes->get('crm_org')->newLeadAlertMinutes(),
        ]]);
    }

    public function saveLeadSettings(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        $data = $request->validate([
            'alert_minutes' => ['required', 'integer', 'min:5', 'max:120'],
            'new_alert_minutes' => ['nullable', 'integer', 'min:5', 'max:120'],
        ]);

        $settings = $org->settings ?? [];
        $settings['leads'] = [
            'alert_minutes' => (int) $data['alert_minutes'],
            'new_alert_minutes' => (int) ($data['new_alert_minutes'] ?? 15),
        ];
        $org->update(['settings' => $settings]);

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'settings.leads', $org, [
            'alert_minutes' => (int) $data['alert_minutes'],
            'new_alert_minutes' => (int) ($data['new_alert_minutes'] ?? 15),
        ]);

        return response()->json([
            'message' => 'Lead alert timing saved.',
            'data' => ['alert_minutes' => $org->fresh()->leadAlertMinutes()],
        ]);
    }

    /**
     * The words a lead is described in — sources and subjects — are the
     * company's own lists. The Admin edits them here; every dropdown that
     * uses them follows, because optionList() reads the same setting.
     */
    public function leadOptions(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        return response()->json(['data' => [
            'lead_sources' => $org->optionList('lead_sources'),
            'lead_subjects' => $org->optionList('lead_subjects'),
        ]]);
    }

    public function saveLeadOptions(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        $data = $request->validate([
            'lead_sources' => ['required', 'array', 'min:1', 'max:50'],
            'lead_sources.*' => ['string', 'max:64'],
            'lead_subjects' => ['required', 'array', 'min:1', 'max:50'],
            'lead_subjects.*' => ['string', 'max:120'],
        ]);

        $settings = $org->settings ?? [];
        foreach (['lead_sources', 'lead_subjects'] as $key) {
            $settings[$key] = collect($data[$key])
                ->map(fn ($v) => trim($v))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }
        $org->update(['settings' => $settings]);

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'settings.lead_options', $org, [
            'sources' => count($settings['lead_sources']),
            'subjects' => count($settings['lead_subjects']),
        ]);

        return response()->json(['message' => 'Lead options saved.', 'data' => [
            'lead_sources' => $settings['lead_sources'],
            'lead_subjects' => $settings['lead_subjects'],
        ]]);
    }

    /**
     * The reasons an approval can be asked for. Every company argues about
     * different things, so the list is theirs — the dropdown on the request
     * form reads it, and requests already filed keep the words they used.
     */
    public function approvalTypes(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'approval_types' => $request->attributes->get('crm_org')->optionList('approval_types'),
        ]]);
    }

    public function saveApprovalTypes(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        $data = $request->validate([
            'approval_types' => ['required', 'array', 'min:1', 'max:60'],
            'approval_types.*' => ['string', 'max:64'],
        ]);

        $settings = $org->settings ?? [];
        $settings['approval_types'] = collect($data['approval_types'])
            ->map(fn ($v) => trim($v))->filter()->unique()->values()->all();
        $org->update(['settings' => $settings]);

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'settings.approval_types', $org, [
            'count' => count($settings['approval_types']),
        ]);

        return response()->json([
            'message' => 'Approval types saved.',
            'data' => ['approval_types' => $settings['approval_types']],
        ]);
    }

    /**
     * The words a complaint is filed under — its source, its subject, its
     * type, the way it arrived — plus how long the office gives itself to
     * close one. All of it is the company's, kept by the Admin or Subadmin:
     * nobody adds a subject mid-complaint, so the same problem is always
     * filed under the same name and the reports mean something. Complaints
     * already filed keep the words they were filed under.
     */
    public function complaintOptions(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        return response()->json(['data' => [
            'complaint_sources' => $org->optionList('complaint_sources'),
            'complaint_subjects' => $org->optionList('complaint_subjects'),
            'complaint_types' => $org->optionList('complaint_types'),
            'complaint_modes' => $org->optionList('complaint_modes'),
            'resolve_hours' => $org->complaintHours(),
        ]]);
    }

    public function saveComplaintOptions(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        $data = $request->validate([
            'complaint_sources' => ['required', 'array', 'min:1', 'max:60'],
            'complaint_sources.*' => ['string', 'max:96'],
            'complaint_subjects' => ['required', 'array', 'min:1', 'max:120'],
            'complaint_subjects.*' => ['string', 'max:191'],
            'complaint_types' => ['required', 'array', 'min:1', 'max:60'],
            'complaint_types.*' => ['string', 'max:96'],
            'complaint_modes' => ['required', 'array', 'min:1', 'max:60'],
            'complaint_modes.*' => ['string', 'max:64'],
            'resolve_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        $settings = $org->settings ?? [];
        foreach (['complaint_sources', 'complaint_subjects', 'complaint_types', 'complaint_modes'] as $key) {
            $settings[$key] = collect($data[$key])
                ->map(fn ($v) => trim($v))->filter()->unique()->values()->all();
        }
        if (($data['resolve_hours'] ?? null) !== null) {
            $settings['complaints'] = ['resolve_hours' => (int) $data['resolve_hours']];
        }
        $org->update(['settings' => $settings]);

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'settings.complaint_options', $org, [
            'subjects' => count($settings['complaint_subjects']),
            'sources' => count($settings['complaint_sources']),
        ]);

        return response()->json(['message' => 'Complaint options saved.', 'data' => [
            'complaint_sources' => $settings['complaint_sources'],
            'complaint_subjects' => $settings['complaint_subjects'],
            'complaint_types' => $settings['complaint_types'],
            'complaint_modes' => $settings['complaint_modes'],
            'resolve_hours' => $org->fresh()->complaintHours(),
        ]]);
    }

    // ---- Issuing companies -------------------------------------------------

    public function storeCompany(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $company = IssuingCompany::create($this->validateCompany($request) + ['organization_id' => $org->id]);

        return response()->json(['message' => 'Issuing company added.', 'data' => $company], 201);
    }

    public function updateCompany(Request $request, int $id): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $company = IssuingCompany::where('organization_id', $org->id)->findOrFail($id);
        $company->update($this->validateCompany($request));
        $this->keepOneSalaryCompany($org->id, $company);

        return response()->json(['message' => 'Issuing company updated.', 'data' => $company->fresh()]);
    }

    /** One company pays the salaries; ticking a new one unticks the rest. */
    private function keepOneSalaryCompany(int $orgId, IssuingCompany $company): void
    {
        if ($company->fresh()->pays_salary) {
            IssuingCompany::where('organization_id', $orgId)
                ->where('id', '!=', $company->id)
                ->update(['pays_salary' => false]);
        }
    }

    /** The company's logo — prints on its invoices, proformas and payslips. */
    public function uploadCompanyLogo(Request $request, int $id): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $company = IssuingCompany::where('organization_id', $org->id)->findOrFail($id);
        $request->validate(['file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);

        if ($company->logo_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($company->logo_path);
        }
        $path = $request->file('file')->store('crm-logos/' . $org->id, 'public');
        $company->update(['logo_path' => $path]);

        return response()->json(['message' => 'Logo uploaded.', 'data' => ['logo_path' => $path]]);
    }

    /** Taking the logo off again, as the stamp has always been able to be. */
    public function deleteCompanyLogo(Request $request, int $id): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $company = IssuingCompany::where('organization_id', $org->id)->findOrFail($id);

        if ($company->logo_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($company->logo_path);
            $company->update(['logo_path' => null]);
        }

        return response()->json(['message' => 'Logo removed.']);
    }

    /**
     * The company's stamp — prints beside the signatory on its documents.
     *
     * Its own image rather than a second use of the logo: a stamp is usually
     * round, usually monochrome and often carries a registration number the
     * header logo does not. A company with both would otherwise have to
     * choose which one it wanted printed.
     *
     * PNG is worth having for this even though the logo takes anything: a
     * stamp is meant to sit over the signature line, and only a transparent
     * background lets it.
     */
    public function uploadCompanyStamp(Request $request, int $id): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $company = IssuingCompany::where('organization_id', $org->id)->findOrFail($id);
        $request->validate(['file' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048']]);

        if ($company->stamp_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($company->stamp_path);
        }
        $path = $request->file('file')->store('crm-stamps/' . $org->id, 'public');
        $company->update(['stamp_path' => $path]);

        return response()->json(['message' => 'Stamp uploaded.', 'data' => ['stamp_path' => $path]]);
    }

    /** Taking the stamp off again, without having to replace it with another. */
    public function deleteCompanyStamp(Request $request, int $id): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $company = IssuingCompany::where('organization_id', $org->id)->findOrFail($id);

        if ($company->stamp_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($company->stamp_path);
            $company->update(['stamp_path' => null]);
        }

        return response()->json(['message' => 'Stamp removed.']);
    }

    /**
     * What a foreign amount is worth in rupees today: the market rate, and
     * the effective rate after the bank-charge margin comes off.
     */
    public function fxRate(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $currency = strtoupper((string) $request->query('currency', 'USD'));
        $fx = new \App\Services\Crm\FxService($org);

        return response()->json(['data' => [
            'currency' => $currency,
            'market_rate' => $fx->marketRate($currency),
            'margin_inr' => $fx->marginInr(),
            'effective_rate' => $fx->effectiveRate($currency),
        ]]);
    }

    /** The FX margin knob: rupees the bank's cut takes off the market rate. */
    public function saveFxSettings(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $data = $request->validate(['margin_inr' => ['required', 'numeric', 'min:0', 'max:50']]);

        $settings = $org->settings ?? [];
        $settings['fx'] = ['margin_inr' => (float) $data['margin_inr']];
        $org->update(['settings' => $settings]);

        return response()->json(['message' => 'FX margin saved.']);
    }

    /** Birthday vibes: the theme switch and the song the shell plays. */
    public function birthdaySettings(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        return response()->json(['data' => [
            'enabled' => (bool) data_get($org->settings, 'birthday.enabled', true),
            'song_url' => data_get($org->settings, 'birthday.song_url'),
        ]]);
    }

    public function saveBirthdaySettings(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'song_url' => ['nullable', 'url', 'max:1024'],
        ]);

        $settings = $org->settings ?? [];
        $settings['birthday'] = ['enabled' => $data['enabled'], 'song_url' => $data['song_url'] ?? null];
        $org->update(['settings' => $settings]);

        return response()->json(['message' => 'Birthday settings saved.']);
    }

    /**
     * The Communication setup: which addresses the company's mail goes out
     * from — the general one, and optional separate senders for invoices
     * and for due-payment follow-ups — plus the channel switches.
     */
    /** The Office Assets category list this company files stock under. */
    public function assetCategories(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'categories' => $request->attributes->get('crm_org')->assetCategories(),
        ]]);
    }

    public function saveAssetCategories(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $data = $request->validate([
            'categories' => ['present', 'array', 'max:100'],
            'categories.*' => ['nullable', 'string', 'max:64'],
        ]);

        // Tidied on the way in: blanks dropped, duplicates collapsed, order
        // kept as typed. An empty list means "use the built-in one" rather
        // than a company with nowhere to file a laptop.
        $clean = collect($data['categories'])
            ->map(fn ($c) => trim($c))->filter()->unique()->values()->all();

        $settings = $org->settings ?? [];
        $settings['assets'] = ['categories' => $clean];
        $org->update(['settings' => $settings]);

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'settings.assets', $org, [
            'categories' => count($clean),
        ]);

        return response()->json(['message' => 'Asset categories saved.', 'data' => ['categories' => $org->fresh()->assetCategories()]]);
    }

    public function communicationSettings(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $data = (array) data_get($org->settings, 'communication', []) + [
            'from_name' => null, 'from_address' => null,
            'invoice_from_address' => null, 'dues_from_address' => null,
            'email_enabled' => true,
            'whatsapp_enabled' => false, 'telegram_enabled' => false, 'netvork_enabled' => true,
            'company_senders' => [],
        ];

        // Secrets never leave the server readable — the screen sees a mask.
        foreach ((array) $data['company_senders'] as $id => $sender) {
            foreach (['smtp_password', 'ses_key', 'ses_secret', 'imap_password'] as $key) {
                if (! empty($sender[$key])) {
                    $data['company_senders'][$id][$key] = '********';
                }
            }
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Try a company's mailbox before trusting it with anything.
     *
     * The stored secrets are used as they are, so a saved mailbox can be
     * tested without retyping its password; a mailbox being edited can be
     * tried by sending what is on screen. Either way nothing is written -
     * this only ever asks questions.
     */
    public function testMailbox(Request $request, \App\Services\Crm\MailboxDoctor $doctor): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $data = $request->validate([
            'check' => ['required', 'in:smtp,imap,dns,send'],
            'company_id' => ['required', 'integer'],
            // What is on screen, for a mailbox not saved yet. Absent, the
            // stored one is tested.
            'sender' => ['nullable', 'array'],
            'to' => ['nullable', 'email'],
        ]);

        $stored = (array) data_get($org->settings, 'communication.company_senders.' . $data['company_id'], []);
        $sender = $data['sender'] ?? [];

        // A masked secret means "keep the stored one" here exactly as it
        // does when saving, so testing an untouched mailbox works.
        foreach (['smtp_password', 'ses_key', 'ses_secret', 'imap_password'] as $key) {
            if (($sender[$key] ?? null) === null || ($sender[$key] ?? '') === '********') {
                $sender[$key] = $stored[$key] ?? null;
            } elseif (($sender[$key] ?? '') !== '') {
                // Typed just now, so it is plain: the doctor decrypts what
                // it is given, and a plain value survives that unchanged.
                $sender[$key] = $sender[$key];
            }
        }
        $sender = $sender + $stored;

        $company = IssuingCompany::where('organization_id', $org->id)->find($data['company_id']);

        $result = match ($data['check']) {
            'smtp' => $doctor->testSmtp($sender),
            'imap' => $doctor->testImap($sender),
            'dns' => $doctor->checkDns($sender),
            'send' => $doctor->sendTest(
                $sender,
                $data['to'] ?: (string) $request->user()->email,
                $company?->name ?? $org->name,
            ),
        };

        return response()->json(['data' => $result]);
    }

    public function saveCommunicationSettings(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $data = $request->validate([
            'from_name' => ['nullable', 'string', 'max:255'],
            'from_address' => ['nullable', 'email', 'max:255'],
            'invoice_from_address' => ['nullable', 'email', 'max:255'],
            'dues_from_address' => ['nullable', 'email', 'max:255'],
            'email_enabled' => ['nullable', 'boolean'],
            'whatsapp_enabled' => ['nullable', 'boolean'],
            'telegram_enabled' => ['nullable', 'boolean'],
            'netvork_enabled' => ['nullable', 'boolean'],
            // One sender (and one mailbox) per issuing company, keyed by
            // the company's id — grapme-mailbox style: SMTP or AWS SES.
            'company_senders' => ['nullable', 'array'],
            'company_senders.*.label' => ['nullable', 'string', 'max:255'],
            // The one mailbox the company's own mail goes out from —
            // reports, notices, staff sign-in codes.
            'company_senders.*.is_report_sender' => ['nullable', 'boolean'],
            'company_senders.*.from_name' => ['nullable', 'string', 'max:255'],
            'company_senders.*.from_address' => ['nullable', 'email', 'max:255'],
            'company_senders.*.mailer' => ['nullable', \Illuminate\Validation\Rule::in(['none', 'smtp', 'ses'])],
            'company_senders.*.smtp_host' => ['nullable', 'string', 'max:255'],
            'company_senders.*.smtp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'company_senders.*.smtp_encryption' => ['nullable', \Illuminate\Validation\Rule::in(['tls', 'ssl', 'none'])],
            'company_senders.*.smtp_username' => ['nullable', 'string', 'max:255'],
            'company_senders.*.smtp_password' => ['nullable', 'string', 'max:512'],
            'company_senders.*.ses_key' => ['nullable', 'string', 'max:512'],
            'company_senders.*.ses_secret' => ['nullable', 'string', 'max:512'],
            'company_senders.*.ses_region' => ['nullable', 'string', 'max:32'],
            // The receiving half of a complete mailbox — kept alongside the
            // sending half so replies can land here when receiving goes live.
            'company_senders.*.imap_host' => ['nullable', 'string', 'max:255'],
            'company_senders.*.imap_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'company_senders.*.imap_encryption' => ['nullable', \Illuminate\Validation\Rule::in(['ssl', 'tls', 'none'])],
            'company_senders.*.imap_username' => ['nullable', 'string', 'max:255'],
            'company_senders.*.imap_password' => ['nullable', 'string', 'max:512'],
            'company_senders.*.imap_allow_self_signed' => ['nullable', 'boolean'],
        ]);

        // Encrypt fresh secrets; a masked value keeps the stored one.
        $stored = (array) data_get($org->settings, 'communication.company_senders', []);
        foreach ((array) ($data['company_senders'] ?? []) as $id => $sender) {
            foreach (['smtp_password', 'ses_key', 'ses_secret', 'imap_password'] as $key) {
                $value = $sender[$key] ?? null;
                if ($value === '********') {
                    $data['company_senders'][$id][$key] = $stored[$id][$key] ?? null;
                } elseif ($value !== null && $value !== '') {
                    $data['company_senders'][$id][$key] = \Illuminate\Support\Facades\Crypt::encryptString($value);
                }
            }
        }

        // One report sender, not several. The screen only ever ticks one,
        // but a payload that arrives with two would otherwise decide the
        // question by iteration order, differently on different days.
        $seen = false;
        foreach ((array) ($data['company_senders'] ?? []) as $id => $sender) {
            if (! empty($sender['is_report_sender'])) {
                $data['company_senders'][$id]['is_report_sender'] = ! $seen;
                $seen = true;
            }
        }

        $settings = $org->settings ?? [];
        $settings['communication'] = $data;
        $org->update(['settings' => $settings]);

        return response()->json(['message' => 'Communication setup saved.']);
    }

    private function validateCompany(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:512'],
            'gstin' => ['nullable', 'string', 'max:32'],
            'pan' => ['nullable', 'string', 'max:32'],
            'state_code' => ['nullable', 'string', 'max:8'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'invoice_prefix' => ['nullable', 'string', 'max:16'],
            'proforma_prefix' => ['nullable', 'string', 'max:16'],
            'next_invoice_no' => ['nullable', 'integer', 'min:1'],
            'next_proforma_no' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            // The billing currency: INR unless the company bills abroad.
            'currency' => ['nullable', 'string', 'size:3'],
            // The registered company salaries are paid from — the payslip
            // carries its details and logo. Only one holds the tick.
            'pays_salary' => ['nullable', 'boolean'],
        ]);
        if (array_key_exists('currency', $data)) {
            $data['currency'] = strtoupper((string) ($data['currency'] ?: 'INR'));
        }

        $data['name'] = TextCase::company($data['name']);
        foreach (['gstin', 'pan', 'state_code'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = TextCase::code($data[$field]);
            }
        }
        if (array_key_exists('email', $data)) {
            $data['email'] = TextCase::email($data['email']);
        }

        return $data;
    }

    // ---- Bank accounts -----------------------------------------------------

    public function storeBank(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $bank = BankAccount::create($this->validateBank($request) + ['organization_id' => $org->id]);

        return response()->json(['message' => 'Bank account added.', 'data' => $bank], 201);
    }

    public function updateBank(Request $request, int $id): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $bank = BankAccount::where('organization_id', $org->id)->findOrFail($id);
        $bank->update($this->validateBank($request));

        return response()->json(['message' => 'Bank account updated.', 'data' => $bank->fresh()]);
    }

    private function validateBank(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:255'],
            // Which registered company this account belongs to — its
            // invoices print THIS account, never a sister company's.
            'issuing_company_id' => ['nullable', 'integer'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_no' => ['nullable', 'string', 'max:64'],
            'ifsc' => ['nullable', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
