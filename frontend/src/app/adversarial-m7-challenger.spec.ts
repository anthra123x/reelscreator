import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ActivatedRoute, provideRouter } from '@angular/router';
import { of, throwError, Subject } from 'rxjs';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

import { DashboardComponent } from './features/dashboard/dashboard.component';
import { VideoUploadComponent } from './features/video-upload/video-upload.component';
import { SocialAccountsComponent } from './features/social-accounts/social-accounts.component';

import { VideoService } from './core/services/video.service';
import { ClipService } from './core/services/clip.service';
import { SocialAccountService } from './core/services/social-account.service';
import { PublishService } from './core/services/publish.service';

describe('Adversarial M7 Challenger Suite: Lifecycle, Reactive Streams, & Form Boundaries', () => {
  // =========================================================================
  // SUITE 1: DASHBOARD COMPONENT POLLING LIFECYCLE & STREAM TEARDOWN
  // =========================================================================
  describe('Suite 1: DashboardComponent 10s Polling Stream Lifecycle & Teardown', () => {
    let component: DashboardComponent;
    let fixture: ComponentFixture<DashboardComponent>;

    let mockVideoService: { getVideos: ReturnType<typeof vi.fn> };
    let mockClipService: { getAllClips: ReturnType<typeof vi.fn> };
    let mockSocialService: { getAccounts: ReturnType<typeof vi.fn> };
    let mockPublishService: { getPublications: ReturnType<typeof vi.fn> };

    beforeEach(async () => {
      vi.useFakeTimers();

      mockVideoService = {
        getVideos: vi.fn().mockReturnValue(of({ data: [], total: 10 })),
      };
      mockClipService = {
        getAllClips: vi.fn().mockReturnValue(of({ data: [], total: 25 })),
      };
      mockSocialService = {
        getAccounts: vi.fn().mockReturnValue(
          of([
            { platform: 'tiktok', is_active: true, status: 'connected' },
            { platform: 'instagram', is_active: true, status: 'connected' },
            { platform: 'youtube', is_active: true, status: 'connected' },
          ])
        ),
      };
      mockPublishService = {
        getPublications: vi.fn().mockReturnValue(
          of({
            data: [
              { id: 'p1', status: 'published' },
              { id: 'p2', status: 'scheduled' },
            ],
            total: 2,
          })
        ),
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

      fixture = TestBed.createComponent(DashboardComponent);
      component = fixture.componentInstance;
      fixture.detectChanges();
    });

    afterEach(() => {
      vi.restoreAllMocks();
      vi.useRealTimers();
    });

    it('empirical check 1.1: 10-second timer triggers periodic refresh at exact intervals', () => {
      // Upon init, loadDashboardData is invoked once
      expect(mockVideoService.getVideos).toHaveBeenCalledTimes(1);

      // Advance by 9,999ms - timer should not have ticked yet
      vi.advanceTimersByTime(9999);
      expect(mockVideoService.getVideos).toHaveBeenCalledTimes(1);

      // Advance by 1ms to reach 10,000ms - second tick fires
      vi.advanceTimersByTime(1);
      expect(mockVideoService.getVideos).toHaveBeenCalledTimes(2);

      // Advance by another 10,000ms - third tick fires
      vi.advanceTimersByTime(10000);
      expect(mockVideoService.getVideos).toHaveBeenCalledTimes(3);

      // Advance by another 30,000ms (3 ticks)
      vi.advanceTimersByTime(30000);
      expect(mockVideoService.getVideos).toHaveBeenCalledTimes(6);
    });

    it('empirical check 1.2: ngOnDestroy terminates timer stream and prevents dangling ticks / leaks', () => {
      expect(mockVideoService.getVideos).toHaveBeenCalledTimes(1);

      // Advance by 10s
      vi.advanceTimersByTime(10000);
      expect(mockVideoService.getVideos).toHaveBeenCalledTimes(2);

      // Destroy component (simulating route switch away from /dashboard)
      fixture.destroy();

      // Advance timers by 60,000ms (6 potential intervals)
      vi.advanceTimersByTime(60000);

      // Total calls must strictly remain 2 - zero dangling executions
      expect(mockVideoService.getVideos).toHaveBeenCalledTimes(2);
      expect(mockClipService.getAllClips).toHaveBeenCalledTimes(2);
      expect(mockSocialService.getAccounts).toHaveBeenCalledTimes(2);
      expect(mockPublishService.getPublications).toHaveBeenCalledTimes(2);
    });

    it('empirical check 1.3: rapid repeated calls to startAutoRefresh do NOT create duplicate timers', () => {
      expect(mockVideoService.getVideos).toHaveBeenCalledTimes(1);

      // Trigger startAutoRefresh 20 times rapidly
      for (let i = 0; i < 20; i++) {
        component.startAutoRefresh();
      }

      // Advance 10s
      vi.advanceTimersByTime(10000);

      // Must only fire once more (total 2), NOT 20 additional times
      expect(mockVideoService.getVideos).toHaveBeenCalledTimes(2);
    });

    it('empirical check 1.4: partial API failure in forkJoin recovers gracefully via catchError', () => {
      mockVideoService.getVideos.mockReturnValue(throwError(() => new Error('DB Connection Timeout')));
      mockClipService.getAllClips.mockReturnValue(of({ data: [], total: 5 }));

      // Trigger manual refresh
      component.loadDashboardData();

      // isLoading must reset to false and metrics must handle empty/fallback safely
      expect(component.isLoading()).toBe(false);
      expect(component.metrics().totalVideos).toBe(0);
      expect(component.metrics().totalClips).toBe(5);
    });
  });

  // =========================================================================
  // SUITE 2: VIDEO UPLOAD FORM BOUNDARY VALUES & RAPID USER INTERACTIONS
  // =========================================================================
  describe('Suite 2: VideoUploadComponent Form Boundary Values & Rapid Interactions', () => {
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
        submitVideo: vi.fn().mockReturnValue(of({ id: 'vid-test-1', status: 'pending' })),
        pollVideoStatus: vi.fn().mockReturnValue(of({ status: 'ready', progress_percent: 100 })),
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
          {
            provide: ActivatedRoute,
            useValue: {
              snapshot: {
                queryParamMap: {
                  get: (key: string) => (key === 'mode' ? 'autonomous' : null),
                },
              },
            },
          },
        ],
      }).compileComponents();

      fixture = TestBed.createComponent(VideoUploadComponent);
      component = fixture.componentInstance;
      fixture.detectChanges();
    });

    afterEach(() => {
      component.ngOnDestroy();
    });

    it('empirical check 2.1: rapidly toggling auto_publish maintains consistent form state', () => {
      const autoPublishCtrl = component.uploadForm.get('auto_publish')!;

      // Rapidly toggle 100 times
      for (let i = 0; i < 100; i++) {
        autoPublishCtrl.setValue(i % 2 === 0);
      }

      // After 100 toggles (0 to 99), last value (i=99) is false
      expect(autoPublishCtrl.value).toBe(false);

      // Set to true
      autoPublishCtrl.setValue(true);
      expect(autoPublishCtrl.value).toBe(true);
      expect(component.uploadForm.valid).toBe(false); // source_url still required
    });

    it('empirical check 2.2: selecting/deselecting all platforms falls back to all platforms', () => {
      component.uploadForm.patchValue({
        source_url: 'https://example.com/source-reel.mp4',
        auto_publish: true,
        publish_tiktok: false,
        publish_instagram: false,
        publish_youtube: false,
      });

      component.onSubmit();

      // Defensive fallback check: if all checkboxes were false, backend receives all 3 platforms
      expect(mockVideoService.submitVideo).toHaveBeenCalledWith(
        expect.objectContaining({
          auto_publish: true,
          platforms: ['tiktok', 'instagram', 'youtube'],
        })
      );
    });

    it('empirical check 2.3: custom platform subsets are strictly preserved in payload', () => {
      // Test 1: Only TikTok
      component.uploadForm.patchValue({
        source_url: 'https://example.com/source-reel.mp4',
        auto_publish: true,
        publish_tiktok: true,
        publish_instagram: false,
        publish_youtube: false,
      });
      component.onSubmit();
      expect(mockVideoService.submitVideo).toHaveBeenLastCalledWith(
        expect.objectContaining({
          platforms: ['tiktok'],
        })
      );

      // Reset submitting status
      component.isSubmitting.set(false);

      // Test 2: Instagram + YouTube
      component.uploadForm.patchValue({
        publish_tiktok: false,
        publish_instagram: true,
        publish_youtube: true,
      });
      component.onSubmit();
      expect(mockVideoService.submitVideo).toHaveBeenLastCalledWith(
        expect.objectContaining({
          platforms: ['instagram', 'youtube'],
        })
      );
    });

    it('empirical check 2.4: stress-test caption with 10,000 characters and complex Unicode/emojis', () => {
      const unicodeCaption =
        '🔥🎬 #Reels2026 ¡Vídeo vertical en español con tildes y eñes: ñandú, pingüino, corazón! ' +
        '日本語の漢字テスト 🚀 ' +
        'العربية فحص ' +
        'Русский текст ' +
        '𝕽𝖊𝖊𝖑𝖘 𝓥𝓲𝓻𝓪𝓵 ' +
        'A'.repeat(10000);

      component.uploadForm.patchValue({
        source_url: 'https://example.com/source-reel.mp4',
        auto_publish: true,
        caption: unicodeCaption,
      });

      component.onSubmit();

      expect(mockVideoService.submitVideo).toHaveBeenCalledWith(
        expect.objectContaining({
          caption: unicodeCaption,
        })
      );
    });

    it('empirical check 2.5: caption omitted when empty or whitespace-only', () => {
      component.uploadForm.patchValue({
        source_url: 'https://example.com/source-reel.mp4',
        auto_publish: true,
        caption: '',
      });

      component.onSubmit();

      expect(mockVideoService.submitVideo).toHaveBeenCalledWith(
        expect.objectContaining({
          caption: undefined,
        })
      );
    });

    it('empirical check 2.6: invalid and hostile source URLs are rejected by pattern validator', () => {
      const hostileUrls = [
        'javascript:alert(1)',
        'file:///etc/passwd',
        'ftp://anonymous:password@ftp.server.com/video.mp4',
        'data:text/html;base64,PHNjcmlwdD4=',
        'not_a_url',
        '//noscheme.com/video.mp4',
        '',
      ];

      for (const badUrl of hostileUrls) {
        component.uploadForm.patchValue({ source_url: badUrl });
        expect(component.uploadForm.get('source_url')?.valid).toBe(false);
      }

      // Valid HTTP/HTTPS URLs must pass
      const validUrls = [
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4',
        'https://subdomain.domain.org/path/video.mp4?token=xyz123&quality=1080p#t=10,30',
      ];

      for (const goodUrl of validUrls) {
        component.uploadForm.patchValue({ source_url: goodUrl });
        expect(component.uploadForm.get('source_url')?.valid).toBe(true);
      }
    });

    it('empirical check 2.7: slider duration bounds validation (min 5s, max 600s)', () => {
      const intervalCtrl = component.uploadForm.get('interval_seconds')!;
      const maxDurationCtrl = component.uploadForm.get('max_duration_seconds')!;

      // Underflow
      intervalCtrl.setValue(4);
      expect(intervalCtrl.invalid).toBe(true);

      // Minimum allowed
      intervalCtrl.setValue(5);
      expect(intervalCtrl.valid).toBe(true);

      // Maximum allowed
      intervalCtrl.setValue(600);
      expect(intervalCtrl.valid).toBe(true);

      // Overflow
      intervalCtrl.setValue(601);
      expect(intervalCtrl.invalid).toBe(true);

      // Max duration underflow and overflow
      maxDurationCtrl.setValue(2);
      expect(maxDurationCtrl.invalid).toBe(true);
      maxDurationCtrl.setValue(600);
      expect(maxDurationCtrl.valid).toBe(true);
      maxDurationCtrl.setValue(1000);
      expect(maxDurationCtrl.invalid).toBe(true);
    });

    it('empirical check 2.8: route query parameters ?mode=autonomous activates auto_publish on init', () => {
      // ActivatedRoute was configured with mode=autonomous
      expect(component.uploadForm.get('auto_publish')?.value).toBe(true);
    });
  });

  // =========================================================================
  // SUITE 3: SOCIAL ACCOUNTS COMPONENT CONCURRENCY & QUICK CONNECT GUARDS
  // =========================================================================
  describe('Suite 3: SocialAccountsComponent Concurrency & Quick Connect Stress', () => {
    let component: SocialAccountsComponent;
    let fixture: ComponentFixture<SocialAccountsComponent>;

    let mockSocialService: {
      getAccounts: ReturnType<typeof vi.fn>;
      connectSimple: ReturnType<typeof vi.fn>;
      mockConnect: ReturnType<typeof vi.fn>;
      disconnectAccount: ReturnType<typeof vi.fn>;
      connectPlatform: ReturnType<typeof vi.fn>;
    };

    beforeEach(async () => {
      mockSocialService = {
        getAccounts: vi.fn().mockReturnValue(of([])),
        connectSimple: vi.fn(),
        mockConnect: vi.fn(),
        disconnectAccount: vi.fn(),
        connectPlatform: vi.fn(),
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

    it('empirical check 3.1: quick connect all handles in-flight request and guards state', () => {
      const inFlightSubject = new Subject<any>();
      mockSocialService.connectSimple.mockReturnValue(inFlightSubject);

      component.onQuickConnectAll();

      expect(component.isConnectingAll()).toBe(true);
      expect(mockSocialService.connectSimple).toHaveBeenCalledWith({
        platform: 'all',
        mode: 'auto',
      });

      // Complete
      inFlightSubject.next({
        message: 'Connected',
        connected: [{ id: '1', platform: 'tiktok' }],
      });
      inFlightSubject.complete();

      expect(component.isConnectingAll()).toBe(false);
      expect(component.mockSuccessMessage()).toContain('Todas las cuentas');
    });

    it('empirical check 3.2: quick connect per platform tracks connectingPlatform state accurately', () => {
      const inFlightSubject = new Subject<any>();
      mockSocialService.connectSimple.mockReturnValue(inFlightSubject);

      component.onQuickConnectPlatform('youtube');

      expect(component.connectingPlatform()).toBe('youtube');
      expect(mockSocialService.connectSimple).toHaveBeenCalledWith({
        platform: 'youtube',
        mode: 'auto',
      });

      inFlightSubject.next({ message: 'OK' });
      inFlightSubject.complete();

      expect(component.connectingPlatform()).toBeNull();
      expect(component.mockSuccessMessage()).toContain('YOUTUBE');
    });

    it('empirical check 3.3: mock connect ignores empty or whitespace-only username', () => {
      component.mockUsername = '   ';
      component.onMockConnect();
      expect(mockSocialService.mockConnect).not.toHaveBeenCalled();

      component.mockUsername = '';
      component.onMockConnect();
      expect(mockSocialService.mockConnect).not.toHaveBeenCalled();
    });
  });
});
