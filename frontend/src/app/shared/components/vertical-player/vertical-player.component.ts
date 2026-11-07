import {
  Component,
  ElementRef,
  EventEmitter,
  Input,
  OnDestroy,
  OnInit,
  Output,
  ViewChild,
  signal,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { DurationPipe } from '../../pipes/duration.pipe';

@Component({
  selector: 'app-vertical-player',
  standalone: true,
  imports: [CommonModule, DurationPipe],
  template: `
    <div class="player-modal-backdrop" (click)="onBackdropClick($event)">
      <div class="phone-frame" (click)="$event.stopPropagation()">
        <!-- Phone Bezel Notch -->
        <div class="phone-notch">
          <div class="notch-speaker"></div>
          <div class="notch-camera"></div>
        </div>

        <!-- Header / Close / Title -->
        <div class="frame-header">
          <span class="video-title" [title]="title">{{ title || 'Preview Reel' }}</span>
          <button type="button" class="close-btn" (click)="onClose()" aria-label="Close Preview">
            <svg viewBox="0 0 24 24" class="svg-icon-sm" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>
        </div>

        <!-- Video Container -->
        <div class="video-wrapper">
          <video
            #videoElement
            class="video-player"
            [src]="src"
            [poster]="poster"
            playsinline
            [loop]="loop"
            (loadedmetadata)="onLoadedMetadata()"
            (timeupdate)="onTimeUpdate()"
            (ended)="onEnded()"
            (error)="onError()"
            (click)="togglePlay()"
          ></video>

          <!-- Centered Play / Pause Overlay Flash -->
          <div
            *ngIf="!isPlaying()"
            class="play-overlay"
            (click)="togglePlay()"
          >
            <div class="play-icon">
              <svg viewBox="0 0 24 24" class="svg-icon" fill="currentColor">
                <polygon points="5 3 19 12 5 21 5 3"></polygon>
              </svg>
            </div>
          </div>

          <!-- Video Error Notice -->
          <div *ngIf="hasError()" class="error-overlay">
            <svg viewBox="0 0 24 24" class="error-icon-svg" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path>
              <line x1="12" y1="9" x2="12" y2="13"></line>
              <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <p>Unable to load video playback.</p>
            <p class="error-sub">Verify video file URL is reachable.</p>
          </div>
        </div>

        <!-- Custom Player Controls Bar -->
        <div class="controls-bar">
          <!-- Timeline Scrubber -->
          <div class="scrubber-container">
            <input
              type="range"
              class="scrubber-range"
              min="0"
              [max]="duration() || 100"
              step="0.1"
              [value]="currentTime()"
              (input)="onSeek($event)"
            />
          </div>

          <div class="control-row">
            <div class="time-display">
              <span>{{ currentTime() | duration }}</span> /
              <span>{{ duration() | duration }}</span>
            </div>

            <div class="btn-group">
              <button
                type="button"
                class="control-btn"
                (click)="toggleMute()"
                [attr.aria-label]="isMuted() ? 'Unmute' : 'Mute'"
              >
                <svg *ngIf="isMuted()" viewBox="0 0 24 24" class="svg-icon-sm" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="1" y1="1" x2="23" y2="23"></line>
                  <path d="M9 9v3a3 3 0 0 0 5.12 2.12M15 9.34V4a3 3 0 0 0-5.94-.6"></path>
                  <path d="M17 16.95A7 7 0 0 1 5 12v-2m14 0v2a7 7 0 0 1-.11 1.23"></path>
                  <line x1="12" y1="19" x2="12" y2="23"></line>
                  <line x1="8" y1="23" x2="16" y2="23"></line>
                </svg>
                <svg *ngIf="!isMuted()" viewBox="0 0 24 24" class="svg-icon-sm" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
                  <path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path>
                </svg>
              </button>

              <button
                *ngIf="showPublish"
                type="button"
                class="btn-publish-reel"
                (click)="onPublishClick()"
              >
                <svg viewBox="0 0 24 24" class="svg-icon-sm" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="22" y1="2" x2="11" y2="13"></line>
                  <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
                <span>Publish</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .player-modal-backdrop {
      position: fixed;
      inset: 0;
      z-index: 9999;
      background-color: rgba(0, 0, 0, 0.82);
      backdrop-filter: blur(6px);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }

    .phone-frame {
      width: 100%;
      max-width: 380px;
      max-height: 85vh;
      aspect-ratio: 9 / 16;
      background-color: #000000;
      border: 4px solid #27272a;
      border-radius: 36px;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.9), 0 0 0 1px rgba(255, 255, 255, 0.08);
      position: relative;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    .phone-notch {
      position: absolute;
      top: 8px;
      left: 50%;
      transform: translateX(-50%);
      width: 120px;
      height: 20px;
      background-color: #18181b;
      border-radius: 12px;
      z-index: 20;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
    }

    .notch-speaker {
      width: 40px;
      height: 4px;
      background-color: #3f3f46;
      border-radius: 2px;
    }

    .notch-camera {
      width: 8px;
      height: 8px;
      background-color: #09090b;
      border-radius: 50%;
      border: 1px solid #27272a;
    }

    .frame-header {
      position: absolute;
      top: 32px;
      left: 0;
      right: 0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 8px 16px;
      z-index: 20;
      background: linear-gradient(to bottom, rgba(0,0,0,0.7), transparent);
    }

    .video-title {
      font-size: 13px;
      font-weight: 600;
      color: #ffffff;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 260px;
      text-shadow: 0 1px 2px rgba(0,0,0,0.8);
    }

    .close-btn {
      background: rgba(24, 24, 27, 0.7);
      border: 1px solid rgba(255, 255, 255, 0.15);
      color: #ffffff;
      width: 28px;
      height: 28px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: background-color 0.15s ease;
    }

    .close-btn:hover {
      background-color: #27272a;
    }

    .video-wrapper {
      flex: 1;
      position: relative;
      background-color: #000000;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
    }

    .video-player {
      width: 100%;
      height: 100%;
      object-fit: cover;
      cursor: pointer;
    }

    .play-overlay {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background-color: rgba(0, 0, 0, 0.35);
      cursor: pointer;
    }

    .play-icon {
      width: 56px;
      height: 56px;
      border-radius: 50%;
      background-color: rgba(255, 255, 255, 0.95);
      color: #09090b;
      display: flex;
      align-items: center;
      justify-content: center;
      padding-left: 3px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.5);
    }

    .error-overlay {
      position: absolute;
      inset: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background-color: #121214;
      color: #ffffff;
      text-align: center;
      padding: 20px;
      gap: 8px;
    }

    .error-icon-svg {
      width: 32px;
      height: 32px;
      color: #a1a1aa;
    }

    .error-sub {
      font-size: 11px;
      color: #71717a;
    }

    .controls-bar {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      z-index: 20;
      padding: 12px 16px 20px 16px;
      background: linear-gradient(to top, rgba(0,0,0,0.85) 0%, rgba(0,0,0,0.4) 70%, transparent 100%);
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .scrubber-container {
      width: 100%;
    }

    .scrubber-range {
      width: 100%;
      height: 4px;
      accent-color: #ffffff;
      cursor: pointer;
    }

    .control-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .time-display {
      font-size: 11px;
      font-weight: 500;
      color: #e4e4e7;
    }

    .btn-group {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .control-btn {
      background: transparent;
      border: none;
      cursor: pointer;
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 4px;
    }

    .btn-publish-reel {
      background-color: #ffffff;
      color: #09090b;
      border: none;
      padding: 5px 12px;
      border-radius: 6px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: background-color 0.15s ease;
    }

    .btn-publish-reel:hover {
      background-color: #e4e4e7;
    }

    .svg-icon {
      width: 20px;
      height: 20px;
    }

    .svg-icon-sm {
      width: 15px;
      height: 15px;
    }
  `],
})
export class VerticalPlayerComponent implements OnInit, OnDestroy {
  @Input() src = '';
  @Input() poster?: string;
  @Input() title?: string;
  @Input() loop = true;
  @Input() showPublish = false;

  @Output() close = new EventEmitter<void>();
  @Output() publish = new EventEmitter<void>();

  @ViewChild('videoElement') videoRef!: ElementRef<HTMLVideoElement>;

  readonly isPlaying = signal(false);
  readonly isMuted = signal(true);
  readonly duration = signal(0);
  readonly currentTime = signal(0);
  readonly hasError = signal(false);

  ngOnInit(): void {
    // Autoplay policy: start muted by default
    this.isMuted.set(true);
  }

  ngOnDestroy(): void {
    if (this.videoRef && this.videoRef.nativeElement) {
      this.videoRef.nativeElement.pause();
    }
  }

  togglePlay(): void {
    const video = this.videoRef.nativeElement;
    if (video.paused) {
      video.play().then(() => {
        this.isPlaying.set(true);
      }).catch(() => {
        this.isPlaying.set(false);
      });
    } else {
      video.pause();
      this.isPlaying.set(false);
    }
  }

  toggleMute(): void {
    const video = this.videoRef.nativeElement;
    video.muted = !video.muted;
    this.isMuted.set(video.muted);
  }

  onSeek(event: Event): void {
    const input = event.target as HTMLInputElement;
    const time = parseFloat(input.value);
    this.videoRef.nativeElement.currentTime = time;
    this.currentTime.set(time);
  }

  onLoadedMetadata(): void {
    this.duration.set(this.videoRef.nativeElement.duration || 0);
    this.hasError.set(false);
  }

  onTimeUpdate(): void {
    this.currentTime.set(this.videoRef.nativeElement.currentTime || 0);
  }

  onEnded(): void {
    this.isPlaying.set(false);
  }

  onError(): void {
    this.hasError.set(true);
    this.isPlaying.set(false);
  }

  onClose(): void {
    this.close.emit();
  }

  onPublishClick(): void {
    this.publish.emit();
  }

  onBackdropClick(event: MouseEvent): void {
    if (event.target === event.currentTarget) {
      this.onClose();
    }
  }
}
