import { Component, signal, computed, HostListener, inject } from '@angular/core';
import { RouterOutlet, RouterLink, RouterLinkActive } from '@angular/router';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { AuthService } from '../../../core/services/auth.service';

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
export class LayoutComponent {
  private authService = inject(AuthService);
  private router      = inject(Router);

  collapsed          = signal(false);
  mobileOpen         = signal(false);
  userMenuOpen       = signal(false);
  notificationsOpen  = signal(false);

  notifications = signal<Notification[]>([
    {
      id: '1',
      icon: 'shopping_cart',
      title: 'Pesanan Baru',
      message: 'Order #12345 baru saja masuk dari WhatsApp',
      time: '2 menit yang lalu',
      read: false,
    },
    {
      id: '2',
      icon: 'inventory_2',
      title: 'Stok Menipis',
      message: 'Produk "Kopi Arabika" hampir habis (sisa 3 pcs)',
      time: '1 jam yang lalu',
      read: false,
    },
    {
      id: '3',
      icon: 'payments',
      title: 'Pembayaran Diterima',
      message: 'Pembayaran QRIS untuk order #12340 berhasil',
      time: '3 jam yang lalu',
      read: true,
    },
  ]);

  notificationCount = computed(() => this.notifications().filter(n => !n.read).length);

  user = computed(() => {
    const raw = localStorage.getItem('user');
    return raw ? JSON.parse(raw) : null;
  });

  business = computed(() => this.user()?.business ?? null);

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
