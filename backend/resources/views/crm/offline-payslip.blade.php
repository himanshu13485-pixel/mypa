{{--
  An offline employee's payslip: the same shape as an on-roll one - earnings
  on the left, deductions on the right, the net at the foot - for somebody
  paid outside the payroll.
--}}
@php
    $money = fn ($v) => number_format((float) $v, 2);
    $deductions = $record->deduction_lines ?? [];
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Payslip — {{ $monthName }}</title>
<style>
  * { font-family: DejaVu Sans, sans-serif; }
  body { color: #0f172a; font-size: 11px; margin: 0; }
  .muted { color: #64748b; }
  h1 { font-size: 17px; margin: 0 0 2px; }
  table { width: 100%; border-collapse: collapse; }
  .head td { vertical-align: top; padding: 0 0 12px; }
  .meta td { padding: 8px 0; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
  .cols { margin-top: 14px; }
  .cols > tbody > tr > td { vertical-align: top; width: 50%; }
  .cols > tbody > tr > td:first-child { padding-right: 12px; }
  .cols > tbody > tr > td:last-child { padding-left: 12px; }
  .lines th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; border-bottom: 1px solid #cbd5e1; padding: 5px 0; }
  .lines td { padding: 5px 0; border-bottom: 1px solid #f1f5f9; }
  .lines td.num, .lines th.num { text-align: right; }
  .lines .note { font-size: 9px; color: #64748b; font-style: italic; margin-top: 1px; }
  .lines tr.total td { border-top: 1px solid #0f172a; border-bottom: none; font-weight: bold; padding-top: 6px; }
  .net { margin-top: 16px; background: #f1f5f9; padding: 10px 12px; }
  .net .big { font-size: 15px; font-weight: bold; }
  .foot { margin-top: 24px; font-size: 9px; color: #64748b; }
</style>
</head>
<body>

<table class="head">
  <tr>
    <td>
      @if (!empty($logoPath))
        <img src="{{ $logoPath }}" alt="" style="max-height:44px; max-width:160px; margin-bottom:4px">
      @endif
      <h1>Payslip — {{ $monthName }}</h1>
      <div class="muted">
        {{ ($company?->name ?? null) ?: $org->name }} ·
        salary for {{ $monthName }}
      </div>
      @if (!empty($company))
        @if ($company->address)<div class="muted">{{ $company->address }}</div>@endif
        <div class="muted">
          @if ($company->gstin)GSTIN: {{ $company->gstin }}@endif
          @if ($company->pan) · PAN: {{ $company->pan }}@endif
        </div>
      @endif
    </td>
    <td style="text-align:right">
      <div style="font-size:13px; font-weight:bold">{{ $person?->name }}</div>
      <div class="muted">{{ $person?->employee_code }}@if ($person?->designation) · {{ $person->designation }}@endif</div>
    </td>
  </tr>
</table>

<table class="meta">
  <tr>
    <td>
      <div class="muted">Attendance</div>
      {{ (float) $record->payable_days }} payable of {{ (int) $record->month_days }} days
      @if ((float) $record->lop_days > 0) · {{ (float) $record->lop_days }} without pay @endif
    </td>
    <td>
      <div class="muted">Monthly gross</div>
      {{ $money($person?->monthly_amount ?? $record->gross) }}
    </td>
    <td>
      <div class="muted">Bank</div>
      {{ collect([$person?->bank_name, $person?->account_no ? 'A/c ' . $person->account_no : null, $person?->ifsc])->filter()->implode(' · ') ?: '—' }}
    </td>
    <td style="text-align:right">
      <div class="muted">Status</div>
      {{ $record->status === 'paid' ? 'Paid' . ($record->paid_on ? ' on ' . $record->paid_on->format('d M Y') : '') . ($record->payment_mode ? ' · ' . $record->payment_mode : '') : 'Pending' }}
    </td>
  </tr>
</table>

<table class="cols">
  <tr>
    <td>
      <table class="lines">
        <tr><th>Earnings</th><th class="num">Amount</th></tr>
        @foreach ($earnings as $line)
          <tr><td>{{ $line['label'] }}</td><td class="num">{{ $money($line['amount']) }}</td></tr>
        @endforeach
        @if ((float) $record->additions > 0)
          <tr>
            <td>
              Additions
              @if ($record->addition_note)<div class="note">{{ $record->addition_note }}</div>@endif
            </td>
            <td class="num">{{ $money($record->additions) }}</td>
          </tr>
        @endif
        <tr class="total"><td>Gross payable</td><td class="num">{{ $money((float) $record->gross + (float) $record->additions) }}</td></tr>
      </table>
    </td>
    <td>
      <table class="lines">
        <tr><th>Deductions</th><th class="num">Amount</th></tr>
        @foreach ($deductions as $line)
          <tr><td>{{ $line['label'] }}</td><td class="num">{{ $money($line['amount']) }}</td></tr>
        @endforeach
        @if ((float) $record->other_deductions > 0)
          <tr>
            <td>
              Other deductions
              @if ($record->other_deduction_note)<div class="note">{{ $record->other_deduction_note }}</div>@endif
            </td>
            <td class="num">{{ $money($record->other_deductions) }}</td>
          </tr>
        @endif
        @if (count($deductions) === 0 && (float) $record->other_deductions <= 0)
          <tr><td class="muted">None</td><td class="num">—</td></tr>
        @endif
        <tr class="total"><td>Total deductions</td><td class="num">{{ $money((float) $record->deductions + (float) $record->other_deductions) }}</td></tr>
      </table>
    </td>
  </tr>
</table>

<table class="net">
  <tr>
    <td class="big">Net salary</td>
    <td class="big" style="text-align:right">{{ $money($record->amount) }}</td>
  </tr>
</table>

<div class="foot">
  Computer-generated payslip · {{ $org->name }} · generated {{ now()->format('d M Y H:i') }}. Paid outside the payroll.
  @if ($record->note) Note: {{ $record->note }}@endif
</div>

</body>
</html>
