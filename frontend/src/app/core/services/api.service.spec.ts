import { TestBed } from '@angular/core/testing';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideHttpClient } from '@angular/common/http';
import { ApiService } from './api.service';
import { API_BASE_URL } from '../tokens/api-url.token';

describe('ApiService', () => {
  let service: ApiService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        ApiService,
        { provide: API_BASE_URL, useValue: '/api' },
      ],
    });
    service = TestBed.inject(ApiService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('should send GET request to resolved endpoint with query params', () => {
    service.get<{ id: string }>('videos', { status: 'ready', per_page: 15 }).subscribe((res) => {
      expect(res.id).toBe('123');
    });

    const req = httpMock.expectOne('/api/videos?status=ready&per_page=15');
    expect(req.request.method).toBe('GET');
    req.flush({ id: '123' });
  });

  it('should send POST request with body', () => {
    service.post<{ success: boolean }>('videos', { source_url: 'https://test.com' }).subscribe((res) => {
      expect(res.success).toBe(true);
    });

    const req = httpMock.expectOne('/api/videos');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ source_url: 'https://test.com' });
    req.flush({ success: true });
  });

  it('should send PATCH request with body', () => {
    service.patch<{ updated: boolean }>('clips/123', { title: 'New' }).subscribe((res) => {
      expect(res.updated).toBe(true);
    });

    const req = httpMock.expectOne('/api/clips/123');
    expect(req.request.method).toBe('PATCH');
    req.flush({ updated: true });
  });

  it('should send DELETE request', () => {
    service.delete('videos/123').subscribe();

    const req = httpMock.expectOne('/api/videos/123');
    expect(req.request.method).toBe('DELETE');
    req.flush(null);
  });
});
