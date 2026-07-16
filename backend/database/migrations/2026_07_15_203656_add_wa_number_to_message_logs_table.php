<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            // Store the customer WA number directly on each log row so logs can be
            // queried per-customer without needing to join through conversations.
            $table->string('wa_number', 20)->nullable()->after('business_id');
            $table->index(['business_id', 'wa_number']);
        });
    }

    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'wa_number']);
            $table->dropColumn('wa_number');
        });
    }
};
