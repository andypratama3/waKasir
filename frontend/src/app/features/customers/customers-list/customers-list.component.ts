import { Component, OnInit, signal, computed, effect } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { CustomersService } from '../customers.service';
import { RupiahPipe } from '../../../shared/pipes/rupiah.pipe';
import { WhatsappIconComponent } from '../../../shared/components/whatsapp-icon/whatsapp-icon.component';
import { TableModule } from 'primeng/table';
import { SkeletonModule } from 'primeng/skeleton';
import { DialogModule } from 'primeng/dialog';
import { TagModule } from 'primeng/tag';
import { debounceTime, distinctUntilChanged, Subject } from 'rxjs';

@Component({
  selector: 'app-customers-list',
  standalone: true,
  imports: [CommonModule, DatePipe, RupiahPipe, TableModule, SkeletonModule, DialogModule, TagModule, WhatsappIconComponent],
  templateUrl: './customers-list.component.html',
  styleUrl: './customers-list.component.scss',
})
export class CustomersListComponent implements OnInit {
  loading       = signal(true);
  customers     = signal<any[]>([]);
  search        = signal('');
  selected      = signal<any | null>(null);
  selectedDetail= signal<any | null>(null);
  loadingDetail = signal(false);
  showModal     = signal(false);

  // Pagination from API
  totalRecords  = signal(0);
  currentPage   = signal(1);
  perPage       = 20;

  private searchSubject = new Subject<string>();

  filtered = computed(() => this.customers()); // already filtered server-side

  stats = computed(() => {
    const all = this.customers();
    const now = new Date();
    const thisMonth = all.filter(c => {
      const d = c.last_order?.created_at ? new Date(c.last_order.created_at) : null;
      return d && d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
    });
    const repeat = all.filter(c => (c.total_orders ?? 0) > 1);
    return { total: this.totalRecords(), activeThisMonth: thisMonth.length, repeatBuyers: repeat.length };
  });

  constructor(private customersService: CustomersService) {
    // Debounce search input
    this.searchSubject.pipe(debounceTime(400), distinctUntilChanged()).subscribe(q => {
      this.currentPage.set(1);
      this.loadCustomers(q);
    });
  }

  ngOnInit(): void {
    this.loadCustomers();
  }

  loadCustomers(searchTerm?: string): void {
    this.loading.set(true);
    const q = searchTerm ?? this.search();
    this.customersService.getCustomers(q, this.currentPage(), this.perPage).subscribe({
      next: (data) => {
        this.customers.set(data.data ?? data.customers ?? []);
        this.totalRecords.set(data.total ?? data.data?.length ?? 0);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  onSearch(value: string): void {
    this.search.set(value);
    this.searchSubject.next(value);
  }

  onPage(page: number): void {
    this.currentPage.set(page + 1); // PrimeNG uses 0-based
    this.loadCustomers();
  }

  openCustomer(c: any): void {
    this.selected.set(c);
    this.selectedDetail.set(null);
    this.showModal.set(true);
    this.loadingDetail.set(true);

    this.customersService.getCustomer(c.id).subscribe({
      next: (data) => {
        this.selectedDetail.set(data);
        this.loadingDetail.set(false);
      },
      error: () => this.loadingDetail.set(false),
    });
  }

  waLink(waNumber: string): string {
    return `https://wa.me/${waNumber?.replace(/\D/g, '')}`;
  }

  getStatusClass(status: string): string {
    return `status-badge status-${status ?? 'pending'}`;
  }
}
