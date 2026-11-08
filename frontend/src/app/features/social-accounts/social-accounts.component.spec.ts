import { ComponentFixture, TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { SocialAccountsComponent } from './social-accounts.component';
import { SocialAccountService } from '../../core/services/social-account.service';
import { SocialAccount } from '../../core/models/social-account.model';

describe('SocialAccountsComponent (M7 Simplified Social UI & Quick Connect)', () => {
  let component: SocialAccountsComponent;
  let fixture: ComponentFixture<SocialAccountsComponent>;

  let mockSocialService: {
    getAccounts: ReturnType<typeof vi.fn>;
    connectSimple: ReturnType<typeof vi.fn>;
    connectPlatform: ReturnType<typeof vi.fn>;
    mockConnect: ReturnType<typeof vi.fn>;
    disconnectAccount: ReturnType<typeof vi.fn>;
  };

  const initialAccounts: SocialAccount[] = [
    {
      id: 'acc-tk',
      platform: 'tiktok',
      platform_username: 'creator_tiktok',
      connected_at: new Date().toISOString(),
      is_mock: true,
      status: 'connected',
      is_active: true,
    },
  ];

  beforeEach(async () => {
    mockSocialService = {
      getAccounts: vi.fn().mockReturnValue(of(initialAccounts)),
      connectSimple: vi.fn().mockReturnValue(of({
        message: 'Social accounts connected successfully',
        connected: [
          { id: '1', platform: 'tiktok', platform_username: 'creator_tk', is_mock: true, status: 'connected', is_active: true, connected_at: '' },
          { id: '2', platform: 'instagram', platform_username: 'creator_ig', is_mock: true, status: 'connected', is_active: true, connected_at: '' },
          { id: '3', platform: 'youtube', platform_username: 'creator_yt', is_mock: true, status: 'connected', is_active: true, connected_at: '' },
        ],
      })),
      connectPlatform: vi.fn().mockReturnValue(of({ auth_url: 'https://oauth.example.com', state: 'xyz' })),
      mockConnect: vi.fn().mockReturnValue(of({
        id: 'mock-1',
        platform: 'instagram',
        platform_username: 'ig_user',
        connected_at: new Date().toISOString(),
        is_mock: true,
      })),
      disconnectAccount: vi.fn().mockReturnValue(of(undefined)),
    };

    await TestBed.configureTestingModule({
      imports: [SocialAccountsComponent],
      providers: [
        { provide: SocialAccountService, useValue: mockSocialService },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(SocialAccountsComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should display accounts with status and is_active tags', () => {
    expect(mockSocialService.getAccounts).toHaveBeenCalled();
    expect(component.accounts().length).toBe(1);

    const tkAccount = component.getAccount('tiktok');
    expect(tkAccount).toBeDefined();
    expect(tkAccount?.status).toBe('connected');
    expect(tkAccount?.is_active).toBe(true);

    const el: HTMLElement = fixture.nativeElement;
    const connectedCard = el.querySelector('.platform-card.tiktok');
    expect(connectedCard?.textContent).toContain('● connected');
    expect(connectedCard?.textContent).toContain('Activo');
  });

  it('should call connectSimple with platform: all when clicking Vincular Todas las Cuentas', () => {
    component.onQuickConnectAll();

    expect(mockSocialService.connectSimple).toHaveBeenCalledWith({
      platform: 'all',
      mode: 'auto',
    });
    expect(component.mockSuccessMessage()).toContain('Todas las cuentas');
  });

  it('should call connectSimple for single platform on Conexión Rápida click', () => {
    component.onQuickConnectPlatform('instagram');

    expect(mockSocialService.connectSimple).toHaveBeenCalledWith({
      platform: 'instagram',
      mode: 'auto',
    });
    expect(component.mockSuccessMessage()).toContain('INSTAGRAM vinculada exitosamente');
  });
});
