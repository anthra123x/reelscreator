# reelforge-cutter

## 1. Propósito y alcance
El crate `reelforge-cutter` implementa el motor de segmentación de alta precisión y transformación de relación de aspecto para ReelForge. Se encarga de seccionar videos horizontales (16:9) u otras relaciones y adaptarlos al formato vertical estándar de redes sociales (9:16, 1080x1920) mediante filtros complejos de FFmpeg, garantizando la preservación estricta de la sincronía de audio AAC, la extracción de miniaturas JPEG en el punto medio exacto de cada clip y la validación técnica posterior al renderizado mediante `ffprobe`.

## 2. Contratos y API
El crate expone las siguientes funciones y estructuras:
- **`VideoCutter`**:
  - `slice_clip(source_path, clip_path, thumb_path, range, dimensions, vertical_mode, cancel_token) -> Result<Clip>`: Ejecución de recorte para un intervalo temporal específico, generación de miniatura y validación posterior.
  - `process_task(task, source_media_path, cancel_token, progress_cb) -> Result<Vec<Clip>>`: Orquestación completa de corte de una tarea `VideoTask`, calculando los intervalos, creando los directorios y reportando progreso incremental.
- **`build_vertical_filtergraph(mode: VerticalMode, dimensions: Dimensions) -> String`**: Generación de grafos de filtros complejos para encuadre vertical:
  - `BlurPadding`: División en dos capas, escalado y desenfoque gaussiano de fondo (`boxblur=25:5`) con superposición centrada del video original.
  - `CenterCrop`: Recorte del área central con relación 9:16 y reescalado a 1080x1920.
  - `Letterbox`: Escalado proporcional con bandas negras superior e inferior (`pad`).
- **`extract_midpoint_thumbnail(clip_path, output_thumb_path, duration) -> Result<PathBuf>`**: Extracción de fotograma clave en el punto medio $(duración / 2)$ en formato JPEG con validación de cabecera mágica `0xFF 0xD8 0xFF`.
- **`validate_cut_clip(clip_path, spec) -> Result<VideoMetadata>`**: Verificación de dimensiones exactas, tolerancia de duración ($\pm 0.75$s) y presencia de pistas de audio.
- **`plan_cut_intervals(strategy, total_duration, interval, max_duration, custom_ranges) -> Result<Vec<TimeRange>>`**: Cálculo de intervalos según estrategia (`FixedInterval`, `ManualTimestamps`, etc.).

## 3. Dependencias
- **Internas**:
  - `reelforge-core`: Tipos de dominio (`Clip`, `Dimensions`, `TimeRange`, `VerticalMode`, `AspectRatio`, `VideoTask`) y `CoreError`.
  - `reelforge-downloader`: Inspección técnica mediante `probe_media_file`.
- **Externas**:
  - `tokio`: Ejecución asíncrona de subprocesos y cancelación reactiva.
  - `tokio-util`: Coordinación de tokens de cancelación.
  - `uuid`: Generación de identificadores únicos para cada clip.
  - `tracing`: Trazabilidad y telemetría de recortes y filtros.
  - `tempfile`: Creación de entornos efímeros para pruebas de integración.
- **Herramientas de Sistema**:
  - `ffmpeg`: Ejecutable `/usr/bin/ffmpeg` configurado con limitación estricta de hilos (`-threads 1`) para operar dentro del límite de 1 vCPU del host.

## 4. Mapa de archivos
- `Cargo.toml`: Manifiesto del crate.
- `src/lib.rs`: Exportación de API pública.
- `src/filtergraph.rs`: Constructor de cadenas de filtros complejos FFmpeg para modos 9:16.
- `src/thumbnail.rs`: Rutina de extracción de miniaturas JPEG en el punto medio temporal.
- `src/validator.rs`: Validador post-render de especificaciones técnicas (dimensiones, duración, codec).
- `src/strategies.rs`: Planificador de intervalos de corte según la estrategia seleccionada.
- `src/ffmpeg.rs`: Ejecutor de subprocesos FFmpeg y ciclo de vida de transformación de clips.

## 5. Lógica y flujo
1. **Inspección de origen**: Se determina la duración total y dimensiones del video de entrada mediante `probe_media_file`.
2. **Planificación de intervalos**: `plan_cut_intervals` calcula los segmentos temporales en función de la estrategia configurada (`FixedInterval` o `ManualTimestamps`).
3. **Construcción del filtro**: Se genera la cadena de `filter_complex` correspondiente al modo de encuadre vertical solicitado (`BlurPadding`, `CenterCrop`, o `Letterbox`).
4. **Ejecución segura de FFmpeg**: Se lanza el subproceso con `-protocol_whitelist "file,crypto,data"`, `-ss <start>` y `-to <end>`, delimitador `"--"` antes de la ruta de salida, transcodificando video con `libx264` (`-crf 23 -preset fast`) y audio con `aac` (`-b:a 128k -avoid_negative_ts make_zero`).
5. **Control de tiempos de ejecución**: La segmentación está protegida por un timeout asíncrono de 300 segundos (`tokio::time::timeout`). Si el corte excede dicho lapso, el proceso se termina mediante `child.start_kill()` liberando los recursos de CPU.
6. **Captura de miniatura**: Se invoca una segunda pasada ligera de FFmpeg con `-protocol_whitelist "file,crypto,data"` y timeout de 30 segundos para extraer un fotograma en el punto medio del clip renderizado.
7. **Validación post-render**: Se verifica que el archivo resultante cumpla exactamente con la resolución (1080x1920) y la duración esperada.

## 6. Decisiones de diseño y gotchas
- **Restricción de hilos de codificación (`-threads 1`)**: Al operar en un host con un único vCPU (`nproc = 1`), ejecutar FFmpeg con concurrencia no acotada satura el planificador del kernel. Limitar explícitamente a 1 hilo previene la inanición de CPU.
- **Protocol Whitelisting en FFmpeg**: La inclusión obligatoria de `-protocol_whitelist "file,crypto,data"` previene ataques de Server-Side Request Forgery (SSRF) o fugas de archivos locales a través de listas de reproducción maliciosas (ej. protocolos `concat:`, `subfile:`, `http:`).
- **Prevención de cuelgues permanentes**: La inclusión de timeouts explícitos de 300 segundos en recortes y 30 segundos en miniaturas asegura que pipes bloqueados o procesos congelados no retengan permisos del semáforo indefinidamente.
- **Preservación y alineación de audio**: El uso de `-avoid_negative_ts make_zero` junto con `-c:a aac -ar 44100` evita el desfase de sincronía labial o el truncamiento de milisegundos iniciales al aplicar cortes rápidos.
- **Gotcha**: Al aplicar `blur_padding`, el video original se superpone al fondo desenfocado; si la relación de aspecto del video de origen ya fuese 9:16, `scale=force_original_aspect_ratio=decrease` mantiene la escala 1:1 sin deformación.
- **Protección contra floats subnormales**: En `plan_cut_intervals`, `effective_step` se acota con `.max(1.0)` para garantizar que números flotantes diminutos o subnormales (por ejemplo, `1e-18`) no causen bucles infinitos por límites de precisión épsilon de máquina.

## 7. Estado y últimos cambios
- **Estado**: Implementación completa y verificada con pruebas unitarias e integración en pipeline de video real.
- **Cambios recientes**:
  - Incorporación de `-protocol_whitelist "file,crypto,data"` y delimitador `"--"` en comandos de FFmpeg.
  - Implementación de timeouts asíncronos (`tokio::time::timeout`) de 300 segundos para cortes y 30 segundos para miniaturas.
  - Endurecimiento contra bucles infinitos por floats subnormales o NaN en `plan_cut_intervals` mediante `effective_step.max(1.0)`.
  - Implementación de `build_vertical_filtergraph` con soporte para blur padding, center crop y letterbox.
  - Implementación de `VideoCutter::slice_clip` y `VideoCutter::process_task`.
  - Integración de extracción de miniaturas y validador post-corte con `ffprobe`.

## 8. Cómo verificar
Ejecutar la suite de pruebas del crate:
```bash
cargo test --manifest-path rust-engine/Cargo.toml -p reelforge-cutter
```
Precondiciones: `ffmpeg` y `ffprobe` disponibles en el sistema.
