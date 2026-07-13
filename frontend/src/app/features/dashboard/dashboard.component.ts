import { Component, OnInit, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { DashboardService } from './dashboard.service';
import { RupiahPipe } from '../../shared/pipes/rupiah.pipe';
import { UIChart, ChartModule } from 'primeng/chart';
import { Skeleton, SkeletonModule } from 'primeng/skeleton';
import { Tag, TagModule } from 'primeng/tag';

type Period = '7d' | '30d' | '90d';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [CommonModule, RouterLink, RupiahPipe, ChartModule, SkeletonModule, TagModule],
  templateUrl: './dashboard.component.html',
  styleUrl: './dashboard.component.scss',
})
export class DashboardComponent implements OnInit {
  loading = signal(true);
  chartLoading = signal(false);
  stats = signal<any>(null);
  recentOrders = signal<any[]>([]);
  lowStock = signal<any[]>([]);
  selectedPeriod = signal<Period>('7d');

  chartData = signal<any>(null);
  chartOptions = signal<any>({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { intersect: false, mode: 'index' },
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          label: (ctx: any) =>
            ctx.dataset.label + ': Rp ' + ctx.parsed.y.toLocaleString('id-ID'),
        },
      },
    },
    scales: {
      x: { grid: { display: false }, ticks: { font: { size: 11, family: 'Plus Jakarta Sans' } } },
      y: {
        grid: { color: '#ecf0ee' },
        ticks: {
          font: { size: 11 },
          callback: (v: number) => 'Rp ' + (v / 1000).toFixed(0) + 'rb',
        },
      },
    },
  });

  barChartData = signal<any>(null);
  topCities = signal<any[]>([]);

  userName = computed(() => {
    const raw = localStorage.getItem('user');
    if (!raw) return 'Pemilik Toko';
    const user = JSON.parse(raw);
    return user?.name ?? 'Pemilik Toko';
  });

  greeting = computed(() => {
    const hour = new Date().getHours();
    if (hour < 11) return 'Selamat pagi';
    if (hour < 15) return 'Selamat siang';
    if (hour < 18) return 'Selamat sore';
    return 'Selamat malam';
  });

  barChartOptions = signal<any>({
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { display: false }, ticks: { font: { size: 11, family: 'Plus Jakarta Sans' } } },
      y: { grid: { color: '#ecf0ee' }, ticks: { font: { size: 11 } } },
    },
  });

  periods: { label: string; value: Period }[] = [
    { label: '7 hari', value: '7d' },
    { label: '30 hari', value: '30d' },
    { label: '90 hari', value: '90d' },
  ];

  constructor(private dashService: DashboardService) {}

  ngOnInit(): void {
    this.loadStats();
    this.loadChart('7d');
    this.loadTopProducts();
    this.loadTopCities();
  }

  loadStats(): void {
    this.loading.set(true);
    this.dashService.getStats().subscribe({
      next: (data) => {
        this.stats.set(data.stats);
        this.recentOrders.set(data.recent_orders ?? []);
        this.lowStock.set(data.low_stock_products ?? []);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  loadChart(period: Period): void {
    this.selectedPeriod.set(period);
    this.chartLoading.set(true);
    this.dashService.getSalesChart(period).subscribe({
      next: (data) => {
        const raw = data.chart_data ?? [];
        this.chartData.set({
          labels: raw.map((d: any) => {
            const dt = new Date(d.date);
            return dt.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
          }),
          datasets: [
            {
              label: 'Omzet',
              data: raw.map((d: any) => d.revenue),
              borderColor: '#128C7E',
              backgroundColor: 'rgba(18,140,126,0.08)',
              tension: 0.4,
              fill: true,
              pointRadius: 4,
              pointBackgroundColor: '#128C7E',
            },
          ],
        });
        this.chartLoading.set(false);
      },
      error: () => this.chartLoading.set(false),
    });
  }

  loadTopProducts(): void {
    this.dashService.getTopProducts().subscribe({
      next: (data) => {
        const top = (data.top_products ?? []).slice(0, 5);
        this.barChartData.set({
          labels: top.map((p: any) => p.name),
          datasets: [
            {
              label: 'Terjual',
              data: top.map((p: any) => p.total_sold),
              backgroundColor: [
                '#128C7E', '#25D366', '#1aaa9c', '#0ea5e9', '#8b5cf6',
              ],
              borderRadius: 6,
            },
          ],
        });
      },
    });
  }

  loadTopCities(): void {
    this.dashService.getTopCities().subscribe({
      next: (data) => this.topCities.set(data.top_cities ?? []),
    });
  }

  getStatusClass(status: string): string {
    return `status-badge status-${status}`;
  }
}
