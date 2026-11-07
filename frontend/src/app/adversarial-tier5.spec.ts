import { TestBed } from '@angular/core/testing';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';

import { apiErrorInterceptor, AppApiError } from './core/interceptors/api-error.interceptor';
import { ApiService } from './core/services/api.service';
import { VideoService } from './core/services/video.service';
import { ClipService } from './core/services/clip.service';
import { PublishService } from './core/services/publish.service';
import { API_BASE_URL } from './core/tokens/api-url.token';
import { DurationPipe } from './shared/pipes/duration.pipe';
import { RelativeTimePipe } from './shared/pipes/relative-time.pipe';

describe('Tier 5 Adversarial Coverage Hardening Suite (Angular)', () => {
  let httpMock: HttpTestingController;
  let apiService: ApiService;
  let videoService: VideoService;
  let clipService: ClipService;
  let publishService: PublishService;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([apiErrorInterceptor])),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '/api' },
        ApiService,
        VideoService,
        ClipService,
        PublishService,
      ],
    });

    httpMock = TestBed.inject(HttpTestingController);
    apiService = TestBed.inject(ApiService);
    videoService = TestBed.inject(VideoService);
    clipService = TestBed.inject(ClipService);
    publishService = TestBed.inject(PublishService);
  });

  afterEach(() => {
    httpMock.verify();
  });

  // =========================================================================
  // 1. HTTP INTERCEPTOR ADVERSARIAL ERROR HANDLING
  // =========================================================================
  describe('apiErrorInterceptor Adversarial Robustness', () => {
    it('should safely handle raw HTML 502/500 gateway error pages without crashing', () => {
      let capturedError: AppApiError | null = null;

      apiService.get('/test-endpoint').subscribe({
        next: () => expect.unreachable('Should have failed'),
        error: (err: AppApiError) => {
          capturedError = err;
        },
      });

      const req = httpMock.expectOne('/api/test-endpoint');
      req.flush('<html><body>502 Bad Gateway: Nginx</body></html>', {
        status: 502,
        statusText: 'Bad Gateway',
      });

      expect(capturedError).not.toBeNull();
      expect(capturedError!.status).toBe(502);
      expect(capturedError!.message).toBe('An unexpected server error occurred');
    });

    it('should safely handle empty JSON body in 422 Unprocessable Entity', () => {
      let capturedError: AppApiError | null = null;

      apiService.post('/test-endpoint', {}).subscribe({
        next: () => expect.unreachable('Should have failed'),
        error: (err: AppApiError) => {
          capturedError = err;
        },
      });

      const req = httpMock.expectOne('/api/test-endpoint');
      req.flush({}, { status: 422, statusText: 'Unprocessable Entity' });

      expect(capturedError).not.toBeNull();
      expect(capturedError!.status).toBe(422);
      expect(capturedError!.message).toBe('An unexpected server error occurred');
    });

    it('should handle malformed validation error bags with empty field arrays', () => {
      let capturedError: AppApiError | null = null;

      apiService.post('/test-endpoint', {}).subscribe({
        next: () => expect.unreachable('Should have failed'),
        error: (err: AppApiError) => {
          capturedError = err;
        },
      });

      const req = httpMock.expectOne('/api/test-endpoint');
      req.flush(
        { message: 'Validation failed', errors: { missing_field: [] } },
        { status: 422, statusText: 'Unprocessable Entity' }
      );

      expect(capturedError).not.toBeNull();
      expect(capturedError!.status).toBe(422);
      expect(capturedError!.message).toBe('Validation failed');
    });

    it('should handle network drop (status 0)', () => {
      let capturedError: AppApiError | null = null;

      apiService.get('/test-endpoint').subscribe({
        next: () => expect.unreachable('Should have failed'),
        error: (err: AppApiError) => {
          capturedError = err;
        },
      });

      const req = httpMock.expectOne('/api/test-endpoint');
      req.flush(null, { status: 0, statusText: 'Unknown Error' });

      expect(capturedError).not.toBeNull();
      expect(capturedError!.status).toBe(0);
      expect(capturedError!.message).toContain('Unable to reach ReelForge server');
    });

    it('should document precedence gap when status 0 has a ProgressEvent error body', () => {
      let capturedError: AppApiError | null = null;

      apiService.get('/test-endpoint-event').subscribe({
        next: () => expect.unreachable('Should have failed'),
        error: (err: AppApiError) => {
          capturedError = err;
        },
      });

      const req = httpMock.expectOne('/api/test-endpoint-event');
      req.error(new ProgressEvent('error'), { status: 0, statusText: 'Unknown Error' });

      expect(capturedError).not.toBeNull();
      expect(capturedError!.status).toBe(0);
      // Because ProgressEvent is typeof 'object' without message, it falls back to unexpected server error
      expect(capturedError!.message).toBe('An unexpected server error occurred');
    });
  });

  // =========================================================================
  // 2. PIPE ADVERSARIAL INPUT BOUNDARIES
  // =========================================================================
  describe('DurationPipe and RelativeTimePipe Adversarial Edge Cases', () => {
    const durationPipe = new DurationPipe();
    const relativePipe = new RelativeTimePipe();

    it('duration pipe should safely format zero, negative, and extreme numeric values', () => {
      expect(durationPipe.transform(0)).toBe('00:00');
      expect(durationPipe.transform(-10)).toBe('00:00');
      expect(durationPipe.transform(0.4)).toBe('00:00');
      expect(durationPipe.transform(59.9)).toBe('00:59');
      expect(durationPipe.transform(60)).toBe('01:00');
      expect(durationPipe.transform(3599)).toBe('59:59');
      expect(durationPipe.transform(3600)).toBe('01:00:00');
      expect(durationPipe.transform(86400)).toBe('24:00:00');
      expect(durationPipe.transform(null)).toBe('00:00');
      expect(durationPipe.transform(undefined)).toBe('00:00');
      expect(durationPipe.transform(NaN)).toBe('00:00');
    });

    it('relative time pipe should safely format past, future, and invalid date formats', () => {
      expect(relativePipe.transform(null)).toBe('N/A');
      expect(relativePipe.transform(undefined)).toBe('N/A');
      expect(relativePipe.transform('totally-not-a-date')).toBe('N/A');
      expect(relativePipe.transform('')).toBe('N/A');

      // Epoch start (1970)
      const epochStr = relativePipe.transform('1970-01-01T00:00:00Z');
      expect(epochStr).not.toBe('N/A');

      // Recent timestamp (just now)
      const nowIso = new Date().toISOString();
      expect(relativePipe.transform(nowIso)).toBe('Just now');
    });
  });

  // =========================================================================
  // 3. SERVICE RESILIENCE UNDER RAPID SEQUENTIAL INVOCATION
  // =========================================================================
  describe('Service Concurrency and Rapid Requests', () => {
    it('should cleanly handle rapid burst of clip updates without race conditions', () => {
      const updates = [
        { id: 'clip-1', title: 'Reel 1' },
        { id: 'clip-2', title: 'Reel 2' },
        { id: 'clip-3', title: 'Reel 3' },
      ];

      const results: string[] = [];

      updates.forEach((item) => {
        clipService.updateClip(item.id, { title: item.title }).subscribe((res) => {
          results.push(res.title ?? '');
        });
      });

      updates.forEach((item) => {
        const req = httpMock.expectOne(`/api/clips/${item.id}`);
        expect(req.request.method).toBe('PATCH');
        req.flush({ id: item.id, title: item.title, duration: 15 });
      });

      expect(results).toEqual(['Reel 1', 'Reel 2', 'Reel 3']);
    });

    it('should fan-out multi-platform publication requests gracefully', () => {
      let responseReceived: any = null;

      publishService
        .publishClip('clip-99', {
          platforms: ['tiktok', 'instagram', 'youtube'],
          caption: 'Cross-post #reel',
        })
        .subscribe((res) => {
          responseReceived = res;
        });

      const req = httpMock.expectOne('/api/clips/clip-99/publish');
      expect(req.request.method).toBe('POST');
      expect(req.request.body.platforms).toEqual(['tiktok', 'instagram', 'youtube']);

      req.flush({
        publication_ids: ['pub-1', 'pub-2', 'pub-3'],
        status: 'queued',
      });

      expect(responseReceived).not.toBeNull();
      expect(responseReceived.publication_ids.length).toBe(3);
    });
  });
});
