<?php

namespace App\Imports;

use App\Models\Product;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsErrors;

class ProductsImport implements ToCollection, WithHeadingRow, SkipsOnError
{
    use SkipsErrors;

    private int    $businessId;
    private int    $importedCount = 0;
    private int    $skippedCount  = 0;
    private array  $skippedRows   = [];

    public function __construct(int $businessId)
    {
        $this->businessId = $businessId;
    }

    /**
     * Column headers expected (case-insensitive via WithHeadingRow):
     *   nama_produk | harga | stok | berat_gram | kategori | deskripsi
     */
    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $rowNum = $index + 2;

            $name     = trim((string) ($row['nama_produk'] ?? $row['nama'] ?? ''));
            $price    = $row['harga']     ?? null;
            $stock    = $row['stok']      ?? 0;
            $weight   = $row['berat_gram'] ?? $row['berat'] ?? null;
            $category = trim((string) ($row['kategori'] ?? ''));
            $desc     = trim((string) ($row['deskripsi'] ?? ''));

            if (empty($name) || !is_numeric($price) || !is_numeric($weight)) {
                $this->skippedCount++;
                $this->skippedRows[] = [
                    'row'    => $rowNum,
                    'reason' => 'Kolom wajib kosong atau tidak valid (nama_produk, harga, berat_gram)',
                ];
                continue;
            }

            $price  = (float) $price;
            $weight = max(1, (int) $weight);
            $stock  = max(0, (int) $stock);

            if ($price < 0) {
                $this->skippedCount++;
                $this->skippedRows[] = ['row' => $rowNum, 'reason' => 'Harga tidak boleh negatif'];
                continue;
            }

            Product::create([
                'business_id' => $this->businessId,
                'name'        => mb_substr($name,     0, 255),
                'description' => mb_substr($desc,     0, 2000),
                'price'       => $price,
                'stock'       => $stock,
                'weight_gram' => $weight,
                'category'    => mb_substr($category, 0, 100),
                'is_active'   => true,
            ]);

            $this->importedCount++;
        }
    }

    public function getImportedCount(): int { return $this->importedCount; }
    public function getSkippedCount():  int { return $this->skippedCount;  }
    public function getSkippedRows():  array { return $this->skippedRows;  }
}
