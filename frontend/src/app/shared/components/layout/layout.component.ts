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

  collapsed    = signal(false);
  mobileOpen   = signal(false);
  userMenuOpen = signal(false);

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

  @HostListener('document:click', ['$event'])
  onDocumentClick(event: MouseEvent): void {
    const target = event.target as HTMLElement;
    if (!target.closest('.user-menu-wrap')) {
      this.userMenuOpen.set(false);
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
