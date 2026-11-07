import { Component, computed, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, RouterLink, RouterLinkActive } from '@angular/router';

interface NavItem {
  path: string;
  label: string;
  description: string;
}

@Component({
  selector: 'app-layout',
  standalone: true,
  imports: [CommonModule, RouterModule, RouterLink, RouterLinkActive],
  template: `
    <div class="app-container">
      <!-- Sidebar -->
      <aside class="sidebar" [class.mobile-open]="isMobileMenuOpen()">
        <div class="sidebar-header">
          <div class="brand">
            <span class="brand-name">ReelForge</span>
          </div>
          <button
            type="button"
            class="mobile-close-btn"
            (click)="toggleMobileMenu()"
            aria-label="Close menu"
          >
            <svg viewBox="0 0 24 24" class="svg-icon-sm" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>
        </div>

        <nav class="nav-menu">
          <a
            *ngFor="let item of navItems"
            [routerLink]="item.path"
            routerLinkActive="active"
            (click)="closeMobileMenu()"
            class="nav-link"
          >
            <span class="nav-icon">
              <ng-container [ngSwitch]="item.path">
                <svg *ngSwitchCase="'/dashboard'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="3" y="3" width="7" height="7"></rect>
                  <rect x="14" y="3" width="7" height="7"></rect>
                  <rect x="14" y="14" width="7" height="7"></rect>
                  <rect x="3" y="14" width="7" height="7"></rect>
                </svg>
                <svg *ngSwitchCase="'/video-upload'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                  <polyline points="17 8 12 3 7 8"></polyline>
                  <line x1="12" y1="3" x2="12" y2="15"></line>
                </svg>
                <svg *ngSwitchCase="'/clips'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"></rect>
                  <line x1="7" y1="2" x2="7" y2="22"></line>
                  <line x1="17" y1="2" x2="17" y2="22"></line>
                  <line x1="2" y1="12" x2="22" y2="12"></line>
                  <line x1="2" y1="7" x2="7" y2="7"></line>
                  <line x1="2" y1="17" x2="7" y2="17"></line>
                  <line x1="17" y1="17" x2="22" y2="17"></line>
                  <line x1="17" y1="7" x2="22" y2="7"></line>
                </svg>
                <svg *ngSwitchCase="'/social-accounts'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                  <circle cx="9" cy="7" r="4"></circle>
                  <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                  <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
                <svg *ngSwitchCase="'/publish'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="22" y1="2" x2="11" y2="13"></line>
                  <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
              </ng-container>
            </span>
            <div class="nav-details">
              <span class="nav-label">{{ item.label }}</span>
              <span class="nav-desc">{{ item.description }}</span>
            </div>
          </a>
        </nav>

        <div class="sidebar-secondary">
          <a routerLink="/social-accounts" class="secondary-nav-link" (click)="closeMobileMenu()">
            <svg viewBox="0 0 24 24" class="svg-icon-nav" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="3"></circle>
              <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            <span>Configuración</span>
          </a>
          <a routerLink="/dashboard" class="secondary-nav-link" (click)="closeMobileMenu()">
            <svg viewBox="0 0 24 24" class="svg-icon-nav" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10"></circle>
              <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
              <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <span>Centro de Ayuda</span>
          </a>
        </div>
      </aside>

      <!-- Main Layout Body -->
      <div class="main-body">
        <!-- Topbar -->
        <header class="topbar">
          <div class="topbar-left">
            <button
              type="button"
              class="hamburger-btn"
              (click)="toggleMobileMenu()"
              aria-label="Toggle navigation"
            >
              <svg viewBox="0 0 24 24" class="svg-icon" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
              </svg>
            </button>

            <!-- Search Bar (Image 1 Style) -->
            <div class="search-box">
              <svg viewBox="0 0 24 24" class="search-icon" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
              </svg>
              <input
                type="text"
                placeholder="Buscar clips, videos, canales..."
                class="search-input"
              />
            </div>
          </div>

          <div class="topbar-right">
            <!-- Formatted Date -->
            <div class="topbar-date">
              <span>{{ formattedDate() }}</span>
            </div>

            <!-- Calendar Action Icon -->
            <button type="button" class="icon-button" aria-label="Calendario">
              <svg viewBox="0 0 24 24" class="svg-icon" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
              </svg>
            </button>

            <!-- Notification Bell Icon -->
            <button type="button" class="icon-button" aria-label="Notificaciones">
              <svg viewBox="0 0 24 24" class="svg-icon" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
              </svg>
              <span class="notification-dot"></span>
            </button>

            <!-- User Avatar Placeholder -->
            <div class="user-avatar" title="Cuenta activa">
              <span class="user-initials">RF</span>
            </div>
          </div>
        </header>

        <!-- Route Content Area -->
        <main class="content-area">
          <router-outlet></router-outlet>
        </main>
      </div>

      <!-- Mobile Backdrop -->
      <div
        *ngIf="isMobileMenuOpen()"
        class="mobile-backdrop"
        (click)="closeMobileMenu()"
      ></div>
    </div>
  `,
  styles: [`
    .app-container {
      display: flex;
      min-height: 100vh;
      background-color: var(--bg-canvas, #09090b);
      color: var(--text-primary, #ffffff);
    }

    .sidebar {
      width: 250px;
      background-color: var(--bg-surface, #121214);
      border-right: 1px solid var(--border-subtle, #27272a);
      display: flex;
      flex-direction: column;
      flex-shrink: 0;
      transition: transform 0.2s ease-in-out;
      z-index: 100;
    }

    .sidebar-header {
      padding: 24px 20px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .brand {
      display: flex;
      align-items: center;
    }

    .brand-name {
      font-size: 19px;
      font-weight: 700;
      letter-spacing: -0.03em;
      color: #ffffff;
    }

    .mobile-close-btn {
      display: none;
      background: transparent;
      border: none;
      color: var(--text-muted, #a1a1aa);
      cursor: pointer;
      padding: 4px;
    }

    .nav-menu {
      flex: 1;
      padding: 8px 12px;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .nav-link {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 12px;
      border-radius: var(--radius-sm, 6px);
      color: var(--text-muted, #a1a1aa);
      transition: background-color 0.15s ease, color 0.15s ease;
    }

    .nav-link:hover {
      background-color: var(--bg-surface-elevated, #18181b);
      color: #ffffff;
    }

    .nav-link.active {
      background-color: var(--bg-surface-elevated, #18181b);
      color: #ffffff;
      font-weight: 600;
    }

    .nav-icon {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 20px;
      height: 20px;
      flex-shrink: 0;
    }

    .nav-icon ::ng-deep svg {
      width: 18px;
      height: 18px;
      stroke: currentColor;
    }

    .nav-details {
      display: flex;
      flex-direction: column;
      min-width: 0;
    }

    .nav-label {
      font-size: 13px;
      font-weight: 500;
      line-height: 1.2;
    }

    .nav-desc {
      font-size: 11px;
      color: var(--text-faint, #71717a);
      margin-top: 2px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .sidebar-secondary {
      padding: 16px 12px 24px;
      border-top: 1px solid var(--border-subtle, #27272a);
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .secondary-nav-link {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 12px;
      border-radius: var(--radius-sm, 6px);
      color: var(--text-muted, #a1a1aa);
      font-size: 12px;
      transition: background-color 0.15s ease, color 0.15s ease;
    }

    .secondary-nav-link:hover {
      background-color: var(--bg-surface-elevated, #18181b);
      color: #ffffff;
    }

    .svg-icon-nav {
      width: 16px;
      height: 16px;
      flex-shrink: 0;
    }

    .main-body {
      flex: 1;
      display: flex;
      flex-direction: column;
      overflow-x: hidden;
      min-width: 0;
    }

    .topbar {
      height: 64px;
      background-color: var(--bg-surface, #121214);
      border-bottom: 1px solid var(--border-subtle, #27272a);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 28px;
      position: sticky;
      top: 0;
      z-index: 50;
    }

    .topbar-left {
      display: flex;
      align-items: center;
      gap: 14px;
      flex: 1;
      max-width: 440px;
    }

    .search-box {
      display: flex;
      align-items: center;
      gap: 10px;
      background-color: var(--bg-surface-elevated, #18181b);
      border: 1px solid var(--border-subtle, #27272a);
      border-radius: var(--radius-sm, 6px);
      padding: 6px 12px;
      width: 100%;
    }

    .search-icon {
      width: 16px;
      height: 16px;
      color: var(--text-faint, #71717a);
      flex-shrink: 0;
    }

    .search-input {
      background: transparent;
      border: none;
      outline: none;
      color: #ffffff;
      font-size: 13px;
      width: 100%;
    }

    .search-input::placeholder {
      color: var(--text-faint, #71717a);
    }

    .topbar-right {
      display: flex;
      align-items: center;
      gap: 16px;
    }

    .topbar-date {
      font-size: 13px;
      color: var(--text-muted, #a1a1aa);
      font-weight: 500;
    }

    .icon-button {
      background: var(--bg-surface-elevated, #18181b);
      border: 1px solid var(--border-subtle, #27272a);
      border-radius: var(--radius-sm, 6px);
      width: 36px;
      height: 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--text-muted, #a1a1aa);
      cursor: pointer;
      position: relative;
      transition: background-color 0.15s ease, color 0.15s ease;
    }

    .icon-button:hover {
      background-color: var(--border-subtle, #27272a);
      color: #ffffff;
    }

    .notification-dot {
      position: absolute;
      top: 8px;
      right: 8px;
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background-color: #ffffff;
    }

    .user-avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background-color: var(--bg-surface-elevated, #18181b);
      border: 1px solid var(--border-strong, #3f3f46);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
    }

    .user-initials {
      font-size: 12px;
      font-weight: 600;
      color: #ffffff;
    }

    .hamburger-btn {
      display: none;
      background: transparent;
      border: none;
      color: #ffffff;
      cursor: pointer;
      padding: 4px;
    }

    .svg-icon {
      width: 18px;
      height: 18px;
    }

    .svg-icon-sm {
      width: 16px;
      height: 16px;
    }

    .content-area {
      flex: 1;
      padding: 28px;
      max-width: 1400px;
      width: 100%;
      margin: 0 auto;
    }

    .mobile-backdrop {
      display: none;
    }

    @media (max-width: 768px) {
      .sidebar {
        position: fixed;
        top: 0;
        bottom: 0;
        left: 0;
        transform: translateX(-100%);
        box-shadow: 0 0 20px rgba(0, 0, 0, 0.8);
      }

      .sidebar.mobile-open {
        transform: translateX(0);
      }

      .mobile-close-btn {
        display: block;
      }

      .hamburger-btn {
        display: block;
      }

      .topbar-date {
        display: none;
      }

      .mobile-backdrop {
        display: block;
        position: fixed;
        inset: 0;
        background-color: rgba(0, 0, 0, 0.7);
        z-index: 90;
      }

      .content-area {
        padding: 16px;
      }
    }
  `],
})
export class LayoutComponent {
  readonly isMobileMenuOpen = signal<boolean>(false);

  readonly formattedDate = computed<string>(() => {
    const now = new Date();
    return now.toLocaleDateString('es-ES', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      year: 'numeric',
    });
  });

  readonly navItems: NavItem[] = [
    {
      path: '/dashboard',
      label: 'Dashboard',
      description: 'Métricas y telemetría',
    },
    {
      path: '/video-upload',
      label: 'Ingesta de Video',
      description: 'Descargar y cortar reels',
    },
    {
      path: '/clips',
      label: 'Librería de Clips',
      description: 'Reels verticales 9:16',
    },
    {
      path: '/social-accounts',
      label: 'Cuentas Sociales',
      description: 'TikTok, IG y YouTube',
    },
    {
      path: '/publish',
      label: 'Publicación',
      description: 'Despacho y registro',
    },
  ];

  toggleMobileMenu(): void {
    this.isMobileMenuOpen.update((v) => !v);
  }

  closeMobileMenu(): void {
    this.isMobileMenuOpen.set(false);
  }
}
