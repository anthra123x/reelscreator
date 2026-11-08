import { TestBed } from '@angular/core/testing';
import { of, firstValueFrom } from 'rxjs';
import { VideoService } from './video.service';
import { ApiService } from './api.service';
import { VideoIngestRequest, VideoIngestResponse, VideoStatusResponse } from '../models/video.model';

describe('VideoService', () => {
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

  it('should submit video and return 201 response', async () => {
    const request: VideoIngestRequest = {
      source_url: 'https://example.com/video.mp4',
      strategy: 'fixed_interval',
    };
    const response: VideoIngestResponse = {
      id: 'uuid-1',
      source_url: 'https://example.com/video.mp4',
      title: 'Sample',
      status: 'pending',
      created_at: new Date().toISOString(),
    };

    apiMock.post.mockReturnValue(of(response));

    const res = await firstValueFrom(service.submitVideo(request));
    expect(res.id).toBe('uuid-1');
    expect(apiMock.post).toHaveBeenCalledWith('videos', request);
  });

  it('should get video status for polling', async () => {
    const statusPayload: VideoStatusResponse = {
      id: 'uuid-1',
      status: 'processing',
      progress_percent: 65,
      error_message: null,
    };

    apiMock.get.mockReturnValue(of(statusPayload));

    const res = await firstValueFrom(service.getVideoStatus('uuid-1'));
    expect(res.status).toBe('processing');
    expect(res.progress_percent).toBe(65);
    expect(apiMock.get).toHaveBeenCalledWith('videos/uuid-1/status');
  });

  it('should submit video with auto_publish options', async () => {
    const request: VideoIngestRequest = {
      source_url: 'https://example.com/video.mp4',
      strategy: 'fixed_interval',
      auto_publish: true,
      platforms: ['tiktok', 'instagram', 'youtube'],
      caption: 'Auto reel',
    };
    const response: VideoIngestResponse = {
      id: 'uuid-1',
      source_url: 'https://example.com/video.mp4',
      title: 'Sample',
      status: 'pending',
      created_at: new Date().toISOString(),
    };

    apiMock.post.mockReturnValue(of(response));

    const res = await firstValueFrom(service.submitVideo(request));
    expect(res.id).toBe('uuid-1');
    expect(apiMock.post).toHaveBeenCalledWith('videos', request);
  });

  it('should call decomposeVideo on POST /videos/{id}/decompose', async () => {
    const mockDecomposeRes = {
      message: 'Video decomposed and reels dispatched for publication',
      video: { id: 'uuid-1', status: 'ready' as const, source_url: 'http://test.com', title: 'T', created_at: '' },
      clips: [],
      publications: [],
      published_platforms: ['tiktok'],
      status: 'ready',
    };

    apiMock.post.mockReturnValue(of(mockDecomposeRes));

    const res = await firstValueFrom(
      service.decomposeVideo('uuid-1', { auto_publish: true, platforms: ['tiktok'] })
    );

    expect(apiMock.post).toHaveBeenCalledWith('videos/uuid-1/decompose', {
      auto_publish: true,
      platforms: ['tiktok'],
    });
    expect(res.status).toBe('ready');
  });

  it('should call ingestAndDecompose on POST /videos/decompose', async () => {
    const payload = {
      source_url: 'https://example.com/video.mp4',
      auto_publish: true,
      platforms: ['youtube'],
    };
    const mockRes = {
      message: 'Video decomposed and reels dispatched for publication',
      video: { id: 'uuid-2', status: 'ready' as const, source_url: 'http://test.com', title: 'T', created_at: '' },
      clips: [],
      publications: [],
      published_platforms: ['youtube'],
      status: 'ready',
    };

    apiMock.post.mockReturnValue(of(mockRes));

    const res = await firstValueFrom(service.ingestAndDecompose(payload));
    expect(apiMock.post).toHaveBeenCalledWith('videos/decompose', payload);
    expect(res.status).toBe('ready');
  });

  it('should delete video by id', async () => {
    apiMock.delete.mockReturnValue(of(undefined));

    await firstValueFrom(service.deleteVideo('uuid-1'));
    expect(apiMock.delete).toHaveBeenCalledWith('videos/uuid-1');
  });
});
