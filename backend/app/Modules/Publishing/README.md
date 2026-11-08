# Modulo Publishing

## 1. Propósito y alcance
El modulo `Publishing` coordina la publicacion inmediata, masiva y programada de clips 9:16 en multiples plataformas sociales (TikTok, Instagram Reels y YouTube Shorts). Implementa el patron Estrategia/Adaptador mediante la interfaz `SocialPublisherAdapterInterface` y la clase abstracta `AbstractPublisherAdapter`, garantizando desacoplamiento total respecto a las APIs de terceros y permitiendo ejecucion 100% desconectada mediante `MockSocialAdapter` sin requerir credenciales de produccion en pruebas ni en entornos de desarrollo.

Ademas, orquesta la publicacion individual y en lote de todos los clips asociados a un video mediante `PublishingOrchestrator::publishVideoClips`, articulandose con el encolamiento asincrono de publicaciones mediante trabajos dedicados (`PublishToTikTokJob`, `PublishToInstagramJob`, `PublishToYouTubeJob`) enriquecidos con el trait `HandlesPublishingJob`. Este subsistema administra politicas de reintentos con backoff exponencial (`$this->release(pow(2, $attempts) * 10)`), integracion de limitacion de tasa (`RateLimited('social-publishing')`), preservacion de subtitulos en reintentos y registro estructurado de eventos en la tabla `publishing_logs`.

## 2. Contratos y API
### Endpoints HTTP
- `POST /api/clips/{id}/publish`: Despacha o programa la publicacion de un clip en una o mas redes sociales.
  - Parametro de ruta: `id` (UUID del clip).
  - Payload: `{"platforms": ["tiktok"|"instagram"|"youtube"], "caption"?: string, "scheduled_at"?: iso8601}`
  - Respuestas: `202 Accepted` (`{"publication_ids": [uuid, ...], "status": "queued"}`), `404 Not Found`, `422 Unprocessable Content`.
- `GET /api/publications`: Listado de auditoria de publicaciones historicas.
  - Query params: `status` (`pending`, `scheduled`, `publishing`, `published`, `failed`), `page`, `per_page`.
  - Respuesta: `200 OK` (Paginador JSON con relaciones `clip`, `socialAccount` y `publishingLogs`).
- `POST /api/publications/{id}/retry`: Reintenta una publicacion fallida preservando los subtitulos y metadatos de despacho previos.
  - Parametro de ruta: `id` (UUID de la publicacion).
  - Respuestas: `202 Accepted` (`{"message": string, "publication": object, "status": "queued"}`), `404 Not Found`.

### Servicios Principales
- `PublishingOrchestrator::publishClip(Clip $clip, array $platforms, ?string $caption, ?string $scheduledAt, ?string $userId): array`: Despacho individual por clip con guarda de idempotencia.
- `PublishingOrchestrator::publishVideoClips(Video $video, ?array $platforms, ?string $caption, ?string $userId): array`: Despacho por lote de todos los clips 9:16 generados para un video, descubriendo automaticamente las plataformas activas mediante `SocialAccountService::getActivePlatforms`.

### Eventos Estructurados (`publishing_logs`)
- `dispatched`: Registrado al encolar o programar una publicacion o reintento manual.
- `upload_started`: Registrado al iniciar la ejecucion del trabajo en cola.
- `chunk_uploaded`: Registrado durante la subida por fragmentos o simulacion de chunking.
- `published`: Registrado tras confirmacion exitosa de la plataforma con su identificador de post.
- `retry_attempted`: Registrado al fallar un intento si restan reintentos disponibles, calculando el retardo exponencial.
- `failed`: Registrado cuando los intentos se agotan o ante errores terminales irrecuperables.

### Contrato de Adaptadores
```php
namespace App\Modules\Publishing\Contracts;

interface SocialPublisherAdapterInterface
{
    public function getPlatform(): string;
    public function publish(Clip $clip, SocialAccount $account, array $options = []): PublishResult;
    public function refreshToken(SocialAccount $account): bool;
    public function validateAccount(SocialAccount $account): bool;
}
```

## 3. Dependencias
- **Modulos internos**:
  - `App\Modules\Clips`: Consume la entidad `Clip` como fuente de video vertical y miniatura.
  - `App\Modules\Videos`: Relaciona y procesa publicaciones masivas a partir de la entidad `Video`.
  - `App\Modules\SocialAccounts`: Obtiene las credenciales, cuentas activas y estado de expiracion de tokens.
- **Paquetes externos**:
  - `illuminate/bus`, `illuminate/queue`, `illuminate/database`, `illuminate/support`, `guzzlehttp/guzzle`.

## 4. Mapa de archivos
- `Adapters/AbstractPublisherAdapter.php`: Clase base abstracta que centraliza validaciones comunes de clips y verificacion de tokens.
- `Adapters/InstagramPublisherAdapter.php`: Adaptador para Meta Graph API v21.0 (creacion de contenedor y publicacion).
- `Adapters/MockSocialAdapter.php`: Adaptador de pruebas avanzado con simulacion de chunking, expiracion de tokens, fallos de red y restricciones de duracion.
- `Adapters/TikTokPublisherAdapter.php`: Adaptador para TikTok Content Posting API v2 (`/post/publish/video/init/`).
- `Adapters/YouTubePublisherAdapter.php`: Adaptador para YouTube Data API v3 con inclusion obligatoria de `#Shorts` y limite de 60 segundos.
- `Contracts/SocialPublisherAdapterInterface.php`: Contrato comun para todos los adaptadores de red social.
- `Controllers/PublishController.php`: Controlador REST para publicacion, consulta y reintento.
- `DTOs/PublishResult.php`: Objeto de respuesta tipado (`success`, `platformPostId`, `errorMessage`, `metadata`).
- `Jobs/Concerns/HandlesPublishingJob.php`: Trait reutilizable con logica de ejecucion, emision de eventos a `publishing_logs`, chunking y retardo exponencial.
- `Jobs/PublishToInstagramJob.php`: Trabajo encolado de publicacion en Instagram Reels.
- `Jobs/PublishToTikTokJob.php`: Trabajo encolado de publicacion en TikTok.
- `Jobs/PublishToYouTubeJob.php`: Trabajo encolado de publicacion en YouTube Shorts.
- `Models/Publication.php`: Modelo Eloquent con clave UUID, estados, marcas de tiempo y relacion `publishingLogs`.
- `Models/PublishingLog.php`: Modelo Eloquent para la tabla `publishing_logs` con casteo JSONB y relaciones referenciales.
- `Routes/api.php`: Rutas de publicacion y auditoria.
- `Services/PublishingOrchestrator.php`: Orquestador que resuelve adaptadores, despacha publicaciones por clip y por lote de video, emite eventos y preserva metadatos en reintentos.

## 5. Lógica y flujo
1. El usuario invoca `POST /api/clips/{id}/publish` para un clip especifico, o el pipeline de videos invoca `PublishingOrchestrator::publishVideoClips` durante la descomposicion autonoma.
2. Si las plataformas no se especifican explicitamente, el orquestador consulta `SocialAccountService::getActivePlatforms($userId)`.
3. Para cada clip y plataforma seleccionada, el orquestador localiza la cuenta vinculada o provisiona una cuenta mock automatica si el modo simulado esta habilitado.
4. Se crea un registro `Publication` por cada plataforma en estado `pending` (o `scheduled` si la fecha es futura), protegiendo la creacion con una guarda de idempotencia.
5. Se registra el evento estructurado `dispatched` en la tabla `publishing_logs` con la carga util del subtitulo original y parametros de programacion.
6. Si la publicacion es inmediata, se despacha el trabajo encolado correspondiente (`PublishToTikTokJob`, etc.).
7. Al iniciar el trabajo, se emite el evento `upload_started`. Si la opcion `simulate_chunking` esta activa, se emiten eventos individuales `chunk_uploaded`.
8. El trabajo invoca `publish` en el adaptador correspondiente.
9. En caso de exito, la publicacion pasa a `published`, se almacena el `platform_post_id` y se emite el evento `published`.
10. Si el adaptador devuelve fallo o se produce una excepcion y restan intentos (`attempts < tries`), se emite el evento `retry_attempted` con el tiempo de espera calculado y se libera el trabajo a la cola mediante `$this->release(pow(2, $this->attempts()) * 10)`.
11. Si los intentos se agotan, la publicacion pasa a `failed` y se emite el evento estructurado `failed`.
12. Ante un reintento manual via `POST /api/publications/{id}/retry`, el orquestador recupera el subtitulo guardado en el log de despacho previo para evitar perdida de informacion.

## 6. Decisiones de diseño y gotchas
- **Despacho masivo por video (`publishVideoClips`)**: Permite la operacion 100% desatendida del sistema; una vez que los clips 9:16 son generados, se integran directamente a las redes sociales configuradas sin intervencion manual en el panel.
- **Aislamiento por plataforma**: Cada plataforma se ejecuta en un job independiente; un fallo en la API de Instagram no interrumpe la publicacion concurrente en TikTok o YouTube.
- **Inclusion forzosa de `#Shorts` y limite de duracion**: YouTube exige la etiqueta `#Shorts` en titulo o descripcion para que el algoritmo clasifique el video como corto vertical; `YouTubePublisherAdapter` garantiza su presencia y valida que la duracion no exceda 60 segundos.
- **Degradacion elegante a Mock**: Si no existen credenciales de produccion configuradas en `.env` o la variable `SOCIAL_MOCK_MODE=true` esta activa, los adaptadores delegan internamente en `MockSocialAdapter`, asegurando que pipelines de prueba y CI nunca queden bloqueados.
- **Simulacion integral en Mock**: `MockSocialAdapter` soporta banderas de prueba explicitas (`simulate_chunking`, `simulate_token_expired`, `simulate_network_failure`, `skip_token_refresh`), validando duraciones minimas (> 0s) y topes especificos por plataforma.
- **Guarda de idempotencia en publicacion**: En `PublishingOrchestrator`, se comprueba si ya existe una publicacion encolada o en curso (`pending`, `scheduled`, `publishing`) para la tupla `(clip_id, social_account_id)`, reutilizando el identificador existente y evitando duplicacion de envíos o encolamientos redundantes ante dobles clics.
- **Preservacion de subtitulos en reintento**: La tabla `publications` no requiere columnas mutables adicionales; el subtitulo y opciones de despacho originales se persisten en el registro `dispatched` de `publishing_logs` y se recuperan al ejecutar `retryPublication()`.

## 7. Estado y últimos cambios
- **Estado**: Funcionalidad completada y verificada al 100% en pruebas automatizadas.
- **Cambios recientes**:
  - Implementacion del metodo `publishVideoClips` en `PublishingOrchestrator` para publicacion masiva desatendida de videos completos.
  - Integracion con `SocialAccountService::getActivePlatforms` para autodeteccion de canales sociales activos.
  - Refactorizacion orientada a objetos: creacion de `AbstractPublisherAdapter` e implementacion heredada en adaptadores de TikTok, Instagram, YouTube y Mock.
  - Enriquecimiento de `MockSocialAdapter` con simulacion de chunking, expiracion de tokens, fallos de red y validacion de duracion de YouTube Shorts (60s) e Instagram Reels (90s).
  - Creacion del trait `HandlesPublishingJob` con emision estructurada de eventos a `publishing_logs`, backoff exponencial dinamico y rate limiting middleware.

## 8. Cómo verificar
Ejecutar la suite completa de pruebas unitarias y de caracteristicas de publicacion:
```bash
cd backend && php artisan test --filter=Publishing
```
Ejecutar pruebas de resiliencia y registro estructurado:
```bash
cd backend && php artisan test --filter=PublishingResilienceAndStructuredLoggingTest
```
Comandos de verificacion estatica y formateo:
```bash
cd backend && ./vendor/bin/pint --test
```
