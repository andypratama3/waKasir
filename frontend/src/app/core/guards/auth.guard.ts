import { CanActivateFn, Router } from '@angular/router';
import { inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { map, catchError, of } from 'rxjs';
import { environment } from '../../../environments/environment';

/**
 * How long (ms) a successful /api/me response is cached in sessionStorage.
 * Prevents hammering the backend on every route navigation.
 * Default: 5 minutes.
 */
const TOKEN_VALID_TTL_MS = 5 * 60 * 1000;
const CACHE_KEY          = '_wk_auth_ok';

function isCachedValid(): boolean {
  try {
    const raw = sessionStorage.getItem(CACHE_KEY);
    if (!raw) return false;
    const { ts } = JSON.parse(raw) as { ts: number };
    return Date.now() - ts < TOKEN_VALID_TTL_MS;
  } catch {
    return false;
  }
}

function setCacheValid(): void {
  try {
    sessionStorage.setItem(CACHE_KEY, JSON.stringify({ ts: Date.now() }));
  } catch { /* ignore quota errors */ }
}

function clearCache(): void {
  sessionStorage.removeItem(CACHE_KEY);
}

/**
 * authGuard — validates the stored Bearer token against the backend.
 *
 * Flow:
 * 1. No token in localStorage → redirect to /login (no HTTP).
 * 2. Token valid in sessionStorage cache (< 5 min) → allow immediately.
 * 3. Otherwise → ping GET /api/me. On 200 → cache + allow. On 401/error → clear + redirect.
 */
export const authGuard: CanActivateFn = (_route, _state) => {
  const router = inject(Router);
  const http   = inject(HttpClient);
  const token  = localStorage.getItem('token');

  // Fast path — no token
  if (!token) {
    clearCache();
    return router.createUrlTree(['/login']);
  }

  // Fast path — recently validated
  if (isCachedValid()) {
    return true;
  }

  // Validate against backend
  return http.get(`${environment.apiUrl}/me`, {
    headers: { Authorization: `Bearer ${token}` },
  }).pipe(
    map((res: any) => {
      // Refresh user in localStorage in case subscription/business changed
      if (res?.user) {
        localStorage.setItem('user', JSON.stringify(res.user));
      }
      setCacheValid();
      return true;
    }),
    catchError(() => {
      localStorage.removeItem('token');
      localStorage.removeItem('user');
      clearCache();
      return of(router.createUrlTree(['/login']));
    }),
  );
};

/**
 * guestGuard — prevents authenticated users from seeing login/register.
 */
export const guestGuard: CanActivateFn = () => {
  const router = inject(Router);
  const token  = localStorage.getItem('token');
  if (!token) return true;
  return router.createUrlTree(['/dashboard']);
};
