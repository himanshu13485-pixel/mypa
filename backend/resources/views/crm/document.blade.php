{{--
  A CRM proforma or tax invoice, as a PDF.

  The same document the Print button prints from the invoice screen, laid out
  the same way: the owner preferred that one, so the downloaded file, the View
  preview and the PDF attached to an e-mailed invoice all follow it now rather
  than keeping a second layout of their own. What was on the paper is still
  on the paper; only how it looks has come into line.

  Server-rendered all the same, because the browser's print dialog is not
  there everywhere the CRM runs — the Android app has none — and an e-mail
  needs a file. dompdf reads tables rather than flex or grid, so the screen's
  columns are tables here, sized in the same pixels so the page comes out the
  size the browser prints it.

  Rendered from more than one place with slightly different data (the invoice
  screen, the recurring generator, tests), so anything beyond that shared set
  comes off the invoice itself or has a default below.
--}}
@php
    $isProforma = $invoice->kind === 'proforma';
    $columns = $columns ?? [];
    $headings = $headings ?? [];
    $extraColumns = $extraColumns ?? [];
    $documentFields = $documentFields ?? [];
    $moneyLines = $moneyLines ?? [];
    $currency = strtoupper((string) ($currency ?? 'INR'));
    $received = (float) ($received ?? 0);

    /*
     * A figure the way the screen writes it.
     *
     * Rupees wear the rupee sign and Indian grouping — 1,00,000.00 — which is
     * how the Print version reads and how an Indian office reads a rupee
     * figure. A company that bills in another currency keeps that currency's
     * code in front, as this document always gave it: the screen writes a
     * rupee sign on those too, which is wrong, and copying it onto the paper
     * would put the wrong money on a document a client pays from.
     */
    $money = function ($value) use ($currency): string {
        $n = round((float) $value, 2);
        $negative = $n < 0;
        [$whole, $paise] = explode('.', number_format(abs($n), 2, '.', ''));

        if ($currency === 'INR') {
            $lastThree = substr($whole, -3);
            $rest = substr($whole, 0, -3);
            $grouped = $rest !== ''
                ? preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $lastThree
                : $lastThree;
            $text = '₹' . $grouped . '.' . $paise;
        } else {
            $text = $currency . ' ' . number_format(abs($n), 2);
        }

        return ($negative ? '−' : '') . $text;
    };

    $shown = fn (string $key) => ! ($columns[$key]['hidden'] ?? false);
    $heading = fn (string $key, string $fallback) => $columns[$key]['label'] ?? $fallback;
    $docHidden = fn (string $key) => (bool) ($headings[$key]['hidden'] ?? false);
    $docLabel = fn (string $key, string $fallback) => $headings[$key]['label'] ?? $fallback;

    // The screen's own words for where the money stands.
    $paymentLabels = [
        'due' => 'Due', 'partial' => 'Partial paid', 'paid' => 'Fully paid',
        'refunded' => 'Refunded', 'credit_note' => 'GST credit note', 'bad_debt' => 'Bad debt',
    ];
    $paymentColour = match ($invoice->payment_status) {
        'paid' => '#059669',
        'due' => '#ef4444',
        default => '#d97706',
    };

    /*
     * A description that is a list reads back as one: "brass, steel, iron"
     * is three keywords, each in a colour of its own, exactly as the screen
     * shows it. A separator is what makes it a list — prose with no comma
     * stays prose. Repeats drop out, keeping the first spelling.
     */
    $keywords = function (?string $text): array {
        $text = (string) $text;
        if (! preg_match('/[,\n]/', $text)) {
            return [];
        }
        $seen = [];
        $words = [];
        foreach (preg_split('/[,\n]/', $text) as $part) {
            $word = trim($part);
            $key = mb_strtolower($word);
            if ($word === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $words[] = $word;
        }

        return $words;
    };
    // The six keyword colours the screen uses — no two alike on one line.
    $tones = [
        ['#e0f2fe', '#0369a1'], ['#ede9fe', '#6d28d9'], ['#fef3c7', '#b45309'],
        ['#ffe4e6', '#be123c'], ['#ecfccb', '#4d7c0f'], ['#cffafe', '#0e7490'],
    ];
    $toneFor = function (array $words) use ($tones): array {
        $used = [];
        $out = [];
        foreach ($words as $word) {
            $pick = crc32(mb_strtolower($word)) % count($tones);
            for ($step = 0; $step < count($tones) && isset($used[$pick]); $step++) {
                $pick = ($pick + 1) % count($tones);
            }
            $used[$pick] = true;
            $out[] = $tones[$pick];
        }

        return $out;
    };

    // The service span in months, part months counting — the same rule the
    // incentive spread runs on.
    $months = function ($from, $to): int {
        $n = $from->diffInMonths($to);
        if ($from->copy()->addMonthsNoOverflow($n)->lt($to)) {
            $n++;
        }

        return max(1, (int) $n);
    };

    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $rate = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $invoice->number }}</title>
<style>
  @page { margin: 36px 40px; }
  /* DejaVu is dompdf's built-in face and the one that carries ₹. */
  * { font-family: DejaVu Sans, sans-serif; }
  body { color: #0f172a; font-size: 14px; margin: 0; line-height: 1.35; }
  table { width: 100%; border-collapse: collapse; }
  td, th { vertical-align: top; }

  /* The screen's greys, which is the look of the Print version. */
  .s400 { color: #94a3b8; }
  .s500 { color: #64748b; }
  .s600 { color: #475569; }
  .xs { font-size: 12px; }
  .right { text-align: right; }

  .head td { padding: 0 0 16px; border-bottom: 1px solid #f1f5f9; }
  .head .company { font-size: 18px; font-weight: bold; color: #0f172a; }
  .head .kind { font-size: 16px; font-weight: bold; text-transform: uppercase; letter-spacing: .05em; color: #334155; }
  .chip { display: inline-block; margin-top: 4px; padding: 2px 8px; border-radius: 9px; background: #f1f5f9; color: #64748b; font-size: 11px; }

  .parties td { padding: 16px 0; border-bottom: 1px solid #f1f5f9; }
  .label { font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; }

  .lines th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; padding: 8px 12px 8px 0; border-bottom: 1px solid #f1f5f9; }
  .lines td { padding: 10px 12px 10px 0; border-bottom: 1px solid #f8fafc; }
  .lines th.num, .lines td.num { text-align: right; }
  .lines th.last, .lines td.last { padding-right: 0; }
  .keyword { display: inline-block; margin: 4px 4px 0 0; padding: 2px 8px; border-radius: 9px; font-size: 11px; }
  .nowrap { white-space: nowrap; }

  .totals { width: 100%; }
  .totals td { padding: 2px 0; }
  .totals .grand td { border-top: 1px solid #e2e8f0; padding-top: 6px; font-size: 16px; font-weight: bold; color: #0f172a; }

  .payments { margin-top: 20px; }
  .payments th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; padding: 6px 12px 6px 0; border-bottom: 1px solid #e2e8f0; }
  .payments td { padding: 6px 12px 6px 0; border-bottom: 1px solid #f8fafc; }
  .payments .num { text-align: right; padding-right: 0; }

  /* The note carries terms - a contract value split into an advance and a
     balance - so it is printed to be read, not as fine print. */
  .notes { margin-top: 16px; padding: 10px 12px; background: #f8fafc; border: 1px solid #e2e8f0;
           border-radius: 6px; font-size: 12.5px; line-height: 1.6; color: #334155; }
  .bank { margin-top: 24px; font-size: 12px; color: #475569; }
  .sign { margin-top: 32px; text-align: right; font-size: 12px; color: #64748b; }
  /* Capped so a large upload cannot push the signatory line onto a page of
     its own. */
  .stamp img { max-height: 76px; max-width: 150px; margin: 4px 0; }
  .legal { margin-top: 20px; text-align: center; font-size: 11px; color: #64748b; }
</style>
</head>
<body>

{{-- ---- who is billing, and what this is ---------------------------------- --}}
<table class="head">
  <tr>
    <td>
      @if (!empty($logoPath))
        <img src="{{ $logoPath }}" alt="" style="max-height:52px; max-width:180px; margin-bottom:6px">
      @elseif (!empty($letterhead))
        {{-- The paper already carries the logo. Its space is kept so the rest
             of the page sits where it does on an ordinary print. --}}
        <div style="height:52px; margin-bottom:6px"></div>
      @endif
      <div class="company">{{ $company?->name ?? 'Invoice' }}</div>
      @if ($company?->address)<div class="xs s500" style="margin-top:2px; max-width:320px">{{ $company->address }}</div>@endif
      @if ($company?->gstin)<div class="xs s500">GSTIN: {{ $company->gstin }}</div>@endif
      @if ($company?->pan)<div class="xs s500">PAN: {{ $company->pan }}</div>@endif
      {{-- Joined, and only what has been filled in: a company with an e-mail
           and no phone gets one line, not a separator with nothing before it. --}}
      @php $reach = array_filter([$company?->phone, $company?->email]); @endphp
      @if ($reach)<div class="xs s500">{{ implode(' · ', $reach) }}</div>@endif
    </td>
    <td class="right">
      <div class="kind">{{ $isProforma ? 'Proforma invoice' : 'Tax invoice' }}</div>
      <div style="margin-top:4px"><span class="s400">No: </span><span style="font-weight:bold">{{ $invoice->number }}</span></div>
      <div><span class="s400">Date: </span>{{ $invoice->invoice_date?->toDateString() }}</div>
      @if ($invoice->due_date && ! $docHidden('due_date'))
        <div><span class="s400">Due: </span>{{ $invoice->due_date->toDateString() }}</div>
      @endif
      @if ($invoice->recurring_note)<div class="chip">{{ $invoice->recurring_note }}</div>@endif
      {{-- Not on the screen's card, which says so elsewhere on the page. A
           piece of paper has nowhere else to say it. --}}
      @if ($invoice->status === 'cancelled')<div style="margin-top:4px; color:#dc2626; font-weight:bold">CANCELLED</div>@endif
    </td>
  </tr>
</table>

{{-- ---- who is billed, and the terms ----------------------------------------- --}}
<table class="parties">
  <tr>
    <td style="width:50%; padding-right:16px">
      <div class="label">Billed to</div>
      <div style="margin-top:4px; font-weight:bold; color:#1e293b">{{ $invoice->client?->company_name }}</div>
      @if ($invoice->client?->contact_person)<div class="s500">{{ $invoice->client->contact_person }}</div>@endif
      {{-- Directly under the name: they belong to the person. Joined so a
           missing half leaves no stray separator. --}}
      @php $clientReach = array_filter([$invoice->client?->mobile, $invoice->client?->email]); @endphp
      @if ($clientReach)<div class="s500">{{ implode(' · ', $clientReach) }}</div>@endif
      @php
          $clientAddress = collect([
              $invoice->client?->address, $invoice->client?->city,
              $invoice->client?->state, $invoice->client?->pincode,
          ])->filter()->implode(', ');
      @endphp
      @if ($clientAddress !== '')<div class="s500">{{ $clientAddress }}</div>@endif
      @if ($invoice->client?->gst_no)<div class="s500">GSTIN: {{ $invoice->client->gst_no }}</div>@endif
    </td>
    <td class="right">
      @if ($invoice->member?->user && ! $docHidden('member'))
        <div><span class="s400">{{ $docLabel('member', 'Salesperson') }}: </span>{{ $invoice->member->user->name }}</div>
      @endif
      @if ($invoice->creator?->name)
        <div><span class="s400">Raised by: </span>{{ $invoice->creator->name }}</div>
      @endif
      @if ($invoice->terms_of_payment && ! $docHidden('terms_of_payment'))
        <div><span class="s400">{{ $docLabel('terms_of_payment', 'Terms') }}: </span>{{ $invoice->terms_of_payment }}</div>
      @endif
      @foreach ($documentFields as $field)
        <div><span class="s400">{{ $field['label'] }}: </span>{{ is_bool($field['value']) ? 'Yes' : $field['value'] }}</div>
      @endforeach
      <div>
        <span class="s400">Payment: </span>
        <span style="font-weight:bold; color:{{ $paymentColour }}">{{ $paymentLabels[$invoice->payment_status] ?? $invoice->payment_status }}</span>
      </div>
    </td>
  </tr>
</table>

{{-- ---- the work order ------------------------------------------------------- --}}
<table class="lines">
  <thead>
    <tr>
      <th style="width:22px">#</th>
      <th>Particulars</th>
      @if ($shown('validity'))<th>{{ $heading('validity', 'Validity') }}</th>@endif
      <th class="num">{{ $heading('qty', 'Qty') }}</th>
      <th class="num">{{ $heading('unit_price', 'Rate') }}</th>
      <th class="num last">Amount</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($invoice->items as $i => $item)
      <tr>
        <td class="s400">{{ $i + 1 }}</td>
        <td>
          <div style="font-weight:bold; color:#1e293b">
            {{ collect([
                $shown('membership') ? $item->membership : null,
                $shown('plan_name') ? $item->plan_name : null,
            ])->filter()->implode(' — ') ?: '—' }}
          </div>
          @if ($shown('description') && $item->description)
            @php $words = $keywords($item->description); @endphp
            @if ($words)
              {{-- A list, read back as one. --}}
              <div>
                @foreach ($toneFor($words) as $n => $tone)
                  <span class="keyword" style="background:{{ $tone[0] }}; color:{{ $tone[1] }}">{{ $words[$n] }}</span>
                @endforeach
              </div>
            @else
              <div class="xs s500" style="margin-top:2px">{!! nl2br(e($item->description)) !!}</div>
            @endif
          @endif
          {{-- The company's own Work Order fields, with the line they belong to. --}}
          @php
              $extras = collect($extraColumns)->map(function ($column) use ($item) {
                  $value = data_get($item->custom_fields, $column['key']);
                  if ($value === null || $value === '' || $value === false) {
                      return null;
                  }

                  return ['label' => $column['label'], 'value' => is_bool($value) ? 'Yes' : $value];
              })->filter()->values();
          @endphp
          @if ($extras->isNotEmpty())
            <div class="xs s500" style="margin-top:4px">
              @foreach ($extras as $extra)
                <span style="margin-right:12px"><span class="s400">{{ $extra['label'] }}:</span> {{ $extra['value'] }}</span>
              @endforeach
            </div>
          @endif
        </td>
        @if ($shown('validity'))
          <td class="xs s500 nowrap">
            @if ($item->validity_from && $item->validity_to)
              @php $span = $months($item->validity_from, $item->validity_to); @endphp
              {{ $item->validity_from->toDateString() }} → {{ $item->validity_to->toDateString() }}
              <span style="color:#059669">({{ $span }} {{ $span === 1 ? 'month' : 'months' }})</span>
            @else
              —
            @endif
          </td>
        @endif
        <td class="num">{{ $qty($item->qty) }}</td>
        <td class="num nowrap">{{ $money($item->unit_price) }}</td>
        <td class="num last nowrap" style="font-weight:bold">{{ $money($item->amount) }}</td>
      </tr>
    @endforeach
  </tbody>
</table>

{{-- ---- what it comes to ---------------------------------------------------- --}}
{{-- Held to the right by an empty cell beside it rather than by margin-left:
     auto, which dompdf does not honour on a table. --}}
<table style="margin-top:16px">
<tr>
<td></td>
<td style="width:320px">
<table class="totals">
  <tr class="s500"><td>Subtotal</td><td class="right">{{ $money($invoice->subtotal) }}</td></tr>
  {{-- The money lines this document was raised with, in the company's own
       wording — renaming a line later never rewrites old paper. --}}
  @foreach ($moneyLines as $line)
    <tr class="s500">
      <td>{{ $line['label'] }}@if ($line['rate'] !== null && (float) $line['rate'] > 0) @ {{ $rate($line['rate']) }}%@endif</td>
      <td class="right">{{ $line['sign'] === '-' ? '− ' : '' }}{{ $money($line['amount']) }}</td>
    </tr>
  @endforeach
  <tr class="grand"><td>Grand total</td><td class="right">{{ $money($invoice->total) }}</td></tr>
  {{-- Not on a document in another currency: the client pays in that
       currency, and the rupee figure is kept for our books, not theirs. --}}
  @if ($invoice->total_fx && $currency === 'INR')
    <tr class="xs s400">
      <td>{{ $invoice->fx_currency }} equivalent</td>
      <td class="right">{{ number_format((float) $invoice->total_fx, 2) }}</td>
    </tr>
  @endif
  @if (! $isProforma)
    <tr style="color:#059669"><td>Received</td><td class="right">{{ $money($received) }}</td></tr>
    <tr style="color:#ef4444; font-weight:bold"><td>Balance</td><td class="right">{{ $money((float) $invoice->total - $received) }}</td></tr>
  @endif
</table>
</td>
</tr>
</table>

{{-- ---- the money already received, entry by entry ---------------------------- --}}
@if (! $isProforma && $invoice->payments->isNotEmpty())
  <table class="payments">
    <thead>
      <tr>
        <th>Payments received</th>
        <th>Mode</th>
        <th>Reference</th>
        <th class="num">Amount</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($invoice->payments as $payment)
        <tr>
          <td class="s500">
            {{ $payment->received_at?->toDateString() }}
            {{-- The unique payment id a bank statement reconciles against. --}}
            @if ($payment->payment_no)<div style="font-size:10px">{{ $payment->payment_no }}</div>@endif
          </td>
          <td class="s500">{{ $payment->payment_mode ?? '—' }}</td>
          <td class="s500">{{ $payment->reference_no ?? '—' }}</td>
          <td class="num" style="font-weight:bold; color:#059669">
            {{ $money($payment->amount) }}
            {{-- The client paid in full; the charge is ours, and saying so stops
                 anyone reading it as a shortfall. --}}
            @if ((float) $payment->charge_amount > 0)
              <div style="font-size:10px; font-weight:normal; color:#94a3b8">
                incl. {{ $payment->charge_note ?: 'collection charge' }} {{ $money($payment->charge_amount) }}
                — net {{ $money($payment->netAmount()) }}
              </div>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
@endif

@if ($invoice->notes)
  <div class="notes">{!! nl2br(e($invoice->notes)) !!}</div>
@endif

{{-- Where to send the money: above the signatory, read before they stop reading. --}}
@if (!empty($bank))
  <div class="bank">
    <strong>Bank details: </strong>
    {{ collect([$bank->bank_name, $bank->account_no ? 'A/c ' . $bank->account_no : null, $bank->ifsc ? 'IFSC ' . $bank->ifsc : null])->filter()->implode(' · ') }}
  </div>
@endif

<div class="sign">
  <div>For {{ $company?->name }}</div>
  {{-- The stamp sits over the signing space rather than beside it, which is
       where a rubber stamp lands on paper. A company with no stamp keeps the
       blank space to sign in. --}}
  @if (!empty($stampPath))
    <div class="stamp"><img src="{{ $stampPath }}" alt=""></div>
  @else
    <div style="height:48px"></div>
  @endif
  <div>Authorised signatory</div>
</div>

{{--
  The line every computer-issued invoice in India carries.

  Rule 46 of the CGST Rules requires an invoice to be signed by the supplier,
  and exempts one issued electronically under the Information Technology Act
  from that — which is what this sentence asserts. "Physical" rather than
  simply "signature" on purpose: the document may well be digitally signed.
  Below the signatory, because it explains the absence of a signature.
--}}
<div class="legal">
  This is a computer-generated {{ $isProforma ? 'document' : 'invoice' }} and does not require a
  physical signature.
</div>

</body>
</html>
