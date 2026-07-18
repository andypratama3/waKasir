<?php

namespace App\Exports;

use App\Models\Order;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OrdersExport implements FromQuery, WithHeadings, WithMapping, WithTitle, WithStyles
{
    public function __construct(
        private string $businessId,
        private ?string $status     = null,
        private ?string $dateFrom   = null,
        private ?string $dateTo     = null,
    ) {}

    public function query()
    {
        $query = Order::with(['customer', 'address', 'items'])
            ->where('business_id', $this->businessId)
            ->orderByDesc('created_at');

        if ($this->status)   $query->where('status', $this->status);
        if ($this->dateFrom) $query->whereDate('created_at', '>=', $this->dateFrom);
        if ($this->dateTo)   $query->whereDate('created_at', '<=', $this->dateTo);

        return $query;
    }

    public function map($order): array
    {
        return [
            $order->order_number,
            $order->created_at?->format('d/m/Y H:i'),
            $order->customer?->name      ?? '-',
            $order->customer?->wa_number ?? '-',
            $order->address?->city_name  ?? '-',
            trim(($order->courier_name ?? '') . ' ' . ($order->courier_service ?? '')) ?: '-',
            number_format($order->subtotal,      0, ',', '.'),
            number_format($order->shipping_cost, 0, ',', '.'),
            number_format($order->total_amount,  0, ',', '.'),
            $order->status,
            $order->tracking_number ?? '-',
        ];
    }

    public function headings(): array
    {
        return [
            'No. Order', 'Tanggal', 'Nama Pelanggan', 'No. WA', 'Kota',
            'Kurir', 'Subtotal', 'Ongkir', 'Total', 'Status', 'No. Resi',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '075E54']],
            ],
        ];
    }

    public function title(): string { return 'Laporan Pesanan'; }
}
