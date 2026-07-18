import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormBuilder, Validators } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { MessageService, ConfirmationService } from 'primeng/api';
import { ToastModule } from 'primeng/toast';
import { SkeletonModule } from 'primeng/skeleton';
import { DialogModule } from 'primeng/dialog';
import { ConfirmDialogModule } from 'primeng/confirmdialog';
import { environment } from '../../../environments/environment';

interface Member { id: string; name: string; email: string; role: string; created_at: string; }

@Component({
  selector: 'app-team',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, ToastModule, SkeletonModule, DialogModule, ConfirmDialogModule],
  providers: [MessageService, ConfirmationService],
  templateUrl: './team.component.html',
  styleUrl: './team.component.scss',
})
export class TeamComponent implements OnInit {
  private api  = environment.apiUrl;
  private fb    = inject(FormBuilder);
  private http  = inject(HttpClient);
  private toast = inject(MessageService);

  loading     = signal(true);
  saving      = signal(false);
  showInvite  = signal(false);
  members     = signal<Member[]>([]);

  inviteForm = this.fb.group({
    name:  ['', [Validators.required, Validators.maxLength(255)]],
    email: ['', [Validators.required, Validators.email]],
    role:  ['staff', Validators.required],
  });

  tempPassword = signal<string | null>(null);

  roleOptions = [
    { label: 'Staff — lihat & kelola pesanan', value: 'staff' },
    { label: 'Admin — semua kecuali pengaturan billing', value: 'admin' },
  ];

  private confirm = inject(ConfirmationService);

  ngOnInit(): void { this.loadMembers(); }

  loadMembers(): void {
    this.loading.set(true);
    this.http.get<any>(`${this.api}/team`).subscribe({
      next:  (d) => { this.members.set(d.members ?? []); this.loading.set(false); },
      error: ()  => this.loading.set(false),
    });
  }

  openInvite(): void {
    this.inviteForm.reset({ role: 'staff' });
    this.tempPassword.set(null);
    this.showInvite.set(true);
  }

  invite(): void {
    if (this.inviteForm.invalid) { this.inviteForm.markAllAsTouched(); return; }
    this.saving.set(true);
    this.http.post<any>(`${this.api}/team/invite`, this.inviteForm.value).subscribe({
      next: (r) => {
        this.saving.set(false);
        this.tempPassword.set(r.temp_password);
        this.loadMembers();
        this.toast.add({ severity: 'success', summary: 'Berhasil', detail: r.message });
      },
      error: (e) => {
        this.saving.set(false);
        const msg = e?.error?.message ?? e?.error?.error ?? 'Gagal mengundang.';
        this.toast.add({ severity: 'error', summary: 'Gagal', detail: msg });
      },
    });
  }

  changeRole(member: Member, newRole: string): void {
    this.http.put<any>(`${this.api}/team/${member.id}/role`, { role: newRole }).subscribe({
      next: () => {
        this.loadMembers();
        this.toast.add({ severity: 'success', summary: 'Berhasil', detail: 'Role diperbarui.' });
      },
      error: (e) => this.toast.add({ severity: 'error', summary: 'Gagal', detail: e?.error?.error ?? 'Error' }),
    });
  }

  removeMember(member: Member): void {
    this.confirm.confirm({
      message: `Hapus ${member.name} dari tim? Akun mereka akan dihapus permanen.`,
      header: 'Konfirmasi Hapus',
      icon: 'pi pi-exclamation-triangle',
      acceptLabel: 'Hapus',
      rejectLabel: 'Batal',
      accept: () => {
        this.http.delete(`${this.api}/team/${member.id}`).subscribe({
          next: () => { this.loadMembers(); this.toast.add({ severity: 'success', summary: 'Berhasil', detail: 'Anggota dihapus.' }); },
          error: (e) => this.toast.add({ severity: 'error', summary: 'Gagal', detail: e?.error?.error ?? 'Error' }),
        });
      },
    });
  }

  roleBadge(role: string): string {
    return { owner: 'badge-owner', admin: 'badge-admin', staff: 'badge-staff' }[role] ?? 'badge-staff';
  }

  roleLabel(role: string): string {
    return { owner: 'Pemilik', admin: 'Admin', staff: 'Staff' }[role] ?? role;
  }
}
