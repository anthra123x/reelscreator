import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ClipStatus, PublicationStatus, VideoStatus } from '../../../core/models/common.model';

@Component({
  selector: 'app-status-pill',
  standalone: true,
  imports: [CommonModule],
  template: `
    <span class="status-pill" [ngClass]="[statusClass, pulse ? 'pulse' : '']">
      <span class="dot"></span>
      <span class="label">{{ label }}</span>
    </span>
  `,
  styles: [`
    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 3px 10px;
      border-radius: 9999px;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      border: 1px solid transparent;
      line-height: 1.2;
    }

    .dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background-color: currentColor;
    }

    .pill-amber {
      background-color: #18181b;
      color: #e4e4e7;
      border-color: #3f3f46;
    }

    .pill-blue {
      background-color: #27272a;
      color: #ffffff;
      border-color: #52525b;
    }

    .pill-emerald {
      background-color: #ffffff;
      color: #09090b;
      border-color: #ffffff;
    }

    .pill-violet {
      background-color: #18181b;
      color: #d4d4d8;
      border-color: #3f3f46;
    }

    .pill-rose {
      background-color: #18181b;
      color: #a1a1aa;
      border-color: #3f3f46;
    }

    .pill-slate {
      background-color: #121214;
      color: #71717a;
      border-color: #27272a;
    }

    .pulse {
      animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
    }

    @keyframes pulse {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.5; }
    }
  `],
})
export class StatusPillComponent {
  @Input() status: VideoStatus | ClipStatus | PublicationStatus | string = 'pending';
  @Input() pulse = false;

  get statusClass(): string {
    const s = (this.status || '').toLowerCase();
    switch (s) {
      case 'pending':
      case 'queued':
        return 'pill-amber';
      case 'downloading':
      case 'processing':
      case 'publishing':
        return 'pill-blue';
      case 'ready':
      case 'published':
        return 'pill-emerald';
      case 'scheduled':
        return 'pill-violet';
      case 'failed':
        return 'pill-rose';
      default:
        return 'pill-slate';
    }
  }

  get label(): string {
    return this.status ? this.status.replace(/_/g, ' ') : 'UNKNOWN';
  }
}
