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

export type PlatformSkin = 'tiktok' | 'instagram' | 'youtube';
export type SlicingMode = 'cover' | 'blur';

@Component({
  selector: 'app-vertical-player',
  standalone: true,
  imports: [CommonModule, DurationPipe],
  template: `
    <div class="player-modal-backdrop" (click)="onBackdropClick($event)">
      <!-- Top External Controller Bar (Platform & Mode Switcher) -->
      <div class="top-preview-controls" (click)="$event.stopPropagation()">
        <!-- Platform Skin Tabs -->
        <div class="skin-tabs-group">
          <button
            type="button"
            class="skin-tab-btn"
            [class.active]="activeSkin() === 'tiktok'"
            (click)="setSkin('tiktok')"
            title="Vista previa estilo TikTok"
          >
            <svg viewBox="0 0 24 24" class="tab-svg" fill="currentColor">
              <path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64c.298-.002.595.042.88.13V9.4a6.33 6.33 0 0 0-1-.08A6.34 6.34 0 0 0 3 15.66a6.34 6.34 0 0 0 10.82 4.47 6.27 6.27 0 0 0 1.94-4.47V8.5a8.28 8.28 0 0 0 4.83 1.55v-3.36z"/>
            </svg>
            <span>TikTok</span>
          </button>
          <button
            type="button"
            class="skin-tab-btn"
            [class.active]="activeSkin() === 'instagram'"
            (click)="setSkin('instagram')"
            title="Vista previa estilo Instagram Reels"
          >
            <svg viewBox="0 0 24 24" class="tab-svg" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect>
              <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path>
              <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line>
            </svg>
            <span>Instagram Reels</span>
          </button>
          <button
            type="button"
            class="skin-tab-btn"
            [class.active]="activeSkin() === 'youtube'"
            (click)="setSkin('youtube')"
            title="Vista previa estilo YouTube Shorts"
          >
            <svg viewBox="0 0 24 24" class="tab-svg" fill="currentColor">
              <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
            </svg>
            <span>YouTube Shorts</span>
          </button>
        </div>

        <!-- Mode Toggle & Action Group -->
        <div class="top-action-group">
          <!-- Crop Mode Toggle -->
          <div class="crop-toggle-pill">
            <button
              type="button"
              class="crop-btn"
              [class.active]="cropMode() === 'cover'"
              (click)="setCropMode('cover')"
              title="Formato vertical completo (Center Crop 9:16)"
            >
              9:16 Completo
            </button>
            <button
              type="button"
              class="crop-btn"
              [class.active]="cropMode() === 'blur'"
              (click)="setCropMode('blur')"
              title="Formato vertical con difuminado ambiental (Blur Padding)"
            >
              Blur Padding
            </button>
          </div>

          <!-- Mute / Unmute Button -->
          <button
            type="button"
            class="control-circle-btn"
            (click)="toggleMute()"
            [attr.aria-label]="isMuted() ? 'Unmute' : 'Mute'"
            title="Silenciar / Activar sonido"
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

          <!-- Publish Button -->
          <button
            *ngIf="showPublish"
            type="button"
            class="btn-publish-header"
            (click)="onPublishClick()"
            title="Publicar este reel en redes vinculadas"
          >
            <svg viewBox="0 0 24 24" class="svg-icon-xs" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="22" y1="2" x2="11" y2="13"></line>
              <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
            </svg>
            <span>Publicar Reel</span>
          </button>

          <!-- Close Modal Button -->
          <button type="button" class="close-btn-header" (click)="onClose()" aria-label="Cerrar vista previa">
            <svg viewBox="0 0 24 24" class="svg-icon-sm" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>
        </div>
      </div>

      <!-- Smartphone Phone Bezel Frame (9:16 Aspect Ratio) -->
      <div class="phone-frame" (click)="$event.stopPropagation()">
        <!-- Phone Bezel Notch (Camera & Speaker) -->
        <div class="phone-notch">
          <div class="notch-speaker"></div>
          <div class="notch-camera"></div>
        </div>

        <!-- Frame Header (for test contract compatibility) -->
        <div class="frame-header">
          <span class="video-title" [title]="title">{{ title || 'Preview Reel' }}</span>
          <button type="button" class="close-btn" (click)="onClose()" aria-label="Close Preview">
            <svg viewBox="0 0 24 24" class="svg-icon-sm" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>
        </div>

        <!-- Video Wrapper Viewport -->
        <div class="video-wrapper" [ngClass]="'mode-' + cropMode()" (click)="togglePlay()">
          <!-- Ambient Blur Backdrop Layer (active in both modes for maximum depth) -->
          <div
            *ngIf="poster || true"
            class="ambient-blur-backdrop"
            [style.background-image]="poster ? 'url(' + poster + ')' : 'none'"
          ></div>

          <!-- Embed Player Layer (YouTube / Vimeo without desktop chrome) -->
          <div *ngIf="playerMode() === 'embed' && trustedEmbedUrl()" class="embed-viewport">
            <iframe
              class="video-embed-iframe"
              [src]="trustedEmbedUrl()"
              title="Reel Video Preview"
              allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
              allowfullscreen
            ></iframe>
          </div>

          <!-- Native HTML5 Video Element (always present for media tests and ViewChild contract) -->
          <video
            #videoElement
            class="video-player"
            [class.hidden-player]="playerMode() === 'embed'"
            [src]="playerMode() === 'video' ? src : ''"
            [poster]="poster"
            playsinline
            [loop]="loop"
            (loadedmetadata)="onLoadedMetadata()"
            (timeupdate)="onTimeUpdate()"
            (ended)="onEnded()"
            (error)="onError()"
          ></video>

          <!-- Interactive Play/Pause Flash Overlay -->
          <div *ngIf="!isPlaying()" class="play-overlay">
            <div class="play-icon">
              <svg viewBox="0 0 24 24" class="svg-icon-play" fill="currentColor">
                <polygon points="5 3 19 12 5 21 5 3"></polygon>
              </svg>
            </div>
          </div>

          <!-- Video Error Notice Overlay (required by unit test contracts) -->
          <div *ngIf="playerMode() === 'video' && hasError()" class="error-overlay">
            <svg viewBox="0 0 24 24" class="error-icon-svg" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path>
              <line x1="12" y1="9" x2="12" y2="13"></line>
              <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <p>Unable to load video playback.</p>
            <p class="error-sub">Verify video file URL is reachable.</p>
          </div>

          <!-- ============================================================== -->
          <!-- AUTHENTIC SOCIAL MEDIA UI OVERLAY (TikTok / Reels / Shorts)   -->
          <!-- ============================================================== -->
          <div class="social-ui-overlay" (click)="$event.stopPropagation()">
            <!-- Top Social Header Indicator -->
            <div class="social-top-bar">
              <div *ngIf="activeSkin() === 'tiktok'" class="tiktok-top-nav">
                <span class="nav-dim">Siguiendo</span>
                <span class="nav-divider">|</span>
                <span class="nav-bold">Para ti</span>
              </div>

              <div *ngIf="activeSkin() === 'instagram'" class="instagram-top-nav">
                <span class="reels-brand-text">Reels</span>
                <svg viewBox="0 0 24 24" class="svg-icon-sm" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                  <circle cx="12" cy="13" r="4"></circle>
                </svg>
              </div>

              <div *ngIf="activeSkin() === 'youtube'" class="youtube-top-nav">
                <span class="shorts-badge-pill">Shorts</span>
                <svg viewBox="0 0 24 24" class="svg-icon-sm" fill="none" stroke="currentColor" stroke-width="2">
                  <circle cx="11" cy="11" r="8"></circle>
                  <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
              </div>
            </div>

            <!-- Right Sidebar Action Column -->
            <div class="social-action-column">
              <!-- Creator Avatar with Follow (+) Badge -->
              <div class="action-item avatar-item">
                <div class="avatar-circle">
                  <span class="avatar-initials">{{ channelInitials }}</span>
                </div>
                <div class="follow-plus-badge" *ngIf="activeSkin() !== 'youtube'">+</div>
              </div>

              <!-- Like Button -->
              <button
                type="button"
                class="action-item action-btn"
                [class.active-heart]="isLiked()"
                (click)="toggleLike()"
                [attr.aria-label]="isLiked() ? 'Quitar Me Gusta' : 'Dar Me Gusta'"
              >
                <div class="icon-bubble">
                  <svg *ngIf="activeSkin() !== 'youtube'" viewBox="0 0 24 24" class="social-icon" [attr.fill]="isLiked() ? '#ef4444' : 'currentColor'" stroke="currentColor" stroke-width="2">
                    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                  </svg>
                  <!-- YouTube Thumbs Up -->
                  <svg *ngIf="activeSkin() === 'youtube'" viewBox="0 0 24 24" class="social-icon" [attr.fill]="isLiked() ? '#ffffff' : 'currentColor'" stroke="currentColor" stroke-width="2">
                    <path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path>
                  </svg>
                </div>
                <span class="action-count">{{ formatCount(currentLikes()) }}</span>
              </button>

              <!-- Dislike Button (Only YouTube Shorts) -->
              <button
                *ngIf="activeSkin() === 'youtube'"
                type="button"
                class="action-item action-btn"
              >
                <div class="icon-bubble">
                  <svg viewBox="0 0 24 24" class="social-icon" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-13h3a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-3"></path>
                  </svg>
                </div>
                <span class="action-count">No gusta</span>
              </button>

              <!-- Comments Button -->
              <button type="button" class="action-item action-btn">
                <div class="icon-bubble">
                  <svg viewBox="0 0 24 24" class="social-icon" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                  </svg>
                </div>
                <span class="action-count">1,842</span>
              </button>

              <!-- Bookmark / Save Button -->
              <button
                *ngIf="activeSkin() !== 'youtube'"
                type="button"
                class="action-item action-btn"
                [class.active-save]="isSaved()"
                (click)="toggleSave()"
              >
                <div class="icon-bubble">
                  <svg viewBox="0 0 24 24" class="social-icon" [attr.fill]="isSaved() ? '#fbbf24' : 'none'" stroke="currentColor" stroke-width="2">
                    <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path>
                  </svg>
                </div>
                <span class="action-count">15.3K</span>
              </button>

              <!-- Share Button -->
              <button type="button" class="action-item action-btn">
                <div class="icon-bubble">
                  <svg *ngIf="activeSkin() === 'tiktok' || activeSkin() === 'youtube'" viewBox="0 0 24 24" class="social-icon" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="18" cy="5" r="3"></circle>
                    <circle cx="6" cy="12" r="3"></circle>
                    <circle cx="18" cy="19" r="3"></circle>
                    <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
                    <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>
                  </svg>
                  <!-- Instagram Paper Airplane -->
                  <svg *ngIf="activeSkin() === 'instagram'" viewBox="0 0 24 24" class="social-icon" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="22" y1="2" x2="11" y2="13"></line>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                  </svg>
                </div>
                <span class="action-count">4,210</span>
              </button>

              <!-- Rotating Vinyl Audio Disc -->
              <div class="action-item disc-item" [class.spinning]="isPlaying()">
                <div class="vinyl-disc">
                  <div class="disc-grooves"></div>
                  <div class="disc-center">
                    <span class="disc-dot"></span>
                  </div>
                </div>
                <div class="floating-music-notes" *ngIf="isPlaying()">
                  <span class="note note-1">♪</span>
                  <span class="note note-2">♫</span>
                </div>
              </div>
            </div>

            <!-- Bottom Information Panel (Handle, Title, Sound Ticker) -->
            <div class="social-bottom-info">
              <!-- Creator Handle & Follow Pill -->
              <div class="creator-row">
                <span class="channel-handle-text">{{ channelHandle }}</span>
                <span *ngIf="activeSkin() === 'instagram'" class="ig-follow-pill">Seguir</span>
                <span *ngIf="activeSkin() === 'youtube'" class="yt-subscribe-pill">Suscribirse</span>
              </div>

              <!-- Reel Caption & Trending Tags -->
              <p class="reel-caption-text">
                {{ displayTitle }}
                <span class="caption-hashtags">{{ hashtags }}</span>
              </p>

              <!-- Audio Ticker Marquee -->
              <div class="sound-ticker-row">
                <svg viewBox="0 0 24 24" class="music-icon" fill="currentColor">
                  <path d="M9 18V5l12-2v13"></path>
                  <circle cx="6" cy="18" r="3"></circle>
                  <circle cx="18" cy="16" r="3"></circle>
                </svg>
                <div class="ticker-marquee-track">
                  <span class="ticker-text">Sonido original - {{ channelHandle }} &bull; Audio oficial ReelForge 9:16</span>
                </div>
              </div>

              <!-- Real Slice Duration Progress Bar (NOT 22 minutes!) -->
              <div class="slice-progress-container">
                <div
                  class="slice-progress-bar"
                  [style.width.%]="reelProgressPercent()"
                ></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Lower Bezel Controls Bar (Retained for test suite compatibility & manual control) -->
        <div class="controls-bar">
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

          <!-- Bottom Time Range Pill -->
          <div class="embed-meta-row">
            <span class="embed-slice-tag">Reel 9:16 ({{ activeSkin().toUpperCase() }})</span>
            <span class="embed-time-tag">
              {{ reelCurrentTime() | duration }} / {{ reelDuration() | duration }} ({{ reelDuration() }}s)
            </span>
          </div>

          <div class="control-row">
            <div *ngIf="playerMode() === 'video'" class="time-display">
              <span>{{ currentTime() | duration }}</span> /
              <span>{{ duration() | duration }}</span>
            </div>

            <div class="embed-actions">
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
                <span>Publicar</span>
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
      background-color: rgba(0, 0, 0, 0.88);
      backdrop-filter: blur(8px);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 16px;
      gap: 12px;
      overflow-y: auto;
    }

    /* Top Preview Controls Bar */
    .top-preview-controls {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      width: 100%;
      max-width: 440px;
      padding: 6px 12px;
      background: #121214;
      border: 1px solid #27272a;
      border-radius: 9999px;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.6);
      z-index: 50;
      flex-wrap: wrap;
    }

    .skin-tabs-group {
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .skin-tab-btn {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      background: transparent;
      border: none;
      color: #a1a1aa;
      font-size: 11px;
      font-weight: 600;
      padding: 5px 10px;
      border-radius: 9999px;
      cursor: pointer;
      transition: all 0.15s ease;
    }

    .skin-tab-btn:hover {
      color: #ffffff;
    }

    .skin-tab-btn.active {
      background: #27272a;
      color: #ffffff;
      border: 1px solid #3f3f46;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
    }

    .tab-svg {
      width: 13px;
      height: 13px;
    }

    .top-action-group {
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .crop-toggle-pill {
      display: inline-flex;
      background: #18181b;
      border: 1px solid #27272a;
      border-radius: 9999px;
      padding: 2px;
    }

    .crop-btn {
      background: transparent;
      border: none;
      color: #71717a;
      font-size: 10px;
      font-weight: 600;
      padding: 3px 8px;
      border-radius: 9999px;
      cursor: pointer;
      transition: all 0.15s ease;
    }

    .crop-btn.active {
      background: #27272a;
      color: #ffffff;
    }

    .control-circle-btn {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: #18181b;
      border: 1px solid #27272a;
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: background 0.15s ease;
    }

    .control-circle-btn:hover {
      background: #27272a;
    }

    .btn-publish-header {
      background: #ffffff;
      color: #09090b;
      border: none;
      font-size: 11px;
      font-weight: 700;
      padding: 5px 10px;
      border-radius: 9999px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: background 0.15s ease;
    }

    .btn-publish-header:hover {
      background: #e4e4e7;
    }

    .close-btn-header {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: #27272a;
      border: 1px solid #3f3f46;
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: background 0.15s ease;
    }

    .close-btn-header:hover {
      background: #3f3f46;
    }

    /* Smartphone Frame (9:16 Aspect Ratio) */
    .phone-frame {
      width: 100%;
      max-width: 375px;
      height: 667px;
      max-height: 82vh;
      aspect-ratio: 9 / 16;
      background-color: #000000;
      border: 4px solid #27272a;
      border-radius: 38px;
      box-shadow: 0 25px 60px -10px rgba(0, 0, 0, 0.95), 0 0 0 1px rgba(255, 255, 255, 0.08);
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
      width: 110px;
      height: 18px;
      background-color: #121214;
      border-radius: 12px;
      z-index: 30;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      border: 1px solid #1f1f23;
    }

    .notch-speaker {
      width: 36px;
      height: 3px;
      background-color: #3f3f46;
      border-radius: 2px;
    }

    .notch-camera {
      width: 7px;
      height: 7px;
      background-color: #000000;
      border-radius: 50%;
      border: 1px solid #27272a;
    }

    .frame-header {
      position: absolute;
      top: 10px;
      left: 14px;
      right: 14px;
      z-index: 10;
      display: flex;
      align-items: center;
      justify-content: space-between;
      pointer-events: none;
      opacity: 0;
    }

    .video-title {
      font-size: 12px;
    }

    .close-btn {
      pointer-events: auto;
    }

    /* Video Viewport */
    .video-wrapper {
      flex: 1;
      position: relative;
      background-color: #000000;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      cursor: pointer;
    }

    .ambient-blur-backdrop {
      position: absolute;
      inset: -30px;
      background-size: cover;
      background-position: center;
      filter: blur(32px) brightness(0.5);
      transform: scale(1.2);
      z-index: 1;
    }

    /* Video & Iframe scaling */
    .video-player {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      z-index: 2;
    }

    .hidden-player {
      position: absolute;
      width: 1px;
      height: 1px;
      opacity: 0;
      pointer-events: none;
    }

    .embed-viewport {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      z-index: 2;
    }

    /* Mode: Cover (Center Crop 9:16) */
    .mode-cover .video-embed-iframe {
      position: absolute;
      width: 177.78vh;
      min-width: 100%;
      height: 100%;
      aspect-ratio: 16 / 9;
      border: none;
      pointer-events: none; /* Disables desktop YouTube controls; tapping delegates to phone frame */
      transform: scale(1.68);
      transform-origin: center center;
    }

    /* Mode: Blur Padding (Sharp center + blurred ambient) */
    .mode-blur .video-embed-iframe {
      position: relative;
      width: 100%;
      aspect-ratio: 16 / 9;
      max-height: 100%;
      border: none;
      pointer-events: none;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.9);
      border-radius: 4px;
    }

    /* Play/Pause Overlay */
    .play-overlay {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background-color: rgba(0, 0, 0, 0.35);
      z-index: 10;
    }

    .play-icon {
      width: 64px;
      height: 64px;
      background-color: rgba(24, 24, 27, 0.85);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #ffffff;
      padding-left: 5px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.7);
    }

    .svg-icon-play {
      width: 28px;
      height: 28px;
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
      z-index: 25;
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

    /* ============================================================== */
    /* SOCIAL MEDIA UI OVERLAY STYLES                                 */
    /* ============================================================== */
    .social-ui-overlay {
      position: absolute;
      inset: 0;
      z-index: 15;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: 34px 12px 14px;
      pointer-events: none; /* Allows click-through to toggle play */
      background: linear-gradient(
        180deg,
        rgba(0, 0, 0, 0.5) 0%,
        transparent 18%,
        transparent 65%,
        rgba(0, 0, 0, 0.85) 100%
      );
    }

    .social-top-bar {
      display: flex;
      align-items: center;
      justify-content: center;
      pointer-events: auto;
    }

    .tiktok-top-nav {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 14px;
      font-weight: 700;
      text-shadow: 0 1px 4px rgba(0, 0, 0, 0.8);
    }

    .nav-dim {
      color: #a1a1aa;
    }

    .nav-divider {
      color: #52525b;
      font-size: 12px;
    }

    .nav-bold {
      color: #ffffff;
      border-bottom: 2px solid #ffffff;
      padding-bottom: 2px;
    }

    .instagram-top-nav {
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: space-between;
      color: #ffffff;
      text-shadow: 0 1px 4px rgba(0, 0, 0, 0.8);
    }

    .reels-brand-text {
      font-size: 16px;
      font-weight: 800;
      letter-spacing: -0.02em;
    }

    .youtube-top-nav {
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: space-between;
      color: #ffffff;
    }

    .shorts-badge-pill {
      background: #ef4444;
      color: #ffffff;
      font-size: 11px;
      font-weight: 800;
      padding: 2px 8px;
      border-radius: 4px;
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }

    /* Right Action Column */
    .social-action-column {
      position: absolute;
      right: 10px;
      bottom: 50px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 16px;
      z-index: 20;
      pointer-events: auto;
    }

    .action-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
    }

    .avatar-item {
      position: relative;
      margin-bottom: 4px;
    }

    .avatar-circle {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: linear-gradient(135deg, #6366f1, #ec4899);
      border: 2px solid #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #ffffff;
      font-size: 13px;
      font-weight: 700;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.6);
    }

    .follow-plus-badge {
      position: absolute;
      bottom: -6px;
      left: 50%;
      transform: translateX(-50%);
      width: 16px;
      height: 16px;
      background: #ef4444;
      color: #ffffff;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      font-weight: 900;
      line-height: 1;
      border: 1px solid #000;
    }

    .action-btn {
      background: transparent;
      border: none;
      color: #ffffff;
      cursor: pointer;
      padding: 0;
      outline: none;
      transition: transform 0.15s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .action-btn:active {
      transform: scale(1.15);
    }

    .icon-bubble {
      width: 36px;
      height: 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.7));
    }

    .social-icon {
      width: 26px;
      height: 26px;
    }

    .active-heart .social-icon {
      color: #ef4444;
      animation: heartPulse 0.3s ease;
    }

    @keyframes heartPulse {
      0% { transform: scale(1); }
      50% { transform: scale(1.3); }
      100% { transform: scale(1); }
    }

    .action-count {
      font-size: 11px;
      font-weight: 600;
      color: #ffffff;
      text-shadow: 0 1px 3px rgba(0, 0, 0, 0.9);
    }

    /* Rotating Vinyl Disc */
    .disc-item {
      position: relative;
      margin-top: 4px;
    }

    .vinyl-disc {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      background: #18181b;
      border: 3px solid #27272a;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.8);
      position: relative;
    }

    .spinning .vinyl-disc {
      animation: spinDisc 3s linear infinite;
    }

    @keyframes spinDisc {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }

    .disc-grooves {
      position: absolute;
      inset: 4px;
      border-radius: 50%;
      border: 1px dashed rgba(255, 255, 255, 0.15);
    }

    .disc-center {
      width: 14px;
      height: 14px;
      border-radius: 50%;
      background: linear-gradient(135deg, #a855f7, #6366f1);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .disc-dot {
      width: 4px;
      height: 4px;
      border-radius: 50%;
      background: #000;
    }

    .floating-music-notes {
      position: absolute;
      top: -10px;
      right: 0;
      pointer-events: none;
    }

    .note {
      position: absolute;
      font-size: 12px;
      color: #ffffff;
      opacity: 0;
      animation: floatNote 2s linear infinite;
    }

    .note-2 {
      animation-delay: 1s;
      right: 6px;
    }

    @keyframes floatNote {
      0% { transform: translateY(0) scale(0.8); opacity: 0.8; }
      100% { transform: translateY(-24px) scale(1.2); opacity: 0; }
    }

    /* Bottom Information Panel */
    .social-bottom-info {
      display: flex;
      flex-direction: column;
      gap: 6px;
      max-width: calc(100% - 65px);
      z-index: 20;
      pointer-events: auto;
    }

    .creator-row {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .channel-handle-text {
      font-size: 13px;
      font-weight: 700;
      color: #ffffff;
      text-shadow: 0 1px 4px rgba(0, 0, 0, 0.9);
    }

    .ig-follow-pill {
      background: transparent;
      border: 1px solid #ffffff;
      color: #ffffff;
      font-size: 10px;
      font-weight: 700;
      padding: 1px 8px;
      border-radius: 4px;
    }

    .yt-subscribe-pill {
      background: #ffffff;
      color: #000000;
      font-size: 10px;
      font-weight: 800;
      padding: 2px 8px;
      border-radius: 9999px;
    }

    .reel-caption-text {
      font-size: 12px;
      color: #f4f4f5;
      line-height: 1.35;
      margin: 0;
      text-shadow: 0 1px 3px rgba(0, 0, 0, 0.9);
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    .caption-hashtags {
      font-weight: 600;
      color: #e4e4e7;
    }

    .sound-ticker-row {
      display: flex;
      align-items: center;
      gap: 6px;
      overflow: hidden;
    }

    .music-icon {
      width: 12px;
      height: 12px;
      color: #ffffff;
      flex-shrink: 0;
    }

    .ticker-marquee-track {
      overflow: hidden;
      white-space: nowrap;
    }

    .ticker-text {
      font-size: 11px;
      color: #e4e4e7;
      text-shadow: 0 1px 3px rgba(0, 0, 0, 0.9);
    }

    .slice-progress-container {
      width: 100%;
      height: 3px;
      background: rgba(255, 255, 255, 0.2);
      border-radius: 2px;
      margin-top: 4px;
      overflow: hidden;
    }

    .slice-progress-bar {
      height: 100%;
      background: #ffffff;
      border-radius: 2px;
      transition: width 0.2s linear;
    }

    /* Controls Bar at bottom of bezel */
    .controls-bar {
      position: relative;
      z-index: 20;
      background: #09090b;
      border-top: 1px solid #1f1f23;
      padding: 10px 14px;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .embed-meta-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .embed-slice-tag {
      font-size: 10px;
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

    .control-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
    }

    .embed-actions {
      display: flex;
      align-items: center;
    }

    .btn-source-link {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 11px;
      color: #a1a1aa;
      text-decoration: none;
      padding: 3px 6px;
      border-radius: 4px;
      border: 1px solid #27272a;
      background-color: #18181b;
      transition: color 0.15s ease;
    }

    .btn-source-link:hover {
      color: #ffffff;
    }

    .btn-group {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .btn-publish-reel {
      background-color: #ffffff;
      color: #09090b;
      border: none;
      padding: 4px 10px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: background-color 0.15s ease;
    }

    .btn-publish-reel:hover {
      background-color: #e4e4e7;
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

  // Social Preview State
  readonly activeSkin = signal<PlatformSkin>('tiktok');
  readonly cropMode = signal<SlicingMode>('cover');
  readonly isLiked = signal<boolean>(false);
  readonly isSaved = signal<boolean>(false);
  readonly currentLikes = signal<number>(48200);

  // Playback State
  readonly isPlaying = signal<boolean>(true);
  readonly isMuted = signal<boolean>(true);
  readonly duration = signal<number>(0);
  readonly currentTime = signal<number>(0);
  readonly hasError = signal<boolean>(false);

  readonly playerMode = signal<'video' | 'embed'>('video');
  readonly trustedEmbedUrl = signal<SafeResourceUrl | null>(null);
  readonly externalUrl = signal<string | null>(null);

  // Simulated Reel Slice Progress
  readonly reelCurrentTime = signal<number>(0);
  private progressInterval: any = null;

  ngOnInit(): void {
    this.isMuted.set(true);
    this.resolvePlayerMode();
    this.startReelProgressTimer();
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['src'] || changes['clip'] || changes['sourceUrl'] || changes['embedUrl']) {
      this.resolvePlayerMode();
    }
  }

  ngOnDestroy(): void {
    this.stopReelProgressTimer();
    if (this.videoRef && this.videoRef.nativeElement) {
      this.videoRef.nativeElement.pause();
    }
  }

  get channelHandle(): string {
    const raw = this.clip?.video?.title || this.title || '';
    if (raw.toLowerCase().includes('giorgio')) {
      return '@elrincondegiorgio';
    }
    const clean = raw.split(/[-–|:]/)[0].trim().toLowerCase().replace(/[^a-z0-9_]/g, '');
    return clean ? `@${clean}` : '@reelforge';
  }

  get channelInitials(): string {
    const handle = this.channelHandle.replace(/^@/, '');
    return (handle.slice(0, 2) || 'RF').toUpperCase();
  }

  get displayTitle(): string {
    return this.title || this.clip?.title || 'LO QUE HABÍA DENTRO DE ESTE REEL...';
  }

  get hashtags(): string {
    if (this.activeSkin() === 'tiktok') {
      return '#fyp #viral #parati #tiktok';
    }
    if (this.activeSkin() === 'instagram') {
      return '#reels #reelsinstagram #viral #trending';
    }
    return '#shorts #youtubeshorts #viral';
  }

  get reelDuration(): () => number {
    return () => {
      if (this.clip?.duration && this.clip.duration > 0) {
        return Math.round(this.clip.duration);
      }
      const start = this.startTime ?? this.clip?.start_time ?? 0;
      const end = this.endTime ?? this.clip?.end_time ?? (start + 30);
      return Math.max(5, Math.round(end - start));
    };
  }

  reelProgressPercent(): number {
    const dur = this.reelDuration();
    if (dur <= 0) return 0;
    return Math.min(100, (this.reelCurrentTime() / dur) * 100);
  }

  setSkin(skin: PlatformSkin): void {
    this.activeSkin.set(skin);
  }

  setCropMode(mode: SlicingMode): void {
    this.cropMode.set(mode);
  }

  toggleLike(): void {
    const next = !this.isLiked();
    this.isLiked.set(next);
    this.currentLikes.update((cnt) => (next ? cnt + 1 : cnt - 1));
  }

  toggleSave(): void {
    this.isSaved.set(!this.isSaved());
  }

  formatCount(count: number): string {
    if (count >= 1000000) {
      return (count / 1000000).toFixed(1) + 'M';
    }
    if (count >= 1000) {
      return (count / 1000).toFixed(1) + 'K';
    }
    return count.toString();
  }

  private startReelProgressTimer(): void {
    this.stopReelProgressTimer();
    this.reelCurrentTime.set(0);

    this.progressInterval = setInterval(() => {
      if (!this.isPlaying()) return;

      const dur = this.reelDuration();
      const current = this.reelCurrentTime() + 0.25;
      if (current >= dur) {
        this.reelCurrentTime.set(0); // Loop slice
      } else {
        this.reelCurrentTime.set(current);
      }
    }, 250);
  }

  private stopReelProgressTimer(): void {
    if (this.progressInterval) {
      clearInterval(this.progressInterval);
      this.progressInterval = null;
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
      const ytMatch = rawSource.match(
        /(?:youtube\.com\/(?:watch\?.*v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i
      );
      if (ytMatch) {
        const videoId = ytMatch[1];
        const start = Math.floor(this.startTime ?? this.clip?.start_time ?? 0);
        const end = Math.ceil(
          this.endTime ?? this.clip?.end_time ?? start + (this.clip?.duration || 30)
        );
        // controls=0 & disablekb=1 & modestbranding=1 eliminates the desktop YouTube playback bar and 22-min timeline
        const url = `https://www.youtube-nocookie.com/embed/${videoId}?start=${start}&end=${end}&autoplay=1&playsinline=1&controls=0&rel=0&modestbranding=1&iv_load_policy=3&showinfo=0&disablekb=1&fs=0&loop=1&playlist=${videoId}&mute=1`;
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
        const url = `https://player.vimeo.com/video/${vimeoId}?autoplay=1&controls=0&muted=1#t=${start}s`;
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
    const next = !this.isPlaying();
    this.isPlaying.set(next);

    if (this.playerMode() === 'video') {
      const video = this.videoRef?.nativeElement;
      if (!video) return;

      if (next) {
        video.play().catch(() => this.isPlaying.set(false));
      } else {
        video.pause();
      }
    }
  }

  toggleMute(): void {
    const next = !this.isMuted();
    this.isMuted.set(next);

    if (this.playerMode() === 'video') {
      const video = this.videoRef?.nativeElement;
      if (!video) return;
      video.muted = next;
    }
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
    if (this.playerMode() === 'embed') {
      return;
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
