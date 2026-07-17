<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Change midtrans_server_key, midtrans_client_key, rajaongkir_api_key
 * from plain VARCHAR to TEXT so Laravel's encrypt() output fits.
 *
 * Also encrypt any existing plain-text values in-place.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Widen columns to TEXT (encrypted payloads are ~200+ chars)
        Schema::table('businesses', function (Blueprint $table) {
            $table->text('midtrans_server_key')->nullable()->change();
            $table->text('midtrans_client_key')->nullable()->change();
            $table->text('rajaongkir_api_key')->nullable()->change();
        });

        // 2. Encrypt any existing plain-text values
        DB::table('businesses')->get()->each(function ($row) {
            $updates = [];

            foreach (['midtrans_server_key', 'midtrans_client_key', 'rajaongkir_api_key'] as $col) {
                $value = $row->{$col};
                if ($value && !self::isEncrypted($value)) {
                    $updates[$col] = encrypt($value);
                }
            }

            if (!empty($updates)) {
                DB::table('businesses')->where('id', $row->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        // Decrypt back to plain text (for rollback)
        DB::table('businesses')->get()->each(function ($row) {
            $updates = [];

            foreach (['midtrans_server_key', 'midtrans_client_key', 'rajaongkir_api_key'] as $col) {
                $value = $row->{$col};
                if ($value) {
                    try {
                        $updates[$col] = decrypt($value);
                    } catch (\Throwable) {
                        // Already plain text or corrupted — leave as-is
                    }
                }
            }

            if (!empty($updates)) {
                DB::table('businesses')->where('id', $row->id)->update($updates);
            }
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->string('midtrans_server_key')->nullable()->change();
            $table->string('midtrans_client_key')->nullable()->change();
            $table->string('rajaongkir_api_key')->nullable()->change();
        });
    }

    /** Heuristic: Laravel encrypt() output starts with "eyJ" (base64 of {"iv":...) */
    private static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, 'eyJ');
    }
};
