import { Injectable, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { tap } from 'rxjs/operators';
import { ApiService } from './api.service';
import { PaginatedResponse } from '../models/common.model';
import {
  Publication,
  PublicationFilterParams,
  PublishClipRequest,
  PublishClipResponse,
  RetryPublicationResponse,
} from '../models/publication.model';

@Injectable({
  providedIn: 'root',
})
export class PublishService {
  private readonly api = inject(ApiService);

  // Reactive state signals for UI binding
  readonly publications = signal<Publication[]>([]);
  readonly isPublishing = signal<boolean>(false);

  /**
   * Dispatch or schedule multi-platform publication for an individual clip.
   * POST /api/clips/{id}/publish
   */
  publishClip(
    clipId: string,
    payload: PublishClipRequest
  ): Observable<PublishClipResponse> {
    this.isPublishing.set(true);
    return this.api
      .post<PublishClipResponse>(`clips/${clipId}/publish`, payload)
      .pipe(
        tap({
          next: () => this.isPublishing.set(false),
          error: () => this.isPublishing.set(false),
        })
      );
  }

  /**
   * Fetch paginated audit log of publications.
   * GET /api/publications
   */
  getPublications(
    params?: PublicationFilterParams
  ): Observable<PaginatedResponse<Publication>> {
    return this.api.get<PaginatedResponse<Publication>>(
      'publications',
      params as Record<string, string | number>
    );
  }

  /**
   * Retry a previously failed publication.
   * POST /api/publications/{id}/retry
   */
  retryPublication(publicationId: string): Observable<RetryPublicationResponse> {
    return this.api.post<RetryPublicationResponse>(
      `publications/${publicationId}/retry`
    );
  }
}
