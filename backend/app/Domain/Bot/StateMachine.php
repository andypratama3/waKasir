<?php

namespace App\Domain\Bot;

class StateMachine
{
    const STATES = [
        'IDLE' => 'IDLE',
        'BROWSING' => 'BROWSING',
        'SELECTING_QTY' => 'SELECTING_QTY',
        'CART_REVIEW' => 'CART_REVIEW',
        'SELECTING_CITY' => 'SELECTING_CITY',
        'SELECTING_COURIER' => 'SELECTING_COURIER',
        'AWAITING_PAYMENT' => 'AWAITING_PAYMENT',
        'PAID_AWAITING_ADDRESS' => 'PAID_AWAITING_ADDRESS',
        'COMPLETED' => 'COMPLETED',
        'EXPIRED' => 'EXPIRED',
        'FALLBACK_CS' => 'FALLBACK_CS',
    ];

    public static function canTransition(string $from, string $to): bool
    {
        $transitions = [
            'IDLE' => ['BROWSING', 'FALLBACK_CS'],
            'BROWSING' => ['SELECTING_QTY', 'CART_REVIEW', 'IDLE'],
            'SELECTING_QTY' => ['CART_REVIEW', 'BROWSING'],
            'CART_REVIEW' => ['SELECTING_CITY', 'BROWSING', 'IDLE'],
            'SELECTING_CITY' => ['SELECTING_COURIER', 'SELECTING_CITY'],
            'SELECTING_COURIER' => ['AWAITING_PAYMENT', 'SELECTING_CITY'],
            'AWAITING_PAYMENT' => ['PAID_AWAITING_ADDRESS', 'EXPIRED', 'SELECTING_COURIER'],
            'PAID_AWAITING_ADDRESS' => ['COMPLETED'],
            'COMPLETED' => ['IDLE'],
            'EXPIRED' => ['AWAITING_PAYMENT', 'IDLE'],
            'FALLBACK_CS' => ['IDLE'],
        ];

        return in_array($to, $transitions[$from] ?? []);
    }

    public static function getInitialState(): string
    {
        return self::STATES['IDLE'];
    }

    public static function isValidState(string $state): bool
    {
        return in_array($state, self::STATES);
    }
}