import { Routes } from '@angular/router';
import { authGuard, guestGuard } from './core/guards/auth.guard';

export const routes: Routes = [
  { path: '', redirectTo: 'dashboard', pathMatch: 'full' },

  // Auth pages (guests only)
  {
    path: 'login',
    canActivate: [guestGuard],
    loadComponent: () =>
      import('./features/auth/login/login.component').then(m => m.LoginComponent),
  },
  {
    path: 'register',
    canActivate: [guestGuard],
    loadComponent: () =>
      import('./features/auth/register/register.component').then(m => m.RegisterComponent),
  },

  // Protected dashboard layout
  {
    path: '',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./shared/components/layout/layout.component').then(m => m.LayoutComponent),
    children: [
      {
        path: 'dashboard',
        loadComponent: () =>
          import('./features/dashboard/dashboard.component').then(m => m.DashboardComponent),
        title: 'Dashboard — WaKasir',
      },
      {
        path: 'products',
        loadComponent: () =>
          import('./features/products/products-list/products-list.component').then(m => m.ProductsListComponent),
        title: 'Produk — WaKasir',
      },
      {
        path: 'orders',
        loadComponent: () =>
          import('./features/orders/orders-list/orders-list.component').then(m => m.OrdersListComponent),
        title: 'Pesanan — WaKasir',
      },
      {
        path: 'customers',
        loadComponent: () =>
          import('./features/customers/customers-list/customers-list.component').then(m => m.CustomersListComponent),
        title: 'Pelanggan — WaKasir',
      },
      {
        path: 'bot-settings',
        loadComponent: () =>
          import('./features/bot-settings/bot-settings.component').then(m => m.BotSettingsComponent),
        title: 'Pengaturan Bot — WaKasir',
      },
      {
        path: 'store-settings',
        loadComponent: () =>
          import('./features/store-settings/store-settings.component').then(m => m.StoreSettingsComponent),
        title: 'Pengaturan Toko — WaKasir',
      },
      {
        path: 'subscription',
        loadComponent: () =>
          import('./features/subscription/subscription.component').then(m => m.SubscriptionComponent),
        title: 'Langganan — WaKasir',
      },
      {
        path: 'reports',
        loadComponent: () =>
          import('./features/reports/reports.component').then(m => m.ReportsComponent),
        title: 'Laporan — WaKasir',
      },
    ],
  },

  // Fallback
  { path: '**', redirectTo: 'dashboard' },
];
