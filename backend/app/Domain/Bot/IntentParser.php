<?php

namespace App\Domain\Bot;

class IntentParser
{
    public static function parse(string $message): string
    {
        $message = strtolower(trim($message));

        // Intent detection patterns
        $patterns = [
            'view_catalog' => ['/katalog/', '/lihat/', '/produk/', '/menu/'],
            'ask_product' => ['/tanya/', '/info/', '/cari/'],
            'check_order' => ['/status/', '/pesanan/', '/order/'],
            'checkout' => ['/checkout/', '/bayar/', '/selesai/'],
            'add_to_cart' => ['/tambah/', '/beli/', '/order/'],
            'cancel' => ['/batal/', '/cancel/'],
            'help' => ['/bantuan/', '/help/', '/tolong/'],
        ];

        foreach ($patterns as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($message, $keyword)) {
                    return $intent;
                }
            }
        }

        return 'unknown';
    }

    public static function extractProduct(string $message): ?string
    {
        // Extract product name from message
        $message = strtolower($message);
        
        // Remove common words
        $stopWords = ['saya', 'mau', 'ingin', 'tanya', 'info', 'tentang', 'harga', 'berapa'];
        $message = str_replace($stopWords, '', $message);
        
        return trim($message) ?: null;
    }

    public static function extractQuantity(string $message): ?int
    {
        // Extract quantity from message
        preg_match('/(\d+)/', $message, $matches);
        
        return isset($matches[1]) ? (int) $matches[1] : null;
    }

    public static function extractCity(string $message): ?string
    {
        // Extract city name from message
        $message = trim($message);
        
        // Common patterns for city names
        if (strlen($message) > 2) {
            return $message;
        }
        
        return null;
    }
}