<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductTemplateExport implements FromArray, WithHeadings, WithStyles, WithTitle, WithColumnWidths
{
    public function array(): array
    {
        // Sample rows to show the expected format
        return [
            ['Baju Batik Motif Parang',  150000, 50, 400, 'Pakaian',  'Baju batik tulis motif parang, bahan katun premium'],
            ['Kopi Arabika 250gr',         85000, 30, 300, 'Makanan',  'Kopi arabika single origin Aceh gayo'],
            ['Tas Anyam Rotan',            75000, 20, 600, 'Aksesoris','Tas anyam rotan handmade, ukuran medium'],
        ];
    }

    public function headings(): array
    {
        return ['nama_produk', 'harga', 'stok', 'berat_gram', 'kategori', 'deskripsi'];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '128C7E']],
            ],
        ];
    }

    public function columnWidths(): array
    {
        return ['A' => 35, 'B' => 15, 'C' => 10, 'D' => 12, 'E' => 18, 'F' => 50];
    }

    public function title(): string { return 'Template Import Produk'; }
}
