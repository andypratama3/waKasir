import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, BehaviorSubject, tap, finalize } from 'rxjs';
import { environment } from '../../../environments/environment';

interface AuthResponse {
  user: any;
  token: string;
  business?: any;
}

interface LoginRequest {
  email: string;
  password: string;
}

interface RegisterRequest {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  business_name: string;
}

@Injectable({
  providedIn: 'root'
})
export class AuthService {
  private apiUrl = environment.apiUrl;
  private tokenSubject = new BehaviorSubject<string | null>(null);
  private userSubject = new BehaviorSubject<any | null>(null);

  token$ = this.tokenSubject.asObservable();
  user$ = this.userSubject.asObservable();

  constructor(private http: HttpClient) {
    const token = localStorage.getItem('token');
    const user = localStorage.getItem('user');
    
    if (token) {
      this.tokenSubject.next(token);
    }
    
    if (user) {
      try {
        this.userSubject.next(JSON.parse(user));
      } catch {
        localStorage.removeItem('user');
      }
    }
  }

  login(credentials: LoginRequest): Observable<AuthResponse> {
    return this.http.post<AuthResponse>(`${this.apiUrl}/login`, credentials).pipe(
      tap(response => {
        this.setAuthData(response);
      })
    );
  }

  register(data: RegisterRequest): Observable<AuthResponse> {
    return this.http.post<AuthResponse>(`${this.apiUrl}/register`, data).pipe(
      tap(response => {
        this.setAuthData(response);
      })
    );
  }

  logout(): Observable<any> {
    return this.http.post(`${this.apiUrl}/logout`, {}).pipe(
      finalize(() => {
        this.clearAuthData();
      })
    );
  }

  me(): Observable<any> {
    return this.http.get<{ user: any }>(`${this.apiUrl}/me`).pipe(
      tap(response => {
        const user = response?.user ?? response;
        this.userSubject.next(user);
        localStorage.setItem('user', JSON.stringify(user));
      })
    );
  }

  getToken(): string | null {
    return this.tokenSubject.value;
  }

  isLoggedIn(): boolean {
    return !!this.tokenSubject.value;
  }

  private setAuthData(response: AuthResponse): void {
    localStorage.setItem('token', response.token);
    localStorage.setItem('user', JSON.stringify(response.user));
    
    this.tokenSubject.next(response.token);
    this.userSubject.next(response.user);
  }

  private clearAuthData(): void {
    localStorage.removeItem('token');
    localStorage.removeItem('user');
    
    this.tokenSubject.next(null);
    this.userSubject.next(null);
  }
}