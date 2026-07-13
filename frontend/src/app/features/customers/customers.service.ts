import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';

export interface CustomerSummary {
  id: string;
  wa_number: string;
  name: string | null;
  total_orders: number;
  total_spent: number;
  last_order_at: string | null;
  orders: any[];
}

@Injectable({ providedIn: 'root' })
export class CustomersService {
  private api = environment.apiUrl;

  constructor(private http: HttpClient) {}

  getCustomers(): Observable<CustomerSummary[]> {
    return this.http.get<any>(`${this.api}/orders`).pipe(
      map((data) => this.aggregateCustomers(data.orders ?? []))
    );
  }

  private aggregateCustomers(orders: any[]): CustomerSummary[] {
    const map = new Map<string, CustomerSummary>();

    orders.forEach((order) => {
      const c = order.customer;
      if (!c) return;

      const existing = map.get(c.id);
      if (existing) {
        existing.total_orders++;
        existing.total_spent += parseFloat(order.total_amount ?? 0);
        if (!existing.last_order_at || order.created_at > existing.last_order_at) {
          existing.last_order_at = order.created_at;
        }
        existing.orders.push(order);
        if (!existing.name && c.name) existing.name = c.name;
      } else {
        map.set(c.id, {
          id: c.id,
          wa_number: c.wa_number,
          name: c.name ?? null,
          total_orders: 1,
          total_spent: parseFloat(order.total_amount ?? 0),
          last_order_at: order.created_at,
          orders: [order],
        });
      }
    });

    return Array.from(map.values()).sort((a, b) => b.total_spent - a.total_spent);
  }
}
