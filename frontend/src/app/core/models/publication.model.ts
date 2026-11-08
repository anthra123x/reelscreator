import {
  PaginationParams,
  PublicationStatus,
  SocialPlatform,
} from './common.model';
import { Clip } from './clip.model';
import { SocialAccount } from './social-account.model';

export interface Publication {
  id: string;
  clip_id: string;
  social_account_id?: string;
  platform?: SocialPlatform | string;
  status: PublicationStatus;
  platform_post_id?: string | null;
  scheduled_at?: string | null;
  published_at?: string | null;
  error_message?: string | null;
  created_at?: string;
  updated_at?: string;
  clip?: Clip;
  social_account?: SocialAccount;
}

export interface PublishClipRequest {
  platforms: SocialPlatform[];
  caption?: string | null;
  scheduled_at?: string | null;
}

export interface PublishClipResponse {
  publication_ids: string[];
  status: 'queued' | string;
}

export interface RetryPublicationResponse {
  message: string;
  publication: Publication;
  status: 'queued' | string;
}

export interface PublicationFilterParams extends PaginationParams {
  status?: PublicationStatus;
}
