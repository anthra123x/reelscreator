import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, RouterModule, Router } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { ClipService } from '../../core/services/clip.service';
import { VideoService } from '../../core/services/video.service';
import { Clip } from '../../core/models/clip.model';
import { Video } from '../../core/models/video.model';
import { StatusPillComponent } from '../../shared/components/status-pill/status-pill.component';
import { SkeletonLoaderComponent } from '../../shared/components/skeleton-loader/skeleton-loader.component';
import { VerticalPlayerComponent } from '../../shared/components/vertical-player/vertical-player.component';
import { DurationPipe } from '../../shared/pipes/duration.pipe';

@Component({
  selector: 'app-clips',
  standalone: true,
  imports: [
    CommonModule,
    RouterModule,
    FormsModule,
    StatusPillComponent,
    SkeletonLoaderComponent,
    VerticalPlayerComponent,
    DurationPipe,
  ],
  template: `
    <div class="clips-page">
      <!-- Page Header -->
      <div class="page-header">
        <div>
          <h1 class="page-title">Clip Library</h1>
          <p class="page-subtitle">
            Preview, trim, and publish your generated 9:16 vertical short-form reels.
          </p>
        </div>

        <div class="header-controls">
          <!-- Video Filter Dropdown -->
          <div class="filter-group">
            <label class="filter-label" for="video-filter">Filter Video:</label>
            <select
              id="video-filter"
              class="form-select filter-select"
              [ngModel]="selectedVideoId()"
              (ngModelChange)="onVideoSelect($event)"
            >
              <option value="">All Ingested Videos</option>
              <option *ngFor="let v of availableVideos()" [value]="v.id">
                {{ v.title || v.source_url }}
              </option>
            </select>
          </div>

          <a routerLink="/video-upload" class="btn btn-primary">
            + New Video
          </a>
        </div>
      </div>

      <!-- Loading Skeletons -->
      <div *ngIf="isLoading()" class="clips-grid">
        <app-skeleton-loader shape="card-9-16" [count]="4"></app-skeleton-loader>
      </div>

      <!-- Empty State -->
      <div *ngIf="!isLoading() && clips().length === 0" class="empty-state card">
        <div class="empty-icon">
          <svg viewBox="0 0 24 24" class="svg-icon-lg" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"></rect>
            <line x1="7" y1="2" x2="7" y2="22"></line>
            <line x1="17" y1="2" x2="17" y2="22"></line>
            <line x1="2" y1="12" x2="22" y2="12"></line>
            <line x1="2" y1="7" x2="7" y2="7"></line>
            <line x1="2" y1="17" x2="7" y2="17"></line>
            <line x1="17" y1="17" x2="22" y2="17"></line>
            <line x1="17" y1="7" x2="22" y2="7"></line>
          </svg>
        </div>
        <h2 class="empty-title">No Clips Found</h2>
        <p class="empty-desc">
          {{ selectedVideoId() ? 'This video does not have generated clips yet or is still transcoding.' : 'No short clips produced yet across any videos.' }}
        </p>
        <div class="empty-actions">
          <a routerLink="/video-upload" class="btn btn-primary">Ingest Video Now</a>
          <button
            *ngIf="selectedVideoId()"
            type="button"
            class="btn btn-secondary"
            (click)="onVideoSelect('')"
          >
            Clear Filter
          </button>
        </div>
      </div>

      <!-- Clips Grid (9:16 Cards) -->
      <div *ngIf="!isLoading() && clips().length > 0" class="clips-grid">
        <div *ngFor="let clip of clips()" class="clip-card">
          <!-- 9:16 Media Container -->
          <div class="clip-media-container" (click)="openPreview(clip)">
            <!-- Thumbnail Image -->
            <img
              *ngIf="clip.thumbnail_url"
              [src]="clip.thumbnail_url"
              [alt]="clip.title || 'Clip Thumbnail'"
              class="clip-poster"
              (error)="onThumbnailError($event)"
            />
            <div *ngIf="!clip.thumbnail_url" class="placeholder-poster">
              <span>9:16 Reel</span>
            </div>

            <!-- Top Pill Badges -->
            <div class="media-badges-top">
              <app-status-pill [status]="clip.status"></app-status-pill>
              <span class="duration-badge">{{ clip.duration | duration }}</span>
            </div>

            <!-- Play Icon Hover Overlay -->
            <div class="hover-play-overlay">
              <span class="play-btn-circle">▶</span>
              <span class="preview-text">Click to Preview</span>
            </div>

            <!-- Bottom Time Range Pill -->
            <div class="media-badges-bottom">
              <span class="time-range-pill">
                {{ clip.start_time | duration }} - {{ clip.end_time | duration }}
              </span>
            </div>
          </div>

          <!-- Card Body / Inline Editing / Actions -->
          <div class="clip-body">
            <!-- Inline Title Editor -->
            <div class="title-editor" *ngIf="editingClipId() === clip.id; else displayTitle">
              <input
                type="text"
                class="form-input inline-input"
                [(ngModel)]="editTitle"
                placeholder="Clip Title"
              />
              <div class="trim-inputs">
                <div class="trim-field">
                  <label>Start (s):</label>
                  <input
                    type="number"
                    step="0.5"
                    min="0"
                    class="form-input trim-num"
                    [(ngModel)]="editStartTime"
                  />
                </div>
                <div class="trim-field">
                  <label>End (s):</label>
                  <input
                    type="number"
                    step="0.5"
                    min="0"
                    class="form-input trim-num"
                    [(ngModel)]="editEndTime"
                  />
                </div>
              </div>
              <div class="inline-btn-group">
                <button
                  type="button"
                  class="btn btn-primary btn-xs"
                  (click)="saveEdit(clip)"
                  [disabled]="isUpdating()"
                >
                  Save
                </button>
                <button
                  type="button"
                  class="btn btn-ghost btn-xs"
                  (click)="cancelEdit()"
                  [disabled]="isUpdating()"
                >
                  Cancel
                </button>
              </div>
              <div *ngIf="editErrorMessage()" class="edit-error-msg">
                <svg viewBox="0 0 24 24" class="svg-icon-xs" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path>
                  <line x1="12" y1="9" x2="12" y2="13"></line>
                  <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
                <span>{{ editErrorMessage() }}</span>
              </div>
            </div>

            <ng-template #displayTitle>
              <div class="title-row">
                <h3 class="clip-title" [title]="clip.title || 'Untitled Clip'">
                  {{ clip.title || 'Untitled Reel Clip' }}
                </h3>
                <button
                  type="button"
                  class="btn-icon"
                  (click)="startEdit(clip)"
                  title="Edit title & trim"
                >
                  <svg viewBox="0 0 24 24" class="svg-icon-sm" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 20h9"></path>
                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                  </svg>
                </button>
              </div>
            </ng-template>

            <!-- Card Actions Bar -->
            <div class="card-actions">
              <button
                type="button"
                class="btn btn-secondary btn-sm flex-1"
                (click)="openPreview(clip)"
              >
                <svg viewBox="0 0 24 24" class="svg-icon-sm" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                  <circle cx="12" cy="12" r="3"></circle>
                </svg>
                <span>Preview</span>
              </button>
              <button
                type="button"
                class="btn btn-primary btn-sm flex-1"
                (click)="publishClip(clip)"
              >
                <svg viewBox="0 0 24 24" class="svg-icon-sm" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="22" y1="2" x2="11" y2="13"></line>
                  <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
                <span>Publish</span>
              </button>
              <button
                type="button"
                class="btn btn-danger btn-sm btn-icon-only"
                (click)="deleteClip(clip)"
                title="Delete Clip"
              >
                <svg viewBox="0 0 24 24" class="svg-icon-sm" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="3 6 5 6 21 6"></polyline>
                  <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                </svg>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- 9:16 Vertical Video Player Modal -->
      <app-vertical-player
        *ngIf="previewClip()"
        [src]="previewClip()!.file_url"
        [poster]="previewClip()!.thumbnail_url || undefined"
        [title]="previewClip()!.title || 'Vertical Reel Preview'"
        [showPublish]="true"
        (close)="closePreview()"
        (publish)="publishCurrentPreview()"
      ></app-vertical-player>
    </div>
  `,
  styles: [`
    .clips-page {
      display: flex;
      flex-direction: column;
      gap: 24px;
    }

    .page-header {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    @media (min-width: 768px) {
      .page-header {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
      }
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
      margin-top: 2px;
    }

    .header-controls {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .filter-group {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .filter-label {
      font-size: 12px;
      color: var(--text-muted, #94a3b8);
      white-space: nowrap;
    }

    .filter-select {
      max-width: 240px;
      padding: 6px 12px;
      font-size: 12px;
    }

    .clips-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 24px;
    }

    .clip-card {
      background-color: var(--bg-surface, #0f172a);
      border: 1px solid var(--border-subtle, #1e293b);
      border-radius: var(--radius-md, 12px);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      box-shadow: var(--shadow-card);
      transition: transform 0.15s ease, border-color 0.15s ease;
    }

    .clip-card:hover {
      transform: translateY(-2px);
      border-color: var(--border-strong, #334155);
    }

    .clip-media-container {
      position: relative;
      aspect-ratio: 9 / 16;
      width: 100%;
      background-color: #000000;
      overflow: hidden;
      cursor: pointer;
    }

    .clip-poster {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .placeholder-poster {
      width: 100%;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #1e293b, #0f172a);
      color: var(--text-muted);
      font-size: 14px;
      font-weight: 600;
    }

    .media-badges-top {
      position: absolute;
      top: 10px;
      left: 10px;
      right: 10px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      z-index: 10;
    }

    .duration-badge {
      background-color: rgba(0, 0, 0, 0.75);
      color: #ffffff;
      padding: 3px 8px;
      border-radius: 4px;
      font-size: 11px;
      font-weight: 600;
      font-family: monospace;
      backdrop-filter: blur(4px);
    }

    .media-badges-bottom {
      position: absolute;
      bottom: 10px;
      left: 10px;
      z-index: 10;
    }

    .time-range-pill {
      background-color: rgba(15, 23, 42, 0.8);
      color: #cbd5e1;
      padding: 2px 6px;
      border-radius: 4px;
      font-size: 10px;
      font-family: monospace;
      backdrop-filter: blur(4px);
    }

    .hover-play-overlay {
      position: absolute;
      inset: 0;
      background-color: rgba(0, 0, 0, 0.5);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 8px;
      opacity: 0;
      transition: opacity 0.2s ease;
      z-index: 15;
    }

    .clip-media-container:hover .hover-play-overlay {
      opacity: 1;
    }

    .play-btn-circle {
      width: 52px;
      height: 52px;
      background-color: rgba(79, 70, 229, 0.9);
      color: #ffffff;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      padding-left: 3px;
      box-shadow: 0 4px 15px rgba(0,0,0,0.6);
      transform: scale(0.9);
      transition: transform 0.15s ease;
    }

    .clip-media-container:hover .play-btn-circle {
      transform: scale(1);
    }

    .preview-text {
      font-size: 11px;
      font-weight: 600;
      color: #ffffff;
      text-shadow: 0 1px 3px rgba(0,0,0,0.8);
    }

    .clip-body {
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      background-color: var(--bg-surface, #0f172a);
    }

    .title-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
    }

    .clip-title {
      font-size: 13px;
      font-weight: 600;
      color: #ffffff;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      flex: 1;
    }

    .btn-icon {
      background: transparent;
      border: none;
      cursor: pointer;
      font-size: 13px;
      padding: 2px 4px;
      border-radius: 4px;
    }

    .btn-icon:hover {
      background-color: var(--bg-surface-elevated, #1e293b);
    }

    .title-editor {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .inline-input {
      padding: 6px 10px;
      font-size: 12px;
    }

    .trim-inputs {
      display: flex;
      gap: 8px;
    }

    .trim-field {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .trim-field label {
      font-size: 10px;
      color: var(--text-muted);
    }

    .trim-num {
      padding: 4px 8px;
      font-size: 11px;
    }

    .inline-btn-group {
      display: flex;
      gap: 6px;
      justify-content: flex-end;
    }

    .edit-error-msg {
      font-size: 11px;
      color: #e4e4e7;
      background-color: #27272a;
      border: 1px solid #3f3f46;
      border-radius: 4px;
      padding: 4px 6px;
      line-height: 1.3;
    }

    .btn-xs {
      padding: 3px 8px;
      font-size: 11px;
    }

    .card-actions {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .flex-1 {
      flex: 1;
    }

    .btn-sm {
      padding: 6px 10px;
      font-size: 12px;
    }

    .btn-icon-only {
      padding: 6px 10px;
      font-size: 12px;
    }

    .empty-state {
      padding: 60px 20px;
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
    }

    .empty-icon {
      font-size: 48px;
      margin-bottom: 12px;
    }

    .empty-title {
      font-size: 18px;
      font-weight: 700;
      color: #ffffff;
    }

    .empty-desc {
      font-size: 13px;
      color: var(--text-muted, #94a3b8);
      max-width: 400px;
      margin-top: 4px;
    }

    .empty-actions {
      display: flex;
      gap: 12px;
      margin-top: 20px;
    }

    .svg-icon-lg {
      width: 48px;
      height: 48px;
      color: var(--text-faint, #71717a);
    }

    .svg-icon-sm {
      width: 14px;
      height: 14px;
    }

    .svg-icon-xs {
      width: 12px;
      height: 12px;
    }
  `],
})
export class ClipsComponent implements OnInit {
  private readonly clipService = inject(ClipService);
  private readonly videoService = inject(VideoService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  readonly isLoading = signal<boolean>(true);
  readonly isUpdating = signal<boolean>(false);
  readonly clips = signal<Clip[]>([]);
  readonly availableVideos = signal<Video[]>([]);
  readonly selectedVideoId = signal<string>('');

  // Inline edit state
  readonly editingClipId = signal<string | null>(null);
  readonly editErrorMessage = signal<string | null>(null);
  editTitle = '';
  editStartTime = 0;
  editEndTime = 0;

  // 9:16 Video player modal state
  readonly previewClip = signal<Clip | null>(null);

  ngOnInit(): void {
    // Check query params for videoId
    this.route.queryParams.subscribe((params) => {
      const vid = params['videoId'] || '';
      this.selectedVideoId.set(vid);
      this.loadInitialData(vid);
    });
  }

  loadInitialData(videoIdFilter?: string): void {
    this.isLoading.set(true);

    // Fetch video list to populate the filter dropdown
    this.videoService.getVideos({ per_page: 50 }).subscribe({
      next: (res) => {
        const vList = res.data || [];
        this.availableVideos.set(vList);

        if (videoIdFilter) {
          this.fetchClipsForVideo(videoIdFilter);
        } else if (vList.length > 0) {
          // If no filter, aggregate clips from all videos
          this.fetchAllClips(vList);
        } else {
          this.clips.set([]);
          this.isLoading.set(false);
        }
      },
      error: () => {
        this.isLoading.set(false);
      },
    });
  }

  private fetchClipsForVideo(videoId: string): void {
    this.clipService.getClipsByVideo(videoId).subscribe({
      next: (data) => {
        this.clips.set(data || []);
        this.isLoading.set(false);
      },
      error: () => {
        this.clips.set([]);
        this.isLoading.set(false);
      },
    });
  }

  private fetchAllClips(videos: Video[]): void {
    const allClips: Clip[] = [];
    for (const v of videos) {
      if (v.clips && v.clips.length > 0) {
        allClips.push(...v.clips);
      }
    }
    this.clips.set(allClips);
    this.isLoading.set(false);
  }

  onVideoSelect(videoId: string): void {
    this.selectedVideoId.set(videoId);
    this.router.navigate([], {
      relativeTo: this.route,
      queryParams: videoId ? { videoId } : {},
    });
  }

  openPreview(clip: Clip): void {
    this.previewClip.set(clip);
  }

  closePreview(): void {
    this.previewClip.set(null);
  }

  publishCurrentPreview(): void {
    const clip = this.previewClip();
    if (clip) {
      this.closePreview();
      this.publishClip(clip);
    }
  }

  publishClip(clip: Clip): void {
    this.router.navigate(['/publish'], {
      queryParams: { clipId: clip.id },
    });
  }

  startEdit(clip: Clip): void {
    this.editingClipId.set(clip.id);
    this.editTitle = clip.title || '';
    this.editStartTime = clip.start_time;
    this.editEndTime = clip.end_time;
    this.editErrorMessage.set(null);
  }

  cancelEdit(): void {
    this.editingClipId.set(null);
    this.editErrorMessage.set(null);
  }

  saveEdit(clip: Clip): void {
    const start = Number(this.editStartTime);
    const end = Number(this.editEndTime);

    if (isNaN(start) || isNaN(end) || end <= start) {
      this.editErrorMessage.set('End time must be greater than start time.');
      return;
    }

    this.isUpdating.set(true);
    this.editErrorMessage.set(null);
    this.clipService
      .updateClip(clip.id, {
        title: this.editTitle,
        start_time: start,
        end_time: end,
      })
      .subscribe({
        next: (updated) => {
          this.clips.update((list) =>
            list.map((c) => (c.id === clip.id ? { ...c, ...updated } : c))
          );
          this.isUpdating.set(false);
          this.editingClipId.set(null);
          this.editErrorMessage.set(null);
        },
        error: (err) => {
          this.isUpdating.set(false);
          this.editErrorMessage.set(err?.message || 'Failed to update clip. Please check your inputs.');
        },
      });
  }

  deleteClip(clip: Clip): void {
    if (!confirm(`Are you sure you want to delete clip "${clip.title || clip.id}"?`)) {
      return;
    }

    this.clipService.deleteClip(clip.id).subscribe({
      next: () => {
        this.clips.update((list) => list.filter((c) => c.id !== clip.id));
      },
    });
  }

  onThumbnailError(event: Event): void {
    const img = event.target as HTMLImageElement;
    img.style.display = 'none';
  }
}
