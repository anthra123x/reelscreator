/**
 * Common data transfer objects, pagination schemas, and union types
 * aligned with Laravel 12 backend responses.
 */

export type CuttingStrategy =
  | 'fixed_interval'
  | 'scene_detection'
  | 'silence_detection'
  | 'manual_timestamps';

export type VerticalMode = 'blur_padding' | 'center_crop' | 'letterbox';

export type SocialPlatform = 'tiktok' | 'instagram' | 'youtube';

export type VideoStatus =
  | 'pending'
  | 'downloading'
  | 'processing'
  | 'ready'
  | 'failed';

export type ClipStatus = 'ready' | 'processing' | 'failed';

export type PublicationStatus =
  | 'pending'
  | 'scheduled'
  | 'publishing'
  | 'published'
  | 'failed';

export interface PaginationLink {
  url: string | null;
  label: string;
  active: boolean;
}

export interface PaginatedResponse<T> {
  current_page: number;
  data: T[];
  first_page_url: string;
  from: number | null;
  last_page: number;
  last_page_url: string;
  links: PaginationLink[];
  next_page_url: string | null;
  path: string;
  per_page: number;
  prev_page_url: string | null;
  to: number | null;
  total: number;
}

export interface DataResponse<T> {
  data: T;
}

export interface ApiErrorResponse {
  message: string;
  errors?: Record<string, string[]>;
}

export interface PaginationParams {
  page?: number;
  per_page?: number;
}
