import { HttpInterceptorFn, HttpErrorResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { AuthService } from '../services/auth.service';

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const router = inject(Router);
  const authService = inject(AuthService);
  const token = localStorage.getItem('token');

  const authReq = token
    ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } })
    : req;

  return next(authReq).pipe(
    catchError((error: HttpErrorResponse) => {
      if (error.status === 401) {
        // Use AuthService to properly clear state
        authService.logout().subscribe({
          next: () => router.navigate(['/login']),
          error: () => {
            // Fallback if logout fails
            localStorage.removeItem('token');
            localStorage.removeItem('user');
            router.navigate(['/login']);
          }
        });
      }
      return throwError(() => error);
    })
  );
};
