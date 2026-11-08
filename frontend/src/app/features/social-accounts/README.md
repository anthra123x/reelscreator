# Social Accounts Feature Module — Frontend ReelForge

## 1. Propósito y alcance
El módulo de características Social Accounts administra la vinculación, supervisión y aprovisionamiento simplificado de los canales sociales de destino (TikTok, Instagram Reels, YouTube Shorts) para la distribución desatendida de clips en ReelForge. Su alcance incluye la visualización detallada del estado de conexión y vigencia de canales de producción, la ejecución de vinculación directa mediante handle (@usuario), avisos de configuración para inicio de sesión OAuth y la desconexión segura de canales.

## 2. Contratos y API
El módulo expone el componente standalone `SocialAccountsComponent`:
- Ruta asociada: `/social-accounts`.
- Contratos consumidos:
  - `GET /api/social-accounts`: Listado de cuentas vinculadas (`{ data: SocialAccount[] }`) con propiedades `is_active` y `status`.
  - `POST /api/social-accounts/connect`: Endpoint ágil unificado (`platform: 'all'|'tiktok'|'instagram'|'youtube'`, `mode: 'auto'|'direct'|'env'|'mock'`, `username?: string`).
  - `POST /api/social-accounts/connect/{platform}`: Inicialización de flujo OAuth interactivo con verificación de credenciales registradas (`{ auth_url: string, state: string, configured: boolean, message?: string }`).
  - `DELETE /api/social-accounts/{id}`: Desconexión y eliminación de credenciales de canal.
- Señales reactivas internas:
  - `accounts`: `WritableSignal<SocialAccount[]>` lista de cuentas activas.
  - `isLoading`: `WritableSignal<boolean>` estado de carga inicial.
  - `isConnectingAll`: `WritableSignal<boolean>` estado de vinculación masiva de canales principales.
  - `connectingPlatform`: `WritableSignal<string | null>` plataforma individual en proceso de conexión.
  - `isDisconnecting`: `WritableSignal<boolean>` estado de desconexión en proceso.
  - `directUsernames`: Record reactivo con el valor del input `@usuario` por plataforma.
  - `oauthNotices`: Record reactivo con avisos informativos sobre credenciales OAuth por plataforma.

## 3. Dependencias
- `@angular/core`: Primitivas de componentes, inyección y señales reactivas.
- `@angular/common`: Directivas comunes (`ngFor`, `ngIf`, `ngClass`, `uppercase`).
- `@angular/forms`: `FormsModule` para el formulario de conexión manual.
- `frontend/src/app/core/services`: `SocialAccountService`.
- `frontend/src/app/shared`: `RelativeTimePipe`.

## 4. Mapa de archivos
- `social-accounts.component.ts`: Componente standalone integral con inputs para vinculación directa por handle (@usuario), botón de vinculación de canales principales y tarjetas de canal con etiquetas de estado en producción.
- `social-accounts.component.spec.ts`: Suite de pruebas unitarias que valida la visualización de etiquetas `status` e `is_active`, el botón de vinculación masiva y la conexión de canales.

## 5. Lógica y flujo
1. Al montarse el componente, se invoca `loadAccounts()`, que consulta `GET /api/social-accounts` y almacena el resultado en la señal `accounts`.
2. Las tarjetas de plataforma evalúan `getAccount(platformKey)` para determinar si el canal está enlazado.
3. Si está enlazado, se despliegan las etiquetas de estado (`status`, ej. `connected` o `expired`), el indicador de activación (`Activo` o `Inactivo`), el nombre de usuario (`@handle`), la fecha relativa de vinculación y la insignia de Canal Vinculado.
4. Si no está enlazado, se ofrece un campo de texto para ingresar el `@usuario` o handle del canal y el botón principal "Conectar Canal", además de la opción de autorización OAuth.
5. Si el usuario presiona "Conectar via OAuth" y la plataforma no cuenta con credenciales de desarrollador configuradas, el sistema despliega un aviso guiado recomendando la vinculación directa con su handle.
6. El encabezado de la página incluye el botón "Vincular Canales Principales", que enlaza las tres redes simultáneamente con `platform: 'all'`.

## 6. Decisiones de diseño y gotchas
- Eliminación total de paneles de simulación / mock: En producción se remueven etiquetas o secciones de prueba local para mantener una interfaz sobria, profesional y orientada a canales reales.
- Vinculación directa por handle: Permite al usuario conectar sus cuentas inmediatamente con su identificador público mientras se completan los procesos de aprobación en consolas de desarrolladores de Meta/TikTok.
- Etiquetas de diagnóstico en tarjetas: La inclusión explícita de `is_active` y `status` permite identificar de inmediato si una cuenta vinculada tiene un token vencido o deshabilitado.

## 7. Estado y últimos cambios
- Eliminación del banner y panel "MODO MOCK / PRUEBAS LOCALES".
- Incorporación de conexión directa mediante input de handle `@usuario` por plataforma.
- Soporte para avisos guiados ante intentos de OAuth sin credenciales registradas.
- Actualización de textos y etiquetas a "Canal de Producción" y "Canal Vinculado".
- Verificación completa con 124 pruebas unitarias de frontend pasando.

## 8. Cómo verificar
```bash
cd frontend
bun run build # Valida la ausencia de errores en la compilación de SocialAccountsComponent
bun run test -- --watch=false # Ejecuta las pruebas unitarias del módulo Social Accounts
```
