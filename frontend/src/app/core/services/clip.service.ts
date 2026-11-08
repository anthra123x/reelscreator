import { Injectable, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { ApiService } from './api.service';
import { PaginatedResponse, PaginationParams } from '../models/common.model';
import { Clip, ClipListResponse, UpdateClipRequest } from '../models/clip.model';

@Injectable({
  providedIn: 'root',
})
export class ClipService {
  private readonly api = inject(ApiService);

  // Reactive state signals for UI binding
  readonly clips = signal<Clip[]>([]);
  readonly selectedClip = signal<Clip | null>(null);

  /**
   * Retrieve all generated clips across all videos.
   * GET /api/clips
   */
  getAllClips(params?: PaginationParams): Observable<PaginatedResponse<Clip>> {
    return this.api.get<PaginatedResponse<Clip>>('clips', params as Record<string, string | number>);
  }

  /**
   * Retrieve all generated clips for a given video ID.
   * GET /api/videos/{videoId}/clips
   */
  getClipsByVideo(videoId: string): Observable<Clip[]> {
    return this.api
      .get<ClipListResponse>(`videos/${videoId}/clips`)
      .pipe(map((res) => res.data));
  }

  /**
   * Retrieve a single clip by its UUID.
   * GET /api/clips/{id}
   */
  getClip(id: string): Observable<Clip> {
    return this.api.get<Clip>(`clips/${id}`);
  }

  /**
   * Update clip metadata, title, or start/end boundaries.
   * PATCH /api/clips/{id}
   */
  updateClip(id: string, payload: UpdateClipRequest): Observable<Clip> {
    return this.api.patch<Clip>(`clips/${id}`, payload);
  }

  /**
   * Delete an individual clip and its storage assets.
   * DELETE /api/clips/{id}
   */
  deleteClip(id: string): Observable<void> {
    return this.api.delete<void>(`clips/${id}`);
  }
}
