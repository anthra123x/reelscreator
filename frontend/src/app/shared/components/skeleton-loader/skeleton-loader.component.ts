import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-skeleton-loader',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="skeleton-container" [ngSwitch]="shape">
      <ng-container *ngSwitchCase="'card-9-16'">
        <div *ngFor="let item of items" class="skeleton-card-9-16">
          <div class="skeleton-media"></div>
          <div class="skeleton-body">
            <div class="skeleton-line full"></div>
            <div class="skeleton-line half"></div>
          </div>
        </div>
      </ng-container>

      <ng-container *ngSwitchCase="'table-row'">
        <div *ngFor="let item of items" class="skeleton-row">
          <div class="skeleton-cell w-20"></div>
          <div class="skeleton-cell w-40"></div>
          <div class="skeleton-cell w-20"></div>
          <div class="skeleton-cell w-20"></div>
        </div>
      </ng-container>

      <ng-container *ngSwitchCase="'metric-widget'">
        <div *ngFor="let item of items" class="skeleton-metric">
          <div class="skeleton-line half"></div>
          <div class="skeleton-line title"></div>
        </div>
      </ng-container>

      <ng-container *ngSwitchDefault>
        <div *ngFor="let item of items" class="skeleton-line full"></div>
      </ng-container>
    </div>
  `,
  styles: [`
    .skeleton-container {
      width: 100%;
    }

    .skeleton-card-9-16 {
      aspect-ratio: 9 / 16;
      max-width: 380px;
      background-color: var(--bg-surface-elevated, #1e293b);
      border-radius: var(--radius-md, 10px);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      animation: pulse 1.8s ease-in-out infinite;
      border: 1px solid var(--border-subtle, #1e293b);
    }

    .skeleton-media {
      flex: 1;
      background-color: #334155;
    }

    .skeleton-body {
      padding: 12px;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .skeleton-line {
      height: 12px;
      background-color: #334155;
      border-radius: 4px;
      animation: pulse 1.8s ease-in-out infinite;
    }

    .skeleton-line.full {
      width: 100%;
    }

    .skeleton-line.half {
      width: 50%;
    }

    .skeleton-line.title {
      height: 24px;
      width: 70%;
    }

    .skeleton-row {
      display: flex;
      gap: 16px;
      padding: 12px 16px;
      border-bottom: 1px solid #1e293b;
      align-items: center;
    }

    .skeleton-cell {
      height: 14px;
      background-color: #334155;
      border-radius: 4px;
      animation: pulse 1.8s ease-in-out infinite;
    }

    .w-20 { width: 20%; }
    .w-40 { width: 40%; }

    .skeleton-metric {
      padding: 20px;
      background-color: #0f172a;
      border: 1px solid #1e293b;
      border-radius: 10px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    @keyframes pulse {
      0%, 100% { opacity: 0.6; }
      50% { opacity: 0.25; }
    }
  `],
})
export class SkeletonLoaderComponent {
  @Input() shape: 'card-9-16' | 'table-row' | 'metric-widget' | 'text' = 'text';
  @Input() set count(val: number) {
    this.items = Array.from({ length: Math.max(1, val) });
  }

  items: number[] = [1];
}
