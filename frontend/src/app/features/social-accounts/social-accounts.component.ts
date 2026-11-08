import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
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
                    (click)="openOAuthModal(p.key)"
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

      <!-- OAuth Official Configuration Modal -->
      <div *ngIf="isOAuthModalOpen() && selectedPlatform() as sel" class="modal-backdrop" (click)="closeOAuthModal()">
        <div class="modal-dialog" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <div class="modal-title-group">
              <span class="modal-platform-icon">
                <svg *ngIf="sel.key === 'tiktok'" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64c.298-.002.595.042.88.13V9.4a6.33 6.33 0 0 0-1-.08A6.34 6.34 0 0 0 3 15.66a6.34 6.34 0 0 0 10.82 4.47 6.27 6.27 0 0 0 1.94-4.47V8.5a8.28 8.28 0 0 0 4.83 1.55v-3.36z"/>
                </svg>
                <svg *ngIf="sel.key === 'instagram'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect>
                  <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path>
                  <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line>
                </svg>
                <svg *ngIf="sel.key === 'youtube'" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                </svg>
              </span>
              <div>
                <h3 class="modal-title">Conectar {{ sel.name }} (OAuth Oficial)</h3>
                <p class="modal-subtitle">Configura la autenticación oficial para publicación automatizada.</p>
              </div>
            </div>
            <button type="button" class="btn-modal-close" (click)="closeOAuthModal()" aria-label="Cerrar modal">&times;</button>
          </div>

          <div class="modal-body">
            <!-- Redirect URI Box -->
            <div class="redirect-uri-box">
              <div class="redirect-uri-header">
                <span class="redirect-uri-label">URI de Redirección Autorizada (OAuth Callback):</span>
                <button type="button" class="btn-copy-uri" (click)="copyRedirectUri(sel.key)">
                  {{ copyFeedback() ? 'Copiado' : 'Copiar URI' }}
                </button>
              </div>
              <div class="redirect-uri-val">{{ getRedirectUri(sel.key) }}</div>
              <p class="redirect-uri-desc">
                Copia esta URL en tu consola de desarrollador ({{ sel.key === 'youtube' ? 'Google Cloud Console > Credenciales > URIs de redireccionamiento autorizados' : sel.key === 'instagram' ? 'Meta for Developers > Instagram Graph API > Configuración' : 'TikTok for Developers > App Details > Redirect URI' }}).
              </p>
            </div>

            <!-- Tabs -->
            <div class="modal-tabs">
              <button
                type="button"
                class="modal-tab-btn"
                [class.active]="oauthTab() === 'credentials'"
                (click)="oauthTab.set('credentials')"
              >
                Credenciales OAuth (Client ID)
              </button>
              <button
                type="button"
                class="modal-tab-btn"
                [class.active]="oauthTab() === 'token'"
                (click)="oauthTab.set('token')"
              >
                Token de Acceso Directo (Bearer)
              </button>
            </div>

            <!-- Error Banner in Modal -->
            <div *ngIf="oauthModalError()" class="modal-alert-error">
              <span>{{ oauthModalError() }}</span>
            </div>

            <!-- Tab 1: App Credentials -->
            <div *ngIf="oauthTab() === 'credentials'" class="tab-pane">
              <div class="form-group">
                <label class="form-label" for="oauth-client-id">
                  {{ sel.key === 'tiktok' ? 'Client Key' : sel.key === 'instagram' ? 'App ID / Client ID' : 'Client ID' }}:
                </label>
                <input
                  id="oauth-client-id"
                  type="text"
                  class="form-control"
                  [placeholder]="sel.key === 'tiktok' ? 'ej. awxxxxxx' : sel.key === 'instagram' ? 'ej. 123456789012345' : 'ej. xxxxxx.apps.googleusercontent.com'"
                  [(ngModel)]="oauthClientId"
                  autocomplete="off"
                />
              </div>

              <div class="form-group">
                <label class="form-label" for="oauth-client-secret">
                  {{ sel.key === 'tiktok' ? 'Client Secret' : sel.key === 'instagram' ? 'App Secret' : 'Client Secret' }}:
                </label>
                <input
                  id="oauth-client-secret"
                  type="password"
                  class="form-control"
                  placeholder="ej. GOCSPX-xxxxxx o secret key"
                  [(ngModel)]="oauthClientSecret"
                  autocomplete="off"
                />
              </div>

              <div class="modal-actions">
                <button type="button" class="btn btn-secondary" (click)="closeOAuthModal()">
                  Cancelar
                </button>
                <button
                  type="button"
                  class="btn btn-primary"
                  (click)="submitOAuthCredentials()"
                  [disabled]="isSubmittingOAuth()"
                >
                  {{ isSubmittingOAuth() ? 'Iniciando Autorización...' : 'Iniciar Autorización en ' + sel.name + ' →' }}
                </button>
              </div>
            </div>

            <!-- Tab 2: Direct Access Token -->
            <div *ngIf="oauthTab() === 'token'" class="tab-pane">
              <div class="form-group">
                <label class="form-label" for="direct-username">Nombre de Usuario / Handle:</label>
                <input
                  id="direct-username"
                  type="text"
                  class="form-control"
                  placeholder="ej. mi_canal o reels_brand"
                  [(ngModel)]="directUsername"
                />
              </div>

              <div class="form-group">
                <label class="form-label" for="direct-token">Access Token (Bearer):</label>
                <input
                  id="direct-token"
                  type="password"
                  class="form-control"
                  placeholder="ej. ya29.a0... o EAA..."
                  [(ngModel)]="directToken"
                  autocomplete="off"
                />
                <span class="field-hint">Token emitido desde Google OAuth Playground, Meta Graph Explorer o TikTok Developer.</span>
              </div>

              <div class="form-group">
                <label class="form-label" for="direct-refresh-token">Refresh Token (Opcional):</label>
                <input
                  id="direct-refresh-token"
                  type="password"
                  class="form-control"
                  placeholder="ej. 1//0gxxxxxx (opcional para renovación automática)"
                  [(ngModel)]="directRefreshToken"
                  autocomplete="off"
                />
              </div>

              <div class="modal-actions">
                <button type="button" class="btn btn-secondary" (click)="closeOAuthModal()">
                  Cancelar
                </button>
                <button
                  type="button"
                  class="btn btn-primary"
                  (click)="submitDirectToken()"
                  [disabled]="isSubmittingOAuth()"
                >
                  {{ isSubmittingOAuth() ? 'Vinculando...' : 'Vincular con Token Oficial' }}
                </button>
              </div>
            </div>
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

    .alert-success {
      background-color: rgba(16, 185, 129, 0.15);
      border: 1px solid rgba(16, 185, 129, 0.3);
      color: #34d399;
      padding: 8px 12px;
      border-radius: 6px;
      font-size: 12px;
      display: flex;
      align-items: center;
      gap: 8px;
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

    /* Modal Styles */
    .modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.75);
      backdrop-filter: blur(8px);
      z-index: 1000;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }

    .modal-dialog {
      background: #0f0f11;
      border: 1px solid #27272a;
      border-radius: 12px;
      width: 100%;
      max-width: 540px;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.8);
      display: flex;
      flex-direction: column;
      overflow: hidden;
      animation: modalFadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes modalFadeIn {
      from {
        opacity: 0;
        transform: scale(0.96) translateY(8px);
      }
      to {
        opacity: 1;
        transform: scale(1) translateY(0);
      }
    }

    .modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 16px 20px;
      border-bottom: 1px solid #27272a;
    }

    .modal-title-group {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .modal-platform-icon {
      width: 36px;
      height: 36px;
      border-radius: 8px;
      background: #18181b;
      border: 1px solid #27272a;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
    }

    .modal-platform-icon svg {
      width: 18px;
      height: 18px;
    }

    .modal-title {
      font-size: 15px;
      font-weight: 700;
      color: #ffffff;
      margin: 0;
    }

    .modal-subtitle {
      font-size: 12px;
      color: #a1a1aa;
      margin: 2px 0 0 0;
    }

    .btn-modal-close {
      background: transparent;
      border: none;
      color: #a1a1aa;
      font-size: 24px;
      cursor: pointer;
      line-height: 1;
      padding: 4px;
      transition: color 0.15s ease;
    }

    .btn-modal-close:hover {
      color: #ffffff;
    }

    .modal-body {
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .redirect-uri-box {
      background: #18181b;
      border: 1px solid #27272a;
      border-radius: 8px;
      padding: 12px;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .redirect-uri-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .redirect-uri-label {
      font-size: 11px;
      font-weight: 600;
      color: #a1a1aa;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .btn-copy-uri {
      background: #27272a;
      border: 1px solid #3f3f46;
      border-radius: 4px;
      color: #e4e4e7;
      font-size: 11px;
      padding: 3px 8px;
      cursor: pointer;
      transition: background 0.15s ease;
    }

    .btn-copy-uri:hover {
      background: #3f3f46;
      color: #ffffff;
    }

    .redirect-uri-val {
      font-family: monospace;
      font-size: 11px;
      color: #60a5fa;
      background: #09090b;
      padding: 6px 8px;
      border-radius: 4px;
      border: 1px solid #27272a;
      word-break: break-all;
    }

    .redirect-uri-desc {
      font-size: 11px;
      color: #71717a;
      margin: 0;
      line-height: 1.3;
    }

    .modal-tabs {
      display: flex;
      gap: 8px;
      border-bottom: 1px solid #27272a;
      padding-bottom: 8px;
    }

    .modal-tab-btn {
      background: transparent;
      border: none;
      color: #a1a1aa;
      font-size: 12px;
      font-weight: 600;
      padding: 6px 12px;
      border-radius: 6px;
      cursor: pointer;
      transition: all 0.15s ease;
    }

    .modal-tab-btn.active {
      background: #27272a;
      color: #ffffff;
      border: 1px solid #3f3f46;
    }

    .modal-tab-btn:hover:not(.active) {
      color: #e4e4e7;
    }

    .modal-alert-error {
      background: rgba(239, 68, 68, 0.15);
      border: 1px solid rgba(239, 68, 68, 0.3);
      color: #f87171;
      padding: 8px 12px;
      border-radius: 6px;
      font-size: 12px;
    }

    .tab-pane {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .form-label {
      font-size: 11px;
      font-weight: 500;
      color: #d4d4d8;
    }

    .form-control {
      background: #18181b;
      border: 1px solid #27272a;
      border-radius: 6px;
      padding: 8px 10px;
      font-size: 12px;
      color: #ffffff;
      outline: none;
      transition: border-color 0.15s ease;
    }

    .form-control:focus {
      border-color: #ffffff;
    }

    .field-hint {
      font-size: 10px;
      color: #71717a;
    }

    .modal-actions {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      margin-top: 8px;
    }

    .btn-secondary {
      background: #18181b;
      border: 1px solid #27272a;
      color: #d4d4d8;
      padding: 8px 14px;
      font-size: 12px;
      border-radius: 6px;
      cursor: pointer;
      transition: background 0.15s ease;
    }

    .btn-secondary:hover {
      background: #27272a;
      color: #ffffff;
    }
  `],
})
export class SocialAccountsComponent implements OnInit {
  private readonly socialService = inject(SocialAccountService);
  private readonly route = inject(ActivatedRoute, { optional: true });
  private readonly router = inject(Router, { optional: true });

  readonly accounts = signal<SocialAccount[]>([]);
  readonly isLoading = signal<boolean>(true);
  readonly isSubmittingMock = signal<boolean>(false);
  readonly isConnectingAll = signal<boolean>(false);
  readonly connectingPlatform = signal<string | null>(null);
  readonly isDisconnecting = signal<boolean>(false);
  readonly mockSuccessMessage = signal<string | null>(null);

  // Modal State
  readonly isOAuthModalOpen = signal<boolean>(false);
  readonly selectedPlatform = signal<PlatformMeta | null>(null);
  readonly oauthTab = signal<'credentials' | 'token'>('credentials');
  readonly isSubmittingOAuth = signal<boolean>(false);
  readonly oauthModalError = signal<string | null>(null);
  readonly copyFeedback = signal<boolean>(false);

  oauthClientId = '';
  oauthClientSecret = '';
  directUsername = '';
  directToken = '';
  directRefreshToken = '';

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

    if (this.route) {
      const connected = this.route.snapshot.queryParamMap.get('connected');
      const error = this.route.snapshot.queryParamMap.get('error');

      if (connected) {
        this.mockSuccessMessage.set(
          `Canal de ${connected.toUpperCase()} conectado exitosamente vía OAuth oficial.`
        );
        setTimeout(() => this.mockSuccessMessage.set(null), 6000);
        this.router?.navigate([], { replaceUrl: true });
      } else if (error) {
        this.mockSuccessMessage.set(
          `Error en la autorización OAuth: ${decodeURIComponent(error)}`
        );
        setTimeout(() => this.mockSuccessMessage.set(null), 7000);
        this.router?.navigate([], { replaceUrl: true });
      }
    }
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

  openOAuthModal(platformKey: SocialPlatform): void {
    const p = this.platforms.find((item) => item.key === platformKey) || null;
    this.selectedPlatform.set(p);
    this.oauthClientId = '';
    this.oauthClientSecret = '';
    this.directToken = '';
    this.directRefreshToken = '';
    this.directUsername = (this.channelHandles[platformKey] || '').replace(/^@/, '');
    this.oauthModalError.set(null);
    this.oauthTab.set('credentials');
    this.isOAuthModalOpen.set(true);
  }

  closeOAuthModal(): void {
    this.isOAuthModalOpen.set(false);
    this.selectedPlatform.set(null);
    this.oauthModalError.set(null);
  }

  getRedirectUri(platform: SocialPlatform): string {
    return `https://reelscreator-api.vercel.app/api/social-accounts/callback/${platform}`;
  }

  copyRedirectUri(platform: SocialPlatform): void {
    const uri = this.getRedirectUri(platform);
    navigator.clipboard?.writeText(uri).then(() => {
      this.copyFeedback.set(true);
      setTimeout(() => this.copyFeedback.set(false), 2000);
    }).catch(() => {
      // Fallback
    });
  }

  submitOAuthCredentials(): void {
    const p = this.selectedPlatform();
    if (!p) return;

    const clientId = this.oauthClientId.trim();
    const clientSecret = this.oauthClientSecret.trim();

    if (!clientId) {
      this.oauthModalError.set(
        `Por favor ingresa el ${p.key === 'tiktok' ? 'Client Key' : 'Client ID'} de tu aplicación.`
      );
      return;
    }

    this.isSubmittingOAuth.set(true);
    this.oauthModalError.set(null);

    this.socialService
      .connectPlatform(p.key, {
        client_id: clientId,
        client_secret: clientSecret || undefined,
      })
      .subscribe({
        next: (res) => {
          this.isSubmittingOAuth.set(false);
          if (res.auth_url) {
            window.location.href = res.auth_url;
          } else {
            this.oauthModalError.set('No se recibió la URL de autenticación del servidor.');
          }
        },
        error: (err) => {
          this.isSubmittingOAuth.set(false);
          this.oauthModalError.set(
            err?.error?.message || err?.message || 'Error al iniciar flujo OAuth.'
          );
        },
      });
  }

  submitDirectToken(): void {
    const p = this.selectedPlatform();
    if (!p) return;

    const token = this.directToken.trim();
    const username = (this.directUsername.trim() || `${p.key}_creator`).replace(/^@/, '');

    if (!token) {
      this.oauthModalError.set('Por favor ingresa el Access Token emitido por la plataforma.');
      return;
    }

    this.isSubmittingOAuth.set(true);
    this.oauthModalError.set(null);

    this.socialService
      .connectSimple({
        platform: p.key,
        mode: 'direct',
        username,
        access_token: token,
        refresh_token: this.directRefreshToken.trim() || undefined,
      })
      .subscribe({
        next: () => {
          this.isSubmittingOAuth.set(false);
          this.mockSuccessMessage.set(
            `Canal de ${p.name} (@${username}) vinculado exitosamente con token oficial.`
          );
          this.closeOAuthModal();
          this.loadAccounts();
          setTimeout(() => this.mockSuccessMessage.set(null), 5000);
        },
        error: (err) => {
          this.isSubmittingOAuth.set(false);
          this.oauthModalError.set(
            err?.error?.message || err?.message || 'Error al vincular con token oficial.'
          );
        },
      });
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
          this.oauthNotices[platform] =
            err?.error?.message || err?.message || 'Error al conectar canal.';
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
          this.loadAccounts();
          setTimeout(() => this.mockSuccessMessage.set(null), 4000);
        },
        error: () => {
          this.isSubmittingMock.set(false);
        },
      });
  }

  connectOAuth(platform: SocialPlatform): void {
    this.openOAuthModal(platform);
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
