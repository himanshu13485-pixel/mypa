{{--
  A CRM proforma or tax invoice, as a PDF.

  The browser's own print dialog is not available everywhere the CRM runs, so
  the document is rendered server-side and handed over as a file. The columns
  follow the company's own Work Order method, exactly as the screen does.
--}}
@php
    $isProforma = $invoice->kind === 'proforma';
    $money = fn ($v) => number_format((float) $v, 2);
    $shown = fn (string $key) => ! ($columns[$key]['hidden'] ?? false);
    $heading = fn (string $key, string $fallback) => $columns[$key]['label'] ?? $fallback;
    $lineSpan = 3 + ($shown('validity') ? 1 : 0);
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $invoice->number }}</title>
<style>
  /* DejaVu is dompdf's built-in face and the one that carries ₹. */
  * { font-family: DejaVu Sans, sans-serif; }
  body { color: #0f172a; font-size: 11px; margin: 0; }
  .muted { color: #64748b; }
  .right { text-align: right; }
  h1 { font-size: 18px; margin: 0 0 2px; }
  table { width: 100%; border-collapse: collapse; }
  .head td { vertical-align: top; padding: 0 0 14px; }
  .parties td { vertical-align: top; padding: 10px 0; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; }
  .lines { margin-top: 14px; }
  .lines th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; border-bottom: 1px solid #cbd5e1; padding: 6px 6px 6px 0; }
  .lines td { padding: 7px 6px 7px 0; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
  .lines th.num, .lines td.num { text-align: right; padding-right: 0; }
  .totals { width: 45%; margin-left: 55%; margin-top: 12px; }
  .totals td { padding: 3px 0; }
  .totals .grand td { border-top: 1px solid #0f172a; font-weight: bold; font-size: 13px; padding-top: 6px; }
  .chip { display: inline-block; padding: 1px 7px; border-radius: 8px; background: #f1f5f9; font-size: 10px; }
  .extras { color: #64748b; font-size: 10px; margin-top: 2px; }
  .foot { margin-top: 26px; font-size: 10px; color: #64748b; }
  .sign { margin-top: 34px; text-align: right; font-size: 10px; }
  /* Capped so a large upload cannot push the signatory line onto a
     page of its own, and centred in the signing space. */
  .stamp { padding: 4px 0; }
  .stamp img { max-height: 76px; max-width: 150px; }
</style>
</head>
<body>

<table class="head">
  <tr>
    <td>
      @if (!empty($logoPath))
        <img src="{{ $logoPath }}" alt="" style="max-height:52px; max-width:180px; margin-bottom:4px">
      @endif
      <h1>{{ $company?->name ?? 'Invoice' }}</h1>
      @if ($company?->address)<div class="muted">{{ $company->address }}</div>@endif
      <div class="muted">
        @if ($company?->gstin)GSTIN: {{ $company->gstin }}@endif
        @if ($company?->pan) · PAN: {{ $company->pan }}@endif
      </div>
      <div class="muted">
        @if ($company?->phone){{ $company->phone }}@endif
        @if ($company?->email) · {{ $company->email }}@endif
      </div>
    </td>
    <td class="right">
      <div style="font-size:15px; font-weight:bold">{{ $isProforma ? 'PROFORMA INVOICE' : 'TAX INVOICE' }}</div>
      <div style="font-size:13px">{{ $invoice->number }}</div>
      <div class="muted">Date: {{ $invoice->invoice_date->format('d M Y') }}</div>
      @if ($invoice->due_date && ! ($headings['due_date']['hidden'] ?? false))
        <div class="muted">{{ $headings['due_date']['label'] ?? 'Due' }}: {{ $invoice->due_date->format('d M Y') }}</div>
      @endif
      @foreach ($documentFields as $field)
        <div class="muted">{{ $field['label'] }}: {{ is_bool($field['value']) ? 'Yes' : $field['value'] }}</div>
      @endforeach
      @if ($invoice->recurring_note)<div class="chip" style="margin-top:3px">{{ $invoice->recurring_note }}</div>@endif
      @if ($invoice->status === 'cancelled')<div style="color:#dc2626; font-weight:bold">CANCELLED</div>@endif
    </td>
  </tr>
</table>

<table class="parties">
  <tr>
    <td style="width:60%">
      <div class="muted">Billed to</div>
      <div style="font-weight:bold">{{ $invoice->client?->company_name }}</div>
      @if ($invoice->client?->contact_person)
        <div>{{ trim($invoice->client->title . ' ' . $invoice->client->contact_person) }}</div>
      @endif
      @if ($invoice->client?->address)<div class="muted">{{ $invoice->client->address }}</div>@endif
      <div class="muted">
        {{ collect([$invoice->client?->city, $invoice->client?->state, $invoice->client?->pincode])->filter()->implode(', ') }}
      </div>
      @if ($invoice->client?->gst_no)<div class="muted">GSTIN: {{ $invoice->client->gst_no }}</div>@endif
      @if ($invoice->client?->mobile)<div class="muted">{{ $invoice->client->mobile }}</div>@endif
    </td>
    <td class="right">
      @if ($invoice->member?->user && ! ($headings['member']['hidden'] ?? false))
        <div class="muted">{{ $headings['member']['label'] ?? 'Salesperson' }}: {{ $invoice->member->user->name }}</div>
      @endif
      @if ($invoice->terms_of_payment && ! ($headings['terms_of_payment']['hidden'] ?? false))
        <div class="muted">{{ $headings['terms_of_payment']['label'] ?? 'Terms' }}: {{ $invoice->terms_of_payment }}</div>
      @endif
      @if (! $isProforma)<div class="chip">{{ ucfirst($invoice->payment_status) }}</div>@endif
    </td>
  </tr>
</table>

<table class="lines">
  <thead>
    <tr>
      <th style="width:22px">#</th>
      <th>Particulars</th>
      @if ($shown('validity'))<th style="width:120px">{{ $heading('validity', 'Validity') }}</th>@endif
      <th class="num" style="width:50px">{{ $heading('qty', 'Qty') }}</th>
      <th class="num" style="width:80px">{{ $heading('unit_price', 'Rate') }}</th>
      <th class="num" style="width:90px">Amount</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($invoice->items as $i => $item)
      <tr>
        <td class="muted">{{ $i + 1 }}</td>
        <td>
          <div style="font-weight:bold">
            {{ collect([
                $shown('membership') ? $item->membership : null,
                $shown('plan_name') ? $item->plan_name : null,
            ])->filter()->implode(' — ') ?: '—' }}
          </div>
          @if ($shown('description') && $item->description)
            <div class="muted">{{ $item->description }}</div>
          @endif
          {{-- The company's own Work Order fields, printed with their line. --}}
          @php $extras = collect($extraColumns)->map(function ($column) use ($item) {
              $value = data_get($item->custom_fields, $column['key']);
              if ($value === null || $value === '' || $value === false) {
                  return null;
              }

              return $column['label'] . ': ' . (is_bool($value) ? 'Yes' : $value);
          })->filter(); @endphp
          @if ($extras->isNotEmpty())
            <div class="extras">{{ $extras->implode('  ·  ') }}</div>
          @endif
        </td>
        @if ($shown('validity'))
          <td class="muted">
            @if ($item->validity_from && $item->validity_to)
              {{ $item->validity_from->format('d M Y') }} → {{ $item->validity_to->format('d M Y') }}
              @php
                  // The service span in months, part months counting — the
                  // same rule the incentive spread runs on.
                  $vm = $item->validity_from->diffInMonths($item->validity_to);
                  if ($item->validity_from->copy()->addMonthsNoOverflow($vm)->lt($item->validity_to)) { $vm++; }
                  $vm = max(1, $vm);
              @endphp
              ({{ $vm }} {{ $vm === 1 ? 'month' : 'months' }})
            @else
              —
            @endif
          </td>
        @endif
        <td class="num">{{ rtrim(rtrim(number_format((float) $item->qty, 2), '0'), '.') }}</td>
        <td class="num">{{ $money($item->unit_price) }}</td>
        <td class="num">{{ $money($item->amount) }}</td>
      </tr>
    @endforeach
  </tbody>
</table>

<table class="totals">
  <tr><td class="muted">Subtotal</td><td class="right">{{ $currency }} {{ $money($invoice->subtotal) }}</td></tr>
  @foreach ($moneyLines as $line)
    <tr>
      <td class="muted">
        {{ $line['label'] }}
        @if ($line['rate'] !== null)
          @ {{ rtrim(rtrim((string) $line['rate'], '0'), '.') }}%
        @endif
      </td>
      <td class="right">{{ $line['sign'] === '-' ? '− ' : '' }}{{ $currency }} {{ $money($line['amount']) }}</td>
    </tr>
  @endforeach
  <tr class="grand"><td>Grand total</td><td class="right">{{ $currency }} {{ $money($invoice->total) }}</td></tr>
  @if ($invoice->total_fx)
    <tr>
      <td class="muted">
        {{ $invoice->fx_currency }} equivalent
        @if ($invoice->fx_rate > 0) @ {{ rtrim(rtrim(number_format((float) $invoice->fx_rate, 4, '.', ''), '0'), '.') }} @endif
      </td>
      <td class="right">{{ $invoice->fx_currency }} {{ $money($invoice->total_fx) }}</td>
    </tr>
  @endif
  @if (! $isProforma && $received > 0)
    <tr><td class="muted">Received</td><td class="right">{{ $currency }} {{ $money($received) }}</td></tr>
    <tr><td class="muted">Balance</td><td class="right">{{ $currency }} {{ $money((float) $invoice->total - $received) }}</td></tr>
  @endif
</table>

{{-- The consolidated accountant's block lives on the SCREEN view of the
     document, not on the client-facing paper. --}}

{{-- The money that has already arrived is part of the story the document
     tells — a client holding this paper should see what was received and
     what remains, entry by entry. --}}
@if (! $isProforma && $invoice->payments->isNotEmpty())
  <table class="lines" style="margin-top:18px">
    <thead>
      <tr>
        <th>Payments received</th>
        <th style="width:90px">Mode</th>
        <th style="width:150px">Reference</th>
        <th class="num" style="width:90px">Amount</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($invoice->payments as $payment)
        <tr>
          <td class="muted">
            {{ $payment->received_at->format('d M Y') }}
            @if ($payment->payment_no)
              {{-- The unique payment id — the handle a bank statement
                   reconciles against. --}}
              <span style="font-size:9px"> · {{ $payment->payment_no }}</span>
            @endif
            {{-- The client paid in full; the charge is ours, and saying so
                 on the document stops anyone reading it as a shortfall. --}}
            @if ((float) $payment->charge_amount > 0)
              <span style="font-size:9px">
                (incl. {{ $payment->charge_note ?: 'collection charge' }}
                {{ $money($payment->charge_amount) }} — net {{ $money($payment->netAmount()) }})
              </span>
            @endif
          </td>
          <td class="muted">{{ $payment->payment_mode ?? '—' }}</td>
          <td class="muted">{{ $payment->reference_no ?? '—' }}</td>
          <td class="num">{{ $money($payment->amount) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
@endif

@if ($invoice->notes)
  <div class="foot"><strong>Notes:</strong> {{ $invoice->notes }}</div>
@endif

@if ($bank)
  <div class="foot">
    <strong>Bank details:</strong>
    {{ collect([$bank->bank_name, $bank->account_no ? 'A/c ' . $bank->account_no : null, $bank->ifsc ? 'IFSC ' . $bank->ifsc : null])->filter()->implode(' · ') }}
  </div>
@endif

<div class="sign">
  For {{ $company?->name }}
  {{-- The stamp sits where a signature goes, over the space rather than
       beside it, which is where a rubber stamp lands on paper. A company
       with no stamp keeps the blank space it always had. --}}
  @if (!empty($stampPath))
    <div class="stamp"><img src="{{ $stampPath }}" alt=""></div>
  @else
    <br><br><br>
  @endif
  Authorised signatory
</div>

</body>
</html>
