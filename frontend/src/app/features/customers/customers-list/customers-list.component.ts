import { Component, OnInit, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { CustomersService, CustomerSummary } from '../customers.service';
import { RupiahPipe } from '../../../shared/pipes/rupiah.pipe';
import { WhatsappIconComponent } from '../../../shared/components/whatsapp-icon/whatsapp-icon.component';
import { TableModule } from 'primeng/table';
import { SkeletonModule } from 'primeng/skeleton';
import { DialogModule } from 'primeng/dialog';
import { TagModule } from 'primeng/tag';
@Component({
  selector: 'app-customers-list',
  standalone: true,
  imports: [CommonModule, RupiahPipe, TableModule, SkeletonModule, DialogModule, TagModule, WhatsappIconComponent],
  templateUrl: './customers-list.component.html',
  styleUrl: './customers-list.component.scss',
})
export class CustomersListComponent implements OnInit {
  loading   = signal(true);
  customers = signal<CustomerSummary[]>([]);
  search    = signal('');
  selected  = signal<CustomerSummary | null>(null);
  showModal = signal(false);

  filtered = computed(() => {
    const q = this.search().toLowerCase();
    if (!q) return this.customers();
    return this.customers().filter(c =>
      c.wa_number?.toLowerCase().includes(q) ||
      (c.name ?? '').toLowerCase().includes(q)
    );
  });

  stats = computed(() => {
    const all = this.customers();
    const now = new Date();
    const thisMonth = all.filter(c => {
      if (!c.last_order_at) return false;
      const d = new Date(c.last_order_at);
      return d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
    });
    const repeat = all.filter(c => c.total_orders > 1);
    return { total: all.length, activeThisMonth: thisMonth.length, repeatBuyers: repeat.length };
  });

  constructor(private customersService: CustomersService) {}

  ngOnInit(): void {
    this.customersService.getCustomers().subscribe({
      next: (data) => { this.customers.set(data); this.loading.set(false); },
      error: () => this.loading.set(false),
    });
  }

  openCustomer(c: CustomerSummary): void {
    this.selected.set(c);
    this.showModal.set(true);
  }

  waLink(waNumber: string): string {
    return `https://wa.me/${waNumber?.replace(/\D/g, '')}`;
  }

  getStatusClass(status: string): string {
    return `status-badge status-${status ?? 'pending'}`;
  }
}
