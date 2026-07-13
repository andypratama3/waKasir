import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

@Injectable({ providedIn: 'root' })
export class DashboardService {
  private api = environment.apiUrl;

  constructor(private http: HttpClient) {}

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
