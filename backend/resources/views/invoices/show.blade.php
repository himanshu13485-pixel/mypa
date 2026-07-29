<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $invoice->invoice_number }} — My PA Invoice</title>
<style>
  body { font-family: 'Segoe UI', Arial, sans-serif; color: #0f172a; margin: 40px auto; max-width: 720px; }
  h1 { font-size: 20px; margin: 0; }
  .muted { color: #64748b; font-size: 12px; }
  .row { display: flex; justify-content: space-between; margin-top: 24px; }
  table { width: 100%; border-collapse: collapse; margin-top: 24px; font-size: 14px; }
  th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #e2e8f0; }
  td.num, th.num { text-align: right; }
  .total td { font-weight: 700; border-top: 2px solid #0f172a; border-bottom: none; }
  .badge { display: inline-block; background: #dcfce7; color: #166534; border-radius: 999px; padding: 2px 10px; font-size: 12px; font-weight: 600; }
  @media print { body { margin: 0; } .no-print { display: none; } }
</style>
</head>
<body>
  <div class="row" style="margin-top:0">
    <div>
      <h1>Invoice {{ $invoice->invoice_number }}</h1>
      <p class="muted">Issued {{ $invoice->issued_at->toFormattedDateString() }} · <span class="badge">PAID</span></p>
    </div>
    <div style="text-align:right">
      <strong>{{ $invoice->billing_snapshot['seller']['name'] ?? 'My PA' }}</strong><br>
      <span class="muted">{{ $invoice->billing_snapshot['seller']['address'] ?? '' }}</span><br>
      @if(!empty($invoice->billing_snapshot['seller']['tax_number']))
        <span class="muted">Tax no: {{ $invoice->billing_snapshot['seller']['tax_number'] }}</span>
      @endif
    </div>
  </div>

  <div class="row">
    <div>
      <span class="muted">Billed to</span><br>
      <strong>{{ $invoice->billing_snapshot['buyer']['name'] ?? $invoice->user->name }}</strong><br>
      <span class="muted">{{ $invoice->billing_snapshot['buyer']['email'] ?? '' }}</span><br>
      <span class="muted">{{ $invoice->billing_snapshot['buyer']['app_id'] ?? '' }}</span>
    </div>
    <div style="text-align:right">
      <span class="muted">Payment reference</span><br>
      <span class="muted">Order: {{ $invoice->billing_snapshot['order_number'] ?? '—' }}</span><br>
      <span class="muted">Gateway payment: {{ $invoice->billing_snapshot['gateway_payment_id'] ?? '—' }}</span>
    </div>
  </div>

  <table>
    <tr><th>Description</th><th class="num">Amount</th></tr>
    <tr>
      <td>
        My PA {{ $invoice->plan_name }} plan — {{ $invoice->billing_frequency }} billing<br>
        <span class="muted">{{ $invoice->period_starts_on->toFormattedDateString() }} → {{ $invoice->period_ends_on->toFormattedDateString() }}</span>
      </td>
      <td class="num">{{ $money($invoice->base_amount) }}</td>
    </tr>
    @if($invoice->discount_amount > 0)
      <tr><td>Discount</td><td class="num">− {{ $money($invoice->discount_amount) }}</td></tr>
    @endif
    <tr>
      <td>{{ $invoice->tax_label }} ({{ $invoice->tax_percent_bp / 100 }}%)</td>
      <td class="num">{{ $money($invoice->tax_amount) }}</td>
    </tr>
    <tr class="total"><td>Total paid</td><td class="num">{{ $money($invoice->total_amount) }}</td></tr>
  </table>

  <p class="muted" style="margin-top:32px">
    This is a computer-generated invoice for your My PA subscription. Amounts are in {{ $invoice->currency }}.
  </p>
  <p class="no-print"><button onclick="window.print()">Print / save as PDF</button></p>
</body>
</html>
