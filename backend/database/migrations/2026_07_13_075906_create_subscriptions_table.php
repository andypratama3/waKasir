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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->string('plan')->default('starter');
            $table->integer('quota_conversation')->default(200);
            $table->integer('quota_used')->default(0);
            $table->integer('max_products')->default(30);
            $table->timestamp('renewed_at');
            $table->timestamp('ends_at');
            $table->string('status')->default('active');
            $table->json('payment_history')->nullable();
            $table->timestamps();
            
            $table->index('business_id');
            $table->index('ends_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
