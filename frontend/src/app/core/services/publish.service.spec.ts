import { TestBed } from '@angular/core/testing';
import { of, firstValueFrom } from 'rxjs';
import { PublishService } from './publish.service';
import { ApiService } from './api.service';
import { PublishClipRequest, PublishClipResponse } from '../models/publication.model';

describe('PublishService', () => {
  let service: PublishService;
  let apiMock: {
    get: ReturnType<typeof vi.fn>;
    post: ReturnType<typeof vi.fn>;
  };

  beforeEach(() => {
    apiMock = {
      get: vi.fn(),
      post: vi.fn(),
    };

    TestBed.configureTestingModule({
      providers: [
        PublishService,
        { provide: ApiService, useValue: apiMock },
      ],
    });
    service = TestBed.inject(PublishService);
  });

  it('should dispatch multi-platform publication request', async () => {
    const payload: PublishClipRequest = {
      platforms: ['tiktok', 'instagram'],
      caption: 'Awesome reel',
    };
    const response: PublishClipResponse = {
      publication_ids: ['pub-1', 'pub-2'],
      status: 'queued',
    };

    apiMock.post.mockReturnValue(of(response));

    const res = await firstValueFrom(service.publishClip('clip-1', payload));
    expect(res.publication_ids.length).toBe(2);
    expect(res.status).toBe('queued');
    expect(apiMock.post).toHaveBeenCalledWith('clips/clip-1/publish', payload);
  });

  it('should get publication history', async () => {
    apiMock.get.mockReturnValue(of({ data: [], total: 0 }));

    const res = await firstValueFrom(service.getPublications());
    expect(res.data).toEqual([]);
    expect(apiMock.get).toHaveBeenCalledWith('publications', undefined);
  });

  it('should retry a failed publication', async () => {
    const response = {
      message: 'Retried',
      publication: { id: 'pub-1' } as any,
      status: 'queued',
    };
    apiMock.post.mockReturnValue(of(response));

    const res = await firstValueFrom(service.retryPublication('pub-1'));
    expect(res.status).toBe('queued');
    expect(apiMock.post).toHaveBeenCalledWith('publications/pub-1/retry');
  });
});
