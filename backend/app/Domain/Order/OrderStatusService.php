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

    public static function canTransition(string $from, string $to): bool
    {
        $transitions = [
            'pending'    => ['paid', 'cancelled'],
            'paid'       => ['processing', 'cancelled', 'refunded'],
            'processing' => ['shipped', 'cancelled'],
            'shipped'    => ['completed', 'refunded'],
            'completed'  => ['refunded'],
            'expired'    => ['pending', 'cancelled'],   // expired QR can be retried
            'cancelled'  => [],
            'refunded'   => [],
        ];

        return in_array($to, $transitions[$from] ?? []);
    }

    public static function getAvailableTransitions(string $currentStatus): array
    {
        $transitions = [
            'pending'    => ['paid', 'cancelled'],
            'paid'       => ['processing', 'cancelled', 'refunded'],
            'processing' => ['shipped', 'cancelled'],
            'shipped'    => ['completed', 'refunded'],
            'completed'  => ['refunded'],
            'expired'    => ['pending', 'cancelled'],
            'cancelled'  => [],
            'refunded'   => [],
        ];

        return $transitions[$currentStatus] ?? [];
    }
}