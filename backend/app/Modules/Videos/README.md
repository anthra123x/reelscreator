# Modulo Videos

## 1. Propósito y alcance
El modulo `Videos` gestiona el ciclo de vida completo de ingesta, descomposicion autonoma en formato vertical 9:16 (1080x1920) y despacho a redes sociales en ReelForge. Es responsable de recibir solicitudes de procesamiento mediante URLs publicas (YouTube, TikTok, Vimeo, fuentes HTTP directas), validar los parametros de corte vertical (intervalo, estrategia de deteccion, formato 9:16, plataformas objetivo y publicacion desatendida), coordinar la cola de descargas y despachar trabajos de transformacion al motor Axum en Rust o mediante descomposicion autonoma directa.

Adicionalmente, mantiene la maquina de estados de los videos (`pending`, `downloading`, `processing`, `ready`, `failed`), expone endpoints REST para consulta, descomposicion inmediata y sondeo rapido de estado (`status`), y procesa webhooks asincronos entrantes desde el motor de renderizado activando automaticamente el pipeline de publicacion a redes conectadas cuando la bandera `auto_publish` se encuentra habilitada.

## 2. Contratos y API
### Endpoints HTTP
- `POST /api/videos`: Recepcion e inicio del flujo de ingesta asincrono.
  - Payload: `{"source_url": string, "strategy"?: "fixed_interval"|"scene_detection"|"silence_detection"|"manual_timestamps", "interval_seconds"?: int, "max_duration_seconds"?: int, "vertical_mode"?: "blur_padding"|"center_crop"|"letterbox", "title"?: string, "auto_publish"?: bool, "platforms"?: string[], "caption"?: string}`
  - Respuestas: `201 Created` (`{"id": uuid, "source_url": string, "title": string, "status": "pending", "created_at": iso8601}`), `422 Unprocessable Content`.
- `POST /api/videos/decompose`: Ingesta y descomposicion autonoma en reels verticales 9:16 con despacho inmediato en una sola llamada.
  - Payload: `{"source_url": string, "strategy"?: string, "interval_seconds"?: int, "max_duration_seconds"?: int, "vertical_mode"?: string, "title"?: string, "auto_publish"?: bool, "platforms"?: string[], "caption"?: string, "video_duration"?: float}`
  - Respuestas: `201 Created` (`{"message": string, "video": object, "clips": array, "publications": array, "published_platforms": array, "status": "ready"}`), `422 Unprocessable Content`.
- `POST /api/videos/{id}/decompose`: Descompone un video existente en reels verticales 9:16 (1080x1920) y despacha publicaciones a redes vinculadas.
  - Payload opcional: `{"interval_seconds"?: int, "max_duration_seconds"?: int, "vertical_mode"?: string, "auto_publish"?: bool, "platforms"?: string[], "caption"?: string, "video_duration"?: float, "regenerate"?: bool}`
  - Respuestas: `200 OK` (`{"message": string, "video": object, "clips": array, "publications": array, "published_platforms": array, "status": "ready"}`), `404 Not Found`, `422 Unprocessable Content`.
- `GET /api/videos`: Listado paginado de videos por usuario con clips precargados.
  - Query params: `status`, `page`, `per_page`.
  - Respuesta: `200 OK` (Paginador JSON).
- `GET /api/videos/{id}`: Detalle de un video y sus clips asociados.
  - Respuestas: `200 OK`, `404 Not Found`.
- `GET /api/videos/{id}/status`: Consulta ligera de sondeo para interfaces de usuario.
  - Respuesta: `200 OK` (`{"id": uuid, "status": string, "progress_percent": int, "error_message": ?string}`).
- `DELETE /api/videos/{id}`: Eliminacion en cascada del registro y artefactos de almacenamiento.
  - Respuestas: `204 No Content`, `404 Not Found`.
- `POST /api/internal/jobs/{id}/callback`: Webhook interno notificado por el motor Rust con autenticacion compartida.
  - Headers: `X-Internal-Secret` o `X-Webhook-Secret`.
  - Respuestas: `200 OK`, `401 Unauthorized`, `404 Not Found`.

### DTOs
- `VideoIngestDTO`: Encapsula `sourceUrl`, `strategy`, `intervalSeconds`, `maxDurationSeconds`, `verticalMode`, `title`, `autoPublish`, `platforms`, `caption`, `extraParams`.

## 3. Dependencias
- **Modulos internos**:
  - `App\Modules\Clips`: Crea instancias de `Clip` con geometria 1080x1920 y proporcion 9:16.
  - `App\Modules\Publishing`: Invoca `PublishingOrchestrator::publishVideoClips` para despacho masivo.
  - `App\Modules\SocialAccounts`: Consulta canales disponibles via `SocialAccountService::getActivePlatforms`.
  - `App\Models\User`: Relacion `belongsTo` para asociacion de pertenencia.
  - `App\Services\RustProcessorClient`: Cliente HTTP para envio de tareas al motor Axum.
- **Paquetes externos**:
  - `illuminate/support`, `illuminate/database`, `illuminate/bus`, `illuminate/queue`.

## 4. Mapa de archivos
- `Controllers/InternalJobCallbackController.php`: Controlador dedicado para recepcion, autenticacion por cabecera secreta y conciliacion de webhooks internos.
- `Controllers/VideoController.php`: Controlador REST que valida entradas, expone endpoints de ingesta, descomposicion autonoma y delegacion al servicio.
- `DTOs/VideoIngestDTO.php`: Objeto inmutable de transferencia para sanitizacion de parametros de corte, auto-publicacion y plataformas.
- `Jobs/DownloadVideoJob.php`: Trabajo encolado que gestiona la transicion a estado de descarga y desencadena el procesamiento.
- `Jobs/ProcessVideoJob.php`: Trabajo encolado que remite la orden al motor Rust via `RustProcessorClient`.
- `Models/Video.php`: Modelo Eloquent con clave primaria UUID, metadatos JSONB y maquina de estados estricta.
- `Routes/api.php`: Definicion de rutas API publicas e internas del modulo.
- `Services/VideoIngestService.php`: Logica de negocio central para persistencia, descomposicion 9:16 (`decomposeVideo`), sondeo y procesamiento de webhooks con auto-publicacion.

## 5. Lógica y flujo
1. El cliente envia `POST /api/videos/decompose` o `POST /api/videos/{id}/decompose` con opciones de corte y plataformas.
2. `VideoController` valida los datos garantizando URLs validas, rangos seguros de duracion y canales compatibles (`tiktok`, `instagram`, `youtube`).
3. `VideoIngestService::decomposeVideo` divide el metraje en intervalos uniformes (por defecto 30 segundos, limitados a un maximo estricto de 60 segundos para cumplimiento con YouTube Shorts).
4. Se generan y persisten registros `Clip` con resolucion vertical 1080x1920 y `aspect_ratio: '9:16'`.
5. El estado del video transiciona ordenadamente a `ready`.
6. Si la opcion `auto_publish` es verdadera, se invoca `PublishingOrchestrator::publishVideoClips`, el cual localiza las cuentas sociales activas o mock correspondientes y despacha las publicaciones.
7. Cuando el motor Rust completa un renderizado externo y notifica a `/api/internal/jobs/{id}/callback`, `handleWebhookCallback` persiste los clips y, si `metadata['auto_publish']` esta activo, despacha automaticamente la publicacion sin intervencion manual.

## 6. Decisiones de diseño y gotchas
- **Topes estrictos de 60 segundos por reel**: YouTube Shorts rechaza cualquier archivo cuya duracion supere los 60.0 segundos. `decomposeVideo` impone `min($interval, min($maxDuration, 60))` para garantizar que ningun clip generado exceda dicho limite algoritmico.
- **Resolucion vertical nativa 1080x1920**: Los clips creados incorporan metadatos normalizados con `width: 1080`, `height: 1920` y `aspect_ratio: "9:16"`, asegurando consistencia entre la generacion sintetica de servidor y el renderizado FFmpeg del motor Rust.
- **Tolerancia a ejecucion serverless en Vercel**: `POST /api/videos/decompose` y `decomposeVideo` permiten operar sin requerir conexion síncrona obligatoria al motor Axum en entornos serverless sin demonio local en el puerto 3000.
- **Idempotencia de publicacion**: Si se invoca la descomposicion sobre un video con clips preexistentes, `PublishingOrchestrator` reutiliza las publicaciones encoladas existentes para la tupla `(clip_id, social_account_id)` previniendo duplicados.

## 7. Estado y últimos cambios
- **Estado**: Funcionalidad completada y verificada al 100% en suites unitarias y de caracteristicas.
- **Cambios recientes**:
  - Implementacion de `POST /api/videos/{id}/decompose` y `POST /api/videos/decompose` en `VideoController` y `Routes/api.php`.
  - Extension de `VideoIngestDTO` y `VideoController::store` para capturar `auto_publish`, `platforms` y `caption`.
  - Creacion del metodo `VideoIngestService::decomposeVideo` con particionado uniforme 9:16 (1080x1920) y limite de 60s.
  - Integracion de despacho automatico en `VideoIngestService::handleWebhookCallback` cuando `auto_publish` esta habilitado.
  - Creacion de la suite de pruebas `AutonomousDecompositionTest` cubriendo flujo integral, validaciones, banderas y limites de duracion.

## 8. Cómo verificar
Ejecutar la suite de pruebas del flujo autonomo:
```bash
php artisan test --filter=AutonomousDecompositionTest
```
Ejecutar toda la suite del modulo de videos:
```bash
php artisan test --filter=Video
```
Verificacion estatica y formateo de codigo:
```bash
./vendor/bin/pint --test
```
