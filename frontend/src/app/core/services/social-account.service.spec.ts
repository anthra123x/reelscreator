import { TestBed } from '@angular/core/testing';
import { of, firstValueFrom } from 'rxjs';
import { SocialAccountService } from './social-account.service';
import { ApiService } from './api.service';
import { MockConnectRequest, MockConnectResponse, SocialAccount, SocialAccountsResponse } from '../models/social-account.model';

describe('SocialAccountService', () => {
  let service: SocialAccountService;
  let apiMock: {
    get: ReturnType<typeof vi.fn>;
    post: ReturnType<typeof vi.fn>;
    delete: ReturnType<typeof vi.fn>;
  };

  beforeEach(() => {
    apiMock = {
      get: vi.fn(),
      post: vi.fn(),
      delete: vi.fn(),
    };

    TestBed.configureTestingModule({
      providers: [
        SocialAccountService,
        { provide: ApiService, useValue: apiMock },
      ],
    });
    service = TestBed.inject(SocialAccountService);
  });

  it('should list connected accounts, map is_active and status, and unwrap data array', async () => {
    const mockAccounts: SocialAccount[] = [
      {
        id: 'acc-1',
        platform: 'tiktok',
        platform_username: '@creator',
        connected_at: new Date().toISOString(),
        is_mock: true,
      },
    ];
    const response: SocialAccountsResponse = { data: mockAccounts };

    apiMock.get.mockReturnValue(of(response));

    const accounts = await firstValueFrom(service.getAccounts());
    expect(accounts.length).toBe(1);
    expect(accounts[0].platform).toBe('tiktok');
    expect(accounts[0].is_active).toBe(true);
    expect(accounts[0].status).toBe('connected');
    expect(service.accounts()[0].is_active).toBe(true);
    expect(service.accounts()[0].status).toBe('connected');
  });

  it('should call connectSimple via POST /social-accounts/connect and update signal accounts', async () => {
    const simpleRes = {
      message: 'Social accounts connected successfully',
      connected: [
        {
          id: 'acc-tiktok',
          platform: 'tiktok' as const,
          platform_username: 'auto_tiktok',
          is_mock: true,
          status: 'connected',
          is_active: true,
          connected_at: new Date().toISOString(),
        },
        {
          id: 'acc-ig',
          platform: 'instagram' as const,
          platform_username: 'auto_ig',
          is_mock: true,
          status: 'connected',
          is_active: true,
          connected_at: new Date().toISOString(),
        },
      ],
    };

    apiMock.post.mockReturnValue(of(simpleRes));

    const result = await firstValueFrom(
      service.connectSimple({ platform: 'all', mode: 'auto' })
    );

    expect(apiMock.post).toHaveBeenCalledWith('social-accounts/connect', {
      platform: 'all',
      mode: 'auto',
    });
    expect(result.connected.length).toBe(2);
    expect(service.accounts().length).toBe(2);
    expect(service.accounts().find((a) => a.platform === 'tiktok')?.is_active).toBe(true);
  });

  it('should provision mock account and update signal state', async () => {
    const req: MockConnectRequest = { platform: 'instagram', username: 'test_ig' };
    const res: MockConnectResponse = {
      id: 'mock-1',
      platform: 'instagram',
      platform_username: 'test_ig',
      connected_at: new Date().toISOString(),
      is_mock: true,
    };

    apiMock.post.mockReturnValue(of(res));

    const account = await firstValueFrom(service.mockConnect(req));
    expect(account.id).toBe('mock-1');
    expect(account.is_mock).toBe(true);
    expect(service.accounts().some((a) => a.id === 'mock-1')).toBe(true);
  });

  it('should disconnect account and remove from signal list', async () => {
    service.accounts.set([
      {
        id: 'acc-1',
        platform: 'tiktok',
        platform_username: 'test',
        connected_at: '',
        is_mock: true,
      },
    ]);
    apiMock.delete.mockReturnValue(of(undefined));

    await firstValueFrom(service.disconnectAccount('acc-1'));
    expect(apiMock.delete).toHaveBeenCalledWith('social-accounts/acc-1');
    expect(service.accounts().length).toBe(0);
  });
});
