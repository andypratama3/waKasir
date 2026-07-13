import { Component, OnInit, signal, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormBuilder } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { MessageService } from 'primeng/api';
import { ToastModule } from 'primeng/toast';
import { environment } from '../../../environments/environment';

@Component({
  selector: 'app-bot-settings',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, ToastModule],
  providers: [MessageService],
  templateUrl: './bot-settings.component.html',
  styleUrl: './bot-settings.component.scss',
})
export class BotSettingsComponent implements OnInit {
  private fb             = inject(FormBuilder);
  private http           = inject(HttpClient);
  private messageService = inject(MessageService);

  saving = signal(false);
  loading = signal(true);
  operatingHoursEnabled = signal(false);

  form = this.fb.group({
    greeting_message:    ['Halo! Selamat datang di toko kami 👋\n\nSilahkan pilih:\n1️⃣ Lihat Katalog\n2️⃣ Cek Status Pesanan\n3️⃣ Hubungi Admin'],
    fallback_message:    ['Maaf, saya tidak mengerti pesan Anda. Ketik "menu" untuk kembali ke menu utama, atau tunggu admin kami membalas.'],
    operating_start:     ['08:00'],
    operating_end:       ['21:00'],
    operating_enabled:   [false],
    order_confirmation:  ['✅ Pembayaran diterima!\n\nTerima kasih {nama}, pesanan #{order_id} sedang diproses.\nEstimasi pengiriman: {kurir} {estimasi}\n\nKirim alamat lengkap Anda untuk pengiriman:'],
  });

  constructor() {}

  ngOnInit(): void {
    this.http.get<any>(`${environment.apiUrl}/settings/bot`).subscribe({
      next: (data) => {
        if (data) {
          this.form.patchValue({
            greeting_message:   data.greeting_message ?? this.form.value.greeting_message,
            fallback_message:   data.fallback_message ?? this.form.value.fallback_message,
            operating_enabled:  data.operating_hours?.enabled ?? false,
            operating_start:    data.operating_hours?.start ?? '08:00',
            operating_end:      data.operating_hours?.end ?? '21:00',
          });
          this.operatingHoursEnabled.set(data.operating_hours?.enabled ?? false);
        }
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  save(): void {
    this.saving.set(true);
    const v = this.form.value;
    this.http.put(`${environment.apiUrl}/settings/bot`, {
      greeting_message: v.greeting_message,
      fallback_message: v.fallback_message,
      operating_hours: {
        enabled: v.operating_enabled,
        start:   v.operating_start,
        end:     v.operating_end,
      },
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.messageService.add({ severity: 'success', summary: 'Tersimpan', detail: 'Pengaturan bot berhasil disimpan' });
      },
      error: () => {
        this.saving.set(false);
        this.messageService.add({ severity: 'error', summary: 'Gagal', detail: 'Tidak dapat menyimpan pengaturan' });
      },
    });
  }

  toggleOperating(): void {
    const cur = this.form.value.operating_enabled;
    this.form.patchValue({ operating_enabled: !cur });
    this.operatingHoursEnabled.set(!cur);
  }
}
