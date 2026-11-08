import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of, throwError, Subject } from 'rxjs';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

import { DashboardComponent } from './features/dashboard/dashboard.component';
import { VideoUploadComponent } from './features/video-upload/video-upload.component';
import { SocialAccountsComponent } from './features/social-accounts/social-accounts.component';

import { VideoService } from './core/services/video.service';
import { ClipService } from './core/services/clip.service';
import { SocialAccountService } from './core/services/social-account.service';
import { PublishService } from './core/services/publish.service';

describe('Adversarial Stress & Empirical Verification Suite — Milestone M7', () => {

  // =========================================================================
  // 1. DASHBOARD COMPONENT: NETWORK RESILIENCE & ADVERSARIAL ERROR BOUNDARIES
  // =========================================================================
  describe('DashboardComponent Adversarial Resilience', () => {
    let component: DashboardComponent;
    let fixture: ComponentFixture<DashboardComponent>;

    let mockVideoService: { getVideos: ReturnType<typeof vi.fn> };
    let mockClipService: { getAllClips: ReturnType<typeof vi.fn> };
    let mockSocialService: { getAccounts: ReturnType<typeof vi.fn> };
    let mockPublishService: { getPublications: ReturnType<typeof vi.fn> };

    beforeEach(async () => {
      mockVideoService = {
        getVideos: vi.fn().mockReturnValue(of({ data: [], total: 0 })),
      };
      mockClipService = {
        getAllClips: vi.fn().mockReturnValue(of({ data: [], total: 0 })),
      };
      mockSocialService = {
        getAccounts: vi.fn().mockReturnValue(of([])),
      };
      mockPublishService = {
        getPublications: vi.fn().mockReturnValue(of({ data: [], total: 0 })),
      };

      await TestBed.configureTestingModule({
        imports: [DashboardComponent],
        providers: [
          provideRouter([]),
          { provide: VideoService, useValue: mockVideoService },
          { provide: ClipService, useValue: mockClipService },
          { provide: SocialAccountService, useValue: mockSocialService },
          { provide: PublishService, useValue: mockPublishService },
        ],
      }).compileComponents();
    });

    afterEach(() => {
      if (component) {
        component.ngOnDestroy();
      }
    });

    it('adversarial check: complete network outage (all 4 services throw error) does not crash UI and sets isLoading to false', () => {
      mockVideoService.getVideos.mockReturnValue(throwError(() => new Error('ECONNREFUSED 500')));
      mockClipService.getAllClips.mockReturnValue(throwError(() => new Error('ECONNREFUSED 500')));
      mockSocialService.getAccounts.mockReturnValue(throwError(() => new Error('ECONNREFUSED 500')));
      mockPublishService.getPublications.mockReturnValue(throwError(() => new Error('ECONNREFUSED 500')));

      fixture = TestBed.createComponent(DashboardComponent);
      component = fixture.componentInstance;
      expect(() => fixture.detectChanges()).not.toThrow();

      expect(component.isLoading()).toBe(false);
      const metrics = component.metrics();
      expect(metrics.totalVideos).toBe(0);
      expect(metrics.totalClips).toBe(0);
      expect(metrics.connectedAccounts).toBe(0);
      expect(metrics.totalPublications).toBe(0);
      expect(metrics.successfulPublications).toBe(0);

      // Verify DOM renders 0 values gracefully
      const el: HTMLElement = fixture.nativeElement;
      const kpiValues = el.querySelectorAll('.kpi-val');
      expect(kpiValues.length).toBe(4);
      kpiValues.forEach((val) => {
        expect(val.textContent?.trim()).toBe('0');
      });
    });

    it('adversarial check: when clipService returns response without total field, it uses videoClipsCount from videos', () => {
      mockVideoService.getVideos.mockReturnValue(of({
        data: [
          { id: 'v1', status: 'ready', clips: [{ id: 'c1' }, { id: 'c2' }] },
          { id: 'v2', status: 'ready', clips: [{ id: 'c3' }] },
        ],
        total: 2,
      }));
      // Clip service returns payload without total property
      mockClipService.getAllClips.mockReturnValue(of({ data: [] } as any));

      fixture = TestBed.createComponent(DashboardComponent);
      component = fixture.componentInstance;
      fixture.detectChanges();

      expect(component.isLoading()).toBe(false);
      expect(component.metrics().totalVideos).toBe(2);
      // Fallback extracts 3 embedded clips from videoList when clips.total is undefined
      expect(component.metrics().totalClips).toBe(3);
    });

    it('adversarial check: malformed responses (nulls/undefined in payload) are safely handled', () => {
      mockVideoService.getVideos.mockReturnValue(of({ data: null, total: undefined } as any));
      mockClipService.getAllClips.mockReturnValue(of({ data: null, total: null } as any));
      mockSocialService.getAccounts.mockReturnValue(of(null as any));
      mockPublishService.getPublications.mockReturnValue(of({ data: null, total: undefined } as any));

      fixture = TestBed.createComponent(DashboardComponent);
      component = fixture.componentInstance;
      expect(() => fixture.detectChanges()).not.toThrow();

      expect(component.isLoading()).toBe(false);
      expect(component.metrics().totalVideos).toBe(0);
      expect(component.metrics().totalClips).toBe(0);
      expect(component.metrics().connectedAccounts).toBe(0);
      expect(component.metrics().totalPublications).toBe(0);
    });

    it('adversarial check: rapid refresh button spam while request in flight does not throw or corrupt state', () => {
      const pendingSubject = new Subject<any>();
      mockVideoService.getVideos.mockReturnValue(pendingSubject);

      fixture = TestBed.createComponent(DashboardComponent);
      component = fixture.componentInstance;
      fixture.detectChanges();

      expect(component.isLoading()).toBe(true);

      // Button is disabled when isLoading is true
      const el: HTMLElement = fixture.nativeElement;
      const refreshBtn = el.querySelector('.refresh-btn') as HTMLButtonElement;
      expect(refreshBtn.disabled).toBe(true);

      // Programmatically invoke loadDashboardData repeatedly
      for (let i = 0; i < 10; i++) {
        expect(() => component.loadDashboardData()).not.toThrow();
      }

      pendingSubject.next({ data: [], total: 0 });
      pendingSubject.complete();
      fixture.detectChanges();

      expect(component.isLoading()).toBe(false);
    });

    it('adversarial check: component destroy while polling is active cleans up subscription without memory leak', () => {
      fixture = TestBed.createComponent(DashboardComponent);
      component = fixture.componentInstance;
      fixture.detectChanges();

      expect(() => {
        fixture.destroy();
      }).not.toThrow();

      // Ensure stopAutoRefresh has been called
      expect((component as any).pollSubscription).toBeUndefined();
    });
  });

  // =========================================================================
  // 2. VIDEO UPLOAD COMPONENT: AUTONOMOUS TOGGLE, ERROR STATES & PLATFORM SANITIZATION
  // =========================================================================
  describe('VideoUploadComponent Adversarial Controls & Error States', () => {
    let component: VideoUploadComponent;
    let fixture: ComponentFixture<VideoUploadComponent>;

    let mockVideoService: {
      submitVideo: ReturnType<typeof vi.fn>;
      pollVideoStatus: ReturnType<typeof vi.fn>;
    };
    let mockPublishService: {
      getPublications: ReturnType<typeof vi.fn>;
    };

    beforeEach(async () => {
      mockVideoService = {
        submitVideo: vi.fn().mockReturnValue(of({ id: 'vid-99', status: 'pending' })),
        pollVideoStatus: vi.fn().mockReturnValue(of({ status: 'processing', progress_percent: 45 })),
      };
      mockPublishService = {
        getPublications: vi.fn().mockReturnValue(of({ data: [], total: 0 })),
      };

      await TestBed.configureTestingModule({
        imports: [VideoUploadComponent],
        providers: [
          provideRouter([]),
          { provide: VideoService, useValue: mockVideoService },
          { provide: PublishService, useValue: mockPublishService },
        ],
      }).compileComponents();

      fixture = TestBed.createComponent(VideoUploadComponent);
      component = fixture.componentInstance;
      fixture.detectChanges();
    });

    afterEach(() => {
      if (component) {
        component.ngOnDestroy();
      }
    });

    it('adversarial check: auto_publish toggle controls visibility of platform-selector-box in DOM', () => {
      const el: HTMLElement = fixture.nativeElement;

      // Initially auto_publish is false
      expect(component.uploadForm.get('auto_publish')?.value).toBe(false);
      expect(el.querySelector('.platform-selector-box')).toBeNull();

      // Check auto_publish
      const checkbox = el.querySelector('.custom-checkbox') as HTMLInputElement;
      expect(checkbox).not.toBeNull();
      checkbox.click();
      fixture.detectChanges();

      expect(component.uploadForm.get('auto_publish')?.value).toBe(true);
      expect(el.querySelector('.platform-selector-box')).not.toBeNull();

      // Uncheck auto_publish
      checkbox.click();
      fixture.detectChanges();

      expect(component.uploadForm.get('auto_publish')?.value).toBe(false);
      expect(el.querySelector('.platform-selector-box')).toBeNull();
    });

    it('adversarial check: if user unchecks all platforms with auto_publish=true, payload falls back to all platforms', () => {
      component.uploadForm.patchValue({
        source_url: 'https://example.com/video.mp4',
        auto_publish: true,
        publish_tiktok: false,
        publish_instagram: false,
        publish_youtube: false,
      });

      component.onSubmit();

      expect(mockVideoService.submitVideo).toHaveBeenCalledWith(
        expect.objectContaining({
          auto_publish: true,
          platforms: ['tiktok', 'instagram', 'youtube'], // Sanitized fallback
        })
      );
    });

    it('adversarial check: backend 500 error on submitVideo displays graceful error alert and resets isSubmitting', () => {
      mockVideoService.submitVideo.mockReturnValue(throwError(() => new Error('Server transcode cluster unavailable (500)')));

      component.uploadForm.patchValue({
        source_url: 'https://example.com/stream.mp4',
      });

      component.onSubmit();
      fixture.detectChanges();

      expect(component.isSubmitting()).toBe(false);
      expect(component.submissionError()).toBe('Server transcode cluster unavailable (500)');

      const el: HTMLElement = fixture.nativeElement;
      const alert = el.querySelector('.alert-error');
      expect(alert).not.toBeNull();
      expect(alert?.textContent).toContain('Server transcode cluster unavailable');
    });

    it('adversarial check: transcode job fails during polling and "Try Again" resets form', () => {
      const pollSubject = new Subject<any>();
      mockVideoService.pollVideoStatus.mockReturnValue(pollSubject);

      component.uploadForm.patchValue({
        source_url: 'https://example.com/valid.mp4',
      });
      component.onSubmit();
      fixture.detectChanges();

      // Simulate transcode failure
      pollSubject.next({
        status: 'failed',
        error_message: 'Corrupted MP4 container: moov atom not found',
      });
      fixture.detectChanges();

      expect(component.jobStatus()).toBe('failed');
      expect(component.jobErrorMessage()).toContain('moov atom not found');

      const el: HTMLElement = fixture.nativeElement;
      const failedBox = el.querySelector('.failed-box');
      expect(failedBox).not.toBeNull();
      expect(failedBox?.textContent).toContain('moov atom not found');

      // Click "Try Again" button
      const tryAgainBtn = failedBox?.querySelector('button') as HTMLButtonElement;
      expect(tryAgainBtn).not.toBeNull();
      tryAgainBtn.click();
      fixture.detectChanges();

      expect(component.activeJobId()).toBeNull();
      expect(component.jobErrorMessage()).toBeNull();
      expect(component.submissionError()).toBeNull();
      expect(component.uploadForm.get('source_url')?.value).toBe('');
    });

    it('adversarial check: network error fetching publications on video ready status does not crash UI', () => {
      mockPublishService.getPublications.mockReturnValue(throwError(() => new Error('Publications API down')));

      component.uploadForm.patchValue({
        source_url: 'https://example.com/video.mp4',
        auto_publish: true,
      });

      component.onSubmit();

      mockVideoService.pollVideoStatus.mockReturnValue(of({
        status: 'ready',
        progress_percent: 100,
      }));

      // Manually trigger ready
      component.startPolling('vid-ready-1');
      fixture.detectChanges();

      expect(component.jobStatus()).toBe('ready');
      expect(component.isLoadingPublications()).toBe(false);
      expect(component.publications()).toEqual([]);

      const el: HTMLElement = fixture.nativeElement;
      const dispatchEmpty = el.querySelector('.dispatch-empty');
      expect(dispatchEmpty).not.toBeNull();
      expect(dispatchEmpty?.textContent).toContain('Despacho autónomo activado');
    });
  });

  // =========================================================================
  // 3. SOCIAL ACCOUNTS COMPONENT: QUICK CONNECT ALL, RESILIENCE & ADAPTERS
  // =========================================================================
  describe('SocialAccountsComponent Adversarial & Stress Testing', () => {
    let component: SocialAccountsComponent;
    let fixture: ComponentFixture<SocialAccountsComponent>;

    let mockSocialService: {
      getAccounts: ReturnType<typeof vi.fn>;
      connectSimple: ReturnType<typeof vi.fn>;
      connectPlatform: ReturnType<typeof vi.fn>;
      mockConnect: ReturnType<typeof vi.fn>;
      disconnectAccount: ReturnType<typeof vi.fn>;
    };

    beforeEach(async () => {
      mockSocialService = {
        getAccounts: vi.fn().mockReturnValue(of([])),
        connectSimple: vi.fn().mockReturnValue(of({ message: 'Success', connected: [] })),
        connectPlatform: vi.fn().mockReturnValue(of({ auth_url: 'https://auth' })),
        mockConnect: vi.fn().mockReturnValue(of({ id: '1', platform: 'tiktok', platform_username: 'u1' })),
        disconnectAccount: vi.fn().mockReturnValue(of(undefined)),
      };

      await TestBed.configureTestingModule({
        imports: [SocialAccountsComponent],
        providers: [
          { provide: SocialAccountService, useValue: mockSocialService },
        ],
      }).compileComponents();

      fixture = TestBed.createComponent(SocialAccountsComponent);
      component = fixture.componentInstance;
      fixture.detectChanges();
    });

    it('adversarial check: 1-Click connect all sets isConnectingAll to true and calls service with platform: all', () => {
      const connectSubject = new Subject<any>();
      mockSocialService.connectSimple.mockReturnValue(connectSubject);

      const el: HTMLElement = fixture.nativeElement;
      const connectAllBtn = el.querySelector('.btn-quick-connect-all') as HTMLButtonElement;
      expect(connectAllBtn).not.toBeNull();

      connectAllBtn.click();
      fixture.detectChanges();

      expect(component.isConnectingAll()).toBe(true);
      expect(connectAllBtn.disabled).toBe(true);
      expect(connectAllBtn.textContent).toContain('Vinculando Cuentas...');
      expect(mockSocialService.connectSimple).toHaveBeenCalledWith({
        platform: 'all',
        mode: 'auto',
      });

      // Complete request
      connectSubject.next({
        message: 'All accounts connected',
        connected: [
          { id: '1', platform: 'tiktok' },
          { id: '2', platform: 'instagram' },
          { id: '3', platform: 'youtube' },
        ],
      });
      connectSubject.complete();
      fixture.detectChanges();

      expect(component.isConnectingAll()).toBe(false);
      expect(connectAllBtn.disabled).toBe(false);
      expect(component.mockSuccessMessage()).toContain('Todas las cuentas (3) conectadas');
    });

    it('adversarial check: 1-Click connect all failure resets isConnectingAll and does not corrupt UI', () => {
      mockSocialService.connectSimple.mockReturnValue(throwError(() => new Error('API 502 Bad Gateway')));

      component.onQuickConnectAll();
      fixture.detectChanges();

      expect(component.isConnectingAll()).toBe(false);
      const el: HTMLElement = fixture.nativeElement;
      const connectAllBtn = el.querySelector('.btn-quick-connect-all') as HTMLButtonElement;
      expect(connectAllBtn.disabled).toBe(false);
    });

    it('adversarial check: single platform quick connect failure resets connectingPlatform', () => {
      mockSocialService.connectSimple.mockReturnValue(throwError(() => new Error('Rate limit')));

      component.onQuickConnectPlatform('tiktok');
      fixture.detectChanges();

      expect(component.connectingPlatform()).toBeNull();
    });

    it('adversarial check: loadAccounts handles HTTP failure without throwing', () => {
      mockSocialService.getAccounts.mockReturnValue(throwError(() => new Error('Unauthorized 401')));

      expect(() => component.loadAccounts()).not.toThrow();
      expect(component.isLoading()).toBe(false);
      expect(component.accounts()).toEqual([]);
    });
  });
});
