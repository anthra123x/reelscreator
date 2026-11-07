# reelforge-downloader

## 1. Propósito y alcance
El crate `reelforge-downloader` es responsable de la ingesta y adquisición de medios audiovisuales remotos y locales para la plataforma ReelForge. Gestiona la invocación asíncrona de subprocesos `yt-dlp` (soportando tanto binarios del sistema como entornos aislados mediante `uvx yt-dlp`), el análisis en tiempo real del flujo de progreso de descarga vía expresiones regulares, la extracción de metadatos de medios mediante `ffprobe` y la provisión de mecanismos deterministas de respaldo con fixtures sintéticos para pruebas sin conexión.

## 2. Contratos y API
El crate expone las siguientes entidades públicas:
- **`YtDlpCommand`**: Estructura de detección automática del ejecutable (`detect()`), resolviendo rutas explícitas por variable de entorno `YT_DLP_PATH`, binario directo `yt-dlp` o contenedor `uvx yt-dlp`.
- **`VideoDownloader`**:
  - `auto_detect() -> Result<Self>`: Inicialización con resolución automática de herramienta.
  - `download(url, options, cancel_token, progress_cb) -> Result<PathBuf>`: Ejecución de descarga asíncrona con reporte de progreso mediante `DownloadProgress` (`percent`, `speed_bytes_sec`, `eta_seconds`) y soporte de cancelación cooperativa vía `tokio_util::sync::CancellationToken`.
- **`probe_media_file(path: &Path) -> Result<VideoMetadata>`**: Inspección técnica mediante `ffprobe` en formato JSON, devolviendo duración, dimensiones, codecs de video y audio, y tasa de bits.
- **`generate_synthetic_mp4(path, duration, width, height) -> Result<PathBuf>`**: Generador de secuencias de video válidas con patrones de prueba y pista de audio senoidal para entornos de prueba herméticos.
- **`parse_progress_line(line: &str) -> Option<DownloadProgress>`**: Extractor puro de telemetría de descarga a partir de líneas estándar de `yt-dlp`.

## 3. Dependencias
- **Internas**:
  - `reelforge-core`: Modelos de dominio (`VideoMetadata`, `DownloadProgress`) y jerarquía de errores `CoreError`.
- **Externas**:
  - `tokio`: Runtime asíncrono para gestión de subprocesos (`tokio::process::Command`), lectura no bloqueante de líneas y cancelación.
  - `tokio-util`: Primitivas de sincronización y `CancellationToken`.
  - `regex`: Expresiones regulares precompiladas para extracción de métricas de descarga.
  - `tempfile`: Creación y saneamiento de carpetas temporales de prueba.
  - `tracing`: Trazas estructuradas de ejecución y diagnóstico.
- **Herramientas del Sistema Operativo**:
  - `ffmpeg` y `ffprobe`: Instalados en `/usr/bin/` para análisis y generación de secuencias.
  - `yt-dlp` o `uvx`: Gestor de descarga de plataformas de video.

## 4. Mapa de archivos
- `Cargo.toml`: Manifiesto del crate y enlace de dependencias.
- `src/lib.rs`: Re-exportación de módulos y tipos públicos.
- `src/ytdlp.rs`: Gestor de subprocesos `yt-dlp`, ejecución de descargas y ciclo de cancelación.
- `src/parser.rs`: Motor de análisis sintáctico por expresiones regulares para stdout de `yt-dlp`.
- `src/probe.rs`: Extractor de metadatos de contenedores y pistas mediante `ffprobe`.
- `src/fixtures.rs`: Rutinas de generación de video sintético y discriminador de URLs de prueba (`test://`, `file://`, `mock://`).

## 5. Lógica y flujo
1. **Detección de herramienta**: Al inicializar, el downloader verifica la presencia de `yt-dlp` o `uvx yt-dlp`. Si ninguno está disponible, rechaza la operación con `CoreError::DownloadError`.
2. **Discriminación de origen y prevención de LFI**:
   - Si la URL responde a esquemas de prueba (`test://`, `mock://`, `fixture://`) o `REELFORGE_MOCK_DOWNLOAD=1`, se genera un video sintético determinista reportando progreso instantáneo.
   - Si la URL utiliza el esquema `file://` o rutas locales existentes, se valida exhaustivamente mediante `validate_path_security` prohibiendo acceso a archivos sensibles (`.env`, `passwd`, `.git`) y directorios restringidos (`/etc`, `/root`, `/bin`, `/var`). Si la ruta no es segura, la solicitud se rechaza con error de validación para eliminar el riesgo de Local File Inclusion (LFI).
   - En URLs remotas estándar, se exige esquema `http://` o `https://` y se prohíben argumentos que comiencen con guión para evitar inyección de opciones.
3. **Invocación segura de yt-dlp**:
   - Los argumentos posicionales anteponen el delimitador `"--"` antes de la URL remota para neutralizar ataques de inyección de opciones CLI.
   - La ejecución del subproceso `yt-dlp` está protegida por un timeout de 180 segundos (`tokio::time::timeout`). Si el proceso no concluye en dicho intervalo, se aborta mediante `start_kill()`.
4. **Flujo de progreso**: Las líneas emitidas se parsean línea por línea. Cada coincidencia actualiza el callback provisto.
5. **Cancelación**: Si el token de cancelación es activado durante la descarga, el subproceso recibe `child.start_kill()` inmediatamente y se purgan archivos parciales temporales.
6. **Inspección técnica**: Una vez persistido el archivo, `probe_media_file` valida la integridad del contenedor con `ffprobe` protegido por `-protocol_whitelist "file,crypto,data"` y timeout de 30 segundos.

## 6. Decisiones de diseño y gotchas
- **Soporte de entornos restringidos (uvx)**: Debido a que en muchas distribuciones Linux modernas `yt-dlp` se distribuye como herramienta de Python administrada por `uv`, el resolvedor incorpora `uvx yt-dlp` como mecanismo de primera clase sin requerir privilegios de superusuario.
- **Prevención de Argument Injection**: El estándar POSIX de delimitador `"--"` asegura que cadenas hostiles como `--exec="malicious"` no puedan ser interpretadas como parámetros del ejecutable de descarga.
- **Defensa en profundidad contra LFI**: Los esquemas `file://` y resoluciones de archivos locales se restringen mediante `validate_path_security`, garantizando que claves de entorno o configuraciones del servidor no puedan ser filtradas al directorio de medios.
- **Control de tiempos de proceso**: La inclusión de timeouts explícitos evita que bloqueos de red o caídas de yt-dlp retengan permisos de concurrencia de forma indefinida.
- **Gotcha**: Al descargar videos con resoluciones elevadas, `yt-dlp` puede generar archivos temporales con sufijo `.part` antes del multiplexado final en `.mp4`; la búsqueda de archivo final debe inspeccionar el directorio de salida tras el cierre exitoso del proceso.

## 7. Estado y últimos cambios
- **Estado**: Implementación completa, endurecida contra inyección y verificada con pruebas unitarias.
- **Cambios recientes**:
  - Implementación de `validate_local_media_boundary` en `fixtures.rs` para restringir la lectura y copia de archivos locales y esquemas `file://` exclusivamente a directorios autorizados de la aplicación (`canonical_cwd`, `temp_root`, o `REELFORGE_MEDIA_ROOT`), impidiendo la inclusión de archivos de sistema (`/etc/hosts`, `/etc/passwd`).
  - Integración de `validate_local_media_boundary` en el flujo de descarga de `ytdlp.rs` y en la heurística `is_offline_or_test_url`.
  - Incorporación del delimitador `"--"` previo a la URL en invocaciones de `yt-dlp`.
  - Blindaje contra LFI en `fixtures.rs` y `ytdlp.rs` restringiendo `file://` y comprobaciones locales con `validate_path_security`.
  - Aplicación de `-protocol_whitelist "file,crypto,data"` en `ffprobe` y generador de fixtures sintéticos.
  - Implementación de timeouts asíncronos (`tokio::time::timeout`) para procesos `yt-dlp` (180s) y `ffprobe` (30s).
  - Incorporación de derive `Clone` a `DownloaderOptions` y nuevas pruebas adversariales de inyección y LFI.

## 8. Cómo verificar
Ejecutar la suite de pruebas del crate:
```bash
cargo test --manifest-path rust-engine/Cargo.toml -p reelforge-downloader
```
Precondiciones: `ffmpeg` y `ffprobe` disponibles en el `PATH`.
