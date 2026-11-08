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

        <div class="header-actions">
          <button
            type="button"
            class="btn btn-primary btn-quick-connect-all"
            (click)="onQuickConnectAll()"
            [disabled]="isConnectingAll()"
          >
            <svg viewBox="0 0 24 24" class="svg-icon-sm" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
            </svg>
            <span>{{ isConnectingAll() ? 'Vinculando Cuentas...' : 'Vincular Canales Principales (TikTok, Instagram, YouTube)' }}</span>
          </button>
        </div>
      </div>

      <div *ngIf="mockSuccessMessage()" class="alert-success">
        <svg viewBox="0 0 24 24" class="svg-icon-xs" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="20 6 9 17 4 12"></polyline>
        </svg>
        <span>{{ mockSuccessMessage() }}</span>
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
                {{ getAccount(p.key) ? '● Conectado' : '○ No Vinculado' }}
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
                <span class="detail-label">Estado:</span>
                <span class="detail-val tag" [ngClass]="acc.status === 'connected' ? 'status-connected' : 'status-expired'">
                  ● {{ acc.status || 'connected' }}
                </span>
                <span class="detail-val tag" [ngClass]="acc.is_active !== false ? 'active-tag' : 'inactive-tag'">
                  {{ acc.is_active !== false ? 'Activo' : 'Inactivo' }}
                </span>
              </div>
              <div class="detail-row">
                <span class="detail-label">Vinculado:</span>
                <span class="detail-val">{{ acc.connected_at | relativeTime }}</span>
              </div>
              <div class="detail-row">
                <span class="detail-label">Tipo de Canal:</span>
                <span class="detail-val tag active-tag">
                  Canal de Producción
                </span>
              </div>

              <div class="action-row">
                <button
                  type="button"
                  class="btn btn-danger btn-sm"
                  (click)="disconnect(acc)"
                  [disabled]="isDisconnecting()"
                >
                  Desconectar Canal
                </button>
              </div>
            </div>

            <ng-template #notConnectedState>
              <div class="disconnected-details">
                <p class="disconnected-hint">{{ p.formatNotice }}</p>

                <div class="direct-input-group">
                  <label class="input-label" [for]="'handle-' + p.key">Nombre de Usuario (@handle):</label>
                  <div class="input-with-button">
                    <input
                      [id]="'handle-' + p.key"
                      type="text"
                      class="channel-handle-input"
                      [placeholder]="'ej. @' + (p.key === 'tiktok' ? 'reels_creator' : p.key === 'instagram' ? 'mi_marca' : 'mi_canal')"
                      [(ngModel)]="channelHandles[p.key]"
                      (keyup.enter)="onConnectHandle(p.key)"
                    />
                    <button
                      type="button"
                      class="btn btn-primary btn-sm btn-connect-channel"
                      (click)="onConnectHandle(p.key)"
                      [disabled]="connectingPlatform() === p.key"
                    >
                      <span>{{ connectingPlatform() === p.key ? 'Conectando...' : 'Conectar Canal' }}</span>
                    </button>
                  </div>
                </div>

                <div class="oauth-secondary-row">
                  <button
                    type="button"
                    class="btn-link-oauth"
                    (click)="connectOAuth(p.key)"
                  >
                    O conectar mediante OAuth oficial →
                  </button>
                </div>

                <div *ngIf="oauthNotices[p.key]" class="oauth-notice-box">
                  <span>{{ oauthNotices[p.key] }}</span>
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
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
    }

    .btn-quick-connect-all {
      font-size: 13px;
      padding: 8px 16px;
      background: linear-gradient(135deg, #6366f1, #8b5cf6);
      border: 1px solid #7c3aed;
      color: #fff;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      box-shadow: 0 4px 12px rgba(99, 102, 241, 0.25);
    }

    .btn-quick-connect-all:hover {
      background: linear-gradient(135deg, #4f46e5, #7c3aed);
    }

    .btn-quick-mock {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      margin-right: 8px;
    }

    .status-connected {
      background: rgba(34, 197, 94, 0.15);
      color: #4ade80;
      border: 1px solid rgba(34, 197, 94, 0.3);
    }

    .status-expired {
      background: rgba(239, 68, 68, 0.15);
      color: #f87171;
      border: 1px solid rgba(239, 68, 68, 0.3);
    }

    .active-tag {
      background: rgba(59, 130, 246, 0.15);
      color: #60a5fa;
      border: 1px solid rgba(59, 130, 246, 0.3);
    }

    .inactive-tag {
      background: rgba(113, 113, 122, 0.2);
      color: #a1a1aa;
      border: 1px solid #3f3f46;
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

    .direct-input-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
      margin-top: 8px;
    }

    .input-label {
      font-size: 11px;
      font-weight: 500;
      color: var(--text-muted, #a1a1aa);
    }

    .input-with-button {
      display: flex;
      gap: 8px;
    }

    .channel-handle-input {
      flex: 1;
      background: var(--bg-surface-elevated, #18181b);
      border: 1px solid var(--border-subtle, #27272a);
      border-radius: var(--radius-sm, 6px);
      padding: 6px 10px;
      font-size: 12px;
      color: #ffffff;
      outline: none;
      transition: border-color 0.15s ease;
    }

    .channel-handle-input:focus {
      border-color: #ffffff;
    }

    .channel-handle-input::placeholder {
      color: var(--text-faint, #52525b);
    }

    .btn-connect-channel {
      white-space: nowrap;
      padding: 6px 12px;
      font-size: 11px;
    }

    .oauth-secondary-row {
      margin-top: 6px;
    }

    .btn-link-oauth {
      background: none;
      border: none;
      padding: 0;
      font-size: 11px;
      color: var(--text-muted, #a1a1aa);
      cursor: pointer;
      text-decoration: underline;
      transition: color 0.15s ease;
    }

    .btn-link-oauth:hover {
      color: #ffffff;
    }

    .oauth-notice-box {
      font-size: 11px;
      color: #fbbf24;
      background: rgba(251, 191, 36, 0.1);
      border: 1px solid rgba(251, 191, 36, 0.25);
      border-radius: var(--radius-sm, 6px);
      padding: 6px 10px;
      line-height: 1.4;
      margin-top: 6px;
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
  readonly isConnectingAll = signal<boolean>(false);
  readonly connectingPlatform = signal<string | null>(null);
  readonly isDisconnecting = signal<boolean>(false);
  readonly mockSuccessMessage = signal<string | null>(null);

  readonly channelHandles: Record<string, string> = {
    tiktok: '',
    instagram: '',
    youtube: '',
  };

  readonly oauthNotices: Record<string, string> = {
    tiktok: '',
    instagram: '',
    youtube: '',
  };

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

  onQuickConnectAll(): void {
    this.isConnectingAll.set(true);
    this.mockSuccessMessage.set(null);

    this.socialService
      .connectSimple({ platform: 'all', mode: 'auto' })
      .subscribe({
        next: (res) => {
          this.isConnectingAll.set(false);
          const count = res.connected?.length || 3;
          this.mockSuccessMessage.set(
            `Todas las cuentas (${count}) conectadas exitosamente con adaptador ágil.`
          );
          this.loadAccounts();
          setTimeout(() => this.mockSuccessMessage.set(null), 5000);
        },
        error: () => {
          this.isConnectingAll.set(false);
        },
      });
  }

  onQuickConnectPlatform(platform: SocialPlatform): void {
    this.connectingPlatform.set(platform);
    this.mockSuccessMessage.set(null);

    this.socialService
      .connectSimple({ platform, mode: 'auto' })
      .subscribe({
        next: () => {
          this.connectingPlatform.set(null);
          this.mockSuccessMessage.set(
            `Cuenta ${platform.toUpperCase()} vinculada exitosamente.`
          );
          this.loadAccounts();
          setTimeout(() => this.mockSuccessMessage.set(null), 4000);
        },
        error: () => {
          this.connectingPlatform.set(null);
        },
      });
  }

  onConnectHandle(platform: SocialPlatform): void {
    const raw = (this.channelHandles[platform] || '').trim();
    const handle = raw.replace(/^@/, '') || `${platform}_creator`;

    this.connectingPlatform.set(platform);
    this.mockSuccessMessage.set(null);
    this.oauthNotices[platform] = '';

    this.socialService
      .connectSimple({
        platform,
        mode: 'direct',
        username: handle,
      })
      .subscribe({
        next: () => {
          this.connectingPlatform.set(null);
          this.mockSuccessMessage.set(
            `Canal de ${platform.toUpperCase()} (@${handle}) vinculado exitosamente.`
          );
          this.channelHandles[platform] = '';
          this.loadAccounts();
          setTimeout(() => this.mockSuccessMessage.set(null), 4000);
        },
        error: (err) => {
          this.connectingPlatform.set(null);
          this.oauthNotices[platform] = err?.error?.message || err?.message || 'Error al conectar canal.';
        },
      });
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
            `Canal ${created.platform} vinculado para @${created.platform_username}`
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
    this.oauthNotices[platform] = '';
    this.socialService.connectPlatform(platform).subscribe({
      next: (res: any) => {
        if (res.configured === false) {
          this.oauthNotices[platform] =
            res.message ||
            'Para autenticación OAuth con ' +
              platform.toUpperCase() +
              ' se requiere una aplicación registrada en la consola de desarrolladores. Puedes conectar tu canal directamente usando tu @handle.';
          return;
        }
        if (res.auth_url) {
          window.location.href = res.auth_url;
        }
      },
      error: (err) => {
        const msg =
          err?.error?.message ||
          'Para usar OAuth oficial se requiere registrar credenciales de la app en la consola de desarrollador. Puedes conectar tu canal directamente escribiendo tu @handle en el campo superior.';
        this.oauthNotices[platform] = msg;
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
