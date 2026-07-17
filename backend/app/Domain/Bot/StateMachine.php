<?php

namespace App\Domain\Bot;

class StateMachine
{
    const STATES = [
        'IDLE'                  => 'IDLE',
        'BROWSING'              => 'BROWSING',
        'SELECTING_VARIANT'     => 'SELECTING_VARIANT',
        'SELECTING_QTY'         => 'SELECTING_QTY',
        'CART_REVIEW'           => 'CART_REVIEW',
        'SELECTING_CITY'        => 'SELECTING_CITY',
        'SELECTING_COURIER'     => 'SELECTING_COURIER',
        'AWAITING_PAYMENT'      => 'AWAITING_PAYMENT',
        'PAID_AWAITING_ADDRESS' => 'PAID_AWAITING_ADDRESS',
        'COMPLETED'             => 'COMPLETED',
        'EXPIRED'               => 'EXPIRED',
        'FALLBACK_CS'           => 'FALLBACK_CS',
        'CHECKING_ORDER'        => 'CHECKING_ORDER',   // waiting for customer to type their order number
    ];

    private static array $transitions = [
        'IDLE'                  => ['BROWSING', 'CHECKING_ORDER', 'FALLBACK_CS'],
        'CHECKING_ORDER'        => ['IDLE'],
        'BROWSING'              => ['SELECTING_VARIANT', 'SELECTING_QTY', 'CART_REVIEW', 'IDLE'],
        'SELECTING_VARIANT'     => ['SELECTING_QTY', 'BROWSING'],
        'SELECTING_QTY'         => ['CART_REVIEW', 'BROWSING'],
        'CART_REVIEW'           => ['SELECTING_CITY', 'BROWSING', 'IDLE'],
        'SELECTING_CITY'        => ['SELECTING_COURIER', 'SELECTING_CITY'],
        'SELECTING_COURIER'     => ['AWAITING_PAYMENT', 'SELECTING_CITY'],
        'AWAITING_PAYMENT'      => ['PAID_AWAITING_ADDRESS', 'EXPIRED', 'SELECTING_COURIER'],
        'PAID_AWAITING_ADDRESS' => ['COMPLETED'],
        'COMPLETED'             => ['IDLE'],
        'EXPIRED'               => ['AWAITING_PAYMENT', 'IDLE'],
        'FALLBACK_CS'           => ['IDLE'],
    ];

    public static function getInitialState(): string
    {
        return self::STATES['IDLE'];
    }

    public static function isValidState(string $state): bool
    {
        return array_key_exists($state, self::STATES);
    }

    public static function getAvailableTransitions(string $from): array
    {
        return self::$transitions[$from] ?? [];
    }
}
