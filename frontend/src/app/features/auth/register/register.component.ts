import { Component, signal, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormBuilder, Validators, AbstractControl } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../../core/services/auth.service';
import { MessageService } from 'primeng/api';
import { ToastModule } from 'primeng/toast';

function passwordMatch(ctrl: AbstractControl) {
  const p = ctrl.get('password');
  const c = ctrl.get('password_confirmation');
  if (p && c && p.value !== c.value) {
    c.setErrors({ mismatch: true });
  } else {
    c?.setErrors(null);
  }
  return null;
}

@Component({
  selector: 'app-register',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, RouterLink, ToastModule],
  providers: [MessageService],
  templateUrl: './register.component.html',
  styleUrl: './register.component.scss',
})
export class RegisterComponent {
  private fb             = inject(FormBuilder);
  private authService    = inject(AuthService);
  private router         = inject(Router);
  private messageService = inject(MessageService);

  loading = signal(false);
  showPassword = signal(false);
  showConfirm  = signal(false);

  form = this.fb.group({
    name:                  ['', [Validators.required, Validators.minLength(2)]],
    business_name:         ['', [Validators.required, Validators.minLength(2)]],
    email:                 ['', [Validators.required, Validators.email]],
    password:              ['', [Validators.required, Validators.minLength(8)]],
    password_confirmation: ['', Validators.required],
  }, { validators: passwordMatch });

  constructor() {}

  get name()         { return this.form.get('name')!; }
  get businessName() { return this.form.get('business_name')!; }
  get email()        { return this.form.get('email')!; }
  get password()     { return this.form.get('password')!; }
  get confirm()      { return this.form.get('password_confirmation')!; }

  onSubmit(): void {
    if (this.form.invalid) { this.form.markAllAsTouched(); return; }
    this.loading.set(true);

    this.authService.register({
      name:                  this.name.value!,
      business_name:         this.businessName.value!,
      email:                 this.email.value!,
      password:              this.password.value!,
      password_confirmation: this.confirm.value!,
    }).subscribe({
      next: () => {
        this.loading.set(false);
        this.router.navigate(['/dashboard']);
      },
      error: (err) => {
        this.loading.set(false);
        const errors = err?.error?.errors;
        const msg = errors
          ? Object.values(errors).flat().join(' ')
          : (err?.error?.message ?? 'Pendaftaran gagal. Coba lagi.');
        this.messageService.add({ severity: 'error', summary: 'Daftar Gagal', detail: msg });
      },
    });
  }
}
