# Modulo Videos

## 1. Propósito y alcance
El modulo `Videos` gestiona el ciclo de vida completo de ingesta de contenido audiovisual en ReelForge. Es responsable de recibir solicitudes de procesamiento mediante URLs publicas (YouTube, TikTok, Vimeo, fuentes HTTP directas), validar los parametros de corte vertical (intervalo, estrategia de deteccion, formato 9:16), coordinar la cola de descargas y despachar trabajos de transformacion al motor Axum en Rust.

Adicionalmente, mantiene la maquina de estados de los videos (`pending`, `downloading`, `processing`, `ready`, `failed`), expone endpoints REST para consulta y sondeo rapido de estado (`status`), y procesa webhooks asincronos entrantes desde el motor de renderizado para sincronizar clips generados y marcar el contenido como listo para distribucion.

## 2. Contratos y API
### Endpoints HTTP
- `POST /api/videos`: Recepcion e inicio del flujo de ingesta.
  - Payload: `{"source_url": string, "strategy"?: "fixed_interval"|"scene_detection"|"silence_detection"|"manual_timestamps", "interval_seconds"?: int, "max_duration_seconds"?: int, "vertical_mode"?: "blur_padding"|"center_crop"|"letterbox", "title"?: string}`
  - Respuestas: `201 Created` (`{"id": uuid, "source_url": string, "status": "pending", "created_at": iso8601}`), `422 Unprocessable Content`.
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
  - Headers opcionales/requeridos: `X-Internal-Secret` o `X-Webhook-Secret` (comparacion en tiempo constante contra `services.rust_engine.internal_secret`).
  - Payload completado: `{"job_id": uuid, "status": "completed", "clips": [{"clip_id": uuid, "file_path": string, "thumbnail_path": string, "start_time": float, "end_time": float, "duration": float, "width": int, "height": int}]}`
  - Payload fallido: `{"job_id": uuid, "status": "failed", "error": string, "error_message"?: string, "timestamp"?: string}`
  - Respuestas: `200 OK` (`{"status": "ok", "video_id": uuid, "video_status": string, "clips_count": int}`), `401 Unauthorized` (si el secreto no coincide), `404 Not Found`.

### DTOs
- `VideoIngestDTO`: Encapsula `sourceUrl`, `strategy`, `intervalSeconds`, `maxDurationSeconds`, `verticalMode`, `title`, `extraParams`.

## 3. Dependencias
- **Modulos internos**:
  - `App\Modules\Clips`: Crea instancias de `Clip` al recibir notificaciones de finalizacion.
  - `App\Models\User`: Relacion `belongsTo` para asociacion de pertenencia.
  - `App\Services\RustProcessorClient`: Cliente HTTP para envio de tareas al motor Axum.
- **Paquetes externos**:
  - `illuminate/support`, `illuminate/database`, `illuminate/bus`, `illuminate/queue`.

## 4. Mapa de archivos
- `Controllers/InternalJobCallbackController.php`: Controlador dedicado para recepcion, autenticacion por cabecera secreta y conciliacion de webhooks internos.
- `Controllers/VideoController.php`: Controlador REST que valida entradas y delega operaciones al servicio de ingesta y callback.
- `DTOs/VideoIngestDTO.php`: Objeto inmutable de transferencia para sanitizacion de parametros de corte.
- `Jobs/DownloadVideoJob.php`: Trabajo encolado que gestiona la transicion a estado de descarga y desencadena el procesamiento.
- `Jobs/ProcessVideoJob.php`: Trabajo encolado que remite la orden al motor Rust via `RustProcessorClient`.
- `Models/Video.php`: Modelo Eloquent con clave primaria UUID, metadatos JSONB y maquina de estados estricta.
- `Routes/api.php`: Definicion de rutas API publicas e internas del modulo.
- `Services/VideoIngestService.php`: Logica de negocio central para persistencia, sondeo, borrado y procesamiento de webhooks.

## 5. Lógica y flujo
1. El cliente envia `POST /api/videos` con URL y configuracion de recorte.
2. `VideoController` valida los datos y construye un `VideoIngestDTO`.
3. `VideoIngestService` crea el registro en PostgreSQL en estado `pending` y despacha `DownloadVideoJob` a la cola.
4. `DownloadVideoJob` actualiza el estado a `downloading` y despacha `ProcessVideoJob`.
5. `ProcessVideoJob` efectua `POST /api/v1/jobs` hacia el motor Axum (`http://localhost:3000`) indicando el webhook de retorno.
6. El motor Rust procesa la descarga y el recorte 9:16 vertical con FFmpeg.
7. Al concluir o fallar, el motor Rust invoca `POST /api/internal/jobs/{id}/callback` con encabezados de autenticacion (`X-Internal-Secret`).
8. `InternalJobCallbackController` valida el secreto mediante `hash_equals` y delega a `VideoIngestService::handleWebhookCallback`:
   - Si `status` es `completed`: persiste los registros `Clip` generados y transiciona el video a `ready`.
   - Si `status` es `failed` o `cancelled`: transiciona el video a `failed` y almacena el mensaje de error para diagnostico.

## 6. Decisiones de diseño y gotchas
- **Claves primarias UUID**: Se emplean UUID v4 para compatibilidad directa con el identificador de trabajo del motor Rust sin colisiones distribuidas.
- **Maquina de estados protegida**: `Video::transitionTo` valida que unicamente se ejecuten transiciones legales (ej. `pending` -> `downloading` -> `processing` -> `ready` o `failed`), impidiendo inconsistencias por ejecucion desordenada de colas.
- **Autenticacion de Webhooks Internos**: La verificacion mediante `hash_equals` previene ataques de inyeccion de clips o manipulacion de estados desde fuentes no autenticadas en la red.
- **Reconciliacion de Fallos sin Trabajos Zombi**: El manejo explicito de cargas utiles `status: "failed"` asegura que cualquier error durante la descarga o el corte en Rust marque inmediatamente el video como fallido en Laravel, desbloqueando reintentos.
- **Sondeo y Webhook unificados**: Se provee tanto `GET /api/videos/{id}/status` como el webhook interno para soportar entornos locales síncronos y arquitecturas asíncronas distribuidas.
- **Validacion estricta de esquemas URL y paginacion**: La regla de validacion para `source_url` exige estrictamente `url:http,https` para prevenir ataques SSRF mediante protocolos como `gopher://` o `dict://`. El parametro `per_page` se acota a `[1, 100]` para mitigar limites negativos o consultas de denegacion de servicio en PostgreSQL.

## 7. Estado y últimos cambios
- **Estado**: Implementacion completa y verificada con pruebas unitarias y de caracteristicas.
- **Cambios recientes**:
  - Endurecimiento fail-closed en `InternalJobCallbackController`: rechazo con HTTP 401 si la clave compartida interna no se encuentra configurada en entornos no-testing o si las cabeceras `X-Internal-Secret` / `X-Webhook-Secret` no superan la comprobacion `hash_equals`.
  - Incorporacion de validacion estricta de esquema de payload con `Validator::make` en `InternalJobCallbackController` retornando HTTP 422 ante campos requeridos ausentes o anomalos.
  - Creacion de `InternalJobCallbackController` con soporte de autenticacion por clave compartida (`X-Internal-Secret` y `X-Webhook-Secret`).
  - Implementacion de reconciliacion de fallos en `VideoIngestService::handleWebhookCallback` transicionando videos a `failed` con `error_message`.
  - Actualizacion de `VideoController::webhookCallback` para delegacion transparente al controlador de callbacks.
  - Creacion de suite de pruebas `InternalWebhookAuthAndFailureTest` validando autenticacion y estados.
  - Endurecimiento de validacion SSRF en `VideoController` con restriccion `url:http,https`.
- Acotamiento seguro del parametro `per_page` en rango `[1, 100]` en listado de videos.
- Implementacion inicial de arquitectura modular segun `PROJECT.md`.
- Modelos, controladores, DTOs, jobs de cola y servicio de ingesta completados.
- Cobertura de rutas API configurada.

## 8. Cómo verificar
Ejecutar la suite de pruebas automatizadas del backend:
```bash
php artisan test --filter=VideoTest
```
Comandos de verificacion estatica y formateo:
```bash
./vendor/bin/pint --test
```
