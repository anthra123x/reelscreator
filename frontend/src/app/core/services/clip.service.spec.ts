import { TestBed } from '@angular/core/testing';
import { of, firstValueFrom } from 'rxjs';
import { ClipService } from './clip.service';
import { ApiService } from './api.service';
import { Clip, ClipListResponse } from '../models/clip.model';

describe('ClipService', () => {
  let service: ClipService;
  let apiMock: {
    get: ReturnType<typeof vi.fn>;
    patch: ReturnType<typeof vi.fn>;
    delete: ReturnType<typeof vi.fn>;
  };

  beforeEach(() => {
    apiMock = {
      get: vi.fn(),
      patch: vi.fn(),
      delete: vi.fn(),
    };

    TestBed.configureTestingModule({
      providers: [
        ClipService,
        { provide: ApiService, useValue: apiMock },
      ],
    });
    service = TestBed.inject(ClipService);
  });

  it('should list clips by video and unwrap data array', async () => {
    const mockClips: Clip[] = [
      {
        id: 'clip-1',
        video_id: 'vid-1',
        file_path: 'media/clips/1.mp4',
        thumbnail_path: 'media/thumbs/1.jpg',
        file_url: '/storage/media/clips/1.mp4',
        thumbnail_url: '/storage/media/thumbs/1.jpg',
        title: 'Clip 1',
        start_time: 0,
        end_time: 30,
        duration: 30,
        status: 'ready',
      },
    ];
    const response: ClipListResponse = { data: mockClips };

    apiMock.get.mockReturnValue(of(response));

    const clips = await firstValueFrom(service.getClipsByVideo('vid-1'));
    expect(clips.length).toBe(1);
    expect(clips[0].id).toBe('clip-1');
    expect(clips[0].file_url).toBe('/storage/media/clips/1.mp4');
  });

  it('should update clip metadata', async () => {
    const updatedClip: Clip = {
      id: 'clip-1',
      video_id: 'vid-1',
      file_path: 'media/clips/1.mp4',
      thumbnail_path: null,
      file_url: '/storage/media/clips/1.mp4',
      thumbnail_url: null,
      title: 'Updated Title',
      start_time: 5,
      end_time: 25,
      duration: 20,
      status: 'ready',
    };

    apiMock.patch.mockReturnValue(of(updatedClip));

    const res = await firstValueFrom(service.updateClip('clip-1', { title: 'Updated Title' }));
    expect(res.title).toBe('Updated Title');
    expect(apiMock.patch).toHaveBeenCalledWith('clips/clip-1', { title: 'Updated Title' });
  });

  it('should delete a clip', async () => {
    apiMock.delete.mockReturnValue(of(undefined));

    await firstValueFrom(service.deleteClip('clip-1'));
    expect(apiMock.delete).toHaveBeenCalledWith('clips/clip-1');
  });
});
