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
        Schema::create('message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->enum('direction', ['in', 'out']);
            $table->text('content');
            $table->string('message_type')->default('text');
            $table->json('metadata')->nullable();
            $table->timestamp('timestamp');
            $table->timestamps();
            
            $table->index(['business_id', 'timestamp']);
            $table->index('conversation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_logs');
    }
};
