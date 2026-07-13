import { Component, OnInit, signal, computed, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { DashboardService } from './dashboard.service';
import { RupiahPipe } from '../../shared/pipes/rupiah.pipe';
import { ChartModule } from 'primeng/chart';
import { SkeletonModule } from 'primeng/skeleton';
import { BRAND } from '../../core/tokens/brand.tokens';

type Period = '7d' | '30d' | '90d';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [CommonModule, RouterLink, RupiahPipe, ChartModule, SkeletonModule],
  templateUrl: './dashboard.component.html',
  styleUrl: './dashboard.component.scss',
})
export class DashboardComponent implements OnInit {
  private dashService = inject(DashboardService);

  loading      = signal(true);
  chartLoading = signal(false);
  stats        = signal<any>(null);
  recentOrders = signal<any[]>([]);
  lowStock     = signal<any[]>([]);
  selectedPeriod = signal<Period>('7d');

  chartData    = signal<any>(null);
  barChartData = signal<any>(null);
  topCities    = signal<any[]>([]);

  // ── Chart options (clean, modern) ──────────────────────────
  lineOptions = signal<any>({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { intersect: false, mode: 'index' },
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: 'rgba(7,94,84,0.92)',
        titleColor: 'rgba(255,255,255,0.7)',
        bodyColor: '#fff',
        padding: 12,
        cornerRadius: 10,
        callbacks: {
          label: (ctx: any) => ' Rp ' + ctx.parsed.y.toLocaleString('id-ID'),
        },
      },
    },
    scales: {
      x: {
        grid: { display: false },
        border: { display: false },
        ticks: { color: '#9ca3af', font: { size: 11, family: 'Inter' } },
      },
      y: {
        grid: { color: 'rgba(0,0,0,0.04)', drawBorder: false },
        border: { display: false },
        ticks: {
          color: '#9ca3af',
          font: { size: 11, family: 'Inter' },
          callback: (v: number) => v >= 1000000 ? 'Rp ' + (v / 1000000).toFixed(1) + 'jt' : v >= 1000 ? 'Rp ' + (v / 1000).toFixed(0) + 'rb' : 'Rp ' + v,
        },
      },
    },
    elements: { line: { tension: 0.45 }, point: { radius: 0, hoverRadius: 6 } },
  });

  barOptions = signal<any>({
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(7,94,84,0.92)', bodyColor: '#fff', cornerRadius: 10, padding: 10 } },
    scales: {
      x: { grid: { display: false }, border: { display: false }, ticks: { color: '#9ca3af', font: { size: 11 }, maxRotation: 30 } },
      y: { grid: { color: 'rgba(0,0,0,0.04)' }, border: { display: false }, ticks: { color: '#9ca3af', font: { size: 11 } } },
    },
  });

  // ── Derived ────────────────────────────────────────────────
  userName = computed(() => {
    try { return JSON.parse(localStorage.getItem('user') ?? '{}')?.name ?? 'Pemilik Toko'; }
    catch { return 'Pemilik Toko'; }
  });

  businessName = computed(() => {
    try { return JSON.parse(localStorage.getItem('user') ?? '{}')?.business?.name ?? ''; }
    catch { return ''; }
  });

  greeting = computed(() => {
    const h = new Date().getHours();
    if (h < 11) return 'Selamat pagi';
    if (h < 15) return 'Selamat siang';
    if (h < 18) return 'Selamat sore';
    return 'Selamat malam';
  });

  currentDate = computed(() =>
    new Date().toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
  );

  kpiCards = computed(() => {
    const s = this.stats();
    if (!s) return [];
    return [
      {
        id: 'revenue',
        label: 'Omzet Hari Ini',
        value: s.today?.revenue ?? 0,
        isRupiah: true,
        icon: 'payments',
        color: 'green',
        trend: { icon: 'trending_up', label: (s.week?.revenue ?? 0), isRupiah: true, suffix: ' minggu ini', up: true },
      },
      {
        id: 'orders',
        label: 'Order Hari Ini',
        value: s.today?.orders ?? 0,
        isRupiah: false,
        icon: 'receipt_long',
        color: 'blue',
        trend: { icon: 'calendar_today', label: s.week?.orders ?? 0, isRupiah: false, suffix: ' minggu ini', up: true },
      },
      {
        id: 'pending',
        label: 'Perlu Diproses',
        value: (s.orders_by_status?.pending ?? 0) + (s.orders_by_status?.paid ?? 0),
        isRupiah: false,
        icon: 'pending_actions',
        color: 'amber',
        trend: { icon: 'payments', label: s.orders_by_status?.paid ?? 0, isRupiah: false, suffix: ' menunggu bayar', up: false },
      },
      {
        id: 'customers',
        label: 'Total Pelanggan',
        value: s.overall?.total_customers ?? 0,
        isRupiah: false,
        icon: 'group',
        color: 'violet',
        trend: { icon: 'inventory_2', label: s.overall?.active_products ?? 0, isRupiah: false, suffix: ' produk aktif', up: true },
      },
    ];
  });

  periods: { label: string; value: Period }[] = [
    { label: '7 Hari',  value: '7d'  },
    { label: '30 Hari', value: '30d' },
    { label: '90 Hari', value: '90d' },
  ];

  orderStatusSteps = ['pending', 'paid', 'processing', 'shipped', 'completed'];

  ngOnInit(): void {
    this.loadStats();
    this.loadChart('7d');
    this.loadTopProducts();
    this.loadTopCities();
  }

  loadStats(): void {
    this.loading.set(true);
    this.dashService.getStats().subscribe({
      next: d => { this.stats.set(d.stats); this.recentOrders.set(d.recent_orders ?? []); this.lowStock.set(d.low_stock_products ?? []); this.loading.set(false); },
      error: () => this.loading.set(false),
    });
  }

  loadChart(period: Period): void {
    this.selectedPeriod.set(period);
    this.chartLoading.set(true);
    this.dashService.getSalesChart(period).subscribe({
      next: d => {
        const raw = d.chart_data ?? [];
        this.chartData.set({
          labels: raw.map((x: any) => new Date(x.date).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })),
          datasets: [{
            label: 'Omzet',
            data: raw.map((x: any) => +x.revenue),
            borderColor: BRAND.primary,
            backgroundColor: (ctx: any) => {
              const gradient = ctx?.chart?.ctx?.createLinearGradient(0, 0, 0, 280);
              gradient?.addColorStop(0, BRAND.primaryAlpha(0.18));
              gradient?.addColorStop(1, BRAND.primaryAlpha(0));
              return gradient ?? BRAND.primaryAlpha(0.1);
            },
            borderWidth: 2.5,
            fill: true,
          }],
        });
        this.chartLoading.set(false);
      },
      error: () => this.chartLoading.set(false),
    });
  }

  loadTopProducts(): void {
    this.dashService.getTopProducts().subscribe({
      next: d => {
        const top = (d.top_products ?? []).slice(0, 6);
        this.barChartData.set({
          labels: top.map((p: any) => p.name.length > 18 ? p.name.slice(0, 16) + '…' : p.name),
          datasets: [{
            label: 'Terjual',
            data: top.map((p: any) => p.total_sold),
            backgroundColor: [...BRAND.chartPalette],
            borderRadius: 8,
            borderSkipped: false,
          }],
        });
      },
    });
  }

  loadTopCities(): void {
    this.dashService.getTopCities().subscribe({ next: d => this.topCities.set(d.top_cities ?? []) });
  }

  getStatusClass(status: string): string { return `status-badge status-${status ?? 'pending'}`; }

  getStockLevel(stock: number): 'critical' | 'low' | 'ok' {
    if (stock === 0) return 'critical';
    if (stock <= 5) return 'low';
    return 'ok';
  }

  orderStatusCount(status: string): number {
    return this.stats()?.orders_by_status?.[status] ?? 0;
  }
}
