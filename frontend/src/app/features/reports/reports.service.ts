import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

@Injectable({ providedIn: 'root' })
export class ReportsService {
  private api = environment.apiUrl;

  constructor(private http: HttpClient) {}

  getSalesChart(period: '7d' | '30d' | '90d' = '30d'): Observable<any> {
    return this.http.get(`${this.api}/dashboard/sales-chart`, { params: { period } });
  }

  getTopProducts(): Observable<any> {
    return this.http.get(`${this.api}/dashboard/top-products`);
  }

  getTopCities(): Observable<any> {
    return this.http.get(`${this.api}/dashboard/top-cities`);
  }

  getOrderStats(): Observable<any> {
    return this.http.get(`${this.api}/orders/stats`);
  }
}
