import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ActivatedRoute, provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { VideoUploadComponent } from './video-upload.component';
import { VideoService } from '../../core/services/video.service';
import { PublishService } from '../../core/services/publish.service';

describe('VideoUploadComponent (M7 Autonomous Upload & Multi-Platform Dispatch)', () => {
  let component: VideoUploadComponent;
  let fixture: ComponentFixture<VideoUploadComponent>;

  let mockVideoService: {
    submitVideo: ReturnType<typeof vi.fn>;
    pollVideoStatus: ReturnType<typeof vi.fn>;
  };
  let mockPublishService: {
    getPublications: ReturnType<typeof vi.fn>;
  };

  const activatedRouteMock = {
    snapshot: {
      queryParamMap: {
        get: (param: string) => (param === 'auto_publish' ? 'true' : null),
      },
    },
  };

  beforeEach(async () => {
    mockVideoService = {
      submitVideo: vi.fn().mockReturnValue(of({ id: 'video-123', status: 'pending' })),
      pollVideoStatus: vi.fn().mockReturnValue(of({ status: 'ready', progress_percent: 100 })),
    };

    mockPublishService = {
      getPublications: vi.fn().mockReturnValue(of({
        data: [
          { id: 'pub-tiktok-1', platform: 'tiktok', status: 'published' },
          { id: 'pub-ig-1', platform: 'instagram', status: 'queued' },
        ],
        total: 2,
      })),
    };

    await TestBed.configureTestingModule({
      imports: [VideoUploadComponent],
      providers: [
        provideRouter([]),
        { provide: VideoService, useValue: mockVideoService },
        { provide: PublishService, useValue: mockPublishService },
        { provide: ActivatedRoute, useValue: activatedRouteMock },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(VideoUploadComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  afterEach(() => {
    component.ngOnDestroy();
  });

  it('should initialize with auto_publish enabled when query param auto_publish=true is present', () => {
    expect(component.uploadForm.get('auto_publish')?.value).toBe(true);
  });

  it('should include auto_publish, platforms array, and caption in payload when submitted', () => {
    component.uploadForm.patchValue({
      source_url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
      title: 'Auto Ingest Reel',
      auto_publish: true,
      publish_tiktok: true,
      publish_instagram: true,
      publish_youtube: false,
      caption: 'Awesome 9:16 clip #viral',
    });

    component.onSubmit();

    expect(mockVideoService.submitVideo).toHaveBeenCalledWith(
      expect.objectContaining({
        source_url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        title: 'Auto Ingest Reel',
        auto_publish: true,
        platforms: ['tiktok', 'instagram'],
        caption: 'Awesome 9:16 clip #viral',
      })
    );
    expect(component.autoPublishRequested()).toBe(true);
  });

  it('should fetch and display dispatch publication records when video reaches ready status', async () => {
    component.uploadForm.patchValue({
      source_url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
      auto_publish: true,
    });

    component.onSubmit();
    fixture.detectChanges();

    expect(mockPublishService.getPublications).toHaveBeenCalled();
    expect(component.publications().length).toBe(2);

    const el: HTMLElement = fixture.nativeElement;
    const dispatchBox = el.querySelector('.dispatch-summary-box');
    expect(dispatchBox).toBeTruthy();
    expect(dispatchBox?.textContent).toContain('Despacho Autónomo a Redes Sociales');
    expect(dispatchBox?.textContent).toContain('TIKTOK');
    expect(dispatchBox?.textContent).toContain('INSTAGRAM');
  });
});
