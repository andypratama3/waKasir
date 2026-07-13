<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('wa_phone_id')->nullable();
            $table->string('wa_phone_number')->nullable();
            $table->string('midtrans_server_key')->nullable();
            $table->string('midtrans_client_key')->nullable();
            $table->string('midtrans_merchant_id')->nullable();
            $table->string('rajaongkir_api_key')->nullable();
            $table->string('origin_city_id')->nullable();
            $table->string('origin_subdistrict_id')->nullable();
            $table->string('origin_address')->nullable();
            $table->string('subscription_plan')->default('starter');
            $table->string('status')->default('active');
            $table->timestamp('subscription_ends_at')->nullable();
            $table->json('bot_settings')->nullable();
            $table->json('operating_hours')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
