import { SocialPlatform } from './common.model';

export interface SocialAccount {
  id: string;
  platform: SocialPlatform;
  platform_user_id?: string;
  platform_username: string;
  connected_at: string;
  expires_at?: string | null;
  is_mock: boolean;
}

export interface MockConnectRequest {
  platform: SocialPlatform;
  username: string;
}

export interface MockConnectResponse {
  id: string;
  platform: SocialPlatform;
  platform_username: string;
  connected_at: string;
  is_mock: boolean;
}

export interface ConnectPlatformResponse {
  auth_url: string;
  state: string;
}

export interface SocialAccountsResponse {
  data: SocialAccount[];
}
