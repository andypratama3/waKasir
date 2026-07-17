<?php

namespace App\Domain\Bot;

class IntentParser
{
    /**
     * Map a raw message to a high-level intent.
     * Returns one of: view_catalog | check_order | reset | unknown
     */
    public static function parse(string $message): string
    {
        $msg = mb_strtolower(trim($message));

        // Explicit reset/menu commands (always prioritized)
        if (self::matches($msg, ['/^(menu|reset|mulai|start|halo|hi|hai|hello|selamat)$/'])) {
            return 'reset';
        }

        $patterns = [
            'view_catalog'  => ['/katalog/', '/lihat\s*produk/', '/produk/', '/belanja/', '/beli/'],
            'check_order'   => ['/status\s*(order|pesanan)?/', '/pesanan\s*saya/', '/cek\s*order/', '/order\s*#/'],
        ];

        foreach ($patterns as $intent => $regexes) {
            foreach ($regexes as $regex) {
                if (preg_match($regex, $msg)) {
                    return $intent;
                }
            }
        }

        return 'unknown';
    }

    /**
     * Check if message is selecting a numbered menu option (1–9 or "1.", "1)").
     * Returns the integer, or null if not a menu number.
     */
    public static function extractMenuNumber(string $message): ?int
    {
        $msg = trim($message);
        if (preg_match('/^([1-9])[.\):]?\s*$/', $msg, $m)) {
            return (int) $m[1];
        }
        // Written numbers in Indonesian
        $map = ['satu' => 1, 'dua' => 2, 'tiga' => 3, 'empat' => 4, 'lima' => 5,
                'enam' => 6, 'tujuh' => 7, 'delapan' => 8, 'sembilan' => 9];
        $lower = mb_strtolower(trim($msg));
        return $map[$lower] ?? null;
    }

    /**
     * Extract a quantity integer from a message.
     * Handles: "2", "2 pcs", "dua", "pesan 3", etc.
     */
    public static function extractQuantity(string $message): ?int
    {
        $msg = mb_strtolower(trim($message));

        // Compound written numbers (must check before single words)
        $compounds = [
            'sepuluh' => 10, 'sebelas' => 11, 'dua belas' => 12, 'tiga belas' => 13,
            'empat belas' => 14, 'lima belas' => 15, 'enam belas' => 16,
            'tujuh belas' => 17, 'delapan belas' => 18, 'sembilan belas' => 19,
            'dua puluh' => 20, 'tiga puluh' => 30, 'empat puluh' => 40, 'lima puluh' => 50,
            'enam puluh' => 60, 'tujuh puluh' => 70, 'delapan puluh' => 80, 'sembilan puluh' => 90,
            'seratus' => 100, 'dua ratus' => 200, 'tiga ratus' => 300, 'empat ratus' => 400,
            'lima ratus' => 500, 'enam ratus' => 600, 'tujuh ratus' => 700, 'delapan ratus' => 800,
            'sembilan ratus' => 900, 'seribu' => 1000,
        ];
        foreach ($compounds as $word => $num) {
            if (str_contains($msg, $word)) {
                return $num;
            }
        }

        // Single written numbers
        $written = ['satu' => 1, 'dua' => 2, 'tiga' => 3, 'empat' => 4, 'lima' => 5,
                    'enam' => 6, 'tujuh' => 7, 'delapan' => 8, 'sembilan' => 9];
        foreach ($written as $word => $num) {
            if (str_contains($msg, $word)) {
                return $num;
            }
        }

        // Numeric (first number found)
        if (preg_match('/(\d+)/', $msg, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Extract city name from a message.
     * Strips common filler words people type when responding to "kirim ke mana".
     */
    public static function extractCity(string $message): ?string
    {
        $msg = trim($message);

        if (strlen($msg) < 2) {
            return null;
        }

        // Remove common prefixes
        $prefixes = ['kota', 'kab', 'kabupaten', 'ke', 'kirim ke', 'tujuan', 'alamat'];
        $lower    = mb_strtolower($msg);
        foreach ($prefixes as $prefix) {
            if (str_starts_with($lower, $prefix . ' ')) {
                $msg = trim(substr($msg, strlen($prefix) + 1));
                break;
            }
        }

        return strlen($msg) >= 2 ? $msg : null;
    }

    /**
     * Try to match a message against a list of product names.
     * Returns the best matching product index (0-based) or null.
     * Checks: exact number, numeric prefix, partial name match.
     */
    public static function matchProduct(string $message, array $productNames): ?int
    {
        $msg = mb_strtolower(trim($message));

        // Try menu number first
        $num = self::extractMenuNumber($message);
        if ($num !== null && $num >= 1 && $num <= count($productNames)) {
            return $num - 1; // 0-based
        }

        // Try partial name match (case-insensitive)
        foreach ($productNames as $index => $name) {
            if (str_contains(mb_strtolower($name), $msg) || str_contains($msg, mb_strtolower($name))) {
                return $index;
            }
        }

        // Fuzzy: check if any word in message matches any word in a product name
        $msgWords = preg_split('/\s+/', $msg);
        foreach ($productNames as $index => $name) {
            $nameWords = preg_split('/\s+/', mb_strtolower($name));
            foreach ($msgWords as $mw) {
                if (strlen($mw) >= 3 && in_array($mw, $nameWords)) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * Check if message is a positive confirmation (ya, yes, ok, lanjut, etc.).
     */
    public static function isYes(string $message): bool
    {
        $msg = mb_strtolower(trim($message));
        return in_array($msg, ['ya', 'y', 'yes', 'iya', 'ok', 'oke', 'lanjut', 'lanjutkan', 'setuju', 'deal']);
    }

    /**
     * Check if message is a negative confirmation.
     */
    public static function isNo(string $message): bool
    {
        $msg = mb_strtolower(trim($message));
        return in_array($msg, ['tidak', 'tidak jadi', 'gak', 'ga', 'nggak', 'enggak', 'no', 'n', 't', 'batal']);
    }

    // ── private helpers ──────────────────────────────────────────────────

    private static function matches(string $msg, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $msg)) {
                return true;
            }
        }
        return false;
    }
}
