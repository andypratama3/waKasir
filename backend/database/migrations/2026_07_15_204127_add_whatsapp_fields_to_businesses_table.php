<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            // Per-WABA token from Embedded Signup (stored encrypted)
            $table->text('wa_access_token')->nullable()->after('wa_phone_number');
            $table->string('wa_waba_id', 64)->nullable()->after('wa_access_token');
            $table->timestamp('wa_token_expires_at')->nullable()->after('wa_waba_id');
            // Connection health flag (set by status check cron)
            $table->boolean('wa_connected')->default(false)->after('wa_token_expires_at');

            $table->index('wa_phone_id');  // fast lookup on webhook routing
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex(['wa_phone_id']);
            $table->dropColumn(['wa_access_token', 'wa_waba_id', 'wa_token_expires_at', 'wa_connected']);
        });
    }
};
