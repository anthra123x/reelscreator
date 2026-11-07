import {
  CuttingStrategy,
  PaginationParams,
  VerticalMode,
  VideoStatus,
} from './common.model';
import { Clip } from './clip.model';

export interface VideoMetadata {
  strategy?: CuttingStrategy;
  interval_seconds?: number;
  max_duration_seconds?: number;
  vertical_mode?: VerticalMode;
  [key: string]: unknown;
}

export interface Video {
  id: string;
  user_id?: string;
  source_url: string;
  title: string | null;
  status: VideoStatus;
  file_path?: string | null;
  duration?: number | null;
  metadata?: VideoMetadata;
  error_message?: string | null;
  created_at: string;
  updated_at?: string;
  clips?: Clip[];
}

export interface VideoIngestRequest {
  source_url: string;
  strategy?: CuttingStrategy;
  interval_seconds?: number;
  max_duration?: number;
  max_duration_seconds?: number;
  vertical_mode?: VerticalMode;
  title?: string | null;
}

export interface VideoIngestResponse {
  id: string;
  source_url: string;
  title: string | null;
  status: VideoStatus;
  created_at: string;
}

export interface VideoStatusResponse {
  id: string;
  status: VideoStatus;
  progress_percent: number;
  error_message: string | null;
}

export interface VideoFilterParams extends PaginationParams {
  status?: VideoStatus;
}
