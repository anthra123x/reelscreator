# Modulo SocialAccounts

## 1. Propósito y alcance
El modulo `SocialAccounts` gestiona la autenticacion e integracion OAuth 2.0 con las plataformas de distribucion social soportadas (TikTok, Instagram Reels y YouTube Shorts). Su responsabilidad abarca la generacion de URLs de autorizacion con PKCE y alcances especificos, intercambio de codigos de autorizacion por tokens de acceso y refresco, almacenamiento cifrado en reposo mediante Eloquent Encrypted Casting (AES-256-CBC), renovacion automatica de credenciales proximas a caducar y aprovisionamiento instantaneo de cuentas sinteticas (`mock-connect`) para desarrollo y pruebas desconectadas.

Este modulo provee a `Publishing` las credenciales necesarias para despachar publicaciones y valida la vigencia temporal de los tokens antes de cada subida, colaborando estrechamente con los adaptadores sociales y el simulador `MockSocialAdapter`.

## 2. Contratos y API
### Endpoints HTTP
- `GET /api/social-accounts`: Lista las cuentas sociales vinculadas por el usuario autenticado.
  - Respuesta: `200 OK` (`{"data": [{"id": uuid, "platform": "tiktok"|"instagram"|"youtube", "platform_user_id": string, "platform_username": string, "connected_at": iso8601, "expires_at": iso8601, "is_mock": bool}]}`).
- `POST /api/social-accounts/connect/{platform}`: Inicializa el flujo de autenticacion OAuth generando la URL del proveedor y el estado CSRF.
  - Parametro de ruta: `platform` (`tiktok`, `instagram`, `youtube`).
  - Respuestas: `200 OK` (`{"auth_url": string, "state": string}`), `400 Bad Request`.
- `GET /api/social-accounts/callback/{platform}`: Endpoint de redireccion que recibe el codigo de autorizacion y persiste las credenciales cifradas.
  - Query params: `code` (string), `state` (string).
  - Respuestas: `200 OK` (Registro de cuenta creada/actualizada), `400 Bad Request`.
- `POST /api/social-accounts/mock-connect`: Aprovisiona inmediatamente una cuenta de prueba sin requerir credenciales reales de desarrollador.
  - Payload: `{"platform": "tiktok"|"instagram"|"youtube", "username": string}`
  - Respuestas: `201 Created` (`{"id": uuid, "platform": string, "platform_username": string, "connected_at": iso8601, "is_mock": true}`), `422 Unprocessable Content`.
- `DELETE /api/social-accounts/{id}`: Desvincula y elimina la cuenta social.
  - Parametro de ruta: `id` (UUID).
  - Respuestas: `204 No Content`, `404 Not Found`.

### Modelo de Datos y Cifrado
- `SocialAccount`:
  - `access_token`: Cifrado transparente (`cast => 'encrypted'`).
  - `refresh_token`: Cifrado transparente (`cast => 'encrypted'`).
  - `expires_at`: `datetime` con zona horaria.
  - Atributo computado `is_mock`: `true` si el ID inicia con `mock_` o `config('social.mock_mode') === true`.
  - Metodo `isExpired()`: Determina si el token expiro o expirara en menos de 5 minutos.

## 3. Dependencias
- **Modulos internos**:
  - `App\Models\User`: Vinculacion de propiedad mediante clave foranea `user_id`.
  - `App\Modules\Publishing`: Provee las credenciales requeridas por los adaptadores y trabajos en cola.
- **Paquetes externos**:
  - `illuminate/support`, `illuminate/database`, `illuminate/encryption`, `guzzlehttp/guzzle`.

## 4. Mapa de archivos
- `Controllers/OAuthController.php`: Controlador REST para flujos de conexion, redireccion, mock y desconexion.
- `Models/SocialAccount.php`: Modelo con clave UUID, casteo cifrado para credenciales y detector de expiracion.
- `Routes/api.php`: Declaracion de rutas bajo el prefijo `social-accounts`.
- `Services/InstagramOAuthService.php`: Logica de autorizacion y renovacion via Meta Graph API v21.0.
- `Services/SocialAccountService.php`: Repositorio y factoria para persistencia y consulta de cuentas sociales.
- `Services/TikTokOAuthService.php`: Logica de autorizacion PKCE y renovacion via TikTok Content Posting API v2.
- `Services/YouTubeOAuthService.php`: Logica de autorizacion y refresco de tokens via Google Identity Platform.

## 5. Lógica y flujo
1. El usuario solicita conectar una red social mediante `POST /api/social-accounts/connect/{platform}`.
2. El servicio correspondiente genera una URL oficial con state criptografico aleatorio.
3. El proveedor social redirige a `GET /api/social-accounts/callback/{platform}` con el parametro `code`.
4. El servicio intercambia el codigo por tokens de acceso y refresco.
5. `SocialAccount::updateOrCreate` almacena los tokens cifrados con la `APP_KEY` de Laravel.
6. En ejecucion de publicaciones, los adaptadores evaluan `$account->isExpired()`. Si esta proximo a expirar, invocan `refreshToken($account)` para obtener credenciales frescas de forma transparente.
7. En entornos de prueba o CI/CD, `POST /api/social-accounts/mock-connect` emite un registro con tokens sinteticos validos de forma inmediata sin dependencias de red externas.

## 6. Decisiones de diseño y gotchas
- **Cifrado obligatorio en reposo**: Los tokens nunca se guardan en texto plano en la base de datos PostgreSQL, neutralizando filtraciones accidentales por volcados o logs de depuracion.
- **Aislamiento en serializacion**: `access_token` y `refresh_token` forman parte de `$hidden`, impidiendo que se expongan accidentalmente en respuestas JSON hacia el frontend.
- **Deteccion proactiva de expiracion**: El metodo `isExpired()` considera un token como vencido si resta menos de 5 minutos de validez, activando la renovacion antes de que falle una publicacion programada.
- **Soporte de refresco en adaptadores**: Cuando un token expira durante un proceso de publicacion encolado, tanto los adaptadores reales como `MockSocialAdapter` gestionan la renovacion antes de enviar el archivo.

## 7. Estado y últimos cambios
- Sincronizacion con los adaptadores de `Publishing` y `MockSocialAdapter` para verificacion de expiracion y renovacion.
- Mantenimiento del cifrado transparente de credenciales OAuth en base de datos PostgreSQL.
- Verificacion de contratos y suite de pruebas unitarias al 100%.

## 8. Cómo verificar
Ejecutar la suite de pruebas del modulo:
```bash
cd backend && php artisan test --filter=SocialAccount
```
Ejecutar pruebas de servicios OAuth:
```bash
cd backend && php artisan test --filter=OAuthServicesTest
```
Comandos de verificacion estatica y formateo:
```bash
cd backend && ./vendor/bin/pint --test
```
