import { Routes } from '@angular/router';

export const routes: Routes = [
  {
    path: '',
    pathMatch: 'full',
    redirectTo: 'dashboard',
  },
  {
    path: 'dashboard',
    loadComponent: () =>
      import('./features/dashboard/dashboard.component').then(
        (m) => m.DashboardComponent
      ),
    title: 'Dashboard — ReelForge',
  },
  {
    path: 'video-upload',
    loadComponent: () =>
      import('./features/video-upload/video-upload.component').then(
        (m) => m.VideoUploadComponent
      ),
    title: 'Ingest Video — ReelForge',
  },
  {
    path: 'clips',
    loadComponent: () =>
      import('./features/clips/clips.component').then((m) => m.ClipsComponent),
    title: 'Clip Library — ReelForge',
  },
  {
    path: 'social-accounts',
    loadComponent: () =>
      import('./features/social-accounts/social-accounts.component').then(
        (m) => m.SocialAccountsComponent
      ),
    title: 'Social Accounts — ReelForge',
  },
  {
    path: 'publish',
    loadComponent: () =>
      import('./features/publish/publish.component').then(
        (m) => m.PublishComponent
      ),
    title: 'Publishing — ReelForge',
  },
  {
    path: '**',
    redirectTo: 'dashboard',
  },
];
