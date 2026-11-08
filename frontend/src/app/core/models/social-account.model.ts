import { SocialPlatform } from './common.model';

export interface SocialAccount {
  id: string;
  platform: SocialPlatform;
  platform_user_id?: string;
  platform_username: string;
  connected_at: string;
  expires_at?: string | null;
  is_mock: boolean;
  is_active?: boolean;
  status?: string;
}

export interface SimpleConnectRequest {
  platform: string;
  mode?: string;
  username?: string;
  access_token?: string;
  refresh_token?: string;
}

export interface SimpleConnectResponse {
  message: string;
  connected: SocialAccount[];
  account?: SocialAccount;
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
  configured?: boolean;
  message?: string;
  redirect_uri?: string;
}

export interface SocialAccountsResponse {
  data: SocialAccount[];
}
