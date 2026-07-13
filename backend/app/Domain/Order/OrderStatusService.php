<?php

namespace App\Domain\Order;

class OrderStatusService
{
    const STATUSES = [
        'pending' => 'Pending',
        'paid' => 'Paid',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded',
    ];

    public static function getAllStatuses(): array
    {
        return self::STATUSES;
    }

    public static function isValidStatus(string $status): bool
    {
        return array_key_exists($status, self::STATUSES);
    }

    public static function getStatusLabel(string $status): string
    {
        return self::STATUSES[$status] ?? 'Unknown';
    }

    public static function canTransition(string $from, string $to): bool
    {
        $transitions = [
            'pending' => ['paid', 'cancelled'],
            'paid' => ['processing', 'cancelled', 'refunded'],
            'processing' => ['shipped', 'cancelled'],
            'shipped' => ['completed', 'refunded'],
            'completed' => ['refunded'],
            'cancelled' => [],
            'refunded' => [],
        ];

        return in_array($to, $transitions[$from] ?? []);
    }

    public static function getAvailableTransitions(string $currentStatus): array
    {
        $transitions = [
            'pending' => ['paid', 'cancelled'],
            'paid' => ['processing', 'cancelled', 'refunded'],
            'processing' => ['shipped', 'cancelled'],
            'shipped' => ['completed', 'refunded'],
            'completed' => ['refunded'],
            'cancelled' => [],
            'refunded' => [],
        ];

        return $transitions[$currentStatus] ?? [];
    }
}