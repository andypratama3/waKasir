<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 13px; color: #1f2937; line-height: 1.5; }

  .page { padding: 40px 48px; }

  /* Header */
  .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 32px; }
  .brand   { font-size: 22px; font-weight: 700; color: #075E54; }
  .brand-sub { font-size: 11px; color: #6b7280; margin-top: 2px; }
  .invoice-meta { text-align: right; }
  .invoice-meta h2 { font-size: 20px; font-weight: 700; color: #075E54; }
  .invoice-meta p  { font-size: 11px; color: #6b7280; }

  .divider { border: none; border-top: 1.5px solid #e5e7eb; margin: 20px 0; }

  /* Parties */
  .parties { display: flex; gap: 48px; margin-bottom: 28px; }
  .party h4 { font-size: 10px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 6px; }
  .party p  { font-size: 12.5px; }

  /* Items table */
  table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
  th    { background: #f3f4f6; font-size: 11px; font-weight: 600; color: #374151; text-transform: uppercase;
          letter-spacing: .04em; padding: 8px 12px; text-align: left; border-bottom: 1px solid #e5e7eb; }
  td    { padding: 9px 12px; border-bottom: 1px solid #f3f4f6; font-size: 12.5px; }
  .text-right { text-align: right; }
  tr:last-child td { border-bottom: none; }

  /* Totals */
  .totals { margin-left: auto; width: 280px; }
  .total-row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 12.5px; }
  .total-row.final { border-top: 2px solid #075E54; margin-top: 6px; padding-top: 10px;
                     font-weight: 700; font-size: 14px; color: #075E54; }

  /* Status badge */
  .status-badge { display: inline-block; padding: 3px 10px; border-radius: 9999px; font-size: 11px;
                  font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
  .status-paid      { background: #d1fae5; color: #065f46; }
  .status-pending   { background: #fef9c3; color: #854d0e; }
  .status-completed { background: #dbeafe; color: #1e40af; }

  /* Footer */
  .footer { margin-top: 40px; padding-top: 16px; border-top: 1px solid #e5e7eb;
            font-size: 11px; color: #9ca3af; text-align: center; }
</style>
</head>
<body>
<div class="page">

  <!-- Header -->
  <div class="header">
    <div>
      <div class="brand">{{ $business->name }}</div>
      <div class="brand-sub">{{ $business->origin_address ?? 'WaKasir Commerce' }}</div>
      @if ($business->wa_phone_number)
        <div class="brand-sub">WA: {{ $business->wa_phone_number }}</div>
      @endif
    </div>
    <div class="invoice-meta">
      <h2>INVOICE</h2>
      <p>#{{ $order->order_number }}</p>
      <p>{{ $order->created_at?->format('d F Y') }}</p>
      <br>
      <span class="status-badge status-{{ $order->status }}">{{ strtoupper($order->status) }}</span>
    </div>
  </div>

  <hr class="divider">

  <!-- Parties -->
  <div class="parties">
    <div class="party">
      <h4>Dari</h4>
      <p><strong>{{ $business->name }}</strong></p>
      <p>{{ $business->origin_address ?? '-' }}</p>
    </div>
    <div class="party">
      <h4>Kepada</h4>
      <p><strong>{{ $customer?->name ?? 'Pelanggan' }}</strong></p>
      @if ($address)
        <p>{{ $address->full_address }}</p>
        <p>{{ $address->city_name }}{{ $address->postal_code ? ', ' . $address->postal_code : '' }}</p>
        <p>WA: {{ $customer?->wa_number ?? '-' }}</p>
      @endif
    </div>
    <div class="party">
      <h4>Pengiriman</h4>
      <p>{{ $order->courier_name }} {{ $order->courier_service }}</p>
      @if ($order->tracking_number)
        <p>Resi: {{ $order->tracking_number }}</p>
      @endif
      @if ($payment?->paid_at)
        <p>Dibayar: {{ $payment->paid_at->format('d/m/Y H:i') }}</p>
      @endif
    </div>
  </div>

  <!-- Items -->
  <table>
    <thead>
      <tr>
        <th style="width:40%">Produk</th>
        <th class="text-right" style="width:15%">Qty</th>
        <th class="text-right" style="width:22%">Harga Satuan</th>
        <th class="text-right" style="width:23%">Subtotal</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($items as $item)
      <tr>
        <td>
          {{ $item->product?->name ?? 'Produk' }}
          @if ($item->variant_name)
            <span style="color:#6b7280;font-size:11px"> — {{ $item->variant_name }}</span>
          @endif
        </td>
        <td class="text-right">{{ $item->qty }}</td>
        <td class="text-right">Rp{{ number_format($item->price_at_order, 0, ',', '.') }}</td>
        <td class="text-right">Rp{{ number_format($item->price_at_order * $item->qty, 0, ',', '.') }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <!-- Totals -->
  <div class="totals">
    <div class="total-row">
      <span>Subtotal Produk</span>
      <span>Rp{{ number_format($order->subtotal, 0, ',', '.') }}</span>
    </div>
    <div class="total-row">
      <span>Ongkos Kirim</span>
      <span>Rp{{ number_format($order->shipping_cost, 0, ',', '.') }}</span>
    </div>
    <div class="total-row final">
      <span>TOTAL</span>
      <span>Rp{{ number_format($order->total_amount, 0, ',', '.') }}</span>
    </div>
  </div>

  <!-- Footer -->
  <div class="footer">
    Terima kasih telah berbelanja di {{ $business->name }} melalui WaKasir &bull;
    Invoice ini digenerate otomatis
  </div>

</div>
</body>
</html>
