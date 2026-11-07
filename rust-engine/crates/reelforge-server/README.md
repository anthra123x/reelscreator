# reelforge-server

## 1. Propósito y alcance
El crate `reelforge-server` es el microservicio HTTP REST de ReelForge desarrollado con Axum. Su función principal es exponer puntos de conexión no bloqueantes para la recepción de solicitudes de procesamiento de video procedentes del backend modular de Laravel, encolar trabajos en segundo plano, gestionar un límite estricto de concurrencia mediante un semáforo de Tokio (adaptado a entornos con 1 vCPU), reportar el estado del ciclo de vida y emitir notificaciones webhook tras la finalización.

## 2. Contratos y API
El servidor escucha por defecto en el puerto `3000` y expone los siguientes endpoints HTTP:

- **`GET /health`**:
  - Respuesta `200 OK`:
    ```json
    {
      "status": "healthy",
      "version": "0.1.0",
      "concurrency": {
        "active_jobs": 0,
        "max_concurrent_jobs": 1,
        "available_permits": 1
      },
      "uptime_seconds": 120
    }
    ```
- **`POST /api/v1/jobs`**:
  - Cuerpo de solicitud: `CreateJobRequest` (`job_id`, `source_url`, `local_video_path`, `strategy`, `interval_seconds`, `max_duration_seconds`, `aspect_ratio`, `vertical_mode`, `output_dir`, `webhook_url`).
  - Respuesta `202 Accepted`:
    ```json
    {
      "job_id": "8a3d5e21-0d32-474c-897b-9fbf2b349d10",
      "status": "queued",
      "message": "Job accepted and queued for processing"
    }
    ```
- **`GET /api/v1/jobs/:id`**:
  - Respuesta `200 OK` (Completado):
    ```json
    {
      "job_id": "8a3d5e21-0d32-474c-897b-9fbf2b349d10",
      "status": "completed",
      "progress_percent": 100,
      "clips": [...],
      "error_message": null,
      "execution_duration_ms": 4500
    }
    ```
  - Respuesta `404 Not Found`: Si el identificador no existe en el registro en memoria.
- **`POST /api/v1/jobs/:id/cancel`**:
  - Respuesta `200 OK`: Cancela el token de ejecución del trabajo activo y finaliza inmediatamente los subprocesos de FFmpeg o yt-dlp asociados.
- **`POST /api/v1/direct-cut`**:
  - Recorte síncrono para pruebas y operaciones inmediatas.

## 3. Dependencias
- **Internas**:
  - `reelforge-core`: Entidades de dominio (`VideoTask`, `JobStatusRecord`, `Clip`, `TimeRange`), tipos de error.
  - `reelforge-downloader`: Descarga remota e inspección previa con `probe_media_file`.
  - `reelforge-cutter`: Algoritmos de recorte y conversión vertical 9:16.
- **Externas**:
  - `axum`: Framework web asíncrono ergonómico sobre Tokio y Hyper.
  - `tokio`: Runtime asíncrono y primitivas de concurrencia (`Semaphore`, `RwLock`).
  - `tokio-util`: Coordinación reactiva de tokens de cancelación `CancellationToken`.
  - `tower` y `tower-http`: Middleware de CORS y registro de trazas estructuradas.
  - `reqwest`: Cliente HTTP para la emisión de webhooks de notificación.
  - `serde` y `serde_json`: Serialización y deserialización de contratos REST.

## 4. Mapa de archivos
- `Cargo.toml`: Manifiesto del crate con dependencias de Axum y utilidades HTTP.
- `src/lib.rs`: Definición de módulos de la biblioteca y pruebas de integración sobre el router.
- `src/main.rs`: Punto de entrada del binario, parseo de variables de entorno (`PORT`, `HOST`, `REELFORGE_MAX_CONCURRENT_JOBS`) y binding TCP.
- `src/state.rs`: Estado global compartido `AppState` (registro de trabajos, semáforo de concurrencia, tokens de cancelación).
- `src/routes.rs`: Definición del enrutador Axum, configuración de CORS y capas de middleware.
- `src/handlers.rs`: Controladores para endpoints de salud, creación, consulta, cancelación y corte directo.
- `src/worker.rs`: Tarea asíncrona de procesamiento en segundo plano con adquisición de permisos y emisión de webhooks.

## 5. Lógica y flujo
1. **Recepción de trabajo**: `POST /api/v1/jobs` valida la carga útil y las rutas de salida con `validate_path_security`, registrando la tarea en estado `Queued` (serializado como `"queued"`).
2. **Respuesta no bloqueante**: Devuelve inmediatamente un código `202 Accepted` con el `job_id` y estado `"queued"`.
3. **Control de concurrencia y backpressure**: La tarea en segundo plano solicita un permiso en `state.semaphore`. En un entorno mononúcleo, sólo 1 trabajo ejecuta operaciones intensivas de CPU simultáneamente; las demás solicitudes aguardan en cola de forma ordenada.
4. **Adquisición de semáforo en corte directo**: El endpoint `POST /api/v1/direct-cut` adquiere igualmente un permiso en `state.semaphore` antes de invocar los cortes de FFmpeg, garantizando que operaciones síncronas no saturen la CPU ni evadan las cuotas de procesamiento.
5. **Fases de ejecución**:
   - `Downloading`: Adquiere el video si se proporcionó una URL remota (con timeout de 180s).
   - `Analyzing`: Extrae metadatos y planifica los intervalos de corte.
   - `Cutting`: Transcodifica a 9:16 con `blur_padding` y genera miniaturas en el punto medio (con timeout de 300s).
   - `Completed`: Registra los clips y dispara el webhook opcional (con headers `X-Internal-Secret` y `X-Webhook-Secret` si se configuró clave compartida).
   - `Failed` / `Cancelled`: En caso de error o interrupción, registra el fallo y emite un webhook de reconciliación a `webhook_url` con `status: "failed"`, mensaje de error y marca de tiempo ISO 8601.
6. **Ciclo de vida y poda de memoria (TTL)**: Tras la culminación de cada trabajo, `state.prune_jobs()` purga los registros terminales más antiguos que el TTL (1 hora) y asegura que el mapa en memoria no exceda 1,000 registros, eliminando fugas de memoria por acumulación indefinida de estados.
7. **Cancelación cooperativa**: Si el cliente llama a `/api/v1/jobs/:id/cancel`, el token asociado es activado, interrumpiendo el subproceso hijo y liberando el permiso del semáforo.

## 6. Decisiones de diseño y gotchas
- **Modelo Asíncrono 202 Accepted**: Evita que peticiones HTTP largas excedan los límites de tiempo de espera (timeouts de 30 a 60 segundos en proxies o clientes HTTP como Guzzle de Laravel) durante la transcodificación de videos de varios minutos.
- **Semáforo Tokio Bounded**: Previene la saturación del procesador y la memoria compartida asignando por defecto 1 permiso (`REELFORGE_MAX_CONCURRENT_JOBS=1`) en servidores de recursos acotados, aplicándose tanto al pipeline asíncrono como a `direct_cut_handler`.
- **Reconciliación de Errores vía Webhook**: Emitir webhooks de fallo explícitos con `status: "failed"` permite al backend consumidor (Laravel) transicionar los registros de `processing` a `failed` de manera inmediata, eliminando trabajos zombi atascados.
- **Autenticación Interna de Webhooks**: La inclusión de encabezados `X-Internal-Secret` y `X-Webhook-Secret` garantiza que el endpoint de callback en Laravel no pueda ser invocado por actores maliciosos no autorizados en la red interna.
- **Gestión Acotada de Memoria en AppState**: La política de retención LRU/TTL en `AppState::jobs` previene el crecimiento monotónico de memoria en ejecuciones prolongadas sin comprometer la consulta de estado de trabajos activos o recientes.

## 7. Estado y últimos cambios
- **Estado**: Implementación completa y verificada con pruebas unitarias e integración de router.
- **Cambios recientes**:
  - Remediación de fuga de memoria en `AppState::cancellations`: poda automática de tokens asociados a trabajos terminales o inexistentes dentro de `prune_jobs_with_policy`, con adquisición ordenada de cerrojos para evitar inversión de locks.
  - Limpieza explícita de `CancellationToken` y llamada a `state.prune_jobs()` en `worker.rs` ante cancelación previa al inicio de ejecución o fallo de adquisición de permiso.
  - Implementación de webhooks de notificación de fallos y cancelación en `worker.rs` con autenticación por clave compartida.
  - Enforzamiento del semáforo de concurrencia en `direct_cut_handler` mediante `state.semaphore.acquire()`.
  - Implementación de poda de memoria y TTL (`prune_jobs`) en `AppState` para evitar fugas de memoria en `jobs`.
  - Blindaje de rutas de entrada y salida mediante `validate_path_security` en `handlers.rs` y `worker.rs`.
  - Pruebas unitarias de poda de memoria con verificación de no-evicción de trabajos activos.

## 8. Cómo verificar
Ejecutar las pruebas unitarias y de integración de endpoints:
```bash
cargo test --manifest-path rust-engine/Cargo.toml -p reelforge-server
```
Precondiciones: Entorno Rust toolchain 1.99+.
