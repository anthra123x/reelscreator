# Social Accounts Feature Module — Frontend ReelForge

## 1. Propósito y alcance
El módulo de características Social Accounts administra la vinculación y supervisión de los canales sociales de destino para la distribución de clips en ReelForge. Su alcance incluye la visualización del estado de conexión para TikTok, Instagram Reels y YouTube Shorts, la gestión del ciclo de vida de los tokens de autenticación, el inicio del protocolo OAuth 2.0 y la provisión de un panel para el aprovisionamiento de cuentas simuladas (mock) para entornos de prueba sin credenciales reales de API.

## 2. Contratos y API
El módulo expone el componente standalone `SocialAccountsComponent`:
- Ruta asociada: `/social-accounts`.
- Contratos consumidos:
  - `GET /api/social-accounts`: Listado de cuentas vinculadas (`{ data: SocialAccount[] }`).
  - `POST /api/social-accounts/connect/{platform}`: Inicialización de flujo OAuth devolviendo `{ auth_url: string, state: string }`.
  - `POST /api/social-accounts/mock-connect`: Vinculación instantánea de cuenta simulada (`platform`, `username`).
  - `DELETE /api/social-accounts/{id}`: Desconexión y eliminación de credenciales de canal.
- Señales reactivas internas:
  - `accounts`: `WritableSignal<SocialAccount[]>` lista de cuentas activas.
  - `isLoading`: `WritableSignal<boolean>` estado de carga inicial.
  - `isSubmittingMock`: `WritableSignal<boolean>` estado de envío de vinculación simulada.
  - `isDisconnecting`: `WritableSignal<boolean>` estado de desconexión en proceso.
  - `mockSuccessMessage`: `WritableSignal<string | null>` mensaje de confirmación de vinculación.

## 3. Dependencias
- `@angular/core`: Primitivas de componentes, inyección y señales.
- `@angular/common`: Directivas comunes (`ngFor`, `ngIf`, `ngClass`).
- `@angular/forms`: `FormsModule` para el formulario de conexión mock.
- `frontend/src/app/core/services`: `SocialAccountService`.
- `frontend/src/app/shared`: `RelativeTimePipe`.

## 4. Mapa de archivos
- `social-accounts.component.ts`: Componente standalone integral con el panel de desarrollador para cuentas simuladas y las tarjetas de estado de TikTok, Instagram Reels y YouTube Shorts.

## 5. Lógica y flujo
1. Al montarse el componente, se invoca `loadAccounts()`, que consulta `GET /api/social-accounts` y almacena el resultado en la señal `accounts`.
2. Las tarjetas de plataforma evalúan `getAccount(platformKey)` para determinar si el canal está enlazado.
3. Si está enlazado, se muestra el nombre de usuario (`@handle`), la fecha relativa de vinculación y el tipo de canal (Adaptador Mock o OAuth Real), junto a un botón de desconexión.
4. Si no está enlazado, se despliega una guía con los formatos y límites de la plataforma y un botón para iniciar la autorización OAuth.
5. El panel superior permite crear cuentas simuladas al instante despachando `POST /api/social-accounts/mock-connect`, actualizando el estado reactivo sin recargar la página.

## 6. Decisiones de diseño y gotchas
- Compatibilidad para entornos de prueba offline: La presencia del panel de desarrollador permite validar el flujo completo de publicación sin requerir llaves de API externas ni túneles de redirección OAuth.
- Confirmación de desconexión: Las acciones de desvinculación exigen confirmación interactiva para evitar la pérdida accidental de canales vinculados.
- Especificaciones de formato visibles: Cada tarjeta documenta los requerimientos técnicos de la red social correspondiente (por ejemplo, límite de 90 segundos para Instagram Reels vs 60 segundos para YouTube Shorts).

## 7. Estado y últimos cambios
- Implementación de las tarjetas dedicadas para TikTok, Instagram Reels y YouTube Shorts.
- Integración de panel de vinculación rápida mock para pruebas de integración.
- Gestión de estado de desconexión con actualización inmediata del almacén reactivo.

## 8. Cómo verificar
```bash
cd frontend
bun run build # Valida la ausencia de errores en la compilación de SocialAccountsComponent
```
