import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

@Injectable({ providedIn: 'root' })
export class ProductsService {
  private api = environment.apiUrl;

  constructor(private http: HttpClient) {}

  getProducts(): Observable<any> {
    return this.http.get(`${this.api}/products`);
  }

  getProduct(id: string): Observable<any> {
    return this.http.get(`${this.api}/products/${id}`);
  }

  createProduct(data: FormData): Observable<any> {
    return this.http.post(`${this.api}/products`, data);
  }

  updateProduct(id: string, data: FormData): Observable<any> {
    // Laravel doesn't support PUT with FormData — use POST + _method override
    data.append('_method', 'PUT');
    return this.http.post(`${this.api}/products/${id}`, data);
  }

  deleteProduct(id: string): Observable<any> {
    return this.http.delete(`${this.api}/products/${id}`);
  }

  searchProducts(query: string): Observable<any> {
    return this.http.get(`${this.api}/products/search`, { params: { query } });
  }

  toggleActive(id: string, isActive: boolean): Observable<any> {
    const fd = new FormData();
    fd.append('is_active', isActive ? '1' : '0');
    fd.append('_method', 'PUT');
    return this.http.post(`${this.api}/products/${id}`, fd);
  }
}
