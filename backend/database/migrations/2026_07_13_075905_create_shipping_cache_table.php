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
        Schema::create('shipping_cache', function (Blueprint $table) {
            $table->id();
            $table->string('province_id');
            $table->string('province_name');
            $table->string('city_id');
            $table->string('city_name');
            $table->string('city_type')->nullable();
            $table->string('subdistrict_id')->nullable();
            $table->string('subdistrict_name')->nullable();
            $table->timestamps();
            
            $table->unique('city_id');
            $table->index('province_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_cache');
    }
};
