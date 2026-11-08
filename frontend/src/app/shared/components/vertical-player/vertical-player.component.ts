import {
  Component,
  ElementRef,
  EventEmitter,
  Input,
  OnChanges,
  OnDestroy,
  OnInit,
  Output,
  SimpleChanges,
  ViewChild,
  inject,
  signal,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { DurationPipe } from '../../pipes/duration.pipe';
import { Clip } from '../../../core/models/clip.model';

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
          <!-- External Embed Player (YouTube / Vimeo) with Vertical 9:16 Blur Padding -->
          <div *ngIf="playerMode() === 'embed' && trustedEmbedUrl()" class="embed-container">
            <div
              *ngIf="poster"
              class="embed-blur-bg"
              [style.background-image]="'url(' + poster + ')'"
            ></div>
            <iframe
              class="video-embed-iframe"
              [src]="trustedEmbedUrl()"
              title="Reel Video Preview"
              allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
              allowfullscreen
            ></iframe>
          </div>

          <!-- Native HTML5 Video Element (always present for ViewChild and media events) -->
          <video
            #videoElement
            class="video-player"
            [class.hidden-player]="playerMode() === 'embed'"
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

          <!-- Centered Play / Pause Overlay Flash (only for HTML5 video mode) -->
          <div
            *ngIf="playerMode() === 'video' && !isPlaying()"
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
          <div *ngIf="playerMode() === 'video' && hasError()" class="error-overlay">
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
          <!-- Timeline Scrubber (only in video mode) -->
          <div *ngIf="playerMode() === 'video'" class="scrubber-container">
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

          <!-- Time range pill for embed mode -->
          <div *ngIf="playerMode() === 'embed'" class="embed-meta-row">
            <span class="embed-slice-tag">Reel 9:16</span>
            <span class="embed-time-tag">
              {{ (clip?.start_time || 0) | duration }} - {{ (clip?.end_time || 30) | duration }} ({{ (clip?.duration || 30) }}s)
            </span>
          </div>

          <div class="control-row">
            <div *ngIf="playerMode() === 'video'" class="time-display">
              <span>{{ currentTime() | duration }}</span> /
              <span>{{ duration() | duration }}</span>
            </div>

            <!-- In embed mode, direct link to source -->
            <div *ngIf="playerMode() === 'embed'" class="embed-actions">
              <a
                *ngIf="externalUrl()"
                [href]="externalUrl()"
                target="_blank"
                rel="noopener noreferrer"
                class="btn-source-link"
              >
                <svg viewBox="0 0 24 24" class="svg-icon-xs" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                  <polyline points="15 3 21 3 21 9"></polyline>
                  <line x1="10" y1="14" x2="21" y2="3"></line>
                </svg>
                <span>Fuente Original</span>
              </a>
            </div>

            <div class="btn-group">
              <button
                *ngIf="playerMode() === 'video'"
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
      left: 16px;
      right: 16px;
      z-index: 20;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      pointer-events: auto;
    }

    .video-title {
      font-size: 13px;
      font-weight: 600;
      color: #ffffff;
      text-shadow: 0 1px 4px rgba(0, 0, 0, 0.85);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      flex: 1;
    }

    .close-btn {
      background-color: rgba(24, 24, 27, 0.75);
      border: 1px solid rgba(255, 255, 255, 0.1);
      color: #ffffff;
      width: 28px;
      height: 28px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      backdrop-filter: blur(4px);
      transition: background-color 0.15s ease;
      flex-shrink: 0;
    }

    .close-btn:hover {
      background-color: rgba(39, 39, 42, 0.95);
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
      display: block;
      cursor: pointer;
    }

    .hidden-player {
      position: absolute;
      width: 1px;
      height: 1px;
      opacity: 0;
      pointer-events: none;
    }

    .embed-container {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      background-color: #000000;
    }

    .embed-blur-bg {
      position: absolute;
      inset: -20px;
      background-size: cover;
      background-position: center;
      filter: blur(28px) brightness(0.45);
      transform: scale(1.15);
      z-index: 1;
    }

    .video-embed-iframe {
      position: relative;
      z-index: 2;
      width: 100%;
      aspect-ratio: 16 / 9;
      max-height: 100%;
      border: none;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.85);
      border-radius: 6px;
    }

    .embed-meta-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 2px 8px;
    }

    .embed-slice-tag {
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #ffffff;
      background-color: #27272a;
      border: 1px solid #3f3f46;
      border-radius: 4px;
      padding: 2px 6px;
    }

    .embed-time-tag {
      font-size: 11px;
      font-family: monospace;
      color: #a1a1aa;
    }

    .btn-source-link {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 11px;
      color: #a1a1aa;
      text-decoration: none;
      padding: 4px 8px;
      border-radius: 4px;
      border: 1px solid #27272a;
      background-color: #18181b;
      transition: color 0.15s ease, border-color 0.15s ease;
    }

    .btn-source-link:hover {
      color: #ffffff;
      border-color: #3f3f46;
    }

    .svg-icon-xs {
      width: 12px;
      height: 12px;
    }

    .play-overlay {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background-color: rgba(0, 0, 0, 0.4);
      cursor: pointer;
      z-index: 10;
    }

    .play-icon {
      width: 60px;
      height: 60px;
      background-color: rgba(24, 24, 27, 0.85);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #ffffff;
      padding-left: 4px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.6);
      transform: scale(1);
      transition: transform 0.15s ease;
    }

    .play-overlay:hover .play-icon {
      transform: scale(1.08);
    }

    .error-overlay {
      position: absolute;
      inset: 0;
      background-color: rgba(9, 9, 11, 0.95);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 12px;
      padding: 24px;
      text-align: center;
      z-index: 15;
    }

    .error-icon-svg {
      width: 44px;
      height: 44px;
      color: #ef4444;
    }

    .error-overlay p {
      font-size: 14px;
      font-weight: 600;
      color: #ffffff;
      margin: 0;
    }

    .error-sub {
      font-size: 12px;
      color: #71717a !important;
      font-weight: 400 !important;
    }

    .controls-bar {
      position: relative;
      z-index: 20;
      background: linear-gradient(to top, rgba(0, 0, 0, 0.95) 0%, rgba(0, 0, 0, 0.75) 70%, transparent 100%);
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .scrubber-container {
      width: 100%;
    }

    .scrubber-range {
      width: 100%;
      height: 4px;
      -webkit-appearance: none;
      appearance: none;
      background: #27272a;
      border-radius: 2px;
      outline: none;
      cursor: pointer;
    }

    .scrubber-range::-webkit-slider-thumb {
      -webkit-appearance: none;
      appearance: none;
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background: #ffffff;
      cursor: pointer;
      box-shadow: 0 0 6px rgba(0, 0, 0, 0.5);
    }

    .control-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
    }

    .time-display {
      font-size: 11px;
      font-family: monospace;
      color: #a1a1aa;
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
export class VerticalPlayerComponent implements OnInit, OnChanges, OnDestroy {
  @Input() src = '';
  @Input() poster?: string;
  @Input() title?: string;
  @Input() loop = true;
  @Input() showPublish = false;
  @Input() clip?: Clip | null;
  @Input() sourceUrl?: string | null;
  @Input() startTime?: number;
  @Input() endTime?: number;
  @Input() embedUrl?: string | null;

  @Output() close = new EventEmitter<void>();
  @Output() publish = new EventEmitter<void>();

  @ViewChild('videoElement') videoRef!: ElementRef<HTMLVideoElement>;

  private readonly sanitizer = inject(DomSanitizer);

  readonly isPlaying = signal(false);
  readonly isMuted = signal(true);
  readonly duration = signal(0);
  readonly currentTime = signal(0);
  readonly hasError = signal(false);

  readonly playerMode = signal<'video' | 'embed'>('video');
  readonly trustedEmbedUrl = signal<SafeResourceUrl | null>(null);
  readonly externalUrl = signal<string | null>(null);

  ngOnInit(): void {
    this.isMuted.set(true);
    this.resolvePlayerMode();
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['src'] || changes['clip'] || changes['sourceUrl'] || changes['embedUrl']) {
      this.resolvePlayerMode();
    }
  }

  ngOnDestroy(): void {
    if (this.videoRef && this.videoRef.nativeElement) {
      this.videoRef.nativeElement.pause();
    }
  }

  private resolvePlayerMode(): void {
    const directEmbed = this.embedUrl || this.clip?.embed_url;
    const rawSource = this.sourceUrl || this.clip?.video?.source_url || '';

    // 1. Direct explicit embed URL
    if (directEmbed) {
      this.trustedEmbedUrl.set(this.sanitizer.bypassSecurityTrustResourceUrl(directEmbed));
      this.playerMode.set('embed');
      this.externalUrl.set(rawSource || directEmbed);
      this.hasError.set(false);
      return;
    }

    // 2. Check if rawSource is a YouTube URL
    if (rawSource) {
      const ytMatch = rawSource.match(/(?:youtube\.com\/(?:watch\?.*v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i);
      if (ytMatch) {
        const videoId = ytMatch[1];
        const start = Math.floor(this.startTime ?? this.clip?.start_time ?? 0);
        const end = Math.ceil(this.endTime ?? this.clip?.end_time ?? (start + (this.clip?.duration || 30)));
        const url = `https://www.youtube-nocookie.com/embed/${videoId}?start=${start}&end=${end}&autoplay=1&playsinline=1&controls=1&rel=0&modestbranding=1`;
        this.trustedEmbedUrl.set(this.sanitizer.bypassSecurityTrustResourceUrl(url));
        this.playerMode.set('embed');
        this.externalUrl.set(`https://www.youtube.com/watch?v=${videoId}&t=${start}s`);
        this.hasError.set(false);
        return;
      }

      // 3. Check if rawSource is Vimeo
      const vimeoMatch = rawSource.match(/vimeo\.com\/(?:video\/)?([0-9]+)/i);
      if (vimeoMatch) {
        const vimeoId = vimeoMatch[1];
        const start = Math.floor(this.startTime ?? this.clip?.start_time ?? 0);
        const url = `https://player.vimeo.com/video/${vimeoId}?autoplay=1#t=${start}s`;
        this.trustedEmbedUrl.set(this.sanitizer.bypassSecurityTrustResourceUrl(url));
        this.playerMode.set('embed');
        this.externalUrl.set(`https://vimeo.com/${vimeoId}#t=${start}s`);
        this.hasError.set(false);
        return;
      }
    }

    // 4. Default to native HTML5 video player
    this.playerMode.set('video');
    this.trustedEmbedUrl.set(null);
    this.externalUrl.set(null);
  }

  togglePlay(): void {
    if (this.playerMode() !== 'video') return;
    const video = this.videoRef?.nativeElement;
    if (!video) return;

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
    if (this.playerMode() !== 'video') return;
    const video = this.videoRef?.nativeElement;
    if (!video) return;

    video.muted = !video.muted;
    this.isMuted.set(video.muted);
  }

  onSeek(event: Event): void {
    if (this.playerMode() !== 'video') return;
    const video = this.videoRef?.nativeElement;
    if (!video) return;

    const input = event.target as HTMLInputElement;
    const time = parseFloat(input.value);
    video.currentTime = time;
    this.currentTime.set(time);
  }

  onLoadedMetadata(): void {
    this.duration.set(this.videoRef?.nativeElement?.duration || 0);
    this.hasError.set(false);
  }

  onTimeUpdate(): void {
    this.currentTime.set(this.videoRef?.nativeElement?.currentTime || 0);
  }

  onEnded(): void {
    this.isPlaying.set(false);
  }

  onError(): void {
    // If native video failed but we have a source URL to embed, attempt recovery
    if (this.playerMode() !== 'embed') {
      const rawSource = this.sourceUrl || this.clip?.video?.source_url || '';
      const ytMatch = rawSource.match(/(?:youtube\.com\/(?:watch\?.*v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i);
      if (ytMatch) {
        const videoId = ytMatch[1];
        const start = Math.floor(this.startTime ?? this.clip?.start_time ?? 0);
        const end = Math.ceil(this.endTime ?? this.clip?.end_time ?? (start + (this.clip?.duration || 30)));
        const url = `https://www.youtube-nocookie.com/embed/${videoId}?start=${start}&end=${end}&autoplay=1&playsinline=1&controls=1&rel=0&modestbranding=1`;
        this.trustedEmbedUrl.set(this.sanitizer.bypassSecurityTrustResourceUrl(url));
        this.playerMode.set('embed');
        this.externalUrl.set(`https://www.youtube.com/watch?v=${videoId}&t=${start}s`);
        this.hasError.set(false);
        return;
      }
    }

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
