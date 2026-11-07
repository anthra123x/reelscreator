import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, RouterModule } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { PublishService } from '../../core/services/publish.service';
import { ClipService } from '../../core/services/clip.service';
import { SocialAccountService } from '../../core/services/social-account.service';
import { VideoService } from '../../core/services/video.service';
import { SocialPlatform } from '../../core/models/common.model';
import { Publication, PublishClipRequest } from '../../core/models/publication.model';
import { Clip } from '../../core/models/clip.model';
import { SocialAccount } from '../../core/models/social-account.model';
import { StatusPillComponent } from '../../shared/components/status-pill/status-pill.component';
import { SkeletonLoaderComponent } from '../../shared/components/skeleton-loader/skeleton-loader.component';
import { DurationPipe } from '../../shared/pipes/duration.pipe';
import { RelativeTimePipe } from '../../shared/pipes/relative-time.pipe';

@Component({
  selector: 'app-publish',
  standalone: true,
  imports: [
    CommonModule,
    RouterModule,
    FormsModule,
    StatusPillComponent,
    SkeletonLoaderComponent,
    DurationPipe,
    RelativeTimePipe,
  ],
  template: `
    <div class="publish-page">
      <!-- Page Header -->
      <div class="page-header">
        <div>
          <h1 class="page-title">Publishing & Multi-Channel Distribution</h1>
          <p class="page-subtitle">
            Dispatch 9:16 vertical reels to TikTok, Instagram Reels, and YouTube Shorts simultaneously.
          </p>
        </div>
      </div>

      <div class="publish-layout">
        <!-- Publishing Dispatch Form Card -->
        <div class="card publish-card">
          <h2 class="card-title">Queue Reel Publication</h2>

          <form (ngSubmit)="onPublishSubmit()">
            <!-- Clip Selector -->
            <div class="form-group">
              <label class="form-label" for="clip-select">Select Clip to Publish *</label>
              <select
                id="clip-select"
                class="form-select"
                [(ngModel)]="selectedClipId"
                (ngModelChange)="onClipSelected($event)"
                name="selectedClipId"
                required
              >
                <option value="">-- Choose a ready reel clip --</option>
                <option *ngFor="let clip of availableClips()" [value]="clip.id">
                  {{ clip.title || 'Untitled Clip' }} ({{ clip.duration | duration }})
                </option>
              </select>
            </div>

            <!-- Previews Selected Clip -->
            <div *ngIf="activeClip" class="selected-clip-preview">
              <div class="mini-thumb-wrap">
                <img
                  *ngIf="activeClip.thumbnail_url"
                  [src]="activeClip.thumbnail_url"
                  alt="Clip thumbnail"
                  class="mini-thumb"
                />
                <span class="mini-duration">{{ activeClip.duration | duration }}</span>
              </div>
              <div class="mini-info">
                <span class="mini-title">{{ activeClip.title || 'Untitled Clip' }}</span>
                <span class="mini-sub">
                  Segment: {{ activeClip.start_time | duration }} - {{ activeClip.end_time | duration }}
                </span>
              </div>
            </div>

            <!-- Target Platforms Checkboxes -->
            <div class="form-group">
              <label class="form-label">Target Destinations *</label>
              <div class="platforms-checkbox-group">
                <label
                  class="platform-check-item"
                  [class.checked]="isPlatformSelected('tiktok')"
                >
                  <input
                    type="checkbox"
                    [checked]="isPlatformSelected('tiktok')"
                    (change)="togglePlatform('tiktok')"
                  />
                  <span class="platform-icon">
                    <svg viewBox="0 0 24 24" class="svg-icon-sm" fill="currentColor">
                      <path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64c.298-.002.595.042.88.13V9.4a6.33 6.33 0 0 0-1-.08A6.34 6.34 0 0 0 3 15.66a6.34 6.34 0 0 0 10.82 4.47 6.27 6.27 0 0 0 1.94-4.47V8.5a8.28 8.28 0 0 0 4.83 1.55v-3.36z"/>
                    </svg>
                  </span>
                  <div class="platform-text">
                    <span class="name">TikTok</span>
                    <span class="handle">
                      {{ getAccountUsername('tiktok') || '(Auto-Mock Fallback)' }}
                    </span>
                  </div>
                </label>

                <label
                  class="platform-check-item"
                  [class.checked]="isPlatformSelected('instagram')"
                >
                  <input
                    type="checkbox"
                    [checked]="isPlatformSelected('instagram')"
                    (change)="togglePlatform('instagram')"
                  />
                  <span class="platform-icon">
                    <svg viewBox="0 0 24 24" class="svg-icon-sm" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect>
                      <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path>
                      <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line>
                    </svg>
                  </span>
                  <div class="platform-text">
                    <span class="name">Instagram Reels</span>
                    <span class="handle">
                      {{ getAccountUsername('instagram') || '(Auto-Mock Fallback)' }}
                    </span>
                  </div>
                </label>

                <label
                  class="platform-check-item"
                  [class.checked]="isPlatformSelected('youtube')"
                >
                  <input
                    type="checkbox"
                    [checked]="isPlatformSelected('youtube')"
                    (change)="togglePlatform('youtube')"
                  />
                  <span class="platform-icon">
                    <svg viewBox="0 0 24 24" class="svg-icon-sm" fill="currentColor">
                      <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                    </svg>
                  </span>
                  <div class="platform-text">
                    <span class="name">YouTube Shorts</span>
                    <span class="handle">
                      {{ getAccountUsername('youtube') || '(Auto-Mock Fallback)' }}
                    </span>
                  </div>
                </label>
              </div>
              <span *ngIf="selectedPlatforms.length === 0" class="field-error">
                Select at least one platform.
              </span>
            </div>

            <!-- Caption & Hashtags -->
            <div class="form-group">
              <div class="caption-header">
                <label class="form-label" for="caption">Reel Caption & Hashtags</label>
                <span class="char-count">{{ caption.length }} / 2200</span>
              </div>
              <textarea
                id="caption"
                class="form-textarea"
                rows="3"
                [(ngModel)]="caption"
                name="caption"
                placeholder="Write an engaging caption for your reel..."
                maxlength="2200"
              ></textarea>

              <!-- Hashtag quick chips -->
              <div class="hashtag-chips">
                <button
                  *ngFor="let tag of quickHashtags"
                  type="button"
                  class="tag-chip"
                  (click)="appendHashtag(tag)"
                >
                  {{ tag }}
                </button>
              </div>
            </div>

            <!-- Scheduling Controls -->
            <div class="form-group">
              <label class="form-label">Dispatch Mode</label>
              <div class="radio-row">
                <label class="radio-label">
                  <input
                    type="radio"
                    name="scheduleMode"
                    [value]="'now'"
                    [(ngModel)]="scheduleMode"
                  />
                  <span>Publish Immediately</span>
                </label>

                <label class="radio-label">
                  <input
                    type="radio"
                    name="scheduleMode"
                    [value]="'later'"
                    [(ngModel)]="scheduleMode"
                  />
                  <span>Schedule for Later</span>
                </label>
              </div>

              <div *ngIf="scheduleMode === 'later'" class="datetime-picker-wrap">
                <label class="form-label" for="scheduled_at">Scheduled Date & Time (UTC/Local):</label>
                <input
                  id="scheduled_at"
                  type="datetime-local"
                  class="form-input"
                  [(ngModel)]="scheduledAt"
                  name="scheduledAt"
                  [min]="minDateTime"
                  required
                />
              </div>
            </div>

            <!-- Success / Error Notice -->
            <div *ngIf="publishSuccessMessage()" class="alert-success">
              <svg viewBox="0 0 24 24" class="svg-icon-xs" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"></polyline>
              </svg>
              <span>{{ publishSuccessMessage() }}</span>
            </div>
            <div *ngIf="publishErrorMessage()" class="alert-error">
              <svg viewBox="0 0 24 24" class="svg-icon-xs" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path>
                <line x1="12" y1="9" x2="12" y2="13"></line>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
              </svg>
              <span>{{ publishErrorMessage() }}</span>
            </div>

            <div class="form-actions">
              <button
                type="submit"
                class="btn btn-primary btn-submit-publish"
                [disabled]="!selectedClipId || selectedPlatforms.length === 0 || (scheduleMode === 'later' && !scheduledAt) || isPublishing()"
              >
                <svg viewBox="0 0 24 24" class="svg-icon-sm" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="22" y1="2" x2="11" y2="13"></line>
                  <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
                <span>{{ isPublishing() ? 'Queueing Dispatches...' : 'Dispatch Publication' }}</span>
              </button>
            </div>
          </form>
        </div>

        <!-- Publication Audit Log Table -->
        <div class="card history-card">
          <div class="history-header">
            <h2 class="card-title">Publication Audit Log</h2>
            <button
              type="button"
              class="btn btn-secondary btn-xs"
              (click)="loadPublications()"
              [disabled]="isLoadingPublications()"
            >
              <svg viewBox="0 0 24 24" class="svg-icon-xs" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="23 4 23 10 17 10"></polyline>
                <polyline points="1 20 1 14 7 14"></polyline>
                <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
              </svg>
              <span>Refresh Log</span>
            </button>
          </div>

          <div *ngIf="isLoadingPublications()" class="loading-wrap">
            <app-skeleton-loader shape="table-row" [count]="4"></app-skeleton-loader>
          </div>

          <div *ngIf="!isLoadingPublications() && publications().length === 0" class="empty-log">
            <p>No publications recorded yet.</p>
          </div>

          <div *ngIf="!isLoadingPublications() && publications().length > 0" class="table-responsive">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Clip</th>
                  <th>Platform</th>
                  <th>Channel Account</th>
                  <th>Scheduled / Published</th>
                  <th>Status</th>
                  <th class="text-right">Action</th>
                </tr>
              </thead>
              <tbody>
                <tr *ngFor="let pub of publications()">
                  <td>
                    <span class="clip-name">{{ pub.clip?.title || pub.clip_id }}</span>
                  </td>
                  <td>
                    <span class="platform-pill" [ngClass]="pub.social_account?.platform || ''">
                      {{ pub.social_account?.platform || 'Social' }}
                    </span>
                  </td>
                  <td>
                    <span class="account-name">
                      {{ pub.social_account ? '@' + pub.social_account.platform_username : 'Mock Channel' }}
                    </span>
                  </td>
                  <td class="date-cell">
                    {{ (pub.published_at || pub.scheduled_at || pub.created_at) | relativeTime }}
                  </td>
                  <td>
                    <app-status-pill [status]="pub.status"></app-status-pill>
                  </td>
                  <td class="text-right">
                    <button
                      *ngIf="pub.status === 'failed'"
                      type="button"
                      class="btn btn-secondary btn-xs"
                      (click)="retry(pub)"
                    >
                      Retry
                    </button>
                    <span *ngIf="pub.status !== 'failed'" class="ok-mark">
                      <svg viewBox="0 0 24 24" class="svg-icon-xs" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                      </svg>
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .publish-page {
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

    .publish-layout {
      display: grid;
      grid-template-columns: 1fr;
      gap: 24px;
    }

    @media (min-width: 1024px) {
      .publish-layout {
        grid-template-columns: 1fr 1.2fr;
      }
    }

    .card-title {
      font-size: 15px;
      font-weight: 600;
      color: #ffffff;
      border-bottom: 1px solid var(--border-subtle, #1e293b);
      padding-bottom: 12px;
      margin-bottom: 16px;
    }

    .selected-clip-preview {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px;
      background-color: var(--bg-surface-elevated, #1e293b);
      border-radius: var(--radius-sm, 8px);
      margin-bottom: 16px;
      border: 1px solid var(--border-strong, #334155);
    }

    .mini-thumb-wrap {
      position: relative;
      width: 44px;
      aspect-ratio: 9 / 16;
      background-color: #000;
      border-radius: 4px;
      overflow: hidden;
      flex-shrink: 0;
    }

    .mini-thumb {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .mini-duration {
      position: absolute;
      bottom: 2px;
      right: 2px;
      background: rgba(0,0,0,0.8);
      color: #fff;
      font-size: 8px;
      padding: 1px 3px;
      border-radius: 2px;
      font-family: monospace;
    }

    .mini-info {
      display: flex;
      flex-direction: column;
      gap: 2px;
      overflow: hidden;
    }

    .mini-title {
      font-size: 13px;
      font-weight: 600;
      color: #ffffff;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .mini-sub {
      font-size: 11px;
      color: var(--text-muted, #94a3b8);
      font-family: monospace;
    }

    .platforms-checkbox-group {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-top: 6px;
    }

    .platform-check-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 14px;
      background-color: var(--bg-surface-elevated, #1e293b);
      border: 1px solid var(--border-subtle, #1e293b);
      border-radius: 8px;
      cursor: pointer;
      transition: border-color 0.15s ease, background-color 0.15s ease;
    }

    .platform-check-item.checked {
      border-color: #ffffff;
      background-color: #27272a;
    }

    .platform-icon {
      font-size: 20px;
    }

    .platform-text {
      display: flex;
      flex-direction: column;
    }

    .platform-text .name {
      font-size: 13px;
      font-weight: 600;
      color: #ffffff;
    }

    .platform-text .handle {
      font-size: 11px;
      color: var(--text-muted, #94a3b8);
    }

    .field-error {
      font-size: 11px;
      color: #f87171;
      margin-top: 4px;
      display: block;
    }

    .caption-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .char-count {
      font-size: 11px;
      color: var(--text-faint, #64748b);
      font-family: monospace;
    }

    .hashtag-chips {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      margin-top: 6px;
    }

    .tag-chip {
      background-color: var(--bg-surface-elevated, #1e293b);
      border: 1px solid var(--border-strong, #334155);
      color: #a78bfa;
      padding: 3px 8px;
      border-radius: 4px;
      font-size: 11px;
      cursor: pointer;
      font-weight: 500;
    }

    .tag-chip:hover {
      background-color: var(--bg-surface-hover, #334155);
    }

    .radio-row {
      display: flex;
      gap: 20px;
      margin-top: 6px;
    }

    .radio-label {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 13px;
      color: var(--text-muted, #94a3b8);
      cursor: pointer;
    }

    .datetime-picker-wrap {
      margin-top: 12px;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .alert-success {
      background-color: rgba(16, 185, 129, 0.15);
      border: 1px solid rgba(16, 185, 129, 0.3);
      color: #34d399;
      padding: 10px 14px;
      border-radius: 6px;
      font-size: 12px;
      margin-bottom: 12px;
    }

    .alert-error {
      background-color: rgba(239, 68, 68, 0.15);
      border: 1px solid rgba(239, 68, 68, 0.3);
      color: #f87171;
      padding: 10px 14px;
      border-radius: 6px;
      font-size: 12px;
      margin-bottom: 12px;
    }

    .btn-submit-publish {
      width: 100%;
      padding: 12px;
      font-size: 14px;
      font-weight: 600;
    }

    .history-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
    }

    .history-header .card-title {
      border: none;
      margin-bottom: 0;
      padding-bottom: 0;
    }

    .btn-xs {
      padding: 4px 8px;
      font-size: 11px;
    }

    .table-responsive {
      overflow-x: auto;
    }

    .data-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      font-size: 13px;
    }

    .data-table th {
      padding: 10px 12px;
      border-bottom: 1px solid var(--border-subtle, #1e293b);
      color: var(--text-muted, #94a3b8);
      font-size: 11px;
      font-weight: 500;
    }

    .data-table td {
      padding: 12px;
      border-bottom: 1px solid var(--border-subtle, #1e293b);
      vertical-align: middle;
    }

    .clip-name {
      font-weight: 600;
      color: #ffffff;
      max-width: 160px;
      display: block;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .platform-pill {
      font-size: 10px;
      font-weight: 600;
      padding: 2px 6px;
      border-radius: 4px;
      text-transform: uppercase;
      background-color: var(--bg-surface-elevated, #1e293b);
    }

    .account-name {
      font-family: monospace;
      font-size: 11px;
      color: #94a3b8;
    }

    .date-cell {
      font-size: 11px;
      color: var(--text-muted, #94a3b8);
    }

    .ok-mark {
      color: #ffffff;
      font-weight: 700;
    }

    .text-right {
      text-align: right;
    }

    .empty-log {
      padding: 40px 16px;
      text-align: center;
      color: var(--text-muted, #94a3b8);
      font-size: 13px;
    }
  `],
})
export class PublishComponent implements OnInit {
  private readonly publishService = inject(PublishService);
  private readonly clipService = inject(ClipService);
  private readonly videoService = inject(VideoService);
  private readonly socialService = inject(SocialAccountService);
  private readonly route = inject(ActivatedRoute);

  readonly availableClips = signal<Clip[]>([]);
  readonly connectedAccounts = signal<SocialAccount[]>([]);
  readonly publications = signal<Publication[]>([]);
  readonly isLoadingPublications = signal<boolean>(true);
  readonly isPublishing = signal<boolean>(false);
  readonly publishSuccessMessage = signal<string | null>(null);
  readonly publishErrorMessage = signal<string | null>(null);

  selectedClipId = '';
  activeClip: Clip | null = null;
  selectedPlatforms: SocialPlatform[] = ['tiktok', 'instagram'];
  caption = 'Check out this awesome vertical clip! #reels #shorts #fyp';
  scheduleMode: 'now' | 'later' = 'now';
  scheduledAt = '';

  readonly quickHashtags = ['#reels', '#shorts', '#fyp', '#viral', '#trending', '#video'];

  ngOnInit(): void {
    this.loadClips();
    this.loadAccounts();
    this.loadPublications();

    this.route.queryParams.subscribe((params) => {
      const cId = params['clipId'];
      if (cId) {
        this.selectedClipId = cId;
        this.fetchClipDetails(cId);
      }
    });
  }

  loadClips(): void {
    this.videoService.getVideos({ per_page: 50 }).subscribe({
      next: (res) => {
        const vList = res.data || [];
        const clips: Clip[] = [];
        for (const v of vList) {
          if (v.clips && v.clips.length > 0) {
            clips.push(...v.clips);
          }
        }
        this.availableClips.set(clips);

        if (this.selectedClipId && !this.activeClip) {
          this.activeClip = clips.find((c) => c.id === this.selectedClipId) || null;
        }
      },
    });
  }

  loadAccounts(): void {
    this.socialService.getAccounts().subscribe({
      next: (list) => {
        this.connectedAccounts.set(list || []);
      },
    });
  }

  loadPublications(): void {
    this.isLoadingPublications.set(true);
    this.publishService.getPublications({ per_page: 25 }).subscribe({
      next: (res) => {
        this.publications.set(res.data || []);
        this.isLoadingPublications.set(false);
      },
      error: () => {
        this.isLoadingPublications.set(false);
      },
    });
  }

  fetchClipDetails(clipId: string): void {
    this.clipService.getClip(clipId).subscribe({
      next: (c) => {
        this.activeClip = c;
      },
    });
  }

  onClipSelected(id: string): void {
    this.selectedClipId = id;
    this.activeClip = this.availableClips().find((c) => c.id === id) || null;
    if (!this.activeClip && id) {
      this.fetchClipDetails(id);
    }
  }

  isPlatformSelected(platform: SocialPlatform): boolean {
    return this.selectedPlatforms.includes(platform);
  }

  togglePlatform(platform: SocialPlatform): void {
    if (this.isPlatformSelected(platform)) {
      this.selectedPlatforms = this.selectedPlatforms.filter((p) => p !== platform);
    } else {
      this.selectedPlatforms.push(platform);
    }
  }

  getAccountUsername(platform: SocialPlatform): string | null {
    const acc = this.connectedAccounts().find((a) => a.platform === platform);
    return acc ? `@${acc.platform_username}` : null;
  }

  get minDateTime(): string {
    const now = new Date();
    const pad = (n: number) => n.toString().padStart(2, '0');
    const year = now.getFullYear();
    const month = pad(now.getMonth() + 1);
    const day = pad(now.getDate());
    const hours = pad(now.getHours());
    const minutes = pad(now.getMinutes());
    return `${year}-${month}-${day}T${hours}:${minutes}`;
  }

  isScheduledAtValid(): boolean {
    if (this.scheduleMode !== 'later') {
      return true;
    }
    if (!this.scheduledAt) {
      return false;
    }
    const time = new Date(this.scheduledAt).getTime();
    return !isNaN(time) && time > Date.now();
  }

  appendHashtag(tag: string): void {
    if (!this.caption.includes(tag)) {
      this.caption = (this.caption + ' ' + tag).trim();
    }
  }

  onPublishSubmit(): void {
    if (this.isPublishing()) {
      return;
    }

    if (!this.selectedClipId || this.selectedPlatforms.length === 0) {
      return;
    }

    if (this.scheduleMode === 'later') {
      if (!this.scheduledAt) {
        this.publishErrorMessage.set('Please select a scheduled date and time.');
        return;
      }
      const scheduledTime = new Date(this.scheduledAt).getTime();
      if (isNaN(scheduledTime) || scheduledTime <= Date.now()) {
        this.publishErrorMessage.set('Scheduled date and time must be in the future.');
        return;
      }
    }

    this.isPublishing.set(true);
    this.publishSuccessMessage.set(null);
    this.publishErrorMessage.set(null);

    const payload: PublishClipRequest = {
      platforms: this.selectedPlatforms,
      caption: this.caption.trim() || null,
      scheduled_at: this.scheduleMode === 'later' && this.scheduledAt ? new Date(this.scheduledAt).toISOString() : null,
    };

    this.publishService.publishClip(this.selectedClipId, payload).subscribe({
      next: (res) => {
        this.isPublishing.set(false);
        this.publishSuccessMessage.set(
          `Publication queued successfully for ${this.selectedPlatforms.join(', ')} (IDs: ${res.publication_ids.join(', ')})`
        );
        this.loadPublications();
        setTimeout(() => this.publishSuccessMessage.set(null), 5000);
      },
      error: (err) => {
        this.isPublishing.set(false);
        this.publishErrorMessage.set(err.message || 'Failed to dispatch publication');
      },
    });
  }

  retry(pub: Publication): void {
    this.publishService.retryPublication(pub.id).subscribe({
      next: () => {
        this.loadPublications();
      },
    });
  }
}
