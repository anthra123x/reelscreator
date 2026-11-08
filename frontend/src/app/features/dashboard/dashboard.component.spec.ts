import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { DashboardComponent } from './dashboard.component';
import { VideoService } from '../../core/services/video.service';
import { ClipService } from '../../core/services/clip.service';
import { SocialAccountService } from '../../core/services/social-account.service';
import { PublishService } from '../../core/services/publish.service';

describe('DashboardComponent (M7 Unified Dynamic Dashboard)', () => {
  let component: DashboardComponent;
  let fixture: ComponentFixture<DashboardComponent>;

  let mockVideoService: {
    getVideos: ReturnType<typeof vi.fn>;
  };
  let mockClipService: {
    getAllClips: ReturnType<typeof vi.fn>;
  };
  let mockSocialService: {
    getAccounts: ReturnType<typeof vi.fn>;
  };
  let mockPublishService: {
    getPublications: ReturnType<typeof vi.fn>;
  };

  beforeEach(async () => {
    mockVideoService = {
      getVideos: vi.fn().mockReturnValue(of({
        data: [
          { id: 'v1', status: 'ready', clips: [{ id: 'c1' }] },
          { id: 'v2', status: 'processing', clips: [{ id: 'c2' }] },
        ],
        total: 2,
      })),
    };

    mockClipService = {
      getAllClips: vi.fn().mockReturnValue(of({
        data: [{ id: 'c1' }, { id: 'c2' }, { id: 'c3' }],
        total: 3,
      })),
    };

    mockSocialService = {
      getAccounts: vi.fn().mockReturnValue(of([
        { platform: 'tiktok', is_active: true, status: 'connected' },
        { platform: 'instagram', is_active: true, status: 'connected' },
        { platform: 'youtube', is_active: false, status: 'expired' },
      ])),
    };

    mockPublishService = {
      getPublications: vi.fn().mockReturnValue(of({
        data: [
          { id: 'p1', status: 'published' },
          { id: 'p2', status: 'scheduled' },
          { id: 'p3', status: 'failed' },
        ],
        total: 3,
      })),
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
    component.ngOnDestroy();
  });

  it('should initialize and aggregate dynamic KPIs from real API services without fallback', () => {
    expect(mockVideoService.getVideos).toHaveBeenCalled();
    expect(mockClipService.getAllClips).toHaveBeenCalled();
    expect(mockSocialService.getAccounts).toHaveBeenCalled();
    expect(mockPublishService.getPublications).toHaveBeenCalled();

    const metrics = component.metrics();
    expect(metrics.totalVideos).toBe(2);
    expect(metrics.totalClips).toBe(3); // Real 3 clips from API, NOT the 1240 fallback
    expect(metrics.connectedAccounts).toBe(2); // Only active & connected accounts
    expect(metrics.totalPublications).toBe(3);
    expect(metrics.successfulPublications).toBe(2); // published + scheduled, excluding failed
  });

  it('should render the live polling badge and autonomous workflow quick button', () => {
    const el: HTMLElement = fixture.nativeElement;
    const pollingBadge = el.querySelector('.polling-indicator-badge');
    expect(pollingBadge).toBeTruthy();
    expect(pollingBadge?.textContent).toContain('Sincronización en vivo');

    const autonomousBtn = el.querySelector('.autonomous-quick-btn');
    expect(autonomousBtn).toBeTruthy();
    expect(autonomousBtn?.textContent).toContain('Descomposición y Publicación Autónoma');
  });

  it('should clean up polling timer subscription on destroy', () => {
    expect(() => component.ngOnDestroy()).not.toThrow();
  });
});
