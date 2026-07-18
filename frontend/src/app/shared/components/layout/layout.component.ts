import { Component, signal, computed, HostListener, inject, OnInit, OnDestroy } from '@angular/core';
import { RouterOutlet, RouterLink, RouterLinkActive } from '@angular/router';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { AuthService } from '../../../core/services/auth.service';
import { HttpClient } from '@angular/common/http';
import { Subscription, interval } from 'rxjs';
import { switchMap } from 'rxjs/operators';
import { environment } from '../../../../environments/environment';

interface NavItem {
  label: string;
  icon: string;
  route: string;
}

interface NavGroup {
  title: string;
  items: NavItem[];
}

interface Notification {
  id: string;
  icon: string;
  title: string;
  message: string;
  time: string;
  read: boolean;
}

@Component({
  selector: 'app-layout',
  standalone: true,
  imports: [RouterOutlet, RouterLink, RouterLinkActive, CommonModule],
  templateUrl: './layout.component.html',
  styleUrl: './layout.component.scss',
})
export class LayoutComponent implements OnInit, OnDestroy {
  private authService = inject(AuthService);
  private router      = inject(Router);
  private http        = inject(HttpClient);
  private pollSub?: Subscription;

  collapsed          = signal(false);
  mobileOpen         = signal(false);
  userMenuOpen       = signal(false);
  notificationsOpen  = signal(false);

  notifications = signal<Notification[]>([]);
  private lastPendingCount = 0;

  notificationCount = computed(() => this.notifications().filter(n => !n.read).length);

  user = computed(() => {
    const raw = localStorage.getItem('user');
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch {
      localStorage.removeItem('user');
      return null;
    }
  });

  business = computed(() => this.user()?.business ?? null);

  ngOnInit(): void {
    // Initial fetch immediately, then poll every 30s
    const poll$ = this.http.get<any>(`${environment.apiUrl}/dashboard`);

    // First call right away
    poll$.subscribe({ next: (d) => this.processStats(d) });

    // Then poll every 30 seconds
    this.pollSub = interval(30_000).pipe(
      switchMap(() => poll$)
    ).subscribe({
      next: (d) => this.processStats(d),
    });
  }

  private processStats(d: any): void {
    const pending = (d.stats?.orders_by_status?.pending ?? 0)
                  + (d.stats?.orders_by_status?.paid    ?? 0);

    if (this.lastPendingCount > 0 && pending > this.lastPendingCount) {
      const diff = pending - this.lastPendingCount;
      const newNotifs: Notification[] = [{
        id:      Date.now().toString(),
        icon:    'shopping_cart',
        title:   'Pesanan Baru',
        message: diff === 1 ? '1 order baru masuk dari WhatsApp' : `${diff} order baru masuk dari WhatsApp`,
        time:    'Baru saja',
        read:    false,
      }];
      this.notifications.update(n => [...newNotifs, ...n].slice(0, 20));
    }

    this.lastPendingCount = pending;

    // Low stock notifications (added once, marked as read — informational only)
    const lowStock: any[] = d.low_stock_products ?? [];
    if (lowStock.length > 0) {
      const existingIds = new Set(this.notifications().map(n => n.id));
      const stockNotifs: Notification[] = lowStock
        .filter((p: any) => !existingIds.has('stock_' + p.id))
        .map((p: any) => ({
          id:      'stock_' + p.id,
          icon:    'inventory_2',
          title:   'Stok Menipis',
          message: `"${p.name}" tersisa ${p.stock} pcs`,
          time:    '',
          read:    true,
        }));
      if (stockNotifs.length > 0) {
        this.notifications.update(n => [...n, ...stockNotifs].slice(0, 20));
      }
    }
  }

  ngOnDestroy(): void {
    this.pollSub?.unsubscribe();
  }

  navGroups: NavGroup[] = [
    {
      title: 'Utama',
      items: [
        { label: 'Dashboard',  icon: 'dashboard',     route: '/dashboard' },
        { label: 'Produk',     icon: 'inventory_2',   route: '/products' },
        { label: 'Pesanan',    icon: 'receipt_long',  route: '/orders' },
        { label: 'Pelanggan',  icon: 'people',        route: '/customers' },
        { label: 'Laporan',    icon: 'bar_chart',     route: '/reports' },
      ],
    },
    {
      title: 'Pengaturan',
      items: [
        { label: 'Pengaturan Bot',  icon: 'smart_toy',         route: '/bot-settings' },
        { label: 'Pengaturan Toko', icon: 'store',             route: '/store-settings' },
        { label: 'Langganan',       icon: 'workspace_premium', route: '/subscription' },
        { label: 'Tim',             icon: 'group',             route: '/team' },
      ],
    },
  ];

  toggleSidebar(): void {
    if (window.innerWidth <= 768) {
      this.mobileOpen.update(v => !v);
    } else {
      this.collapsed.update(v => !v);
    }
  }

  closeMobile(): void { this.mobileOpen.set(false); }

  toggleUserMenu(): void { this.userMenuOpen.update(v => !v); }

  toggleNotifications(): void { this.notificationsOpen.update(v => !v); }

  markAllAsRead(): void {
    this.notifications.update(notifs => notifs.map(n => ({ ...n, read: true })));
  }

  @HostListener('document:click', ['$event'])
  onDocumentClick(event: MouseEvent): void {
    const target = event.target as HTMLElement;
    if (!target.closest('.user-menu-wrap')) {
      this.userMenuOpen.set(false);
    }
    if (!target.closest('.topbar-icon-btn') && !target.closest('.notification-dropdown')) {
      this.notificationsOpen.set(false);
    }
  }

  logout(): void {
    this.authService.logout().subscribe({
      next:  () => this.router.navigate(['/login']),
      error: () => { localStorage.clear(); this.router.navigate(['/login']); },
    });
  }

  getInitials(): string {
    const name = this.user()?.name ?? 'U';
    return name.split(' ').map((n: string) => n[0]).slice(0, 2).join('').toUpperCase();
  }

  getGreeting(): string {
    const hour = new Date().getHours();
    if (hour < 12) return 'Selamat pagi,';
    if (hour < 15) return 'Selamat siang,';
    if (hour < 18) return 'Selamat sore,';
    return 'Selamat malam,';
  }
}
