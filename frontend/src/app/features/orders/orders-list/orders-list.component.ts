import { Component, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule } from '@angular/forms';
import { OrdersService } from '../orders.service';
import { RupiahPipe } from '../../../shared/pipes/rupiah.pipe';
import { WhatsappIconComponent } from '../../../shared/components/whatsapp-icon/whatsapp-icon.component';
import { MessageService } from 'primeng/api';
import { TableModule } from 'primeng/table';
import { ButtonModule } from 'primeng/button';
import { DialogModule } from 'primeng/dialog';
import { InputTextModule } from 'primeng/inputtext';
import { TagModule } from 'primeng/tag';
import { ToastModule } from 'primeng/toast';
import { SkeletonModule } from 'primeng/skeleton';
import { TooltipModule } from 'primeng/tooltip';
import { TimelineModule } from 'primeng/timeline';

const STATUS_ORDER = ['pending','paid','processing','shipped','completed','cancelled'];

@Component({
  selector: 'app-orders-list',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule, RupiahPipe,
    TableModule, ButtonModule, DialogModule, InputTextModule,
    TagModule, ToastModule, SkeletonModule,
    TooltipModule, TimelineModule, WhatsappIconComponent,
  ],
  providers: [MessageService],
  templateUrl: './orders-list.component.html',
  styleUrl: './orders-list.component.scss',
})
export class OrdersListComponent implements OnInit {
  loading        = signal(true);
  orders         = signal<any[]>([]);
  selectedOrder  = signal<any | null>(null);
  showDetail     = signal(false);
  trackingInput  = signal('');
  savingTracking = signal(false);
  savingStatus   = signal(false);

  filterStatus = signal('');
  filterSearch = signal('');
  filterFrom   = signal('');
  filterTo     = signal('');

  statusOptions = [
    { label: 'Semua Status', value: '' },
    { label: 'Menunggu Bayar', value: 'pending' },
    { label: 'Dibayar', value: 'paid' },
    { label: 'Diproses', value: 'processing' },
    { label: 'Dikirim', value: 'shipped' },
    { label: 'Selesai', value: 'completed' },
    { label: 'Dibatalkan', value: 'cancelled' },
  ];

  timelineSteps = [
    { label: 'Order Masuk', status: 'pending',    icon: 'receipt_long' },
    { label: 'Dibayar',     status: 'paid',        icon: 'payments' },
    { label: 'Diproses',    status: 'processing',  icon: 'package_2' },
    { label: 'Dikirim',     status: 'shipped',     icon: 'local_shipping' },
    { label: 'Selesai',     status: 'completed',   icon: 'check_circle' },
  ];

  constructor(
    private ordersService: OrdersService,
    private messageService: MessageService,
  ) {}

  ngOnInit(): void { this.loadOrders(); }

  loadOrders(): void {
    this.loading.set(true);
    this.ordersService.getOrders({
      status:    this.filterStatus(),
      search:    this.filterSearch(),
      date_from: this.filterFrom(),
      date_to:   this.filterTo(),
    }).subscribe({
      next: (data) => {
        // Backend returns Laravel paginator → data.data[] is the items array
        // Fallback to flat data.orders for older responses
        const items = data?.data ?? data?.orders ?? [];
        this.orders.set(items);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  openDetail(order: any): void {
    this.selectedOrder.set(order);
    this.trackingInput.set(order.tracking_number ?? '');
    this.showDetail.set(true);
  }

  saveTracking(): void {
    if (!this.trackingInput().trim() || !this.selectedOrder()) return;
    this.savingTracking.set(true);
    this.ordersService.updateTracking(this.selectedOrder().id, this.trackingInput()).subscribe({
      next: (data) => {
        this.savingTracking.set(false);
        this.selectedOrder.set(data.order);
        this.messageService.add({ severity: 'success', summary: 'Resi disimpan', detail: 'Notifikasi dikirim ke pelanggan' });
        this.loadOrders();
      },
      error: () => {
        this.savingTracking.set(false);
        this.messageService.add({ severity: 'error', summary: 'Gagal', detail: 'Tidak dapat menyimpan resi' });
      },
    });
  }

  updateStatus(newStatus: string): void {
    if (!this.selectedOrder()) return;
    this.savingStatus.set(true);
    this.ordersService.updateStatus(this.selectedOrder().id, newStatus).subscribe({
      next: (data) => {
        this.savingStatus.set(false);
        this.selectedOrder.set(data.order);
        this.messageService.add({ severity: 'success', summary: 'Status diperbarui', detail: `Order ${newStatus}` });
        this.loadOrders();
      },
      error: () => {
        this.savingStatus.set(false);
        this.messageService.add({ severity: 'error', summary: 'Gagal', detail: 'Tidak dapat mengubah status' });
      },
    });
  }

  getStatusClass(status: string): string {
    return `status-badge status-${status ?? 'pending'}`;
  }

  getStepClass(step: any, currentStatus: string): string {
    const stepIdx    = STATUS_ORDER.indexOf(step.status);
    const currentIdx = STATUS_ORDER.indexOf(currentStatus);
    if (currentStatus === 'cancelled') return stepIdx === 0 ? 'done' : 'cancelled';
    if (stepIdx < currentIdx)  return 'done';
    if (stepIdx === currentIdx) return 'active';
    return 'future';
  }

  getAddress(order: any): any {
    return Array.isArray(order?.address) ? order.address[0] : order?.address;
  }

  getPayment(order: any): any {
    return Array.isArray(order?.payment) ? order.payment[0] : order?.payment;
  }

  waLink(waNumber: string): string {
    const clean = waNumber?.replace(/\D/g, '');
    return `https://wa.me/${clean}`;
  }

  canProcess(status: string): boolean  { return status === 'paid'; }
  canShip(status: string): boolean     { return status === 'processing'; }
  canComplete(status: string): boolean { return status === 'shipped'; }
  canCancel(status: string): boolean   { return ['pending','paid','processing'].includes(status); }
}
