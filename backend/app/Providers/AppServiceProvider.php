<?php

namespace App\Providers;

use App\Domain\Payment\MidtransService;
use App\Domain\Payment\PaymentService;
use App\Domain\Shipping\RajaOngkirService;
use App\Domain\Shipping\ShippingService;
use App\Domain\Tenant\WhatsAppService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application service bindings.
     */
    public function register(): void
    {
        // MidtransService: default binding uses global config keys.
        // For per-business key injection, resolve manually:
        //   app(MidtransService::class, ['serverKey' => $biz->midtrans_server_key, ...])
        $this->app->bind(MidtransService::class, fn ($app) => new MidtransService(
            serverKey:    config('services.midtrans.server_key', ''),
            clientKey:    config('services.midtrans.client_key', ''),
            isProduction: config('services.midtrans.is_production', false),
        ));

        // PaymentService depends on MidtransService — resolved from container.
        $this->app->bind(PaymentService::class, fn ($app) => new PaymentService(
            $app->make(MidtransService::class)
        ));

        // ShippingService wraps RajaOngkirService.
        $this->app->bind(ShippingService::class, fn ($app) => new ShippingService(
            $app->make(RajaOngkirService::class)
        ));

        // WhatsAppService — BSP layer, singleton (no per-request state)
        $this->app->singleton(WhatsAppService::class, fn ($app) => new WhatsAppService());
    }

    /**
     * Bootstrap application services.
     */
    public function boot(): void
    {
        // Enforce HTTPS in production
        if ($this->app->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
    }
}
