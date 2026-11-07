# rust-engine

## 1. Propósito y alcance
El directorio `rust-engine` aloja el espacio de trabajo virtual multi-crate del motor de procesamiento multimedia de ReelForge. Su propósito es proveer una solución de alto rendimiento, bajo consumo de memoria y ejecución segura para la adquisición de secuencias de video mediante `yt-dlp`, la transcodificación y segmentación a relaciones verticales (9:16) mediante `ffmpeg` y `ffprobe`, y la exposición de una interfaz de programación de aplicaciones REST asíncrona mediante `axum`, complementada con una herramienta de línea de comandos independiente construida sobre `clap`.

## 2. Contratos y API
El motor opera como un espacio de trabajo modular compuesto por 5 crates con contratos bien definidos:
- **`reelforge-core`**: Biblioteca de modelos de dominio (`VideoTask`, `Clip`, `TimeRange`, `CutStrategy`, `AspectRatio`, `VerticalMode`), máquina de estados `JobState` y jerarquía de errores `CoreError`.
- **`reelforge-downloader`**: Ingesta de video con autodetección de binario `yt-dlp` o contenedor `uvx yt-dlp`, extractor de metadatos con `ffprobe`, parser de progreso con expresiones regulares y generador de fixtures sintéticos fuera de línea.
- **`reelforge-cutter`**: Motor de corte temporal y composición vertical (1080x1920) con filtros `blur_padding` (estándar), `center_crop` y `letterbox`, extracción de miniaturas JPEG en el punto medio y verificación de integridad post-render.
- **`reelforge-server`**: Microservicio HTTP REST en puerto 3000 con encolamiento asíncrono (`202 Accepted`), semáforo de concurrencia Tokio (1 slot por defecto para hosts mononúcleo), y endpoints:
  - `GET /health`
  - `POST /api/v1/jobs`
  - `GET /api/v1/jobs/:id`
  - `POST /api/v1/jobs/:id/cancel`
  - `POST /api/v1/direct-cut`
- **`reelforge-cli`**: Herramienta de línea de comandos para operaciones autónomas (`download`, `cut`, `probe`, `batch`).

## 3. Dependencias
- **Internas**: Enlazadas mediante rutas relativas locales en `crates/`.
- **Externas**:
  - `tokio` (v1.43+): Runtime asíncrono con soporte multi-hilo y subprocesos.
  - `axum` (v0.8+): Servidor web modular y enrutamiento HTTP.
  - `tower` y `tower-http`: Capas de middleware para CORS y trazas.
  - `serde` y `serde_json`: Serialización JSON para contratos de backend.
  - `thiserror`: Jerarquía de errores estática.
  - `clap` (v4.5+): Analizador sintáctico de argumentos de línea de comandos.
  - `uuid` (v1.13+) y `chrono` (v0.4+): Identificadores únicos y marcas temporales UTC.
  - `reqwest`: Cliente HTTP para notificaciones webhook.
- **Herramientas de Sistema**:
  - `ffmpeg` y `ffprobe` (v9.0.2): Codificación y decodificación de video.
  - `yt-dlp` / `uvx`: Descarga de flujos audiovisuales.

## 4. Mapa de archivos
- `Cargo.toml`: Manifiesto raíz del espacio de trabajo virtual.
- `README.md`: Documentación técnica del motor y mapa de componentes.
- `crates/reelforge-core/`: Crate de contratos de dominio y máquina de estados.
- `crates/reelforge-downloader/`: Crate de adquisición de video e inspección de metadatos.
- `crates/reelforge-cutter/`: Crate de transcodificación, filtros 9:16 y miniaturas.
- `crates/reelforge-server/`: Crate del microservicio Axum con control de concurrencia.
- `crates/reelforge-cli/`: Crate de la interfaz de línea de comandos.

## 5. Lógica y flujo
1. **Recepción o Ingesta**: Una solicitud de procesamiento ingresa vía HTTP (`POST /api/v1/jobs`) o CLI (`reelforge cut` / `batch`).
2. **Control de Concurrencia**: La tarea solicita un permiso en el semáforo de Tokio de `reelforge-server`. Si el slot está ocupado, la tarea permanece en cola en memoria sin saturar la CPU.
3. **Descarga**: Si se proporciona una URL remota, `reelforge-downloader` adquiere el archivo en `storage/media/raw/`. Si se usa una URI de prueba (`test://`), genera un archivo sintético MP4 determinista.
4. **Análisis**: `probe_media_file` extrae las propiedades de contenedor y pista.
5. **Corte y Composición**: `reelforge-cutter` procesa cada intervalo con FFmpeg aplicando el filtro vertical solicitado (por defecto, fondo desenfocado con `boxblur=25:5`).
6. **Captura de Miniatura**: Se extrae una imagen JPEG en el punto medio exacto de cada clip en `storage/media/thumbnails/`.
7. **Verificación y Finalización**: `validate_cut_clip` inspecciona las dimensiones y codecs del archivo renderizado. La máquina de estados marca el trabajo como `Completed` y opcionalmente notifica al webhook configurado.

## 6. Decisiones de diseño y gotchas
- **Aislamiento en Espacio de Trabajo Virtual**: El archivo raíz `Cargo.toml` utiliza `resolver = "2"` y unifica las versiones de dependencias en `[workspace.dependencies]`, asegurando tiempos de compilación optimizados y consistencia binaria.
- **Respeto a Restricciones de Hardware Mononúcleo**: En hosts con 1 vCPU (`nproc = 1`), la concurrencia no regulada produce colapsos por contención. Se fijó un semáforo de 1 permiso en el servidor y la bandera `-threads 1` en las invocaciones a FFmpeg.
- **Modo Offline Fixture Fallback**: Las pruebas automatizadas no dependen de servicios de red externos; generan secuencias reales con generadores sintéticos de FFmpeg para certificar funcionalidad genuina sin vulnerar la integridad.

## 7. Estado y últimos cambios
- **Estado**: Espacio de trabajo completamente implementado, con 5 crates operativos, pruebas unitarias y de integración pasando al 100%.
- **Cambios recientes**:
  - Configuración del manifiesto virtual `rust-engine/Cargo.toml`.
  - Implementación de los 5 crates (`reelforge-core`, `reelforge-downloader`, `reelforge-cutter`, `reelforge-server`, `reelforge-cli`).
  - Creación de documentación local de 8 secciones por cada crate.

## 8. Cómo verificar
Compilar el espacio de trabajo completo:
```bash
cargo build --manifest-path rust-engine/Cargo.toml --workspace
```
Ejecutar la suite completa de pruebas unitarias e integración en modo monohilo:
```bash
cargo test --manifest-path rust-engine/Cargo.toml --workspace -- --test-threads=1
```
Precondiciones: Herramientas `cargo`, `rustc`, `ffmpeg` y `ffprobe` disponibles en el sistema.
