import { ClipStatus } from './common.model';
import { Video } from './video.model';
import { Publication } from './publication.model';

export interface ClipMetadata {
  width?: number;
  height?: number;
  [key: string]: unknown;
}

export interface Clip {
  id: string;
  video_id: string;
  file_path: string;
  thumbnail_path: string | null;
  file_url: string;
  thumbnail_url: string | null;
  title: string | null;
  start_time: number;
  end_time: number;
  duration: number;
  status: ClipStatus;
  metadata?: ClipMetadata;
  created_at?: string;
  updated_at?: string;
  video?: Video;
  publications?: Publication[];
}

export interface UpdateClipRequest {
  title?: string | null;
  start_time?: number;
  end_time?: number;
  metadata?: Record<string, unknown>;
}

export interface ClipListResponse {
  data: Clip[];
}
