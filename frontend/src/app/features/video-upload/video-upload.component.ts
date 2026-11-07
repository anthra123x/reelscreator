import { Component, OnDestroy, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import {
  FormBuilder,
  FormGroup,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import { RouterModule } from '@angular/router';
import { Subscription } from 'rxjs';
import { VideoService } from '../../core/services/video.service';
import { CuttingStrategy, VerticalMode, VideoStatus } from '../../core/models/common.model';
import { VideoIngestRequest } from '../../core/models/video.model';
import { StatusPillComponent } from '../../shared/components/status-pill/status-pill.component';

@Component({
  selector: 'app-video-upload',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, RouterModule, StatusPillComponent],
  template: `
    <div class="video-upload-page">
      <div class="page-header">
        <h1 class="page-title">Video Ingestion & Clipping Pipeline</h1>
        <p class="page-subtitle">
          Submit a source video URL to automatically download, transcode, and slice into 9:16 vertical reels.
        </p>
      </div>

      <div class="upload-layout">
        <!-- Ingestion Form Container -->
        <div class="card form-card">
          <form [formGroup]="uploadForm" (ngSubmit)="onSubmit()">
            <!-- Source URL Input -->
            <div class="form-group">
              <label class="form-label" for="source_url">
                Source Video URL <span class="required">*</span>
              </label>
              <input
                id="source_url"
                type="url"
                class="form-input"
                placeholder="https://www.youtube.com/watch?v=... or direct MP4 link"
                formControlName="source_url"
              />
              <div
                *ngIf="uploadForm.get('source_url')?.invalid && uploadForm.get('source_url')?.touched"
                class="field-error"
              >
                A valid HTTP/HTTPS URL is required.
              </div>
            </div>

            <!-- Optional Title -->
            <div class="form-group">
              <label class="form-label" for="title">Title (Optional)</label>
              <input
                id="title"
                type="text"
                class="form-input"
                placeholder="e.g. Masterclass Episode 4: AI Revolution"
                formControlName="title"
              />
            </div>

            <div class="form-row-2">
              <!-- Slicing Strategy -->
              <div class="form-group">
                <label class="form-label" for="strategy">Clipping Strategy</label>
                <select id="strategy" class="form-select" formControlName="strategy">
                  <option value="fixed_interval">Fixed Interval (Uniform slices)</option>
                  <option value="scene_detection">Scene Detection (Visual cut)</option>
                  <option value="silence_detection">Silence Detection (Audio pauses)</option>
                  <option value="manual_timestamps">Manual Timestamps</option>
                </select>
              </div>

              <!-- Vertical 9:16 Transformation Mode -->
              <div class="form-group">
                <label class="form-label" for="vertical_mode">9:16 Aspect Mode</label>
                <select id="vertical_mode" class="form-select" formControlName="vertical_mode">
                  <option value="blur_padding">Blur Padding (Sharp center + blurred edges)</option>
                  <option value="center_crop">Center Crop (1080x1920 center view)</option>
                  <option value="letterbox">Letterbox (Matte top & bottom borders)</option>
                </select>
              </div>
            </div>

            <!-- Interval & Duration Sliders -->
            <div class="form-row-2">
              <!-- Interval Seconds -->
              <div class="form-group">
                <div class="slider-header">
                  <label class="form-label" for="interval_seconds">Interval Duration</label>
                  <span class="slider-value">{{ uploadForm.get('interval_seconds')?.value }}s</span>
                </div>
                <input
                  id="interval_seconds"
                  type="range"
                  class="range-slider"
                  min="5"
                  max="600"
                  step="5"
                  formControlName="interval_seconds"
                />
                <span class="slider-hint">Slice duration (5s - 600s)</span>
              </div>

              <!-- Max Duration Seconds -->
              <div class="form-group">
                <div class="slider-header">
                  <label class="form-label" for="max_duration_seconds">Max Reel Duration</label>
                  <span class="slider-value">{{ uploadForm.get('max_duration_seconds')?.value }}s</span>
                </div>
                <input
                  id="max_duration_seconds"
                  type="range"
                  class="range-slider"
                  min="5"
                  max="600"
                  step="5"
                  formControlName="max_duration_seconds"
                />
                <span class="slider-hint">Target clip cap (5s - 600s)</span>
              </div>
            </div>

            <div class="form-actions">
              <button
                type="submit"
                class="btn btn-primary submit-btn"
                [disabled]="uploadForm.invalid || isSubmitting()"
              >
                <svg
                  *ngIf="isSubmitting()"
                  viewBox="0 0 24 24"
                  class="svg-icon-sm animate-spin"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <line x1="12" y1="2" x2="12" y2="6"></line>
                  <line x1="12" y1="18" x2="12" y2="22"></line>
                  <line x1="4.93" y1="4.93" x2="7.76" y2="7.76"></line>
                  <line x1="16.24" y1="16.24" x2="19.07" y2="19.07"></line>
                  <line x1="2" y1="12" x2="6" y2="12"></line>
                  <line x1="18" y1="12" x2="22" y2="12"></line>
                  <line x1="4.93" y1="19.07" x2="7.76" y2="16.24"></line>
                  <line x1="16.24" y1="7.76" x2="19.07" y2="4.93"></line>
                </svg>
                <svg
                  *ngIf="!isSubmitting()"
                  viewBox="0 0 24 24"
                  class="svg-icon-sm"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <line x1="22" y1="2" x2="11" y2="13"></line>
                  <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
                <span>{{ isSubmitting() ? 'Submitting to Queue...' : 'Start Reel Ingestion' }}</span>
              </button>
            </div>
          </form>
        </div>

        <!-- Pipeline Progress Status Card -->
        <div class="card progress-card" *ngIf="activeJobId() || submissionError()">
          <h2 class="card-title">Processing Pipeline Status</h2>

          <!-- Error Alert -->
          <div *ngIf="submissionError()" class="alert-error">
            <svg viewBox="0 0 24 24" class="svg-icon alert-icon-svg" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path>
              <line x1="12" y1="9" x2="12" y2="13"></line>
              <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <div class="alert-content">
              <strong>Pipeline Error:</strong>
              <p>{{ submissionError() }}</p>
            </div>
          </div>

          <!-- Progress State -->
          <div *ngIf="activeJobId() && !submissionError()" class="progress-container">
            <div class="job-meta">
              <span class="job-id-label">Job ID: <code class="job-id">{{ activeJobId() }}</code></span>
              <app-status-pill [status]="jobStatus()" [pulse]="isProcessing()"></app-status-pill>
            </div>

            <!-- Animated Progress Bar -->
            <div class="progress-bar-bg">
              <div
                class="progress-bar-fill"
                [style.width.%]="progressPercent()"
                [class.completed]="jobStatus() === 'ready'"
                [class.failed]="jobStatus() === 'failed'"
              ></div>
            </div>

            <div class="progress-info">
              <span class="progress-pct">{{ progressPercent() }}%</span>
              <span class="status-phase">{{ currentPhaseLabel() }}</span>
            </div>

            <!-- Success Box -->
            <div *ngIf="jobStatus() === 'ready'" class="success-box">
              <div class="success-icon">
                <svg viewBox="0 0 24 24" class="svg-icon" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                  <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
              </div>
              <div class="success-text">
                <span class="success-title">Video Processing Complete!</span>
                <span class="success-sub">9:16 vertical clips are ready for preview and publishing.</span>
              </div>
              <a
                [routerLink]="['/clips']"
                [queryParams]="{ videoId: activeJobId() }"
                class="btn btn-primary btn-view-clips"
              >
                View Generated Clips →
              </a>
            </div>

            <!-- Failure Box -->
            <div *ngIf="jobStatus() === 'failed'" class="failed-box">
              <div class="failed-icon">
                <svg viewBox="0 0 24 24" class="svg-icon" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="12" cy="12" r="10"></circle>
                  <line x1="15" y1="9" x2="9" y2="15"></line>
                  <line x1="9" y1="9" x2="15" y2="15"></line>
                </svg>
              </div>
              <p class="failed-text">{{ jobErrorMessage() || 'Processing encountered an error.' }}</p>
              <button type="button" class="btn btn-secondary btn-sm" (click)="resetForm()">
                Try Again
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .video-upload-page {
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

    .upload-layout {
      display: grid;
      grid-template-columns: 1fr;
      gap: 24px;
    }

    @media (min-width: 992px) {
      .upload-layout {
        grid-template-columns: 1.2fr 0.8fr;
      }
    }

    .form-card {
      display: flex;
      flex-direction: column;
    }

    .required {
      color: #f87171;
    }

    .field-error {
      font-size: 11px;
      color: #f87171;
      margin-top: 4px;
    }

    .form-row-2 {
      display: grid;
      grid-template-columns: 1fr;
      gap: 16px;
    }

    @media (min-width: 600px) {
      .form-row-2 {
        grid-template-columns: 1fr 1fr;
      }
    }

    .slider-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .slider-value {
      font-size: 12px;
      font-weight: 600;
      color: #ffffff;
      background-color: #27272a;
      padding: 2px 8px;
      border-radius: 4px;
      font-family: monospace;
    }

    .range-slider {
      width: 100%;
      height: 6px;
      accent-color: #ffffff;
      cursor: pointer;
      margin-top: 6px;
    }

    .slider-hint {
      font-size: 11px;
      color: var(--text-faint, #64748b);
      margin-top: 2px;
    }

    .form-actions {
      margin-top: 12px;
    }

    .submit-btn {
      width: 100%;
      padding: 12px;
      font-size: 14px;
      font-weight: 600;
    }

    .progress-card {
      display: flex;
      flex-direction: column;
      gap: 16px;
      background-color: var(--bg-surface, #0f172a);
    }

    .card-title {
      font-size: 15px;
      font-weight: 600;
      color: #ffffff;
      border-bottom: 1px solid var(--border-subtle, #1e293b);
      padding-bottom: 12px;
    }

    .progress-container {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .job-meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .job-id-label {
      font-size: 12px;
      color: var(--text-muted, #94a3b8);
    }

    .job-id {
      color: #a78bfa;
      font-size: 11px;
    }

    .progress-bar-bg {
      width: 100%;
      height: 10px;
      background-color: var(--bg-surface-elevated, #1e293b);
      border-radius: 9999px;
      overflow: hidden;
      border: 1px solid var(--border-subtle, #1e293b);
    }

    .progress-bar-fill {
      height: 100%;
      background: #ffffff;
      transition: width 0.4s ease;
      border-radius: 9999px;
    }

    .progress-bar-fill.completed {
      background: #e4e4e7;
    }

    .progress-bar-fill.failed {
      background: #71717a;
    }

    .progress-info {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 12px;
    }

    .progress-pct {
      font-weight: 700;
      color: #ffffff;
      font-family: monospace;
    }

    .status-phase {
      color: var(--text-muted, #94a3b8);
    }

    .success-box {
      margin-top: 12px;
      padding: 16px;
      background-color: rgba(16, 185, 129, 0.1);
      border: 1px solid rgba(16, 185, 129, 0.3);
      border-radius: 8px;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      gap: 10px;
    }

    .success-icon {
      font-size: 28px;
    }

    .success-title {
      font-size: 14px;
      font-weight: 700;
      color: #34d399;
    }

    .success-sub {
      font-size: 12px;
      color: var(--text-muted, #94a3b8);
      display: block;
      margin-top: 2px;
    }

    .btn-view-clips {
      font-size: 13px;
      padding: 8px 18px;
    }

    .failed-box {
      margin-top: 12px;
      padding: 16px;
      background-color: rgba(239, 68, 68, 0.1);
      border: 1px solid rgba(239, 68, 68, 0.3);
      border-radius: 8px;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      gap: 8px;
    }

    .failed-icon {
      font-size: 24px;
    }

    .failed-text {
      font-size: 12px;
      color: #f87171;
    }

    .alert-error {
      display: flex;
      align-items: flex-start;
      gap: 12px;
      padding: 12px 16px;
      background-color: rgba(239, 68, 68, 0.12);
      border: 1px solid rgba(239, 68, 68, 0.3);
      border-radius: 8px;
      color: #f87171;
      font-size: 13px;
    }

    .alert-icon {
      font-size: 18px;
      flex-shrink: 0;
    }

    .alert-content p {
      margin-top: 2px;
      font-size: 12px;
      color: var(--text-muted, #94a3b8);
    }
  `],
})
export class VideoUploadComponent implements OnInit, OnDestroy {
  private readonly fb = inject(FormBuilder);
  private readonly videoService = inject(VideoService);

  uploadForm!: FormGroup;

  readonly isSubmitting = signal<boolean>(false);
  readonly isProcessing = signal<boolean>(false);
  readonly activeJobId = signal<string | null>(null);
  readonly jobStatus = signal<VideoStatus>('pending');
  readonly progressPercent = signal<number>(0);
  readonly jobErrorMessage = signal<string | null>(null);
  readonly submissionError = signal<string | null>(null);

  private pollSubscription?: Subscription;

  ngOnInit(): void {
    this.uploadForm = this.fb.group({
      source_url: [
        '',
        [
          Validators.required,
          Validators.pattern(/^https?:\/\/.+/i),
        ],
      ],
      title: [''],
      strategy: ['fixed_interval', Validators.required],
      vertical_mode: ['blur_padding', Validators.required],
      interval_seconds: [30, [Validators.min(5), Validators.max(600)]],
      max_duration_seconds: [60, [Validators.min(5), Validators.max(600)]],
    });
  }

  ngOnDestroy(): void {
    this.stopPolling();
  }

  onSubmit(): void {
    if (this.isSubmitting()) {
      return;
    }

    if (this.uploadForm.invalid) {
      this.uploadForm.markAllAsTouched();
      return;
    }

    this.isSubmitting.set(true);
    this.submissionError.set(null);
    this.activeJobId.set(null);
    this.progressPercent.set(0);

    const formVal = this.uploadForm.getRawValue();
    const payload: VideoIngestRequest = {
      source_url: formVal.source_url,
      title: formVal.title || null,
      strategy: formVal.strategy as CuttingStrategy,
      vertical_mode: formVal.vertical_mode as VerticalMode,
      interval_seconds: Number(formVal.interval_seconds),
      max_duration_seconds: Number(formVal.max_duration_seconds),
    };

    this.videoService.submitVideo(payload).subscribe({
      next: (res) => {
        this.isSubmitting.set(false);
        this.activeJobId.set(res.id);
        this.jobStatus.set(res.status);
        this.isProcessing.set(true);
        this.startPolling(res.id);
      },
      error: (err) => {
        this.isSubmitting.set(false);
        this.submissionError.set(err.message || 'Failed to submit video for ingestion');
      },
    });
  }

  startPolling(id: string): void {
    this.stopPolling();

    this.pollSubscription = this.videoService.pollVideoStatus(id, 2000).subscribe({
      next: (statusRes) => {
        this.jobStatus.set(statusRes.status);
        this.progressPercent.set(statusRes.progress_percent || 0);

        if (statusRes.status === 'ready') {
          this.isProcessing.set(false);
          this.progressPercent.set(100);
        } else if (statusRes.status === 'failed') {
          this.isProcessing.set(false);
          this.jobErrorMessage.set(statusRes.error_message || 'Transcoding failed');
        }
      },
      error: (err) => {
        this.isProcessing.set(false);
        this.jobErrorMessage.set(err.message || 'Error checking video status');
      },
    });
  }

  stopPolling(): void {
    if (this.pollSubscription) {
      this.pollSubscription.unsubscribe();
      this.pollSubscription = undefined;
    }
  }

  currentPhaseLabel(): string {
    const status = this.jobStatus();
    switch (status) {
      case 'pending':
        return 'Queueing job in Redis...';
      case 'downloading':
        return 'Downloading source video via yt-dlp...';
      case 'processing':
        return 'Slicing & transcoding vertical 9:16 clips via FFmpeg...';
      case 'ready':
        return 'Transcoding complete. Ready to publish!';
      case 'failed':
        return 'Job processing failed.';
      default:
        return 'Processing...';
    }
  }

  resetForm(): void {
    this.stopPolling();
    this.activeJobId.set(null);
    this.submissionError.set(null);
    this.jobErrorMessage.set(null);
    this.isProcessing.set(false);
    this.uploadForm.reset({
      source_url: '',
      title: '',
      strategy: 'fixed_interval',
      vertical_mode: 'blur_padding',
      interval_seconds: 30,
      max_duration_seconds: 60,
    });
  }
}
