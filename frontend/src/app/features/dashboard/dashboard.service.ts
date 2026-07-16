import { Injectable, OnDestroy } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, Subject, timer, switchMap, share, takeUntil } from 'rxjs';
import { environment } from '../../../environments/environment';

@Injectable({ providedIn: 'root' })
export class DashboardService implements OnDestroy {
  private api     = environment.apiUrl;
  private destroy = new Subject<void>();

  /**
   * Live stats stream — polls every 30 seconds while subscribed.
   * Shared so multiple components don't create duplicate HTTP calls.
   */
  readonly liveStats$: Observable<any> = timer(0, 30_000).pipe(
    switchMap(() => this.http.get(`${this.api}/dashboard`)),
    share(),
    takeUntil(this.destroy),
  );

  constructor(private http: HttpClient) {}

  ngOnDestroy(): void {
    this.destroy.next();
    this.destroy.complete();
  }

  /** One-shot fetch (used when period changes, etc.) */
  getStats(): Observable<any> {
    return this.http.get(`${this.api}/dashboard`);
  }

  getSalesChart(period: '7d' | '30d' | '90d' = '7d'): Observable<any> {
    return this.http.get(`${this.api}/dashboard/sales-chart`, { params: { period } });
  }

  getTopProducts(): Observable<any> {
    return this.http.get(`${this.api}/dashboard/top-products`);
  }

  getTopCities(): Observable<any> {
    return this.http.get(`${this.api}/dashboard/top-cities`);
  }
}
