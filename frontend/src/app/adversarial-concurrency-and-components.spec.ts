import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of, throwError, Subject } from 'rxjs';
import { describe, it, expect, beforeEach, vi } from 'vitest';

import { VideoUploadComponent } from './features/video-upload/video-upload.component';
import { PublishComponent } from './features/publish/publish.component';
import { VerticalPlayerComponent } from './shared/components/vertical-player/vertical-player.component';

import { VideoService } from './core/services/video.service';
import { PublishService } from './core/services/publish.service';
import { ClipService } from './core/services/clip.service';
import { SocialAccountService } from './core/services/social-account.service';

describe('Adversarial Concurrency & Component Stress Suite (Angular)', () => {
  // =========================================================================
  // 1. VIDEO UPLOAD COMPONENT CONCURRENCY & ERROR BOUNDARIES
  // =========================================================================
  describe('VideoUploadComponent Concurrency & Error Boundaries', () => {
    let component: VideoUploadComponent;
    let fixture: ComponentFixture<VideoUploadComponent>;
    let mockVideoService: {
      submitVideo: ReturnType<typeof vi.fn>;
      pollVideoStatus: ReturnType<typeof vi.fn>;
    };

    beforeEach(async () => {
      mockVideoService = {
        submitVideo: vi.fn(),
        pollVideoStatus: vi.fn().mockReturnValue(of({ status: 'processing', progress_percent: 25 })),
      };

      await TestBed.configureTestingModule({
        imports: [VideoUploadComponent],
        providers: [
          provideRouter([]),
          { provide: VideoService, useValue: mockVideoService },
        ],
      }).compileComponents();

      fixture = TestBed.createComponent(VideoUploadComponent);
      component = fixture.componentInstance;
      fixture.detectChanges();
    });

    it('adversarial check: programmatic double submit is blocked by isSubmitting guard', () => {
      const flightSubject = new Subject<any>();
      mockVideoService.submitVideo.mockReturnValue(flightSubject);

      component.uploadForm.patchValue({
        source_url: 'https://example.com/video.mp4',
        title: 'Test Clip',
        strategy: 'fixed_interval',
        vertical_mode: 'blur_padding',
        interval_seconds: 30,
        max_duration_seconds: 60,
      });

      // Submit once - request is in flight
      component.onSubmit();
      expect(mockVideoService.submitVideo).toHaveBeenCalledTimes(1);
      expect(component.isSubmitting()).toBe(true);

      // Submit again while in flight - blocked by guard
      component.onSubmit();
      expect(mockVideoService.submitVideo).toHaveBeenCalledTimes(1);

      // Complete in-flight request
      flightSubject.next({ id: 'uuid-1', status: 'pending' });
      expect(component.isSubmitting()).toBe(false);
    });

    it('adversarial check: handles upstream 500 error gracefully and clears isSubmitting flag', () => {
      mockVideoService.submitVideo.mockReturnValue(
        throwError(() => new Error('Server transcode cluster unavailable (500)'))
      );

      component.uploadForm.patchValue({
        source_url: 'https://example.com/video.mp4',
      });

      component.onSubmit();

      expect(component.isSubmitting()).toBe(false);
      expect(component.submissionError()).toBe('Server transcode cluster unavailable (500)');
      expect(component.activeJobId()).toBeNull();
    });

    it('adversarial check: rejects out-of-range slider boundaries (interval < 5s or > 600s)', () => {
      const intervalControl = component.uploadForm.get('interval_seconds')!;

      intervalControl.setValue(4);
      expect(intervalControl.invalid).toBe(true);
      expect(intervalControl.errors?.['min']).toBeDefined();

      intervalControl.setValue(601);
      expect(intervalControl.invalid).toBe(true);
      expect(intervalControl.errors?.['max']).toBeDefined();

      intervalControl.setValue(30);
      expect(intervalControl.valid).toBe(true);
    });
  });

  // =========================================================================
  // 2. PUBLISH COMPONENT ADVERSARIAL STRESS
  // =========================================================================
  describe('PublishComponent Adversarial Stress', () => {
    let component: PublishComponent;
    let fixture: ComponentFixture<PublishComponent>;
    let mockPublishService: {
      publishClip: ReturnType<typeof vi.fn>;
      getPublications: ReturnType<typeof vi.fn>;
    };
    let mockVideoService: { getVideos: ReturnType<typeof vi.fn> };
    let mockClipService: { getClip: ReturnType<typeof vi.fn> };
    let mockSocialService: { getAccounts: ReturnType<typeof vi.fn> };

    beforeEach(async () => {
      mockPublishService = {
        publishClip: vi.fn(),
        getPublications: vi.fn().mockReturnValue(of({ data: [], meta: { current_page: 1, total: 0 } })),
      };
      mockVideoService = {
        getVideos: vi.fn().mockReturnValue(of({ data: [], meta: { current_page: 1, total: 0 } })),
      };
      mockClipService = {
        getClip: vi.fn().mockReturnValue(of(null)),
      };
      mockSocialService = {
        getAccounts: vi.fn().mockReturnValue(of([])),
      };

      await TestBed.configureTestingModule({
        imports: [PublishComponent],
        providers: [
          provideRouter([]),
          { provide: PublishService, useValue: mockPublishService },
          { provide: VideoService, useValue: mockVideoService },
          { provide: ClipService, useValue: mockClipService },
          { provide: SocialAccountService, useValue: mockSocialService },
        ],
      }).compileComponents();

      fixture = TestBed.createComponent(PublishComponent);
      component = fixture.componentInstance;
      fixture.detectChanges();
    });

    it('adversarial check: blocks publish submission when zero platforms are selected', () => {
      component.selectedClipId = 'clip-uuid-1';
      component.selectedPlatforms = [];

      component.onPublishSubmit();
      expect(mockPublishService.publishClip).not.toHaveBeenCalled();
    });

    it('adversarial check: blocks scheduled publication with past date', () => {
      component.selectedClipId = 'clip-uuid-1';
      component.selectedPlatforms = ['tiktok'];
      component.scheduleMode = 'later';
      component.scheduledAt = '2020-01-01T12:00'; // Past date

      component.onPublishSubmit();
      expect(mockPublishService.publishClip).not.toHaveBeenCalled();
      expect(component.publishErrorMessage()).toContain('Scheduled date and time must be in the future');
    });

    it('adversarial check: handles upstream publish 422 failure without locking isPublishing state', () => {
      mockPublishService.publishClip.mockReturnValue(
        throwError(() => new Error('Validation failed: platform constraint violated'))
      );

      component.selectedClipId = 'clip-uuid-1';
      component.selectedPlatforms = ['tiktok'];
      component.scheduleMode = 'now';

      component.onPublishSubmit();

      expect(component.isPublishing()).toBe(false);
      expect(component.publishErrorMessage()).toBe('Validation failed: platform constraint violated');
    });

    it('adversarial check: programmatic double publish is blocked by isPublishing guard', () => {
      const flightSubject = new Subject<any>();
      mockPublishService.publishClip.mockReturnValue(flightSubject);

      component.selectedClipId = 'clip-uuid-1';
      component.selectedPlatforms = ['tiktok'];
      component.scheduleMode = 'now';

      // Submit once - request is now in flight
      component.onPublishSubmit();
      expect(mockPublishService.publishClip).toHaveBeenCalledTimes(1);
      expect(component.isPublishing()).toBe(true);

      // Submit second time while first is still in flight
      component.onPublishSubmit();
      expect(mockPublishService.publishClip).toHaveBeenCalledTimes(1);

      // Complete in-flight request
      flightSubject.next({ publication_ids: ['pub-1'], status: 'queued' });
      expect(component.isPublishing()).toBe(false);
    });
  });

  // =========================================================================
  // 3. VERTICAL PLAYER COMPONENT ADVERSARIAL STRESS
  // =========================================================================
  describe('VerticalPlayerComponent Adversarial Stability', () => {
    let component: VerticalPlayerComponent;
    let fixture: ComponentFixture<VerticalPlayerComponent>;

    beforeEach(async () => {
      await TestBed.configureTestingModule({
        imports: [VerticalPlayerComponent],
      }).compileComponents();

      fixture = TestBed.createComponent(VerticalPlayerComponent);
      component = fixture.componentInstance;
      fixture.detectChanges();
    });

    it('adversarial check: handles empty or invalid video src and triggers onError', () => {
      component.src = '';
      fixture.detectChanges();
      expect(component.hasError()).toBe(false);

      // Trigger video playback error
      component.onError();
      expect(component.hasError()).toBe(true);
    });

    it('adversarial check: handles video timeline metadata and end events safely', () => {
      component.src = '/storage/media/clips/test.mp4';
      fixture.detectChanges();

      // Video end event stops playing and resets position
      component.isPlaying.set(true);
      component.onEnded();
      expect(component.isPlaying()).toBe(false);
    });
  });
});
