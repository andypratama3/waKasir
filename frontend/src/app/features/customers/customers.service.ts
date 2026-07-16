import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

export interface CustomerSummary {
  id: string;
  wa_number: string;
  name: string | null;
  total_orders: number;
  total_spent: number;
  last_order: any | null;
  last_order_at?: string | null;
  orders?: any[];
}

@Injectable({ providedIn: 'root' })
export class CustomersService {
  private api = environment.apiUrl;

  constructor(private http: HttpClient) {}

  /** Returns a paginated response from the dedicated /customers endpoint */
  getCustomers(search = '', page = 1, perPage = 20): Observable<any> {
    let params = new HttpParams()
      .set('page', page)
      .set('per_page', perPage);
    if (search) params = params.set('search', search);

    return this.http.get(`${this.api}/customers`, { params });
  }

  /** Full customer detail with order history */
  getCustomer(id: string): Observable<any> {
    return this.http.get(`${this.api}/customers/${id}`);
  }
}
