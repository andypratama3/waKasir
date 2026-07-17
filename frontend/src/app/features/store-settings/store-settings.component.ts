import { Component, OnInit, OnDestroy, signal, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormBuilder } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { MessageService } from 'primeng/api';
import { ToastModule } from 'primeng/toast';
import { SkeletonModule } from 'primeng/skeleton';
import { environment } from '../../../environments/environment';

declare const FB: any; // Facebook JS SDK (loaded in index.html)

@Component({
  selector: 'app-store-settings',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, ToastModule, SkeletonModule],
  providers: [MessageService],
  templateUrl: './store-settings.component.html',
  styleUrl: './store-settings.component.scss',
})
export class StoreSettingsComponent implements OnInit, OnDestroy {
  private fb             = inject(FormBuilder);
  private http           = inject(HttpClient);
  private messageService = inject(MessageService);

  activeTab = signal(0);

  // ── Saving states ─────────────────────────────────────────────────────────
  savingProfile    = signal(false);
  savingMidtrans   = signal(false);
  savingRajaOngkir = signal(false);

  // ── WA connection state ──────────────────────────────────────────────────
  waLoading        = signal(false);   // loading current status
  waConnecting     = signal(false);   // Embedded Signup in progress
  waDisconnecting  = signal(false);
  waStatus         = signal<WaStatus | null>(null);

  // ── Midtrans / RajaOngkir reveal toggles ────────────────────────────────
  showMidtransServer = signal(false);
  showMidtransClient = signal(false);
  showRajaOngkirKey  = signal(false);
  midtransEnv        = signal<'sandbox' | 'production'>('sandbox');

  activeCouriers    = signal<string[]>(['JNE', 'J&T Express']);
  availableCouriers = ['JNE', 'J&T Express', 'SiCepat', 'Pos Indonesia', 'TIKI', 'Anteraja'];

  business: any = null;

  profileForm = this.fb.group({
    name: [''],
  });

  midtransForm = this.fb.group({
    midtrans_server_key:  [''],
    midtrans_client_key:  [''],
    midtrans_merchant_id: [''],
  });

  rajaOngkirForm = this.fb.group({
    rajaongkir_api_key: [''],
    origin_city_id:     [''],
    origin_address:     [''],
  });

  tabs = [
    { label: 'Profil Toko',  icon: 'store'          },
    { label: 'WhatsApp',     icon: 'chat'            },
    { label: 'Midtrans',     icon: 'qr_code_2'       },
    { label: 'RajaOngkir',   icon: 'local_shipping'  },
  ];

  // ── FB SDK message listener ──────────────────────────────────────────────
  private fbMessageListener: ((e: MessageEvent) => void) | null = null;

  ngOnInit(): void {
    this.loadBusiness();
  }

  ngOnDestroy(): void {
    if (this.fbMessageListener) {
      window.removeEventListener('message', this.fbMessageListener);
    }
  }

  setTab(i: number): void {
    this.activeTab.set(i);
    if (i === 1 && !this.waStatus()) {
      this.loadWaStatus();
    }
  }

  // ── Business / profile ───────────────────────────────────────────────────

  private loadBusiness(): void {
    this.http.get<any>(`${environment.apiUrl}/business`).subscribe({
      next: (data) => {
        this.business = data.business ?? data;
        this.profileForm.patchValue({ name: this.business.name });

        // Midtrans — show masked values from server (hidden fields return null)
        this.midtransForm.patchValue({
          midtrans_merchant_id: this.business.midtrans_merchant_id ?? '',
          midtrans_server_key:  this.business.midtrans_server_key_masked  ? '••••••••' : '',
          midtrans_client_key:  this.business.midtrans_client_key_masked  ? '••••••••' : '',
        });

        this.rajaOngkirForm.patchValue({
          rajaongkir_api_key: this.business.rajaongkir_api_key_masked ? '••••••••' : '',
          origin_city_id:     this.business.origin_city_id  ?? '',
          origin_address:     this.business.origin_address  ?? '',
        });
      },
    });
  }

  saveProfile(): void {
    this.savingProfile.set(true);
    this.http.put(`${environment.apiUrl}/business`, this.profileForm.value).subscribe({
      next: () => { this.savingProfile.set(false); this.toast('Profil disimpan'); },
      error: () => { this.savingProfile.set(false); this.toast('Gagal menyimpan profil', true); },
    });
  }

  // ── WhatsApp BSP / Embedded Signup ───────────────────────────────────────

  private loadWaStatus(): void {
    this.waLoading.set(true);
    this.http.get<WaStatus>(`${environment.apiUrl}/business/whatsapp/status`).subscribe({
      next:  (s) => { this.waStatus.set(s);    this.waLoading.set(false); },
      error: ()  => {                           this.waLoading.set(false); },
    });
  }

  /**
   * Launch Facebook Embedded Signup popup.
   * FB SDK (loaded in index.html) opens the Meta OAuth dialog.
   * On success, Meta posts a message with the authorization code back to window.
   */
  launchEmbeddedSignup(): void {
    if (typeof FB === 'undefined') {
      this.toast('Facebook SDK belum termuat. Coba refresh halaman.', true);
      return;
    }

    this.waConnecting.set(true);

    // Register a one-time message listener that catches the OAuth callback
    // Meta sends: { type: 'WA_EMBEDDED_SIGNUP', event: 'FINISH', data: { code, ... } }
    this.fbMessageListener = (event: MessageEvent) => {
      if (event.origin !== 'https://www.facebook.com' && event.origin !== 'https://web.facebook.com') {
        return;
      }
      try {
        const payload = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
        if (payload?.type === 'WA_EMBEDDED_SIGNUP') {
          window.removeEventListener('message', this.fbMessageListener!);
          this.fbMessageListener = null;

          if (payload.event === 'FINISH' && payload.data?.code) {
            this.exchangeSignupCode(payload.data.code);
          } else if (payload.event === 'CANCEL') {
            this.waConnecting.set(false);
            this.toast('Koneksi dibatalkan.', true);
          } else {
            this.waConnecting.set(false);
          }
        }
      } catch { /* ignore non-JSON messages */ }
    };

    window.addEventListener('message', this.fbMessageListener);

    FB.login(
      (response: any) => {
        // FB.login callback is the auth response — actual Embedded Signup result
        // comes via the window message above. This callback handles hard failures.
        if (!response.authResponse) {
          window.removeEventListener('message', this.fbMessageListener!);
          this.fbMessageListener = null;
          this.waConnecting.set(false);
          // Don't show error here — user may have just closed the popup
        }
      },
      {
        config_id:                           environment.metaEmbeddedSignupConfigId,
        response_type:                       'code',
        override_default_response_type:      true,
        extras: {
          setup:              {},
          featureType:        '',
          sessionInfoVersion: '3',
        },
      }
    );
  }

  private exchangeSignupCode(code: string): void {
    this.http.post<any>(`${environment.apiUrl}/business/whatsapp/connect`, { code }).subscribe({
      next: (res) => {
        this.waConnecting.set(false);
        this.waStatus.set({
          connected:       true,
          phone_number:    res.phone_number,
          phone_number_id: null,
          waba_id:         res.waba_id,
          verified_name:   res.verified_name,
          quality_rating:  null,
          status:          'CONNECTED',
          token_expires_at: null,
        });
        this.toast(`✅ WhatsApp berhasil dihubungkan! Nomor: ${res.phone_number}`);
      },
      error: (err) => {
        this.waConnecting.set(false);
        const msg = err?.error?.error ?? 'Gagal menghubungkan WhatsApp.';
        this.toast(msg, true);
      },
    });
  }

  disconnectWhatsApp(): void {
    if (!confirm('Yakin ingin memutus koneksi WhatsApp? Bot akan berhenti bekerja.')) return;
    this.waDisconnecting.set(true);
    this.http.delete(`${environment.apiUrl}/business/whatsapp/disconnect`).subscribe({
      next: () => {
        this.waDisconnecting.set(false);
        this.waStatus.set(null);
        this.toast('WhatsApp berhasil diputus.');
      },
      error: () => { this.waDisconnecting.set(false); this.toast('Gagal memutus koneksi.', true); },
    });
  }

  refreshWaStatus(): void {
    this.loadWaStatus();
  }

  // ── Midtrans ──────────────────────────────────────────────────────────────

  saveMidtrans(): void {
    this.savingMidtrans.set(true);
    const v = this.midtransForm.value;
    const payload: any = { midtrans_merchant_id: v.midtrans_merchant_id };
    if (v.midtrans_server_key && !v.midtrans_server_key.startsWith('••')) {
      payload.server_key = v.midtrans_server_key;
    }
    if (v.midtrans_client_key && !v.midtrans_client_key.startsWith('••')) {
      payload.client_key = v.midtrans_client_key;
    }
    this.http.put(`${environment.apiUrl}/business/integration/midtrans`, payload).subscribe({
      next: () => { this.savingMidtrans.set(false); this.toast('Integrasi Midtrans disimpan'); },
      error: () => { this.savingMidtrans.set(false); this.toast('Gagal menyimpan Midtrans', true); },
    });
  }

  // ── RajaOngkir ────────────────────────────────────────────────────────────

  saveRajaOngkir(): void {
    this.savingRajaOngkir.set(true);
    const v = this.rajaOngkirForm.value;
    const payload: any = { origin_city_id: v.origin_city_id, origin_address: v.origin_address };
    if (v.rajaongkir_api_key && !v.rajaongkir_api_key.startsWith('••')) {
      payload.api_key = v.rajaongkir_api_key;
    }
    this.http.put(`${environment.apiUrl}/business/integration/rajaongkir`, payload).subscribe({
      next: () => { this.savingRajaOngkir.set(false); this.toast('Integrasi RajaOngkir disimpan'); },
      error: () => { this.savingRajaOngkir.set(false); this.toast('Gagal menyimpan RajaOngkir', true); },
    });
  }

  // ── Courier helpers ───────────────────────────────────────────────────────

  toggleCourier(c: string): void {
    const cur = this.activeCouriers();
    this.activeCouriers.set(cur.includes(c) ? cur.filter(x => x !== c) : [...cur, c]);
  }

  isCourierActive(c: string): boolean { return this.activeCouriers().includes(c); }

  // ── Utility ───────────────────────────────────────────────────────────────

  get waConnected(): boolean { return this.waStatus()?.connected === true; }

  get tokenExpiresLabel(): string {
    const exp = this.waStatus()?.token_expires_at;
    if (!exp) return 'Tidak kadaluwarsa (System User Token)';
    const d = new Date(exp);
    const days = Math.ceil((d.getTime() - Date.now()) / 86_400_000);
    if (days <= 0) return '⚠️ Token sudah kadaluwarsa!';
    if (days <= 7) return `⚠️ Kadaluwarsa dalam ${days} hari`;
    return `${d.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })} (${days} hari lagi)`;
  }

  qualityClass(rating: string | null): string {
    return { GREEN: 'quality-green', YELLOW: 'quality-yellow', RED: 'quality-red' }[rating ?? ''] ?? '';
  }

  toast(detail: string, error = false): void {
    this.messageService.add({
      severity: error ? 'error' : 'success',
      summary:  error ? 'Gagal'  : 'Berhasil',
      detail,
      life: 4000,
    });
  }
}

interface WaStatus {
  connected:       boolean;
  phone_number:    string | null;
  phone_number_id: string | null;
  waba_id:         string | null;
  verified_name:   string | null;
  quality_rating:  string | null;  // GREEN | YELLOW | RED
  status:          string | null;
  token_expires_at: string | null;
}
