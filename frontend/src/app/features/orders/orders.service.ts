import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

export interface OrderFilters {
  status?:    string;
  search?:    string;
  date_from?: string;
  date_to?:   string;
  page?:      number;
  per_page?:  number;
}

@Injectable({ providedIn: 'root' })
export class OrdersService {
  private api = environment.apiUrl;

  constructor(private http: HttpClient) {}

  getOrders(filters: OrderFilters = {}): Observable<any> {
    let params = new HttpParams();
    Object.entries(filters).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') {
        params = params.set(k, String(v));
      }
    });
    return this.http.get(`${this.api}/orders`, { params });
  }

  getOrder(id: string): Observable<any> {
    return this.http.get(`${this.api}/orders/${id}`);
  }

  getStats(): Observable<any> {
    return this.http.get(`${this.api}/orders/stats`);
  }

  updateStatus(id: string, status: string): Observable<any> {
    return this.http.post(`${this.api}/orders/${id}/status`, { status });
  }

  updateTracking(id: string, tracking_number: string): Observable<any> {
    return this.http.post(`${this.api}/orders/${id}/tracking`, { tracking_number });
  }

  cancelOrder(id: string): Observable<any> {
    return this.http.post(`${this.api}/orders/${id}/cancel`, {});
  }
}
