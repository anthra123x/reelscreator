# Social Accounts Feature Module — Frontend ReelForge

## 1. Propósito y alcance
El módulo de características Social Accounts administra la vinculación, supervisión y aprovisionamiento simplificado de los canales sociales de destino (TikTok, Instagram Reels, YouTube Shorts) para la distribución desatendida de clips en ReelForge. Su alcance incluye la visualización detallada del estado de conexión y vigencia de tokens, la ejecución de vinculación ágil en un solo clic para todas las plataformas, acciones de conexión directa simulada por plataforma y el soporte de inicio de sesión OAuth estándar.

## 2. Contratos y API
El módulo expone el componente standalone `SocialAccountsComponent`:
- Ruta asociada: `/social-accounts`.
- Contratos consumidos:
  - `GET /api/social-accounts`: Listado de cuentas vinculadas (`{ data: SocialAccount[] }`) con propiedades `is_active` y `status`.
  - `POST /api/social-accounts/connect`: Endpoint ágil unificado (`platform: 'all'|'tiktok'|'instagram'|'youtube'`, `mode: 'auto'|'mock'|'env'|'direct'`).
  - `POST /api/social-accounts/connect/{platform}`: Inicialización de flujo OAuth interactivo (`{ auth_url: string, state: string }`).
  - `POST /api/social-accounts/mock-connect`: Aprovisionamiento manual de cuenta simulada.
  - `DELETE /api/social-accounts/{id}`: Desconexión y eliminación de credenciales de canal.
- Señales reactivas internas:
  - `accounts`: `WritableSignal<SocialAccount[]>` lista de cuentas activas.
  - `isLoading`: `WritableSignal<boolean>` estado de carga inicial.
  - `isConnectingAll`: `WritableSignal<boolean>` estado de vinculación masiva en 1 clic.
  - `connectingPlatform`: `WritableSignal<string | null>` plataforma individual en proceso de conexión rápida.
  - `isSubmittingMock`: `WritableSignal<boolean>` estado de envío de vinculación simulada manual.
  - `isDisconnecting`: `WritableSignal<boolean>` estado de desconexión en proceso.
  - `mockSuccessMessage`: `WritableSignal<string | null>` mensaje de confirmación de vinculación.

## 3. Dependencias
- `@angular/core`: Primitivas de componentes, inyección y señales reactivas.
- `@angular/common`: Directivas comunes (`ngFor`, `ngIf`, `ngClass`, `uppercase`).
- `@angular/forms`: `FormsModule` para el formulario de conexión manual.
- `frontend/src/app/core/services`: `SocialAccountService`.
- `frontend/src/app/shared`: `RelativeTimePipe`.

## 4. Mapa de archivos
- `social-accounts.component.ts`: Componente standalone integral con el botón de conexión ágil en 1 clic, panel de desarrollador para cuentas simuladas y tarjetas de estado con etiquetas de vigencia.
- `social-accounts.component.spec.ts`: Suite de pruebas unitarias que valida la visualización de etiquetas `status` e `is_active`, el botón de vinculación en 1 clic y la conexión rápida directa por tarjeta.

## 5. Lógica y flujo
1. Al montarse el componente, se invoca `loadAccounts()`, que consulta `GET /api/social-accounts` y almacena el resultado en la señal `accounts`.
2. Las tarjetas de plataforma evalúan `getAccount(platformKey)` para determinar si el canal está enlazado.
3. Si está enlazado, se despliegan las etiquetas de estado (`status`, ej. `connected` o `expired`), el indicador de activación (`Activo` o `Inactivo`), el nombre de usuario (`@handle`), la fecha relativa de vinculación y el tipo de canal (Adaptador Mock o OAuth Real).
4. Si no está enlazado, se ofrece tanto el botón estándar de autorización OAuth como un botón de acción directa "Conexión Rápida (Mock)" que despacha `POST /api/social-accounts/connect` para esa plataforma.
5. El encabezado de la página incluye el botón principal "Vincular Todas las Cuentas (Quick Connect)", que enlaza las tres redes simultáneamente con `platform: 'all'`.

## 6. Decisiones de diseño y gotchas
- Reducción de fricción para pruebas y producción: El botón de 1 clic permite configurar el entorno completo de redes en una sola llamada sin tener que llenar formularios repetitivos para cada plataforma.
- Soporte transparente para tokens mock y reales: La interfaz consume la misma respuesta estructurada devuelta por `OAuthController::simpleConnect`, adaptándose sin requerir bifurcaciones complejas en la vista.
- Etiquetas de diagnóstico en tarjetas: La inclusión explícita de `is_active` y `status` permite identificar de inmediato si una cuenta vinculada tiene un token vencido o deshabilitado.

## 7. Estado y últimos cambios
- Milestone M7: Incorporación del botón de 1 clic "Vincular Todas las Cuentas (Quick Connect)" en el encabezado.
- Milestone M7: Adición del botón de acción directa "Conexión Rápida (Mock)" en cada tarjeta desconectada.
- Milestone M7: Renderizado de etiquetas visuales de `status` y estado `is_active` en las tarjetas de cuentas vinculadas.
- Milestone M7: Creación de la suite de pruebas unitarias `social-accounts.component.spec.ts`.

## 8. Cómo verificar
```bash
cd frontend
bun run build # Valida la ausencia de errores en la compilación de SocialAccountsComponent
bun run test -- --watch=false # Ejecuta las pruebas unitarias del módulo Social Accounts
```
