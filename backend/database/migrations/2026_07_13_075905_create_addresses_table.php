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
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->string('city_id')->nullable();
            $table->string('subdistrict_id')->nullable();
            $table->string('city_name')->nullable();
            $table->string('subdistrict_name')->nullable();
            $table->text('full_address');
            $table->string('recipient_name');
            $table->string('recipient_phone');
            $table->string('postal_code')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index('order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
