import { Component, OnInit, signal, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormBuilder, Validators, FormArray } from '@angular/forms';
import { ProductsService } from '../products.service';
import { RupiahPipe } from '../../../shared/pipes/rupiah.pipe';
import { MessageService, ConfirmationService } from 'primeng/api';
import { Table, TableModule } from 'primeng/table';
import { Button, ButtonModule } from 'primeng/button';
import { Dialog, DialogModule } from 'primeng/dialog';
import { InputText, InputTextModule } from 'primeng/inputtext';
import { Tag, TagModule } from 'primeng/tag';
import { Toast, ToastModule } from 'primeng/toast';
import { ConfirmDialog, ConfirmDialogModule } from 'primeng/confirmdialog';
import { Skeleton, SkeletonModule } from 'primeng/skeleton';
import { Tooltip, TooltipModule } from 'primeng/tooltip';
import { Textarea, TextareaModule } from 'primeng/textarea';
import { debounceTime, distinctUntilChanged, Subject } from 'rxjs';

@Component({
  selector: 'app-products-list',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule, RupiahPipe,
    TableModule, ButtonModule, DialogModule, InputTextModule,
    TagModule, ToastModule, ConfirmDialogModule, SkeletonModule,
    TooltipModule, TextareaModule,
  ],
  providers: [MessageService, ConfirmationService],
  templateUrl: './products-list.component.html',
  styleUrl: './products-list.component.scss',
})
export class ProductsListComponent implements OnInit {
  private fb              = inject(FormBuilder);
  private productsService = inject(ProductsService);
  private messageService  = inject(MessageService);
  private confirmService  = inject(ConfirmationService);
  loading  = signal(true);
  saving   = signal(false);
  products = signal<any[]>([]);
  filtered = signal<any[]>([]);
  showDialog = signal(false);
  editMode   = signal(false);
  editId     = signal<string | null>(null);
  imagePreview = signal<string | null>(null);

  searchQuery = signal('');
  searchSubject = new Subject<string>();

  categories = ['Pakaian', 'Makanan', 'Minuman', 'Elektronik', 'Aksesoris', 'Lainnya'];

  form = this.fb.group({
    name:        ['', Validators.required],
    description: [''],
    price:       [0, [Validators.required, Validators.min(0)]],
    stock:       [0, [Validators.required, Validators.min(0)]],
    weight_gram: [0, [Validators.required, Validators.min(1)]],
    category:    [''],
    is_active:   [true],
    variants:    this.fb.array([]),
  });

  selectedFile: File | null = null;

  constructor() {}

  ngOnInit(): void {
    this.loadProducts();
    this.searchSubject.pipe(debounceTime(400), distinctUntilChanged()).subscribe(q => {
      this.filterProducts(q);
    });
  }

  get variants(): FormArray { return this.form.get('variants') as FormArray; }

  loadProducts(): void {
    this.loading.set(true);
    this.productsService.getProducts().subscribe({
      next: (data) => {
        // Backend may return flat {products:[]} or paginated {data:[]}
        const items = data?.products ?? data?.data ?? [];
        this.products.set(items);
        this.filtered.set(items);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  onSearch(query: string): void {
    this.searchQuery.set(query);
    this.searchSubject.next(query);
  }

  filterProducts(q: string): void {
    if (!q.trim()) {
      this.filtered.set(this.products());
      return;
    }
    const lower = q.toLowerCase();
    this.filtered.set(
      this.products().filter(p =>
        p.name?.toLowerCase().includes(lower) ||
        p.category?.toLowerCase().includes(lower)
      )
    );
  }

  openAdd(): void {
    this.editMode.set(false);
    this.editId.set(null);
    this.form.reset({ is_active: true, price: 0, stock: 0, weight_gram: 0 });
    this.variants.clear();
    this.imagePreview.set(null);
    this.selectedFile = null;
    this.showDialog.set(true);
  }

  openEdit(product: any): void {
    this.editMode.set(true);
    this.editId.set(product.id);
    this.variants.clear();

    this.form.patchValue({
      name:        product.name,
      description: product.description,
      price:       product.price,
      stock:       product.stock,
      weight_gram: product.weight_gram,
      category:    product.category,
      is_active:   product.is_active,
    });

    (product.variants ?? []).forEach((v: any) => this.addVariant(v));

    this.imagePreview.set(product.image_url ?? null);
    this.selectedFile = null;
    this.showDialog.set(true);
  }

  addVariant(data?: any): void {
    this.variants.push(this.fb.group({
      variant_name:  [data?.variant_name ?? '', Validators.required],
      stock_override:[data?.stock_override ?? null],
      price_override:[data?.price_override ?? null],
    }));
  }

  removeVariant(i: number): void { this.variants.removeAt(i); }

  onFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    if (input.files?.length) {
      this.selectedFile = input.files[0];
      const reader = new FileReader();
      reader.onload = (e) => this.imagePreview.set(e.target?.result as string);
      reader.readAsDataURL(this.selectedFile);
    }
  }

  saveProduct(): void {
    if (this.form.invalid) { this.form.markAllAsTouched(); return; }
    this.saving.set(true);

    const fd = new FormData();
    const v = this.form.value;
    fd.append('name',        v.name ?? '');
    fd.append('description', v.description ?? '');
    fd.append('price',       String(v.price ?? 0));
    fd.append('stock',       String(v.stock ?? 0));
    fd.append('weight_gram', String(v.weight_gram ?? 0));
    fd.append('category',    v.category ?? '');
    fd.append('is_active',   v.is_active ? '1' : '0');

    if (this.selectedFile) fd.append('image', this.selectedFile);

    (v.variants ?? []).forEach((vr: any, i: number) => {
      fd.append(`variants[${i}][name]`,           vr.variant_name ?? '');
      fd.append(`variants[${i}][stock]`,           String(vr.stock_override ?? ''));
      fd.append(`variants[${i}][price]`,           String(vr.price_override ?? ''));
    });

    const req$ = this.editMode()
      ? this.productsService.updateProduct(this.editId()!, fd)
      : this.productsService.createProduct(fd);

    req$.subscribe({
      next: () => {
        this.saving.set(false);
        this.showDialog.set(false);
        this.messageService.add({
          severity: 'success',
          summary: 'Berhasil',
          detail: this.editMode() ? 'Produk diperbarui' : 'Produk ditambahkan',
        });
        this.loadProducts();
      },
      error: (err) => {
        this.saving.set(false);
        this.messageService.add({ severity: 'error', summary: 'Gagal', detail: err?.error?.message ?? 'Terjadi kesalahan' });
      },
    });
  }

  confirmDelete(product: any): void {
    this.confirmService.confirm({
      message: `Hapus produk "${product.name}"? Tindakan ini tidak dapat dibatalkan.`,
      header: 'Konfirmasi Hapus',
      icon: 'pi pi-exclamation-triangle',
      acceptLabel: 'Hapus',
      rejectLabel: 'Batal',
      acceptButtonStyleClass: 'p-button-danger',
      accept: () => this.deleteProduct(product.id),
    });
  }

  deleteProduct(id: string): void {
    this.productsService.deleteProduct(id).subscribe({
      next: () => {
        this.messageService.add({ severity: 'success', summary: 'Dihapus', detail: 'Produk dihapus' });
        this.loadProducts();
      },
      error: () => this.messageService.add({ severity: 'error', summary: 'Gagal', detail: 'Tidak dapat menghapus produk' }),
    });
  }

  toggleActive(product: any): void {
    this.productsService.toggleActive(product.id, !product.is_active).subscribe({
      next: () => {
        this.loadProducts();
        this.messageService.add({ severity: 'success', summary: 'Diperbarui', detail: 'Status produk diubah' });
      },
    });
  }
}
