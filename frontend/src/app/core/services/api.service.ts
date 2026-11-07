import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../tokens/api-url.token';

@Injectable({
  providedIn: 'root',
})
export class ApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);

  /**
   * Resolves full endpoint URL from relative path.
   */
  private resolveUrl(endpoint: string): string {
    if (endpoint.startsWith('http://') || endpoint.startsWith('https://')) {
      return endpoint;
    }
    const cleanBase = this.baseUrl.replace(/\/+$/, '');
    const cleanEndpoint = endpoint.replace(/^\/+/, '');
    return `${cleanBase}/${cleanEndpoint}`;
  }

  /**
   * Formats raw key-value parameter objects into HttpParams.
   */
  private buildParams(
    params?: HttpParams | Record<string, string | number | boolean | null | undefined>
  ): HttpParams {
    if (!params) {
      return new HttpParams();
    }
    if (params instanceof HttpParams) {
      return params;
    }

    let httpParams = new HttpParams();
    for (const [key, value] of Object.entries(params)) {
      if (value !== undefined && value !== null) {
        httpParams = httpParams.set(key, String(value));
      }
    }
    return httpParams;
  }

  get<T>(
    endpoint: string,
    params?: HttpParams | Record<string, string | number | boolean | null | undefined>
  ): Observable<T> {
    const url = this.resolveUrl(endpoint);
    return this.http.get<T>(url, { params: this.buildParams(params) });
  }

  post<T>(
    endpoint: string,
    body?: unknown,
    params?: HttpParams | Record<string, string | number | boolean | null | undefined>
  ): Observable<T> {
    const url = this.resolveUrl(endpoint);
    return this.http.post<T>(url, body ?? {}, { params: this.buildParams(params) });
  }

  patch<T>(
    endpoint: string,
    body?: unknown,
    params?: HttpParams | Record<string, string | number | boolean | null | undefined>
  ): Observable<T> {
    const url = this.resolveUrl(endpoint);
    return this.http.patch<T>(url, body ?? {}, { params: this.buildParams(params) });
  }

  delete<T = void>(
    endpoint: string,
    params?: HttpParams | Record<string, string | number | boolean | null | undefined>
  ): Observable<T> {
    const url = this.resolveUrl(endpoint);
    return this.http.delete<T>(url, { params: this.buildParams(params) });
  }
}
