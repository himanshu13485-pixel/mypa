{{--
  A payslip as a PDF: earnings on the left, deductions on the right, net at
  the foot — the same lines the screen's breakdown shows, in the order the
  company's own sheet uses. An incentive that carries an arrear release says
  so in its own label, so a bigger month explains itself.
--}}
@php
    $money = fn ($v) => number_format((float) $v, 2);
    $earnings = $slip->earnings ?? [];
    $deductions = $slip->deduction_lines ?? [];
    $inc = $slip->incentive_breakdown;
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
  .lines tr.total td { border-top: 1px solid #0f172a; border-bottom: none; font-weight: bold; padding-top: 6px; }
  .net { margin-top: 16px; background: #f1f5f9; padding: 10px 12px; }
  .net .big { font-size: 15px; font-weight: bold; }
  .inc { margin-top: 14px; font-size: 10px; }
  .inc th { text-align: left; font-size: 9px; text-transform: uppercase; color: #64748b; border-bottom: 1px solid #cbd5e1; padding: 4px 0; }
  .inc td { padding: 4px 0; border-bottom: 1px solid #f1f5f9; color: #334155; }
  .inc td.num { text-align: right; }
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
        {{-- The registered company salaries come from, else the workspace. --}}
        {{ ($company?->name ?? null) ?: $org->name }} ·
        salary for {{ $monthName }}, released
        {{ \Carbon\Carbon::create($slip->year, $slip->month, 1)->addMonthNoOverflow()->format('F Y') }}
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
      <div style="font-size:13px; font-weight:bold">{{ $slip->member?->user?->name }}</div>
      @if ($slip->member?->employee_code)
        <div class="muted">{{ $slip->member->employee_code }}</div>
      @endif
    </td>
  </tr>
</table>

<table class="meta">
  <tr>
    <td>
      <div class="muted">Attendance</div>
      @if ($slip->month_days !== null)
        {{ $slip->payable_days }} payable of {{ $slip->month_days }} days
        @if ((float) $slip->lop_days > 0) · {{ (float) $slip->lop_days }} without pay @endif
      @else
        Full month
      @endif
    </td>
    <td>
      <div class="muted">Monthly gross</div>
      {{ $money($slip->monthly_salary) }}
    </td>
    <td>
      <div class="muted">Bank</div>
      {{ collect([$slip->bank_name, $slip->account_no ? 'A/c ' . $slip->account_no : null, $slip->ifsc])->filter()->implode(' · ') ?: '—' }}
    </td>
    <td style="text-align:right">
      <div class="muted">Status</div>
      {{ $slip->status === 'paid' ? 'Paid' . ($slip->paid_on ? ' on ' . $slip->paid_on->format('d M Y') : '') : 'Pending' }}
    </td>
  </tr>
</table>

<table class="cols">
  <tr>
    <td>
      <table class="lines">
        <tr><th>Earnings</th><th class="num">Amount</th></tr>
        @forelse ($earnings as $line)
          <tr><td>{{ $line['label'] }}</td><td class="num">{{ $money($line['amount']) }}</td></tr>
        @empty
          <tr><td>Payable</td><td class="num">{{ $money($slip->payable) }}</td></tr>
        @endforelse
        @if ((float) $slip->additions > 0)
          <tr><td>Additions</td><td class="num">{{ $money($slip->additions) }}</td></tr>
        @endif
        <tr class="total"><td>Gross payable</td><td class="num">{{ $money((float) $slip->payable + (float) $slip->additions) }}</td></tr>
      </table>
    </td>
    <td>
      <table class="lines">
        <tr><th>Deductions</th><th class="num">Amount</th></tr>
        @forelse ($deductions as $line)
          <tr><td>{{ $line['label'] }}</td><td class="num">{{ $money($line['amount']) }}</td></tr>
        @empty
          <tr><td class="muted">None</td><td class="num">—</td></tr>
        @endforelse
        @if ($slip->deduction_note)
          <tr><td class="muted" colspan="2">{{ $slip->deduction_note }}</td></tr>
        @endif
        <tr class="total"><td>Total deductions</td><td class="num">{{ $money($slip->deductions) }}</td></tr>
      </table>
    </td>
  </tr>
</table>

<table class="net">
  <tr>
    <td class="muted">Net without incentive</td>
    <td style="text-align:right">{{ $money($slip->net_without_incentive ?? $slip->net_salary) }}</td>
  </tr>
  @if ((float) $slip->incentive_amount != 0)
    <tr>
      <td class="muted">
        Incentive{{ $slip->incentive_month ? ' (earned ' . $slip->incentive_month . ')' : '' }}
        @if (($inc['arrear_total'] ?? 0) > 0)
          — incl. arrear incentive release {{ $money($inc['arrear_total']) }}
        @endif
      </td>
      <td style="text-align:right">{{ $money($slip->incentive_amount) }}</td>
    </tr>
  @endif
  <tr>
    <td class="big">Net salary</td>
    <td class="big" style="text-align:right">{{ $money($slip->net_salary) }}</td>
  </tr>
</table>

@if (($inc['installments'] ?? []) !== [] || ($inc['arrears'] ?? []) !== [])
  <table class="inc">
    <tr>
      <th>Incentive detail</th>
      <th>Sale month</th>
      <th>Installment</th>
      <th class="num">Amount</th>
    </tr>
    @foreach ($inc['installments'] ?? [] as $line)
      <tr>
        <td>
          {{ $line['client'] ?? $line['invoice_no'] ?? '—' }}
          @if ($line['team'] ?? false)
            — team incentive{{ !empty($line['seller']) ? ' (' . $line['seller'] . ')' : '' }}
          @endif
        </td>
        <td>{{ $line['sale_month'] }}</td>
        <td>{{ $line['number'] }} of {{ $line['of'] }}</td>
        <td class="num">{{ $money($line['installment']) }}</td>
      </tr>
    @endforeach
    @foreach ($inc['arrears'] ?? [] as $line)
      <tr>
        <td>{{ $line['client'] ?? $line['invoice_no'] ?? '—' }}{{ ($line['team'] ?? false) ? ' — team' : '' }} — arrear incentive release</td>
        <td>{{ $line['sale_month'] }}</td>
        <td>{{ $line['months'] }} month{{ $line['months'] === 1 ? '' : 's' }} held</td>
        <td class="num">{{ $money($line['amount']) }}</td>
      </tr>
    @endforeach
  </table>
@endif

<div class="foot">
  Computer-generated payslip · {{ $org->name }} · generated {{ now()->format('d M Y H:i') }}. Attendance,
  leave and incentive figures are computed from the company's records for the month.
</div>

</body>
</html>
