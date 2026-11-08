import { Injectable, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { map, tap } from 'rxjs/operators';
import { ApiService } from './api.service';
import { SocialPlatform } from '../models/common.model';
import {
  ConnectPlatformResponse,
  MockConnectRequest,
  MockConnectResponse,
  SimpleConnectRequest,
  SimpleConnectResponse,
  SocialAccount,
  SocialAccountsResponse,
} from '../models/social-account.model';

@Injectable({
  providedIn: 'root',
})
export class SocialAccountService {
  private readonly api = inject(ApiService);

  // Reactive state signals for UI binding
  readonly accounts = signal<SocialAccount[]>([]);
  readonly isLoading = signal<boolean>(false);

  /**
   * Fetch list of connected social accounts.
   * GET /api/social-accounts
   */
  getAccounts(): Observable<SocialAccount[]> {
    this.isLoading.set(true);
    return this.api
      .get<SocialAccountsResponse>('social-accounts')
      .pipe(
        map((res) => {
          const list = res?.data ?? [];
          return list.map((acc) => ({
            ...acc,
            is_active: acc.is_active ?? (acc.status ? acc.status === 'connected' : true),
            status: acc.status ?? (acc.is_active === false ? 'expired' : 'connected'),
          }));
        }),
        tap({
          next: (data) => {
            this.accounts.set(data);
            this.isLoading.set(false);
          },
          error: () => this.isLoading.set(false),
        })
      );
  }

  /**
   * Unified agile connect endpoint for social accounts.
   * POST /api/social-accounts/connect
   */
  connectSimple(payload: SimpleConnectRequest): Observable<SimpleConnectResponse> {
    return this.api
      .post<SimpleConnectResponse>('social-accounts/connect', payload)
      .pipe(
        tap((res) => {
          if (res?.connected?.length) {
            this.accounts.update((current) => {
              const updated = [...current];
              for (const acc of res.connected) {
                const mappedAcc: SocialAccount = {
                  ...acc,
                  is_active: acc.is_active ?? (acc.status ? acc.status === 'connected' : true),
                  status: acc.status ?? (acc.is_active === false ? 'expired' : 'connected'),
                };
                const idx = updated.findIndex((a) => a.platform === mappedAcc.platform);
                if (idx >= 0) {
                  updated[idx] = mappedAcc;
                } else {
                  updated.push(mappedAcc);
                }
              }
              return updated;
            });
          }
        })
      );
  }

  /**
   * Initiate OAuth handshake flow for a platform (TikTok, Instagram, YouTube).
   * POST /api/social-accounts/connect/{platform}
   */
  connectPlatform(platform: SocialPlatform): Observable<ConnectPlatformResponse> {
    return this.api.post<ConnectPlatformResponse>(`social-accounts/connect/${platform}`);
  }

  /**
   * Instantly provisions a mock account for offline testing without API credentials.
   * POST /api/social-accounts/mock-connect
   */
  mockConnect(payload: MockConnectRequest): Observable<MockConnectResponse> {
    return this.api
      .post<MockConnectResponse>('social-accounts/mock-connect', payload)
      .pipe(
        tap((newAcc) => {
          const mappedAcc: SocialAccount = {
            ...newAcc,
            is_active: true,
            status: 'connected',
          };
          this.accounts.update((current) => [...current, mappedAcc]);
        })
      );
  }

  /**
   * Disconnect and remove linked social account.
   * DELETE /api/social-accounts/{id}
   */
  disconnectAccount(id: string): Observable<void> {
    return this.api.delete<void>(`social-accounts/${id}`).pipe(
      tap(() => {
        this.accounts.update((current) => current.filter((acc) => acc.id !== id));
      })
    );
  }
}
