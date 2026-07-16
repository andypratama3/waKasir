import { Component, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReportsService } from './reports.service';
import { ChartModule } from 'primeng/chart';
import { SkeletonModule } from 'primeng/skeleton';
import { BRAND } from '../../core/tokens/brand.tokens';

type Period = '7d' | '30d' | '90d';

@Component({
  selector: 'app-reports',
  standalone: true,
  imports: [CommonModule, ChartModule, SkeletonModule],
  templateUrl: './reports.component.html',
  styleUrl: './reports.component.scss',
})
export class ReportsComponent implements OnInit {
  loading          = signal(true);
  selectedPeriod   = signal<Period>('30d');

  salesChart       = signal<any>(null);
  topProductsChart = signal<any>(null);
  topCities        = signal<any[]>([]);
  orderStats       = signal<any>(null);

  periods: { label: string; value: Period }[] = [
    { label: '7 Hari',  value: '7d'  },
    { label: '30 Hari', value: '30d' },
    { label: '90 Hari', value: '90d' },
  ];

  lineOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: 'rgba(7,94,84,0.92)',
        bodyColor: '#fff',
        cornerRadius: 10,
        padding: 12,
        callbacks: { label: (ctx: any) => ' Rp ' + ctx.parsed.y.toLocaleString('id-ID') },
      },
    },
    scales: {
      x: { grid: { display: false }, border: { display: false }, ticks: { color: '#9ca3af', font: { size: 11 } } },
      y: {
        grid: { color: 'rgba(0,0,0,0.04)' }, border: { display: false },
        ticks: { color: '#9ca3af', font: { size: 11 }, callback: (v: number) => v >= 1_000_000 ? 'Rp' + (v / 1_000_000).toFixed(1) + 'jt' : 'Rp' + (v / 1000).toFixed(0) + 'rb' },
      },
    },
    elements: { line: { tension: 0.45 }, point: { radius: 0, hoverRadius: 6 } },
  };

  barOptions = {
    indexAxis: 'y' as const,
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(7,94,84,0.92)', bodyColor: '#fff', cornerRadius: 10 } },
    scales: {
      x: { grid: { color: 'rgba(0,0,0,0.04)' }, border: { display: false }, ticks: { color: '#9ca3af', font: { size: 11 } } },
      y: { grid: { display: false }, border: { display: false }, ticks: { color: '#374151', font: { size: 12 } } },
    },
  };

  constructor(private reports: ReportsService) {}

  ngOnInit(): void {
    this.loadAll();
  }

  loadAll(): void {
    this.loading.set(true);
    const period = this.selectedPeriod();

    this.reports.getSalesChart(period).subscribe({
      next: (d) => {
        const raw = d.chart_data ?? [];
        this.salesChart.set({
          labels: raw.map((x: any) => new Date(x.date).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })),
          datasets: [{
            label: 'Omzet',
            data: raw.map((x: any) => +x.revenue),
            borderColor: BRAND.primary,
            backgroundColor: (ctx: any) => {
              const g = ctx?.chart?.ctx?.createLinearGradient(0, 0, 0, 280);
              g?.addColorStop(0, BRAND.primaryAlpha(0.18));
              g?.addColorStop(1, BRAND.primaryAlpha(0));
              return g ?? BRAND.primaryAlpha(0.1);
            },
            borderWidth: 2.5,
            fill: true,
          }],
        });
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });

    this.reports.getTopProducts().subscribe({
      next: (d) => {
        const top = (d.top_products ?? []).slice(0, 8);
        this.topProductsChart.set({
          labels: top.map((p: any) => p.name.length > 24 ? p.name.slice(0, 22) + '…' : p.name),
          datasets: [{
            label: 'Terjual',
            data: top.map((p: any) => p.total_sold),
            backgroundColor: BRAND.chartPalette.slice(0, top.length),
            borderRadius: 6,
            borderSkipped: false,
          }],
        });
      },
    });

    this.reports.getTopCities().subscribe({
      next: (d) => this.topCities.set(d.top_cities ?? []),
    });

    this.reports.getOrderStats().subscribe({
      next: (d) => this.orderStats.set(d.stats ?? d),
    });
  }

  changePeriod(p: Period): void {
    this.selectedPeriod.set(p);
    this.loadAll();
  }

  maxCityOrders(): number {
    const cities = this.topCities();
    if (!cities.length) return 1;
    return Math.max(...cities.map((c: any) => c.order_count));
  }
}
