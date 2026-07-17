<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppNotification;
use App\Models\Business;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\Log;

#[Signature('wa:check-tokens')]
#[Description('Check WhatsApp access token expiry for all businesses and notify owners if renewal is needed.')]
class CheckWhatsAppTokens extends Command
{
    public function handle(): int
    {
        $businesses = Business::whereNotNull('wa_access_token')
            ->where('wa_connected', true)
            ->get();

        if ($businesses->isEmpty()) {
            $this->info('No connected businesses found.');
            return self::SUCCESS;
        }

        $this->info("Checking {$businesses->count()} connected business(es)...");

        $warned = 0;

        foreach ($businesses as $business) {
            try {
                // No expiry recorded — System User Token (non-expiring), skip
                if (!$business->wa_token_expires_at) {
                    continue;
                }

                $daysUntilExpiry = now()->diffInDays($business->wa_token_expires_at, false);

                // Token already expired
                if ($daysUntilExpiry <= 0) {
                    $business->update(['wa_connected' => false]);

                    $this->error("  ✗ [{$business->name}] token EXPIRED. Marked disconnected.");

                    $this->notifyOwner(
                        $business,
                        "⚠️ *Koneksi WhatsApp Terputus*\n\n"
                        . "Token WhatsApp untuk toko *{$business->name}* telah kedaluwarsa.\n\n"
                        . "Bot tidak dapat mengirim atau menerima pesan. "
                        . "Silakan hubungkan ulang WhatsApp di dashboard → Pengaturan Toko → WhatsApp."
                    );

                    Log::warning('wa:check-tokens — token expired', ['business_id' => $business->id]);
                    $warned++;
                    continue;
                }

                // Token expiring within 7 days — warn owner
                if ($daysUntilExpiry <= 7) {
                    $this->warn("  ⚠ [{$business->name}] token expires in {$daysUntilExpiry} day(s).");

                    $this->notifyOwner(
                        $business,
                        "⚠️ *Token WhatsApp Akan Kedaluwarsa*\n\n"
                        . "Token WhatsApp toko *{$business->name}* akan kedaluwarsa dalam *{$daysUntilExpiry} hari*.\n\n"
                        . "Segera hubungkan ulang di dashboard → Pengaturan Toko → WhatsApp sebelum bot berhenti bekerja."
                    );

                    Log::info('wa:check-tokens — token expiring soon', [
                        'business_id'    => $business->id,
                        'days_remaining' => $daysUntilExpiry,
                    ]);
                    $warned++;
                    continue;
                }

                $this->line("  ✓ [{$business->name}] token valid ({$daysUntilExpiry} days remaining).");

            } catch (\Throwable $e) {
                $this->error("  ✗ [{$business->name}] check failed: " . $e->getMessage());
                Log::error('wa:check-tokens failed', [
                    'business_id' => $business->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        $this->info("Done. {$warned} business(es) warned.");
        return self::SUCCESS;
    }

    private function notifyOwner(Business $business, string $message): void
    {
        // Notify via email (owner user record) and WhatsApp if owner has a number
        $owner = User::where('business_id', $business->id)
            ->where('role', 'owner')
            ->first();

        if (!$owner) {
            return;
        }

        // Send WA notification to owner's registered phone (if they have one as customer)
        $ownerCustomer = $business->customers()
            ->where('email', $owner->email)
            ->first();

        if ($ownerCustomer?->wa_number && $business->wa_phone_id) {
            dispatch(new SendWhatsAppNotification(
                $ownerCustomer->wa_number,
                $message,
                $business->id,
            ));
        }

        // TODO: also send email notification when mail is configured
    }
}
