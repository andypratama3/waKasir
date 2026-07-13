import { Component, OnInit, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { MessageService } from 'primeng/api';
import { ToastModule } from 'primeng/toast';
import { RupiahPipe } from '../../shared/pipes/rupiah.pipe';
import { environment } from '../../../environments/environment';

interface Plan {
  key: string;
  name: string;
  price: number;
  quota: number;
  maxProducts: string;
  color: string;
  icon: string;
  features: string[];
  popular?: boolean;
}

@Component({
  selector: 'app-subscription',
  standalone: true,
  imports: [CommonModule, ToastModule, RupiahPipe],
  providers: [MessageService],
  templateUrl: './subscription.component.html',
  styleUrl: './subscription.component.scss',
})
export class SubscriptionComponent implements OnInit {
  loading   = signal(true);
  upgrading = signal(false);

  subscription = signal<any>(null);

  plans: Plan[] = [
    {
      key: 'starter',
      name: 'Starter',
      price: 99_000,
      quota: 200,
      maxProducts: '30 produk',
      color: '#6b7280',
      icon: 'rocket_launch',
      features: [
        'Bot WhatsApp otomatis',
        'Integrasi Midtrans QRIS',
        'Integrasi RajaOngkir',
        'Dashboard analitik dasar',
        'Support email',
      ],
    },
    {
      key: 'growth',
      name: 'Growth',
      price: 249_000,
      quota: 600,
      maxProducts: '200 produk',
      color: '#128C7E',
      icon: 'trending_up',
      popular: true,
      features: [
        'Semua fitur Starter',
        'Import produk via Excel',
        'Laporan penjualan lanjutan',
        'Top kota & produk terlaris',
        'Support prioritas',
      ],
    },
    {
      key: 'pro',
      name: 'Pro',
      price: 499_000,
      quota: 1_500,
      maxProducts: 'Unlimited produk',
      color: '#7c3aed',
      icon: 'workspace_premium',
      features: [
        'Semua fitur Growth',
        'Multi-admin / staff',
        'Invoice PDF otomatis',
        'API webhook kustom',
        'Dedicated support',
      ],
    },
  ];

  usagePercent = computed(() => {
    const s = this.subscription();
    if (!s || !s.quota_conversation) return 0;
    return Math.min(100, Math.round((s.quota_used / s.quota_conversation) * 100));
  });

  usageColor = computed(() => {
    const p = this.usagePercent();
    if (p >= 90) return '#ef4444';
    if (p >= 70) return '#f59e0b';
    return '#128C7E';
  });

  constructor(private http: HttpClient, private messageService: MessageService) {}

  ngOnInit(): void {
    this.http.get<any>(`${environment.apiUrl}/settings/subscription`).subscribe({
      next: (data) => {
        this.subscription.set(data.subscription ?? data);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  isCurrentPlan(planKey: string): boolean {
    return this.subscription()?.plan === planKey;
  }

  isLowerPlan(planKey: string): boolean {
    const order = ['starter', 'growth', 'pro'];
    const current = order.indexOf(this.subscription()?.plan ?? 'starter');
    const target  = order.indexOf(planKey);
    return target < current;
  }

  upgrade(planKey: string): void {
    if (this.isCurrentPlan(planKey) || this.isLowerPlan(planKey)) return;
    this.upgrading.set(true);

    this.http.post(`${environment.apiUrl}/settings/subscription/upgrade`, { plan: planKey }).subscribe({
      next: () => {
        this.upgrading.set(false);
        this.messageService.add({ severity: 'success', summary: 'Upgrade Berhasil', detail: `Paket diubah ke ${planKey}` });
        this.ngOnInit();
      },
      error: () => {
        this.upgrading.set(false);
        this.messageService.add({ severity: 'error', summary: 'Gagal', detail: 'Hubungi tim kami untuk upgrade paket' });
      },
    });
  }

  formatDate(date: string): string {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
  }
}
