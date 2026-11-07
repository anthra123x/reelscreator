import { Injectable, inject, signal } from '@angular/core';
import { Observable, interval } from 'rxjs';
import { startWith, switchMap, take, takeWhile } from 'rxjs/operators';
import { ApiService } from './api.service';
import { PaginatedResponse } from '../models/common.model';
import {
  Video,
  VideoFilterParams,
  VideoIngestRequest,
  VideoIngestResponse,
  VideoStatusResponse,
} from '../models/video.model';

@Injectable({
  providedIn: 'root',
})
export class VideoService {
  private readonly api = inject(ApiService);

  // Reactive state signals for UI binding
  readonly selectedVideo = signal<Video | null>(null);
  readonly isLoading = signal<boolean>(false);

  /**
   * Submit a new video URL for cutting and processing.
   * POST /api/videos
   */
  submitVideo(payload: VideoIngestRequest): Observable<VideoIngestResponse> {
    return this.api.post<VideoIngestResponse>('videos', payload);
  }

  /**
   * Fetch paginated list of ingested videos.
   * GET /api/videos
   */
  getVideos(params?: VideoFilterParams): Observable<PaginatedResponse<Video>> {
    return this.api.get<PaginatedResponse<Video>>('videos', params as Record<string, string | number>);
  }

  /**
   * Fetch complete details of a video with its clips.
   * GET /api/videos/{id}
   */
  getVideo(id: string): Observable<Video> {
    return this.api.get<Video>(`videos/${id}`);
  }

  /**
   * Fetch fast-polling status payload for a processing video.
   * GET /api/videos/{id}/status
   */
  getVideoStatus(id: string): Observable<VideoStatusResponse> {
    return this.api.get<VideoStatusResponse>(`videos/${id}/status`);
  }

  /**
   * Observable polling stream that emits status updates every intervalMs
   * until the video transitions to 'ready' or 'failed'.
   */
  pollVideoStatus(
    id: string,
    intervalMs = 2000,
    maxAttempts = 180
  ): Observable<VideoStatusResponse> {
    return interval(intervalMs).pipe(
      startWith(0),
      switchMap(() => this.getVideoStatus(id)),
      takeWhile(
        (res) => res.status !== 'ready' && res.status !== 'failed',
        true // inclusive: emits the final 'ready' or 'failed' state
      ),
      take(maxAttempts)
    );
  }

  /**
   * Delete a video and all generated assets.
   * DELETE /api/videos/{id}
   */
  deleteVideo(id: string): Observable<void> {
    return this.api.delete<void>(`videos/${id}`);
  }
}
