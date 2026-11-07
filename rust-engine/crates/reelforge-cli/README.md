# reelforge-cli

## 1. Propósito y alcance
El crate `reelforge-cli` provee la interfaz de línea de comandos independiente y sin interfaz gráfica para la plataforma ReelForge. Diseñada para desarrolladores, pipelines de automatización local y procesamiento por lotes, permite ejecutar de forma determinista la descarga de medios con yt-dlp, el recorte y transformación de clips a formato vertical 9:16 mediante FFmpeg, la inspección técnica con ffprobe y la ejecución de tareas por lotes configuradas en archivos JSON.

## 2. Contratos y API
La aplicación de línea de comandos expone cuatro subcomandos principales definidos mediante `clap` (con plantilla de ayuda que incluye la sección canónica `SUBCOMMANDS:`):

- **`download`**:
  - Argumentos: `url` (URL remota o fixture local en disco o sintética `test://`), `--output-dir` (directorio de destino, por defecto `storage/media/raw`), `--output` (ruta de archivo explícita, extrayendo directorio y prefijo), `--filename` (nombre base sin extensión).
  - Salida: Objeto JSON con el estado de la operación y la ruta del archivo descargado (`file_path`).
- **`cut`**:
  - Argumentos: Entrada mediante argumento posicional `<INPUT>` o flag opcional `--input <PATH>`, `--start` (segundo inicial, defecto 0.0), `--end` (segundo final opcional), `--duration` (duración en segundos, calculando `end = start + duration` si no se provee `--end`), `--mode` (`blur_padding`, `center_crop`, `letterbox`), `--output` (ruta del clip resultante), `--thumb` (ruta de miniatura JPEG).
  - Salida: Objeto JSON `Clip` con identificador UUID, rutas, duraciones y dimensiones normalizadas a 1080x1920.
- **`probe`**:
  - Argumentos: `input` (ruta al archivo multimedia).
  - Salida: Objeto JSON con duración técnica en campos duales (`duration` y `duration_seconds`), dimensiones, codecs de video y audio, y tasa de bits.
- **`batch`**:
  - Argumentos: `config` (ruta al archivo JSON con la especificación `VideoTask` individual o un arreglo JSON de definiciones de corte `[{"id": "...", "start": ..., "duration": ...}]`).
  - Salida: Mensaje informativo `Processed X batch jobs successfully` y objeto/arreglo JSON de resultados procesados.

## 3. Dependencias
- **Internas**:
  - `reelforge-core`: Modelos de dominio (`VideoTask`, `Clip`, `Dimensions`, `TimeRange`, `VerticalMode`), y tipos de error.
  - `reelforge-downloader`: Lógica de descarga y sondeo de contenedores (`VideoDownloader`, `probe_media_file`).
  - `reelforge-cutter`: Algoritmos de segmentación vertical y extracción de miniaturas (`VideoCutter`).
- **Externas**:
  - `clap`: Parser declarativo de argumentos de línea de comandos con soporte `derive`.
  - `tokio`: Runtime asíncrono para coordinar subprocesos de FFmpeg y yt-dlp.
  - `serde_json`: Serialización y formateo con sangría de resultados JSON en salida estándar.
  - `uuid`: Generación de nombres y claves de clip.
  - `tempfile`: Soporte para pruebas de integración de subcomandos.

## 4. Mapa de archivos
- `Cargo.toml`: Manifiesto del crate con dependencias de espacio de trabajo.
- `src/main.rs`: Definición de la estructura `Cli`, subcomandos `Commands`, lógica de despacho `main` y pruebas unitarias de parseo de argumentos.

## 5. Lógica y flujo
1. **Parseo de argumentos**: `Cli::parse()` valida flags obligatorios y opcionales.
2. **Despacho asíncrono**:
   - `Download`: Detecta la herramienta disponible (`yt-dlp` o `uvx yt-dlp`) y ejecuta la descarga emitiendo JSON final.
   - `Cut`: Valida el intervalo temporal `[start, end]`, construye el grafo de filtros para la relación 9:16 y genera el video junto a su miniatura en el punto medio.
   - `Probe`: Ejecuta `ffprobe` e imprime los metadatos técnicos en JSON legible.
   - `Batch`: Deserializa la tarea completa desde el archivo JSON especificado, resuelve la fuente (descarga o archivo local) y procesa todos los clips secuencialmente con reporte final estructurado.

## 6. Decisiones de diseño y gotchas
- **Salida estándar estructurada**: Cada subcomando emite su resultado principal en formato JSON válido a través de `stdout`, facilitando la composición con herramientas Unix como `jq` o la integración en scripts externos de bash y CI/CD.
- **Gotcha**: Al invocar el comando `cut`, si no se proporciona `--output`, se genera por defecto un archivo `clip.mp4` en el directorio de trabajo actual; en entornos de producción se recomienda especificar siempre la ruta absoluta dentro de `storage/media/clips/`.

## 7. Estado y últimos cambios
- **Estado**: Implementación completa y verificada con pruebas unitarias y suites E2E.
- **Cambios recientes**:
  - Soporte de entrada dual en `cut` (posicional `<INPUT>` y flag `--input`) y soporte de `--duration`.
  - Soporte de `--output` directo en `download` extrayendo directorio y prefijo de archivo.
  - Emisión de clave dual `duration` y `duration_seconds` en `probe`.
  - Soporte en `batch` de arreglos de manifiesto JSON `[{"id": "...", "start": ..., "duration": ...}]` y objetos `VideoTask`.
  - Inclusión de encabezado canónico `SUBCOMMANDS` en plantilla de ayuda de `clap`.

## 8. Cómo verificar
Ejecutar las pruebas unitarias del crate:
```bash
cargo test --manifest-path rust-engine/Cargo.toml -p reelforge-cli
```
Precondiciones: Entorno Rust toolchain 1.99+.
