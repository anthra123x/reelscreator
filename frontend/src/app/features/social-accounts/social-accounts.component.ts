import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { SocialAccountService } from '../../core/services/social-account.service';
import { SocialPlatform } from '../../core/models/common.model';
import { SocialAccount } from '../../core/models/social-account.model';
import { RelativeTimePipe } from '../../shared/pipes/relative-time.pipe';

interface PlatformMeta {
  key: SocialPlatform;
  name: string;
  badge?: string;
  color: string;
  description: string;
  formatNotice: string;
}

@Component({
  selector: 'app-social-accounts',
  standalone: true,
  imports: [CommonModule, FormsModule, RelativeTimePipe],
  template: `
    <div class="social-accounts-page">
      <!-- Header -->
      <div class="page-header">
        <div>
          <h1 class="page-title">Canales y Redes Sociales</h1>
          <p class="page-subtitle">
            Administra los destinos de publicación para la distribución automatizada de reels en formato vertical 9:16.
          </p>
        </div>
      </div>

      <!-- Developer / Offline Mock Connection Panel -->
      <div class="card mock-panel">
        <div class="mock-header">
          <div class="mock-badge">
            <svg viewBox="0 0 24 24" class="svg-icon-sm" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>
            </svg>
            <span>Modo Mock / Pruebas Locales</span>
          </div>
          <span class="mock-desc">
            Permite aprovisionar canales de prueba sin requerir credenciales de OAuth activas ni acceso a internet.
          </span>
        </div>

        <form class="mock-form" (ngSubmit)="onMockConnect()">
          <div class="mock-inputs">
            <div class="form-group flex-1">
              <label class="form-label" for="mock-platform">Plataforma</label>
              <select
                id="mock-platform"
                class="form-select"
                [(ngModel)]="mockPlatform"
                name="mockPlatform"
              >
                <option value="tiktok">TikTok</option>
                <option value="instagram">Instagram Reels</option>
                <option value="youtube">YouTube Shorts</option>
              </select>
            </div>

            <div class="form-group flex-1">
              <label class="form-label" for="mock-username">Nombre de Usuario / Handle</label>
              <input
                id="mock-username"
                type="text"
                class="form-input"
                [(ngModel)]="mockUsername"
                name="mockUsername"
                placeholder="ej. reelforge_creator"
                required
              />
            </div>
          </div>

          <button
            type="submit"
            class="btn btn-primary btn-mock-submit"
            [disabled]="!mockUsername.trim() || isSubmittingMock()"
          >
            <span>{{ isSubmittingMock() ? 'Conectando...' : '+ Conectar Canal Mock' }}</span>
          </button>
        </form>

        <div *ngIf="mockSuccessMessage()" class="alert-success">
          <svg viewBox="0 0 24 24" class="svg-icon-xs" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"></polyline>
          </svg>
          <span>{{ mockSuccessMessage() }}</span>
        </div>
      </div>

      <!-- Main Platform Cards Grid -->
      <div class="platforms-grid">
        <div *ngFor="let p of platforms" class="platform-card" [ngClass]="p.key">
          <div class="card-top">
            <div class="platform-brand">
              <span class="platform-icon">
                <svg *ngIf="p.key === 'tiktok'" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64c.298-.002.595.042.88.13V9.4a6.33 6.33 0 0 0-1-.08A6.34 6.34 0 0 0 3 15.66a6.34 6.34 0 0 0 10.82 4.47 6.27 6.27 0 0 0 1.94-4.47V8.5a8.28 8.28 0 0 0 4.83 1.55v-3.36z"/>
                </svg>
                <svg *ngIf="p.key === 'instagram'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect>
                  <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path>
                  <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line>
                </svg>
                <svg *ngIf="p.key === 'youtube'" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                </svg>
              </span>
              <div class="platform-info">
                <h2 class="platform-name">{{ p.name }}</h2>
                <span class="platform-sub">{{ p.description }}</span>
              </div>
            </div>

            <!-- Connection Status Pill -->
            <div class="status-indicator">
              <span
                class="channel-pill"
                [class.connected]="getAccount(p.key)"
                [class.disconnected]="!getAccount(p.key)"
              >
                {{ getAccount(p.key) ? '● Connected' : '○ Not Linked' }}
              </span>
            </div>
          </div>

          <!-- Account Details or Connect State -->
          <div class="card-content">
            <div *ngIf="getAccount(p.key) as acc; else notConnectedState" class="connected-details">
              <div class="detail-row">
                <span class="detail-label">Handle:</span>
                <span class="detail-val username">@{{ acc.platform_username }}</span>
              </div>
              <div class="detail-row">
                <span class="detail-label">Connected:</span>
                <span class="detail-val">{{ acc.connected_at | relativeTime }}</span>
              </div>
              <div class="detail-row">
                <span class="detail-label">Channel Type:</span>
                <span class="detail-val tag" [class.mock-tag]="acc.is_mock">
                  {{ acc.is_mock ? 'Mock Adapter' : 'Live OAuth' }}
                </span>
              </div>

              <div class="action-row">
                <button
                  type="button"
                  class="btn btn-danger btn-sm"
                  (click)="disconnect(acc)"
                  [disabled]="isDisconnecting()"
                >
                  Disconnect Channel
                </button>
              </div>
            </div>

            <ng-template #notConnectedState>
              <div class="disconnected-details">
                <p class="disconnected-hint">{{ p.formatNotice }}</p>
                <div class="action-row">
                  <button
                    type="button"
                    class="btn btn-secondary btn-sm"
                    (click)="connectOAuth(p.key)"
                  >
                    Connect via OAuth →
                  </button>
                </div>
              </div>
            </ng-template>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .social-accounts-page {
      display: flex;
      flex-direction: column;
      gap: 24px;
    }

    .page-header {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .page-title {
      font-size: 22px;
      font-weight: 700;
      color: #ffffff;
      letter-spacing: -0.02em;
    }

    .page-subtitle {
      font-size: 13px;
      color: var(--text-muted, #94a3b8);
    }

    .mock-panel {
      background-color: var(--bg-surface, #121214);
      border: 1px solid var(--border-subtle, #27272a);
      border-radius: var(--radius-md, 10px);
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .mock-header {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .mock-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      font-weight: 700;
      color: #ffffff;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .mock-badge svg {
      width: 16px;
      height: 16px;
      flex-shrink: 0;
    }

    .mock-desc {
      font-size: 12px;
      color: var(--text-muted, #a1a1aa);
    }

    .mock-form {
      display: flex;
      flex-direction: column;
      gap: 14px;
    }

    @media (min-width: 640px) {
      .mock-form {
        flex-direction: row;
        align-items: flex-end;
      }
    }

    .mock-inputs {
      display: flex;
      flex-direction: column;
      gap: 12px;
      flex: 1;
    }

    @media (min-width: 480px) {
      .mock-inputs {
        flex-direction: row;
      }
    }

    .flex-1 {
      flex: 1;
      margin-bottom: 0;
    }

    .btn-mock-submit {
      padding: 10px 18px;
      font-size: 13px;
      white-space: nowrap;
      height: 42px;
    }

    .alert-success {
      background-color: rgba(16, 185, 129, 0.15);
      border: 1px solid rgba(16, 185, 129, 0.3);
      color: #34d399;
      padding: 8px 12px;
      border-radius: 6px;
      font-size: 12px;
    }

    .platforms-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 20px;
    }

    .platform-card {
      background-color: var(--bg-surface, #0f172a);
      border: 1px solid var(--border-subtle, #1e293b);
      border-radius: var(--radius-md, 12px);
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 18px;
      box-shadow: var(--shadow-card);
      transition: border-color 0.15s ease;
    }

    .platform-card:hover {
      border-color: var(--border-strong, #334155);
    }

    .card-top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
    }

    .platform-brand {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .platform-icon {
      font-size: 26px;
      width: 48px;
      height: 48px;
      border-radius: var(--radius-sm, 8px);
      background-color: var(--bg-surface-elevated, #18181b);
      border: 1px solid var(--border-subtle, #27272a);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #ffffff;
    }

    .platform-icon svg {
      width: 22px;
      height: 22px;
      flex-shrink: 0;
    }

    .platform-name {
      font-size: 15px;
      font-weight: 700;
      color: #ffffff;
    }

    .platform-sub {
      font-size: 11px;
      color: var(--text-muted, #a1a1aa);
      display: block;
      margin-top: 2px;
    }

    .channel-pill {
      font-size: 11px;
      font-weight: 600;
      padding: 3px 8px;
      border-radius: 9999px;
    }

    .channel-pill.connected {
      background-color: #27272a;
      color: #ffffff;
      border: 1px solid #3f3f46;
    }

    .channel-pill.disconnected {
      background-color: #18181b;
      color: var(--text-faint, #71717a);
      border: 1px solid var(--border-subtle, #27272a);
    }

    .card-content {
      border-top: 1px solid var(--border-subtle, #27272a);
      padding-top: 14px;
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }

    .connected-details {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .detail-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 12px;
    }

    .detail-label {
      color: var(--text-muted, #a1a1aa);
    }

    .detail-val {
      color: #ffffff;
      font-weight: 500;
    }

    .detail-val.username {
      color: #ffffff;
      font-family: monospace;
      font-weight: 600;
    }

    .tag {
      font-size: 10px;
      padding: 2px 6px;
      border-radius: 4px;
      background-color: var(--bg-surface-elevated, #18181b);
    }

    .mock-tag {
      background-color: #27272a;
      color: #ffffff;
      border: 1px solid #3f3f46;
    }

    .disconnected-details {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .disconnected-hint {
      font-size: 12px;
      color: var(--text-muted, #94a3b8);
      line-height: 1.4;
    }

    .action-row {
      margin-top: 12px;
      display: flex;
      justify-content: flex-end;
    }

    .btn-sm {
      padding: 6px 12px;
      font-size: 12px;
    }
  `],
})
export class SocialAccountsComponent implements OnInit {
  private readonly socialService = inject(SocialAccountService);

  readonly accounts = signal<SocialAccount[]>([]);
  readonly isLoading = signal<boolean>(true);
  readonly isSubmittingMock = signal<boolean>(false);
  readonly isDisconnecting = signal<boolean>(false);
  readonly mockSuccessMessage = signal<string | null>(null);

  mockPlatform: SocialPlatform = 'tiktok';
  mockUsername = 'test_creator';

  readonly platforms: PlatformMeta[] = [
    {
      key: 'tiktok',
      name: 'TikTok',
      color: '#ffffff',
      description: 'TikTok Video API (Direct Post)',
      formatNotice: 'Acepta video vertical 9:16 MP4, H.264/AAC, máx 600s.',
    },
    {
      key: 'instagram',
      name: 'Instagram Reels',
      color: '#ffffff',
      description: 'Instagram Graph API (Reels Publishing)',
      formatNotice: 'Acepta video vertical 9:16 MP4 (1080x1920), máx 90s.',
    },
    {
      key: 'youtube',
      name: 'YouTube Shorts',
      color: '#ffffff',
      description: 'YouTube Data API v3 (Shorts #shorts)',
      formatNotice: 'Acepta video vertical 9:16 MP4, máx 60s.',
    },
  ];

  ngOnInit(): void {
    this.loadAccounts();
  }

  loadAccounts(): void {
    this.isLoading.set(true);
    this.socialService.getAccounts().subscribe({
      next: (list) => {
        this.accounts.set(list || []);
        this.isLoading.set(false);
      },
      error: () => {
        this.isLoading.set(false);
      },
    });
  }

  getAccount(platform: SocialPlatform): SocialAccount | undefined {
    return this.accounts().find((a) => a.platform === platform);
  }

  onMockConnect(): void {
    if (!this.mockUsername.trim()) return;

    this.isSubmittingMock.set(true);
    this.mockSuccessMessage.set(null);

    this.socialService
      .mockConnect({
        platform: this.mockPlatform,
        username: this.mockUsername.trim(),
      })
      .subscribe({
        next: (created) => {
          this.isSubmittingMock.set(false);
          this.mockSuccessMessage.set(
            `Connected mock ${created.platform} account for @${created.platform_username}`
          );
          // Reload to sync account list
          this.loadAccounts();
          setTimeout(() => this.mockSuccessMessage.set(null), 4000);
        },
        error: () => {
          this.isSubmittingMock.set(false);
        },
      });
  }

  connectOAuth(platform: SocialPlatform): void {
    this.socialService.connectPlatform(platform).subscribe({
      next: (res) => {
        if (res.auth_url) {
          window.location.href = res.auth_url;
        }
      },
    });
  }

  disconnect(acc: SocialAccount): void {
    if (!confirm(`Disconnect ${acc.platform} channel @${acc.platform_username}?`)) {
      return;
    }

    this.isDisconnecting.set(true);
    this.socialService.disconnectAccount(acc.id).subscribe({
      next: () => {
        this.isDisconnecting.set(false);
        this.loadAccounts();
      },
      error: () => {
        this.isDisconnecting.set(false);
      },
    });
  }
}
