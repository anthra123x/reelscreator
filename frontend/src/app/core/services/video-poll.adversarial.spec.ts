import { TestBed } from '@angular/core/testing';
import { of, firstValueFrom, toArray } from 'rxjs';
import { VideoService } from './video.service';
import { ApiService } from './api.service';
import { VideoStatusResponse } from '../models/video.model';

describe('VideoService.pollVideoStatus (Adversarial & Flow Tests)', () => {
  let service: VideoService;
  let apiMock: {
    get: ReturnType<typeof vi.fn>;
    post: ReturnType<typeof vi.fn>;
    patch: ReturnType<typeof vi.fn>;
    delete: ReturnType<typeof vi.fn>;
  };

  beforeEach(() => {
    apiMock = {
      get: vi.fn(),
      post: vi.fn(),
      patch: vi.fn(),
      delete: vi.fn(),
    };

    TestBed.configureTestingModule({
      providers: [
        VideoService,
        { provide: ApiService, useValue: apiMock },
      ],
    });
    service = TestBed.inject(VideoService);
  });

  it('should immediately complete when video is already in ready status', async () => {
    const readyStatus: VideoStatusResponse = {
      id: 'uuid-ready',
      status: 'ready',
      progress_percent: 100,
      error_message: null,
    };
    apiMock.get.mockReturnValue(of(readyStatus));

    // intervalMs = 10 for fast test execution
    const results = await firstValueFrom(service.pollVideoStatus('uuid-ready', 10, 5).pipe(toArray()));

    expect(results).toHaveLength(1);
    expect(results[0].status).toBe('ready');
    expect(results[0].progress_percent).toBe(100);
    expect(apiMock.get).toHaveBeenCalledTimes(1);
    expect(apiMock.get).toHaveBeenCalledWith('videos/uuid-ready/status');
  });

  it('should terminate and emit failed status when video encounters failure transition', async () => {
    const statusSequence: VideoStatusResponse[] = [
      { id: 'uuid-fail', status: 'pending', progress_percent: 10, error_message: null },
      { id: 'uuid-fail', status: 'downloading', progress_percent: 35, error_message: null },
      { id: 'uuid-fail', status: 'failed', progress_percent: 35, error_message: 'Transcode failed' },
    ];

    let callCount = 0;
    apiMock.get.mockImplementation(() => {
      const resp = statusSequence[Math.min(callCount, statusSequence.length - 1)];
      callCount++;
      return of(resp);
    });

    const results = await firstValueFrom(service.pollVideoStatus('uuid-fail', 15, 10).pipe(toArray()));

    expect(results).toHaveLength(3);
    expect(results.map(r => r.status)).toEqual(['pending', 'downloading', 'failed']);
    expect(results[2].error_message).toBe('Transcode failed');
    expect(callCount).toBe(3);
  });

  it('should progress through multiple processing states until ready', async () => {
    const statusSequence: VideoStatusResponse[] = [
      { id: 'uuid-norm', status: 'pending', progress_percent: 10, error_message: null },
      { id: 'uuid-norm', status: 'processing', progress_percent: 50, error_message: null },
      { id: 'uuid-norm', status: 'processing', progress_percent: 85, error_message: null },
      { id: 'uuid-norm', status: 'ready', progress_percent: 100, error_message: null },
    ];

    let callCount = 0;
    apiMock.get.mockImplementation(() => {
      const resp = statusSequence[Math.min(callCount, statusSequence.length - 1)];
      callCount++;
      return of(resp);
    });

    const results = await firstValueFrom(service.pollVideoStatus('uuid-norm', 15, 10).pipe(toArray()));

    expect(results).toHaveLength(4);
    expect(results.map(r => r.status)).toEqual(['pending', 'processing', 'processing', 'ready']);
    expect(callCount).toBe(4);
  });

  it('should stop polling after maxAttempts is reached even if video remains processing', async () => {
    const processingStatus: VideoStatusResponse = {
      id: 'uuid-stuck',
      status: 'processing',
      progress_percent: 50,
      error_message: null,
    };
    apiMock.get.mockReturnValue(of(processingStatus));

    // Limit to maxAttempts = 3
    const results = await firstValueFrom(service.pollVideoStatus('uuid-stuck', 15, 3).pipe(toArray()));

    expect(results).toHaveLength(3);
    expect(results.every(r => r.status === 'processing')).toBe(true);
    expect(apiMock.get).toHaveBeenCalledTimes(3);
  });
});
