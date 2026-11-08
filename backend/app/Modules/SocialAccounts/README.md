# Modulo SocialAccounts

## 1. Propósito y alcance
El modulo `SocialAccounts` gestiona la autenticacion, vinculacion, ciclo de vida e integracion con las plataformas de distribucion social soportadas (TikTok, Instagram Reels y YouTube Shorts). Su responsabilidad abarca la conexion unificada y agil de cuentas sociales (`POST /api/social-accounts/connect`), el flujo interactivo OAuth 2.0 con PKCE e intercambio de codigos, el almacenamiento seguro de credenciales con cifrado transparente en reposo mediante Eloquent Encrypted Casting (AES-256-CBC), la deteccion proactiva de expiracion de tokens y el aprovisionamiento de cuentas simuladas (`mock`) y directas por variables de entorno para entornos de produccion y pruebas continuas.

Este modulo provee a la capa de publicacion (`Publishing`) las credenciales autorizadas y activas para despachar reels verticales 9:16 de forma desatendida, garantizando idempotencia en la persistencia sobre Neon PostgreSQL sin duplicar registros por usuario y plataforma.

## 2. Contratos y API
### Endpoints HTTP
- `GET /api/social-accounts`: Lista las cuentas sociales vinculadas del usuario.
  - Formato de respuesta: `200 OK` con coleccion `data`:
    ```json
    {
      "data": [
        {
          "id": "uuid",
          "platform": "tiktok|instagram|youtube",
          "platform_user_id": "string",
          "platform_username": "string",
          "connected_at": "ISO-8601",
          "expires_at": "ISO-8601|null",
          "is_mock": true|false,
          "is_active": true|false,
          "status": "connected|expired"
        }
      ]
    }
    ```
- `POST /api/social-accounts/connect`: Endpoint unificado y agil para conexion de cuentas individuales o por lote multi-plataforma.
  - Payload de entrada:
    ```json
    {
      "platform": "tiktok|instagram|youtube|all",
      "mode": "auto|env|mock|direct",
      "username": "handle_opcional",
      "access_token": "token_opcional"
    }
    ```
  - Reglas de validacion: `platform` obligatoria (`in:tiktok,instagram,youtube,all`), `mode` opcional (`in:auto,env,mock,direct`), `username` opcional (max 255), `access_token` opcional.
  - Comportamiento de modos:
    - `all`: Conecta simultaneamente TikTok, Instagram y YouTube bajo una transaccion de base de datos atomica.
    - `env`: Lee credenciales directas desde `config('social.{platform}')`. Si existen tokens configurados, persiste la cuenta en modo productivo (`is_mock = false`). Si no estan definidos, conmuta automaticamente a cuenta simulada (`is_mock = true`).
    - `direct`: Persiste directamente las credenciales provistas en el payload de la peticion.
    - `mock`: Aprovisiona o actualiza una cuenta sintetica para pruebas inmediatas (`is_mock = true`).
    - `auto`: Evalua disponibilidad de tokens de entorno y estado de `config('social.mock_mode')`, seleccionando `env` o `mock` segun corresponda.
  - Formato de respuesta: `200 OK`:
    ```json
    {
      "message": "Social accounts connected successfully",
      "connected": [
        {
          "id": "uuid",
          "platform": "tiktok|instagram|youtube",
          "platform_username": "string",
          "is_mock": true|false,
          "status": "connected",
          "is_active": true,
          "connected_at": "ISO-8601"
        }
      ],
      "account": { ... }
    }
    ```
- `POST /api/social-accounts/connect/{platform}`: Inicializa el flujo interactivo OAuth generando la URL oficial de autorizacion del proveedor y el token de estado CSRF.
  - Parametro de ruta: `platform` (`tiktok`, `instagram`, `youtube`).
  - Respuestas: `200 OK` (`{"auth_url": string, "state": string}`), `400 Bad Request`.
- `GET /api/social-accounts/callback/{platform}`: Recibe el codigo temporal de autorizacion desde el redirect del proveedor y persiste los tokens cifrados.
  - Query params: `code` (string), `state` (string).
  - Respuestas: `200 OK` (objeto de cuenta vinculada), `400 Bad Request`.
- `POST /api/social-accounts/mock-connect`: Aprovisionamiento rapido de cuentas mock individuales (mantenido por compatibilidad de contratos existentes).
  - Payload: `{"platform": "tiktok|instagram|youtube", "username": string}`.
  - Respuestas: `201 Created`, `422 Unprocessable Content`.
- `DELETE /api/social-accounts/{id}`: Desvincula y elimina el registro de cuenta social por su identificador UUID.
  - Respuestas: `204 No Content`, `404 Not Found`.

### Modelo de Datos y Cifrado
- `SocialAccount`:
  - `id`: Identificador unico UUID version 4.
  - `user_id`: Clave foranea hacia la tabla `users` con eliminacion en cascada.
  - `platform`: Identificador de red (`tiktok`, `instagram`, `youtube`).
  - `platform_user_id`: Identificador externo o identificador sintetico con prefijo `mock_`, `env_` o `direct_`.
  - `access_token`: Cifrado transparente en reposo mediante AES-256-CBC (`cast => 'encrypted'`).
  - `refresh_token`: Cifrado transparente en reposo mediante AES-256-CBC (`cast => 'encrypted'`).
  - `expires_at`: Marca temporal de expiracion con zona horaria (`datetime`).
  - Atributos computados y logica de estado:
    - `is_mock`: Devuelve `true` si `platform_user_id` inicia con `mock_` o si `config('social.mock_mode')` esta activo.
    - `isExpired()`: Retorna `true` si el token expira dentro de los proximos 5 minutos de holgura.
    - `is_active`: Booleano calculado (`!$account->isExpired()`).
    - `status`: Cadena de estado (`'connected'` vs `'expired'`).

## 3. Dependencias
- **Modulos internos**:
  - `App\Models\User`: Vinculacion obligatoria de propiedad mediante `user_id`, resolviendo via fallback seguro `User::first() ?? User::firstOrCreate(...)` para preservar integridad referencial ante `SQLSTATE[23503]`.
  - `App\Modules\Publishing`: Provee credenciales a `TikTokPublisherAdapter`, `InstagramPublisherAdapter` y `YouTubePublisherAdapter`.
- **Configuracion**:
  - `backend/config/social.php`: Centraliza claves de clientes, endpoints API y credenciales directas por variables de entorno (`TIKTOK_ACCESS_TOKEN`, `INSTAGRAM_ACCESS_TOKEN`, `YOUTUBE_ACCESS_TOKEN`, `TIKTOK_USERNAME`, `INSTAGRAM_USERNAME`, `YOUTUBE_USERNAME`).
- **Paquetes externos**:
  - `illuminate/database`, `illuminate/support`, `illuminate/encryption`, `guzzlehttp/guzzle`.

## 4. Mapa de archivos
- `Controllers/OAuthController.php`: Controlador REST con metodos `index`, `simpleConnect`, `connect`, `callback`, `mockConnect` y `destroy`.
- `Models/SocialAccount.php`: Modelo Eloquent con soporte UUID, casteo de cifrado AES-256-CBC, relaciones y evaluadores de expiracion/simulacion.
- `Routes/api.php`: Registro de rutas HTTP bajo el prefijo `social-accounts`.
- `Services/InstagramOAuthService.php`: Integracion con Meta Graph API v21.0 para intercambio de tokens de corta duracion por credenciales de larga duracion.
- `Services/SocialAccountService.php`: Capa de servicio con logica de conexion agil `simpleConnect`, resolucion de usuario, persistencia idempotente `updateOrCreate` y consulta de plataformas activas.
- `Services/TikTokOAuthService.php`: Integracion con TikTok API v2 para autorizacion PKCE e intercambio de codigos.
- `Services/YouTubeOAuthService.php`: Integracion con Google OAuth 2.0 para alcance YouTube Data API v3 y subida de Shorts.

## 5. Lógica y flujo
1. Peticion de conexion agil entrante en `POST /api/social-accounts/connect`.
2. Validacion de esquema de entrada (`platform`, `mode`, `username`, `access_token`).
3. Resolucion del identificador de usuario activo o asignacion del usuario predeterminado del sistema.
4. Determinacion de la lista de plataformas objetivo: si `platform === 'all'`, itera sobre las 3 plataformas soportadas (`tiktok`, `instagram`, `youtube`); de lo contrario, aplica sobre la plataforma individual especificada.
5. Ejecucion transaccional en base de datos (`DB::transaction`):
   - Para cada plataforma, localiza si ya existe un registro previo para la combinacion `(user_id, platform)`.
   - Determina el modo efectivo: en modo `auto`, verifica la existencia de tokens directos en `config("social.{$plat}.access_token")` y que `mock_mode` este desactivado; si no se cumplen, asigna modo `mock`.
   - En modo `env`: extrae tokens de configuracion; si faltan, recurre al aprovisionamiento mock.
   - En modo `direct`: utiliza las credenciales proporcionadas explicitamente en la peticion.
   - En modo `mock`: genera tokens sinteticos y fija identificador con prefijo `mock_{platform}_`.
   - Ejecuta `SocialAccount::updateOrCreate` con clave de busqueda `['user_id' => $userId, 'platform' => $plat]`, preservando el identificador UUID si ya existia previamente y actualizando los campos sin duplicar filas.
6. Retorno de coleccion mapeada con banderas de estado `is_mock`, `is_active` y `status`.
7. En despacho de publicaciones (`Publishing`):
   - Los adaptadores evaluan:
     `if (config('social.mock_mode') || $account->is_mock || empty($account->access_token))`
     conmutando a `MockSocialAdapter` unicamente cuando falta el token, cuando el modo mock global esta activado o cuando la cuenta es sintetica. Cuentas provistas con tokens directos o de entorno ejecutan la llamada real de publicacion en produccion.

## 6. Decisiones de diseño y gotchas
- **Idempotencia estricta por plataforma**: `updateOrCreate` opera sobre `['user_id' => $userId, 'platform' => $platform]`. Esto garantiza que cada operador mantenga a lo sumo un canal activo por plataforma, previniendo duplicados accidentales por multiples clics o reintentos de conexion desde el frontend.
- **Preservacion de UUIDs en reconexion**: Al actualizar un registro existente, se mantiene el UUID original en la columna `id`, protegiendo la integridad referencial con publicaciones historicas asociadas en `publications.social_account_id`.
- **Delegacion hibrida de publicacion**: Los adaptadores permiten la ejecucion productiva inmediata mediante tokens directos sin requerir la configuracion completa de credenciales de aplicacion cliente (`client_key`/`client_secret`) si el token de acceso ya esta disponible y `SOCIAL_MOCK_MODE=false`.
- **Aislamiento de credenciales**: `access_token` y `refresh_token` se mantienen en la lista `$hidden` del modelo `SocialAccount`, impidiendo su fuga hacia respuestas JSON o interfaces de usuario.
- **Compatibilidad con esquemas PostgreSQL**: La tabla `social_accounts` cuenta con indice unico `uq_user_platform_acc` (`user_id, platform, platform_user_id`). Al reutilizar o actualizar identificadores de plataforma de forma controlada, nunca se producen colisiones de clave unica.

## 7. Estado y últimos cambios
- Implementacion del endpoint agil unificado `POST /api/social-accounts/connect` con soporte para modos `auto`, `env`, `mock` y `direct`, asi como sincronizacion masiva mediante `platform: "all"`.
- Incorporacion de claves de tokens directos en `backend/config/social.php` (`TIKTOK_ACCESS_TOKEN`, `INSTAGRAM_ACCESS_TOKEN`, `YOUTUBE_ACCESS_TOKEN` y metadatos asociados).
- Actualizacion de la condicion de delegacion en `TikTokPublisherAdapter`, `InstagramPublisherAdapter` y `YouTubePublisherAdapter` para admitir tokens directos en modo real.
- Incorporacion de campos calculados `is_active` y `status` en el listado `GET /api/social-accounts`.
- Creacion y verificacion de la suite de pruebas `backend/tests/Feature/AgileSocialAccountsConnectionTest.php` con 10 escenarios que cubren validacion, conexion masiva, idempotencia de UUID, modos de entorno y ejecucion de adaptadores.
- Verificacion empirica y pruebas de estres adversarial en `backend/tests/Feature/M6AdversarialEmpiricalChallengeTest.php` (7 escenarios, 140 aserciones) validando idempotencia en 10 llamadas secuenciales, preservacion estricta de UUIDs, cifrado AES-256 en base de datos, no fuga de tokens en JSON, umbral de expiracion a 5 minutos y transicion entre modos sin perdida de identidad.

## 8. Cómo verificar
Ejecutar la suite especifica de conexion agil de cuentas sociales:
```bash
cd backend && php artisan test --filter=AgileSocialAccountsConnectionTest
```
Ejecutar la suite de pruebas adversariales y de estres empirico M6:
```bash
cd backend && php artisan test --filter=M6AdversarialEmpiricalChallengeTest
```
Ejecutar las pruebas unitarias y de caracteristicas de cuentas sociales:
```bash
cd backend && php artisan test --filter=SocialAccount
```
Ejecutar la totalidad de pruebas del backend para descartar regresiones:
```bash
cd backend && php artisan test
```
Verificar formateo de codigo con Laravel Pint:
```bash
cd backend && ./vendor/bin/pint --test
```
