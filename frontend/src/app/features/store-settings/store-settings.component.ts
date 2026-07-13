import { Component, OnInit, signal, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormBuilder } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { MessageService } from 'primeng/api';
import { ToastModule } from 'primeng/toast';
import { environment } from '../../../environments/environment';

@Component({
  selector: 'app-store-settings',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, ToastModule],
  providers: [MessageService],
  templateUrl: './store-settings.component.html',
  styleUrl: './store-settings.component.scss',
})
export class StoreSettingsComponent implements OnInit {
  private fb       = inject(FormBuilder);
  private http      = inject(HttpClient);
  private messageService = inject(MessageService);

  activeTab = signal(0);
  savingProfile = signal(false);
  savingWa = signal(false);
  savingMidtrans = signal(false);
  savingRajaOngkir = signal(false);

  showMidtransServer = signal(false);
  showMidtransClient = signal(false);
  showRajaOngkirKey  = signal(false);

  midtransEnv = signal<'sandbox' | 'production'>('sandbox');

  activeCouriers = signal<string[]>(['JNE', 'J&T Express']);
  availableCouriers = ['JNE', 'J&T Express', 'SiCepat', 'Pos Indonesia', 'TIKI', 'Anteraja'];

  profileForm = this.fb.group({
    name: [''],
  });

  waForm = this.fb.group({
    wa_phone_number: [''],
    wa_phone_id:     [''],
  });

  midtransForm = this.fb.group({
    midtrans_server_key: [''],
    midtrans_client_key: [''],
    midtrans_merchant_id: [''],
  });

  rajaOngkirForm = this.fb.group({
    rajaongkir_api_key: [''],
    origin_city_id:     [''],
    origin_address:     [''],
  });

  business: any = null;

  constructor() {}

  ngOnInit(): void {
    this.http.get<any>(`${environment.apiUrl}/business`).subscribe({
      next: (data) => {
        this.business = data.business ?? data;
        this.profileForm.patchValue({ name: this.business.name });
        this.waForm.patchValue({
          wa_phone_number: this.business.wa_phone_number ?? '',
          wa_phone_id:     this.business.wa_phone_id ?? '',
        });
        this.midtransForm.patchValue({
          midtrans_merchant_id: this.business.midtrans_merchant_id ?? '',
          midtrans_server_key: this.business.midtrans_server_key ? '••••••••' + this.business.midtrans_server_key.slice(-4) : '',
          midtrans_client_key: this.business.midtrans_client_key ? '••••••••' + this.business.midtrans_client_key.slice(-4) : '',
        });
        this.rajaOngkirForm.patchValue({
          rajaongkir_api_key: this.business.rajaongkir_api_key ? '••••••••' + this.business.rajaongkir_api_key.slice(-4) : '',
          origin_city_id:     this.business.origin_city_id ?? '',
          origin_address:     this.business.origin_address ?? '',
        });
      },
    });
  }

  setTab(i: number): void { this.activeTab.set(i); }

  saveProfile(): void {
    this.savingProfile.set(true);
    this.http.put(`${environment.apiUrl}/business`, this.profileForm.value).subscribe({
      next: () => { this.savingProfile.set(false); this.toast('Profil disimpan'); },
      error: () => { this.savingProfile.set(false); this.toast('Gagal', true); },
    });
  }

  saveWa(): void {
    this.savingWa.set(true);
    this.http.put(`${environment.apiUrl}/business/integration/whatsapp`, this.waForm.value).subscribe({
      next: () => { this.savingWa.set(false); this.toast('Integrasi WA disimpan'); },
      error: () => { this.savingWa.set(false); this.toast('Gagal', true); },
    });
  }

  saveMidtrans(): void {
    this.savingMidtrans.set(true);
    const v = this.midtransForm.value;
    const payload: any = { midtrans_merchant_id: v.midtrans_merchant_id };
    if (!v.midtrans_server_key?.startsWith('••')) payload.server_key = v.midtrans_server_key;
    if (!v.midtrans_client_key?.startsWith('••')) payload.client_key = v.midtrans_client_key;
    this.http.put(`${environment.apiUrl}/business/integration/midtrans`, payload).subscribe({
      next: () => { this.savingMidtrans.set(false); this.toast('Integrasi Midtrans disimpan'); },
      error: () => { this.savingMidtrans.set(false); this.toast('Gagal', true); },
    });
  }

  saveRajaOngkir(): void {
    this.savingRajaOngkir.set(true);
    const v = this.rajaOngkirForm.value;
    const payload: any = { origin_city_id: v.origin_city_id, origin_address: v.origin_address };
    if (!v.rajaongkir_api_key?.startsWith('••')) payload.api_key = v.rajaongkir_api_key;
    this.http.put(`${environment.apiUrl}/business/integration/rajaongkir`, payload).subscribe({
      next: () => { this.savingRajaOngkir.set(false); this.toast('Integrasi RajaOngkir disimpan'); },
      error: () => { this.savingRajaOngkir.set(false); this.toast('Gagal', true); },
    });
  }

  toggleCourier(c: string): void {
    const cur = this.activeCouriers();
    this.activeCouriers.set(cur.includes(c) ? cur.filter(x => x !== c) : [...cur, c]);
  }

  isCourierActive(c: string): boolean { return this.activeCouriers().includes(c); }

  toast(detail: string, error = false): void {
    this.messageService.add({ severity: error ? 'error' : 'success', summary: error ? 'Gagal' : 'Berhasil', detail });
  }

  tabs = [
    { label: 'Profil Toko',   icon: 'store' },
    { label: 'WhatsApp',      icon: 'chat' },
    { label: 'Midtrans',      icon: 'qr_code_2' },
    { label: 'RajaOngkir',    icon: 'local_shipping' },
  ];
}
