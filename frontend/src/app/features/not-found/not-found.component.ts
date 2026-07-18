import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';

@Component({
  selector: 'app-not-found',
  standalone: true,
  imports: [RouterLink],
  template: `
    <div style="min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem;font-family:'Plus Jakarta Sans',sans-serif">
      <div style="font-size:6rem;font-weight:800;color:#e5e7eb;line-height:1">404</div>
      <h1 style="font-size:1.5rem;font-weight:700;color:#111827;margin:1rem 0 .5rem">Halaman tidak ditemukan</h1>
      <p style="color:#6b7280;margin-bottom:2rem;text-align:center;max-width:380px">
        Halaman yang Anda cari tidak ada atau sudah dipindahkan.
      </p>
      <a routerLink="/dashboard" style="display:inline-flex;align-items:center;gap:.5rem;padding:.75rem 1.5rem;background:#075E54;color:#fff;border-radius:9999px;font-weight:600;text-decoration:none">
        ← Kembali ke Dashboard
      </a>
    </div>
  `,
})
export class NotFoundComponent {}
