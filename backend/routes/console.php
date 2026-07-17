<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── WaKasir Scheduled Tasks ──────────────────────────────────────────────────
//
// All tasks use:
//   ->withoutOverlapping()  — prevents a second instance starting if first hasn't finished
//   ->onOneServer()         — safe for multi-server deployments (requires cache driver = redis/db)
//   ->runInBackground()     — does not block the scheduler process
//
// Ensure the scheduler cron is registered on your server:
//   * * * * * php /path-to-project/artisan schedule:run >> /dev/null 2>&1
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Expire pending orders whose QRIS payment window (15 min) has passed.
 * Runs every 5 minutes. Critical — keep latency low.
 */
Schedule::command('orders:expire')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)   // max overlap timeout: 10 minutes
    ->onOneServer()
    ->runInBackground();

/**
 * Refresh the RajaOngkir city/province cache once a week (Sunday 02:00 WIB).
 * RajaOngkir explicitly allows caching static location data.
 */
Schedule::command('shipping:seed')
    ->weekly()
    ->sundays()
    ->at('02:00')
    ->withoutOverlapping(60)   // max overlap timeout: 60 minutes
    ->onOneServer()
    ->runInBackground();

/**
 * Check WhatsApp token expiry for all connected businesses.
 * Runs daily at 08:00 WIB. Notifies owners 7 days before expiry.
 */
Schedule::command('wa:check-tokens')
    ->dailyAt('08:00')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground();
