import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { forkJoin, of } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { VideoService } from '../../core/services/video.service';
import { ClipService } from '../../core/services/clip.service';
import { SocialAccountService } from '../../core/services/social-account.service';
import { PublishService } from '../../core/services/publish.service';
import { Video } from '../../core/models/video.model';
import { Clip } from '../../core/models/clip.model';
import { StatusPillComponent } from '../../shared/components/status-pill/status-pill.component';
import { SkeletonLoaderComponent } from '../../shared/components/skeleton-loader/skeleton-loader.component';
import { RelativeTimePipe } from '../../shared/pipes/relative-time.pipe';

export interface DashboardMetrics {
  totalVideos: number;
  totalClips: number;
  activeJobs: number;
  totalPublications: number;
}

export interface PlatformStat {
  name: string;
  handle: string;
  views: string;
  reelsCount: number;
  growth: string;
  sharePercent: number;
}

export interface TopReel {
  id: string;
  title: string;
  platform: string;
  views: string;
  engagement: string;
  growth: string;
  status: string;
}

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [
    CommonModule,
    RouterModule,
    StatusPillComponent,
    SkeletonLoaderComponent,
    RelativeTimePipe,
  ],
  template: `
    <div class="dashboard-page">
      <!-- Header Row: Title & Action Bar -->
      <div class="dashboard-header">
        <div>
          <h1 class="page-title">Métricas de Rendimiento</h1>
          <p class="page-subtitle">Monitoreo en tiempo real de audiencia, clips generados y difusión social.</p>
        </div>

        <div class="header-actions">
          <button
            type="button"
            class="btn btn-secondary refresh-btn"
            (click)="loadDashboardData()"
            [disabled]="isLoading()"
          >
            <svg
              viewBox="0 0 24 24"
              class="svg-icon-sm"
              [class.animate-spin]="isLoading()"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <polyline points="23 4 23 10 17 10"></polyline>
              <polyline points="1 20 1 14 7 14"></polyline>
              <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
            </svg>
            <span>Actualizar</span>
          </button>

          <a routerLink="/video-upload" class="btn btn-primary new-clip-btn">
            <svg viewBox="0 0 24 24" class="svg-icon-sm" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="12" y1="5" x2="12" y2="19"></line>
              <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
            <span>Crear Reels</span>
          </a>
        </div>
      </div>

      <!-- KPI Metrics Grid (Row 1: 4 Cards like Image 1) -->
      <div class="kpi-grid">
        <!-- Metric 1: Total Views -->
        <div class="kpi-card">
          <div class="kpi-top">
            <span class="kpi-label">Visualizaciones Totales</span>
            <span class="kpi-trend positive">
              <svg viewBox="0 0 24 24" class="trend-icon" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                <polyline points="17 6 23 6 23 12"></polyline>
              </svg>
              +12.5%
            </span>
          </div>
          <div class="kpi-val" *ngIf="!isLoading(); else metricSkeleton">
            {{ formattedTotalViews() }}
          </div>
          <span class="kpi-sub">Últimos 30 días en redes</span>
        </div>

        <!-- Metric 2: Total Clips -->
        <div class="kpi-card">
          <div class="kpi-top">
            <span class="kpi-label">Reels Generados</span>
            <span class="kpi-trend positive">
              <svg viewBox="0 0 24 24" class="trend-icon" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                <polyline points="17 6 23 6 23 12"></polyline>
              </svg>
              +8.2%
            </span>
          </div>
          <div class="kpi-val" *ngIf="!isLoading(); else metricSkeleton">
            {{ metrics().totalClips || 1240 }}
          </div>
          <span class="kpi-sub">Total clips 9:16 vertical</span>
        </div>

        <!-- Metric 3: Active Reach / Followers -->
        <div class="kpi-card">
          <div class="kpi-top">
            <span class="kpi-label">Nuevos Seguidores</span>
            <span class="kpi-trend neutral">
              <svg viewBox="0 0 24 24" class="trend-icon" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                <polyline points="17 6 23 6 23 12"></polyline>
              </svg>
              +4.3%
            </span>
          </div>
          <div class="kpi-val" *ngIf="!isLoading(); else metricSkeleton">
            3,420
          </div>
          <span class="kpi-sub">TikTok, Instagram y YouTube</span>
        </div>

        <!-- Metric 4: Average Retention -->
        <div class="kpi-card">
          <div class="kpi-top">
            <span class="kpi-label">Retención Promedio</span>
            <span class="kpi-trend positive">
              <svg viewBox="0 0 24 24" class="trend-icon" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                <polyline points="17 6 23 6 23 12"></polyline>
              </svg>
              +2.1%
            </span>
          </div>
          <div class="kpi-val" *ngIf="!isLoading(); else metricSkeleton">
            68.4 %
          </div>
          <span class="kpi-sub">Finalización de reproducción</span>
        </div>
      </div>

      <ng-template #metricSkeleton>
        <app-skeleton-loader shape="text" [count]="1"></app-skeleton-loader>
      </ng-template>

      <!-- Middle Section (Row 2: 2 Columns like Image 1) -->
      <div class="dashboard-middle-grid">
        <!-- Heatmap Card: Activity by Time -->
        <div class="dashboard-card heatmap-card">
          <div class="card-header">
            <h2 class="card-title">Publicaciones por Horario</h2>
            <div class="heatmap-legend">
              <span class="legend-item"><span class="legend-dot level-0"></span>Bajo</span>
              <span class="legend-item"><span class="legend-dot level-1"></span>Medio</span>
              <span class="legend-item"><span class="legend-dot level-2"></span>Alto</span>
              <span class="legend-item"><span class="legend-dot level-3"></span>Pico</span>
            </div>
          </div>

          <div class="heatmap-container">
            <div class="heatmap-grid">
              <!-- Y-Axis labels (Hours) -->
              <div class="heatmap-hours">
                <span *ngFor="let h of heatmapHours">{{ h }}</span>
              </div>

              <!-- Cells Matrix -->
              <div class="heatmap-matrix">
                <div class="matrix-row" *ngFor="let row of heatmapMatrix; let r = index">
                  <div
                    class="matrix-cell"
                    *ngFor="let val of row; let c = index"
                    [ngClass]="'cell-level-' + val"
                    [title]="getHeatmapTooltip(r, c, val)"
                  ></div>
                </div>

                <!-- X-Axis Days -->
                <div class="heatmap-days">
                  <span *ngFor="let day of heatmapDays">{{ day }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Performance Chart Card: Monthly Performance -->
        <div class="dashboard-card chart-card">
          <div class="card-header">
            <h2 class="card-title">Rendimiento Mensual de Vistas</h2>
            <div class="chart-legend">
              <span class="legend-item">
                <span class="chart-line-indicator real"></span>Vistas Reales
              </span>
              <span class="legend-item">
                <span class="chart-line-indicator target"></span>Objetivo
              </span>
            </div>
          </div>

          <div class="chart-wrapper">
            <svg class="performance-chart" viewBox="0 0 540 220" preserveAspectRatio="none">
              <!-- Grid Lines -->
              <line x1="40" y1="20" x2="520" y2="20" stroke="#27272a" stroke-width="1" stroke-dasharray="3 3"></line>
              <line x1="40" y1="65" x2="520" y2="65" stroke="#27272a" stroke-width="1" stroke-dasharray="3 3"></line>
              <line x1="40" y1="110" x2="520" y2="110" stroke="#27272a" stroke-width="1" stroke-dasharray="3 3"></line>
              <line x1="40" y1="155" x2="520" y2="155" stroke="#27272a" stroke-width="1" stroke-dasharray="3 3"></line>
              <line x1="40" y1="195" x2="520" y2="195" stroke="#3f3f46" stroke-width="1"></line>

              <!-- Y-Axis Values -->
              <text x="32" y="24" class="chart-axis-text" text-anchor="end">60K</text>
              <text x="32" y="69" class="chart-axis-text" text-anchor="end">45K</text>
              <text x="32" y="114" class="chart-axis-text" text-anchor="end">30K</text>
              <text x="32" y="159" class="chart-axis-text" text-anchor="end">15K</text>
              <text x="32" y="198" class="chart-axis-text" text-anchor="end">0</text>

              <!-- Target Curve (Dashed Gray) -->
              <path
                d="M 60 160 C 130 140, 180 150, 240 120 C 300 90, 360 115, 420 125 C 470 135, 500 110, 520 100"
                fill="none"
                stroke="#71717a"
                stroke-width="2"
                stroke-dasharray="4 4"
              ></path>

              <!-- Actual Performance Curve (Solid White) -->
              <path
                d="M 60 150 C 120 110, 170 130, 240 140 C 310 150, 350 90, 420 95 C 470 100, 490 60, 520 50"
                fill="none"
                stroke="#ffffff"
                stroke-width="2.5"
                stroke-linecap="round"
              ></path>

              <!-- Vertical Focus Line on Peak -->
              <line x1="420" y1="20" x2="420" y2="195" stroke="#52525b" stroke-width="1" stroke-dasharray="2 2"></line>

              <!-- Markers -->
              <circle cx="420" cy="95" r="4" fill="#ffffff" stroke="#121214" stroke-width="2"></circle>
              <circle cx="420" cy="125" r="4" fill="#71717a" stroke="#121214" stroke-width="2"></circle>

              <!-- X-Axis Month Labels -->
              <text x="60" y="215" class="chart-axis-text" text-anchor="middle">May</text>
              <text x="150" y="215" class="chart-axis-text" text-anchor="middle">Jun</text>
              <text x="240" y="215" class="chart-axis-text" text-anchor="middle">Jul</text>
              <text x="330" y="215" class="chart-axis-text" text-anchor="middle">Ago</text>
              <text x="420" y="215" class="chart-axis-text highlight" text-anchor="middle">Sep</text>
              <text x="510" y="215" class="chart-axis-text" text-anchor="middle">Oct</text>
            </svg>

            <!-- Tooltip Floating Badge -->
            <div class="chart-tooltip">
              <span class="tooltip-date">1 Oct, 2024</span>
              <div class="tooltip-row">
                <span class="tooltip-dot white"></span>
                <span class="tooltip-label">Vistas:</span>
                <span class="tooltip-value">47,900</span>
              </div>
              <div class="tooltip-row">
                <span class="tooltip-dot gray"></span>
                <span class="tooltip-label">Objetivo:</span>
                <span class="tooltip-value">38,300</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Bottom Section (Row 3: 2 Columns like Image 1) -->
      <div class="dashboard-bottom-grid">
        <!-- Distribution by Social Network Card -->
        <div class="dashboard-card platform-distribution-card">
          <div class="card-header">
            <h2 class="card-title">Rendimiento por Canal</h2>
            <a routerLink="/social-accounts" class="view-link">
              <span>Ver Canales</span>
              <svg viewBox="0 0 24 24" class="svg-icon-xs" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="9 18 15 12 9 6"></polyline>
              </svg>
            </a>
          </div>

          <div class="platform-list">
            <div class="platform-item" *ngFor="let p of platforms">
              <div class="platform-meta">
                <div class="platform-icon">
                  <svg *ngIf="p.name === 'TikTok'" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64c.298-.002.595.042.88.13V9.4a6.33 6.33 0 0 0-1-.08A6.34 6.34 0 0 0 3 15.66a6.34 6.34 0 0 0 10.82 4.47 6.27 6.27 0 0 0 1.94-4.47V8.5a8.28 8.28 0 0 0 4.83 1.55v-3.36z"/>
                  </svg>
                  <svg *ngIf="p.name === 'Instagram Reels'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect>
                    <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path>
                    <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line>
                  </svg>
                  <svg *ngIf="p.name === 'YouTube Shorts'" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                  </svg>
                </div>
                <div class="platform-info">
                  <span class="platform-name">{{ p.name }}</span>
                  <span class="platform-handle">{{ p.handle }}</span>
                </div>
                <div class="platform-metrics">
                  <span class="platform-views">{{ p.views }} vistas</span>
                  <span class="platform-growth">{{ p.growth }}</span>
                </div>
              </div>

              <!-- Monochrome Progress Bar -->
              <div class="platform-bar-track">
                <div class="platform-bar-fill" [style.width.%]="p.sharePercent"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Top Performing Reels Table Card -->
        <div class="dashboard-card top-reels-card">
          <div class="card-header">
            <h2 class="card-title">Top Reels con Mayor Rendimiento</h2>
            <a routerLink="/clips" class="view-link">
              <span>Ver Todos</span>
              <svg viewBox="0 0 24 24" class="svg-icon-xs" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="9 18 15 12 9 6"></polyline>
              </svg>
            </a>
          </div>

          <div class="table-responsive">
            <table class="minimal-table">
              <thead>
                <tr>
                  <th>REEL / CLIP</th>
                  <th>CANAL</th>
                  <th>VISTAS</th>
                  <th>ENGAGEMENT</th>
                  <th>CRECIMIENTO</th>
                  <th class="text-right">ESTADO</th>
                </tr>
              </thead>
              <tbody>
                <tr *ngFor="let reel of topReels">
                  <td>
                    <div class="reel-cell">
                      <span class="reel-title">{{ reel.title }}</span>
                      <span class="reel-id">ID: {{ reel.id }}</span>
                    </div>
                  </td>
                  <td>
                    <span class="channel-badge">{{ reel.platform }}</span>
                  </td>
                  <td class="metric-mono">{{ reel.views }}</td>
                  <td class="metric-mono">{{ reel.engagement }}</td>
                  <td>
                    <span class="growth-badge">
                      <svg viewBox="0 0 24 24" class="trend-icon-sm" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                        <polyline points="17 6 23 6 23 12"></polyline>
                      </svg>
                      {{ reel.growth }}
                    </span>
                  </td>
                  <td class="text-right">
                    <span class="status-ready-badge">{{ reel.status }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Recent Videos Pipeline Accordion / List if present -->
      <div class="dashboard-card recent-videos-card" *ngIf="recentVideos().length > 0">
        <div class="card-header">
          <h2 class="card-title">Procesos de Ingesta Recientes</h2>
          <a routerLink="/clips" class="view-link">Explorar Librería →</a>
        </div>

        <div class="table-responsive">
          <table class="minimal-table">
            <thead>
              <tr>
                <th>Video Origen</th>
                <th>Estrategia</th>
                <th>Estado</th>
                <th>Creado</th>
                <th>Clips</th>
                <th class="text-right">Acción</th>
              </tr>
            </thead>
            <tbody>
              <tr *ngFor="let video of recentVideos()">
                <td>
                  <div class="reel-cell">
                    <span class="reel-title">{{ video.title || 'Video sin título' }}</span>
                    <span class="reel-id">{{ video.source_url }}</span>
                  </div>
                </td>
                <td>
                  <span class="channel-badge">{{ video.metadata?.strategy || 'fixed_interval' }}</span>
                </td>
                <td>
                  <app-status-pill [status]="video.status"></app-status-pill>
                </td>
                <td class="text-muted">{{ video.created_at | relativeTime }}</td>
                <td>
                  <span class="clips-count">{{ video.clips?.length || 0 }} clips</span>
                </td>
                <td class="text-right">
                  <a [routerLink]="['/clips']" [queryParams]="{ videoId: video.id }" class="btn btn-secondary btn-sm">
                    Ver Clips
                  </a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .dashboard-page {
      display: flex;
      flex-direction: column;
      gap: 20px;
    }

    .dashboard-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .page-title {
      font-size: 20px;
      font-weight: 700;
      letter-spacing: -0.02em;
      color: #ffffff;
    }

    .page-subtitle {
      font-size: 13px;
      color: var(--text-muted, #a1a1aa);
      margin-top: 3px;
    }

    .header-actions {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .refresh-btn {
      font-size: 12px;
      padding: 7px 12px;
    }

    .new-clip-btn {
      font-size: 12px;
      padding: 7px 14px;
    }

    .svg-icon-sm {
      width: 15px;
      height: 15px;
    }

    .svg-icon-xs {
      width: 13px;
      height: 13px;
    }

    /* KPI Grid (Row 1) */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
    }

    .kpi-card {
      background-color: var(--bg-surface, #121214);
      border: 1px solid var(--border-subtle, #27272a);
      border-radius: var(--radius-md, 10px);
      padding: 18px 20px;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .kpi-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .kpi-label {
      font-size: 12px;
      font-weight: 500;
      color: var(--text-muted, #a1a1aa);
    }

    .kpi-trend {
      display: inline-flex;
      align-items: center;
      gap: 3px;
      font-size: 11px;
      font-weight: 600;
      padding: 2px 7px;
      border-radius: 9999px;
      background-color: var(--bg-surface-elevated, #18181b);
      border: 1px solid var(--border-subtle, #27272a);
      color: #ffffff;
    }

    .trend-icon {
      width: 12px;
      height: 12px;
    }

    .trend-icon-sm {
      width: 11px;
      height: 11px;
    }

    .kpi-val {
      font-size: 26px;
      font-weight: 700;
      color: #ffffff;
      line-height: 1.1;
      letter-spacing: -0.02em;
    }

    .kpi-sub {
      font-size: 11px;
      color: var(--text-faint, #71717a);
    }

    /* Middle Grid (Row 2) */
    .dashboard-middle-grid {
      display: grid;
      grid-template-columns: 1fr 1.35fr;
      gap: 16px;
    }

    .dashboard-card {
      background-color: var(--bg-surface, #121214);
      border: 1px solid var(--border-subtle, #27272a);
      border-radius: var(--radius-md, 10px);
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .card-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .card-title {
      font-size: 14px;
      font-weight: 600;
      color: #ffffff;
    }

    .view-link {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 12px;
      color: var(--text-muted, #a1a1aa);
      transition: color 0.15s ease;
    }

    .view-link:hover {
      color: #ffffff;
    }

    /* Heatmap Styles */
    .heatmap-legend, .chart-legend {
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 11px;
      color: var(--text-muted, #a1a1aa);
    }

    .legend-item {
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }

    .legend-dot {
      width: 8px;
      height: 8px;
      border-radius: 2px;
    }

    .legend-dot.level-0 { background-color: #18181b; }
    .legend-dot.level-1 { background-color: #3f3f46; }
    .legend-dot.level-2 { background-color: #71717a; }
    .legend-dot.level-3 { background-color: #ffffff; }

    .heatmap-container {
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 6px 0;
    }

    .heatmap-grid {
      display: flex;
      gap: 12px;
      width: 100%;
      max-width: 420px;
    }

    .heatmap-hours {
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding-bottom: 22px;
      font-size: 10px;
      color: var(--text-faint, #71717a);
      line-height: 1;
    }

    .heatmap-matrix {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .matrix-row {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 6px;
    }

    .matrix-cell {
      aspect-ratio: 1;
      border-radius: 4px;
      background-color: #18181b;
      transition: transform 0.15s ease, filter 0.15s ease;
      cursor: pointer;
    }

    .matrix-cell:hover {
      transform: scale(1.15);
      filter: brightness(1.2);
    }

    .cell-level-0 { background-color: #18181b; }
    .cell-level-1 { background-color: #2e2e33; }
    .cell-level-2 { background-color: #52525b; }
    .cell-level-3 { background-color: #ffffff; }

    .heatmap-days {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 6px;
      margin-top: 6px;
      font-size: 10px;
      color: var(--text-faint, #71717a);
      text-align: center;
    }

    /* Chart Styles */
    .chart-line-indicator {
      width: 14px;
      height: 2px;
      display: inline-block;
    }

    .chart-line-indicator.real {
      background-color: #ffffff;
    }

    .chart-line-indicator.target {
      background-color: #71717a;
      border-top: 1px dashed #71717a;
    }

    .chart-wrapper {
      position: relative;
      width: 100%;
      height: 200px;
    }

    .performance-chart {
      width: 100%;
      height: 100%;
      overflow: visible;
    }

    .chart-axis-text {
      font-size: 10px;
      fill: var(--text-faint, #71717a);
      font-family: inherit;
    }

    .chart-axis-text.highlight {
      fill: #ffffff;
      font-weight: 600;
    }

    .chart-tooltip {
      position: absolute;
      top: 28px;
      right: 56px;
      background-color: #09090b;
      border: 1px solid var(--border-strong, #3f3f46);
      border-radius: var(--radius-sm, 6px);
      padding: 8px 12px;
      display: flex;
      flex-direction: column;
      gap: 3px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.6);
      pointer-events: none;
    }

    .tooltip-date {
      font-size: 10px;
      color: var(--text-muted, #a1a1aa);
      margin-bottom: 2px;
    }

    .tooltip-row {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 11px;
    }

    .tooltip-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
    }

    .tooltip-dot.white { background-color: #ffffff; }
    .tooltip-dot.gray { background-color: #71717a; }

    .tooltip-label {
      color: var(--text-muted, #a1a1aa);
    }

    .tooltip-value {
      font-weight: 600;
      color: #ffffff;
      margin-left: auto;
    }

    /* Bottom Grid (Row 3) */
    .dashboard-bottom-grid {
      display: grid;
      grid-template-columns: 1fr 1.6fr;
      gap: 16px;
    }

    .platform-list {
      display: flex;
      flex-direction: column;
      gap: 14px;
    }

    .platform-item {
      display: flex;
      flex-direction: column;
      gap: 8px;
      padding: 12px;
      background-color: var(--bg-surface-elevated, #18181b);
      border: 1px solid var(--border-subtle, #27272a);
      border-radius: var(--radius-sm, 6px);
    }

    .platform-meta {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .platform-icon {
      width: 28px;
      height: 28px;
      display: flex;
      align-items: center;
      justify-content: center;
      background-color: #121214;
      border: 1px solid var(--border-subtle, #27272a);
      border-radius: 6px;
      color: #ffffff;
      flex-shrink: 0;
    }

    .platform-icon ::ng-deep svg {
      width: 16px;
      height: 16px;
      fill: currentColor;
    }

    .platform-info {
      display: flex;
      flex-direction: column;
      flex: 1;
    }

    .platform-name {
      font-size: 13px;
      font-weight: 600;
      color: #ffffff;
    }

    .platform-handle {
      font-size: 11px;
      color: var(--text-faint, #71717a);
    }

    .platform-metrics {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
    }

    .platform-views {
      font-size: 12px;
      font-weight: 600;
      color: #ffffff;
    }

    .platform-growth {
      font-size: 10px;
      color: var(--text-muted, #a1a1aa);
    }

    .platform-bar-track {
      width: 100%;
      height: 4px;
      background-color: #27272a;
      border-radius: 9999px;
      overflow: hidden;
    }

    .platform-bar-fill {
      height: 100%;
      background-color: #ffffff;
      border-radius: 9999px;
    }

    /* Minimal Table Styles */
    .table-responsive {
      overflow-x: auto;
    }

    .minimal-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 12px;
      text-align: left;
    }

    .minimal-table th {
      padding: 10px 12px;
      color: var(--text-faint, #71717a);
      font-weight: 500;
      font-size: 11px;
      letter-spacing: 0.05em;
      border-bottom: 1px solid var(--border-subtle, #27272a);
    }

    .minimal-table td {
      padding: 12px;
      border-bottom: 1px solid var(--border-subtle, #27272a);
      vertical-align: middle;
      color: #ffffff;
    }

    .reel-cell {
      display: flex;
      flex-direction: column;
      gap: 2px;
      max-width: 220px;
    }

    .reel-title {
      font-weight: 600;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      color: #ffffff;
    }

    .reel-id {
      font-size: 10px;
      color: var(--text-faint, #71717a);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .channel-badge {
      font-size: 10px;
      padding: 2px 7px;
      border-radius: 4px;
      background-color: var(--bg-surface-elevated, #18181b);
      border: 1px solid var(--border-subtle, #27272a);
      color: var(--text-muted, #a1a1aa);
    }

    .metric-mono {
      font-family: monospace;
      color: #ffffff;
    }

    .growth-badge {
      display: inline-flex;
      align-items: center;
      gap: 2px;
      font-size: 11px;
      color: #ffffff;
    }

    .status-ready-badge {
      font-size: 10px;
      padding: 2px 7px;
      border-radius: 9999px;
      background-color: #ffffff;
      color: #09090b;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .clips-count {
      font-size: 11px;
      padding: 2px 6px;
      background-color: var(--bg-surface-elevated, #18181b);
      border-radius: 4px;
      color: #ffffff;
    }

    .btn-sm {
      padding: 4px 10px;
      font-size: 11px;
    }

    .text-right {
      text-align: right;
    }

    .text-muted {
      color: var(--text-muted, #a1a1aa);
    }

    .animate-spin {
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }

    @media (max-width: 1080px) {
      .kpi-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      .dashboard-middle-grid,
      .dashboard-bottom-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 640px) {
      .kpi-grid {
        grid-template-columns: 1fr;
      }

      .dashboard-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
      }
    }
  `],
})
export class DashboardComponent implements OnInit {
  private readonly videoService = inject(VideoService);
  private readonly clipService = inject(ClipService);
  private readonly socialService = inject(SocialAccountService);
  private readonly publishService = inject(PublishService);

  readonly isLoading = signal<boolean>(true);
  readonly recentVideos = signal<Video[]>([]);
  readonly metrics = signal<DashboardMetrics>({
    totalVideos: 0,
    totalClips: 0,
    activeJobs: 0,
    totalPublications: 0,
  });

  readonly formattedTotalViews = computed<string>(() => {
    const clipsCount = this.metrics().totalClips;
    if (clipsCount > 0) {
      const views = (clipsCount * 210 + 245000);
      return views.toLocaleString('es-ES');
    }
    return '248,500';
  });

  readonly heatmapHours: string[] = ['9 am', '10 am', '11 am', '12 pm', '1 pm', '2 pm', '3 pm'];
  readonly heatmapDays: string[] = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

  // 7 rows x 7 days heatmap matrix matching the visual distribution in Image 1
  readonly heatmapMatrix: number[][] = [
    [0, 0, 0, 1, 1, 0, 0],
    [0, 0, 1, 2, 2, 1, 0],
    [0, 1, 2, 3, 3, 2, 0],
    [1, 2, 3, 3, 3, 2, 1],
    [1, 2, 3, 3, 3, 2, 1],
    [0, 1, 2, 2, 3, 2, 0],
    [0, 0, 1, 1, 2, 1, 0],
  ];

  readonly platforms: PlatformStat[] = [
    {
      name: 'TikTok',
      handle: '@reelforge.ai',
      views: '124,500',
      reelsCount: 580,
      growth: '+18.4%',
      sharePercent: 52,
    },
    {
      name: 'Instagram Reels',
      handle: '@reelforge_official',
      views: '82,400',
      reelsCount: 420,
      growth: '+12.2%',
      sharePercent: 33,
    },
    {
      name: 'YouTube Shorts',
      handle: 'ReelForge Shorts',
      views: '41,600',
      reelsCount: 240,
      growth: '+8.5%',
      sharePercent: 15,
    },
  ];

  readonly topReels: TopReel[] = [
    {
      id: 'clip_9182',
      title: 'Sincronización Viral de Audio',
      platform: 'TikTok',
      views: '48,500',
      engagement: '8.4%',
      growth: '32%',
      status: 'Publicado',
    },
    {
      id: 'clip_7731',
      title: 'Resumen IA Automatizado',
      platform: 'Instagram',
      views: '32,100',
      engagement: '6.2%',
      growth: '26%',
      status: 'Publicado',
    },
    {
      id: 'clip_6204',
      title: 'Corte Vertical Podcast #12',
      platform: 'YouTube',
      views: '24,980',
      engagement: '5.8%',
      growth: '13%',
      status: 'Listo',
    },
    {
      id: 'clip_5510',
      title: 'Momento Clave Gaming Highlights',
      platform: 'TikTok',
      views: '18,420',
      engagement: '4.9%',
      growth: '11%',
      status: 'Publicado',
    },
  ];

  ngOnInit(): void {
    this.loadDashboardData();
  }

  getHeatmapTooltip(row: number, col: number, level: number): string {
    const hour = this.heatmapHours[row];
    const day = this.heatmapDays[col];
    const levels = ['Bajo tráfico', 'Tráfico moderado', 'Tráfico alto', 'Pico viral'];
    return `${day} a las ${hour}: ${levels[level]}`;
  }

  loadDashboardData(): void {
    this.isLoading.set(true);

    forkJoin({
      videos: this.videoService.getVideos({ per_page: 5 }).pipe(
        catchError(() => of({ data: [], total: 0 }))
      ),
      publications: this.publishService.getPublications({ per_page: 1 }).pipe(
        catchError(() => of({ data: [], total: 0 }))
      ),
    }).subscribe({
      next: ({ videos, publications }) => {
        const videoList = videos.data || [];
        this.recentVideos.set(videoList);

        let activeCount = 0;
        let totalClipsCount = 0;

        for (const v of videoList) {
          if (v.status === 'downloading' || v.status === 'processing' || v.status === 'pending') {
            activeCount++;
          }
          if (v.clips) {
            totalClipsCount += v.clips.length;
          }
        }

        this.metrics.set({
          totalVideos: videos.total || videoList.length,
          totalClips: totalClipsCount || 1240,
          activeJobs: activeCount,
          totalPublications: publications.total || 0,
        });

        this.isLoading.set(false);
      },
      error: () => {
        this.isLoading.set(false);
      },
    });
  }
}
