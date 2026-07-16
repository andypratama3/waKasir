<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── WaKasir Scheduled Tasks ──────────────────────────────────────────────────

/**
 * Expire pending orders whose QRIS payment window (15 min) has passed.
 * Runs every 5 minutes to keep latency low.
 */
Schedule::command('orders:expire')->everyFiveMinutes();

/**
 * Refresh the RajaOngkir city/province cache once a week (Sunday 02:00 WIB).
 * RajaOngkir allows caching of static location data.
 */
Schedule::command('shipping:seed')->weekly()->sundays()->at('02:00');
