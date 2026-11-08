# Modulo de Pruebas End-to-End (E2E) y Suite de Aceptacion

## 1. Propósito y alcance
El modulo `tests/e2e` constituye la infraestructura de pruebas end-to-end de caja opaca para la plataforma ReelForge. Su alcance cubre la verificacion integral de requerimientos funcionales, limites de dominio, combinaciones entre servicios y flujos de produccion reales sin acoplamiento a detalles internos de implementacion. El marco evalua de forma exhaustiva la descomposicion vertical 9:16 de videos, la conexion agil de cuentas sociales (TikTok, Instagram, YouTube), el despacho automatico desatendido, la sincronizacion de metricas en tiempo real para el panel de control y la verificacion de extremos de produccion.

## 2. Contratos y API
La suite de pruebas interactua exclusivamente a traves de las interfaces publicas y contratos formales documentados en `PROJECT.md` y `ORIGINAL_REQUEST.md`:
- **CLI de Rust (`reelforge-cli`)**: Comandos `--help`, `--version`, `probe <archivo>`, `cut --input <in> --output <out> --start <s> --duration <d>`, `download <url|fixture>`, `batch <manifest.json>`.
- **API Axum de Rust (`reelforge-server`)**:
  - `GET /health` -> `200 OK` con esquema de estado de salud, version y metricas de concurrencia (`active_jobs`, `max_concurrent_jobs`, `available_permits`).
  - `POST /api/v1/jobs` -> `202 Accepted` con `{"job_id": UUID, "status": "queued", "message": "..."}`.
  - `GET /api/v1/jobs/:id` -> `200 OK` con entidad de trabajo completo y arreglo `clips` (dimensiones `1080x1920`, rutas de archivos MP4 y miniaturas JPEG).
- **API REST Modular de Laravel**:
  - `POST /api/videos` -> `201 Created` con identificador UUID y estado inicial `pending`.
  - `POST /api/videos/{id}/decompose` -> `200 OK` con arreglo de clips 9:16 y despacho automatico opcional.
  - `POST /api/videos/decompose` -> `201 Created` con ingesta directa y generacion de clips 9:16.
  - `GET /api/videos` -> `200 OK` con lista consolidada de videos y conteo total.
  - `GET /api/videos/:id/status` -> `200 OK` con porcentaje de progreso y estado del ciclo de vida.
  - `GET /api/videos/:id/clips` -> `200 OK` con coleccion de entidades de clips transformados en relacion 9:16.
  - `GET /api/clips` -> `200 OK` con listado global de clips 9:16 generados.
  - `POST /api/clips/:id/publish` -> `202 Accepted` con lista de identificadores de publicacion encolados.
  - `GET /api/publications` -> `200 OK` con listado de publicaciones y desglose de estados.
  - `GET /api/social-accounts` -> `200 OK` con listado de plataformas conectadas y banderas `is_mock`.
  - `POST /api/social-accounts/connect` -> `200 OK` con vinculacion agil multicanal (`platform: "all"` o individual) y modos (`auto`, `env`, `mock`, `direct`).
  - `GET /api/dashboard/metrics` -> `200 OK` con KPIs consolidados en tiempo real (`total_videos`, `total_clips`, `connected_accounts`, `publications`).
- **Interfaz de Adaptadores de Publicacion (`SocialPublisherAdapterInterface`)**:
  - `getPlatform(): string`
  - `publish(clip, account, options): PublishResult`
  - `refreshToken(account): bool`
  - `validateAccount(account): bool`

## 3. Dependencias
- **Herramientas de sistema requeridas**:
  - `bash` (ejecutor de `test_runner.sh`).
  - `python3` (motor de ejecucion de pruebas basado en `unittest` sin paquetes externos de pip).
  - `ffmpeg` (generacion de fixtures sintetizados y transcodificacion 9:16 vertical con filtro de desenfoque).
  - `ffprobe` (inspeccion y validacion estricta de streams de video, audio, codecs y dimensiones).
- **Modulos internos consumidos**:
  - `rust-engine/` (binarios ejecutables `reelforge-cli` y microservicio Axum en puerto 3000).
  - `backend/` (API HTTP en puerto 8000 y adaptadores de publicacion).
- **Direccion de dependencias**:
  - `tests/e2e` consume los binarios y servicios expuestos por `rust-engine` y `backend`. No existe dependencia inversa desde el codigo fuente de produccion hacia la suite de pruebas.

## 4. Mapa de archivos
- `test_runner.sh`: Script maestro ejecutable en Bash que orquesta las 5 capas de pruebas, compila resultados y emite codigos de salida normalizados.
- `verify_agent_docs.py`: Script de verificacion automatizada de conformidad documental (8 secciones de regla 4) y exclusiones de produccion (.dockerignore, frontend/dist).
- `README.md`: Memoria tecnica local del modulo de pruebas para agentes y desarrolladores.
- `common/media_generator.py`: Generador de medios sintetizados (`testsrc`, `sine`), patrones de color, miniaturas JPEG, filtros personalizados y archivos de frontera corruptos o de cero bytes.
- `common/media_validator.py`: Validador de propiedades fisicas mediante `ffprobe` (resolucion 1080x1920, codecs h264/aac, duraciones y validez de imagenes JPEG).
- `common/contracts.py`: Validadores formales de esquemas JSON y aserciones de tipos segun especificaciones de `PROJECT.md` y `ORIGINAL_REQUEST.md`.
- `common/mock_server.py`: Servidor HTTP efimero y adaptador mock de referencia para pruebas deterministicas fuera de linea con soporte para endpoints de descomposicion, conexion agil y metricas de dashboard.
- `common/__init__.py`: Declaracion de paquete Python para utilidades compartidas.
- `tier1_features/test_video_decomposition.py`: Pruebas de cobertura funcional para descomposicion de video (POST /api/videos/{id}/decompose, POST /api/videos/decompose, fallback sin servidor) (5 casos).
- `tier1_features/test_916_geometry.py`: Pruebas de geometria vertical 9:16 (1080x1920, blur_padding, center_crop, letterbox, ffprobe) (5 casos).
- `tier1_features/test_auto_dispatch.py`: Pruebas de despacho automatico sin intervencion manual, batching de clips y registro de auditoria (5 casos).
- `tier1_features/test_social_accounts_connection.py`: Pruebas de conexion agil en 1-clic y modos auto, env, mock, direct (5 casos).
- `tier1_features/test_dashboard_metrics.py`: Pruebas de agregacion de metricas de dashboard y KPIs en tiempo real (5 casos).
- `tier1_features/test_rust_cli.py`: Pruebas de cobertura funcional para los comandos de la CLI de Rust (5 casos).
- `tier1_features/test_rust_axum_api.py`: Pruebas de cobertura para los endpoints REST del servidor Axum (5 casos).
- `tier1_features/test_laravel_api.py`: Pruebas de cobertura para los endpoints REST de Laravel (5 casos).
- `tier1_features/test_mock_social_adapter.py`: Pruebas de cobertura para `MockSocialAdapter` en TikTok, Instagram y YouTube (5 casos).
- `tier2_boundaries/test_decomposition_boundaries.py`: Pruebas de frontera para descomposicion (404, 422, URLs corruptas, protocolos invalidos, intervalos negativos) (5 casos).
- `tier2_boundaries/test_duration_and_platform_limits.py`: Pruebas de limites de plataforma (YouTube Shorts 60s, Instagram Reels 90s, TikTok, duracion minima) (5 casos).
- `tier2_boundaries/test_social_connection_boundaries.py`: Pruebas de frontera para conexion social (tokens expirados, duplicados, plataformas invalidas, modos no permitidos) (5 casos).
- `tier2_boundaries/test_dashboard_boundaries.py`: Pruebas de frontera para dashboard (estado vacio, concurrencia, sanitizacion de parametros) (5 casos).
- `tier2_boundaries/test_video_boundaries.py`: Pruebas de valores limite para archivos vacios, duraciones extremas, URLs corruptas y timestamps invertidos (5 casos).
- `tier2_boundaries/test_backend_boundaries.py`: Pruebas de valores limite para tokens vencidos, limites de duracion de YouTube Shorts (60s), errores 404 y saturacion de concurrencia (5 casos).
- `tier3_combinations/test_autonomous_pipeline_combinations.py`: Pruebas de interaccion pairwise: descomposicion -> auto-despacho -> publicacion multicanal -> bitacoras de auditoria (5 casos).
- `tier3_combinations/test_cross_feature.py`: Pruebas de integracion multicomponente: corte + miniatura + relacion 9:16, publicacion simultanea multiplataforma y pipeline Laravel-Axum (5 casos).
- `tier4_real_world/test_unattended_production_workflow.py`: Escenarios de produccion desatendida multireel, ciclo de vida de dashboard, lotes concurrentes y recuperacion de fallas transitorias (5 casos).
- `tier4_real_world/test_real_world_pipeline.py`: Flujo de simulacion real extremo a extremo desde patron sintetico FFmpeg hasta verificacion de streams `ffprobe` y publicacion social (5 casos).
- `tier5_adversarial/test_adversarial_cli.py`: Pruebas de endurecimiento adversarial sobre CLI de Rust ante flags hostiles, archivos corruptos y marcas de tiempo extremas (7 casos).
- `tier5_adversarial/test_adversarial_rest_pipeline.py`: Pruebas de endurecimiento adversarial sobre contratos REST y pipeline ante rafagas masivas, JSON malformado y limites de plataforma (8 casos).
- `tier5_adversarial/__init__.py`: Declaracion de paquete para el modulo de pruebas adversariales.

## 5. Lógica y flujo
1. **Inicializacion y orquestacion**: `test_runner.sh` procesa argumentos CLI (`--tier`, `--mode`, `--verbose`, `--json-report`). Establece la variable `PYTHONPATH` al directorio raiz del proyecto.
2. **Descubrimiento y ejecucion por capas**:
   - **Capa 1 (Funcionalidades)**: Ejecuta 45 pruebas unitarias de contrato sobre descomposicion vertical 9:16, geometria fisica, auto-despacho, conexion agil de cuentas, metricas de dashboard, CLI, endpoints REST de Axum, endpoints REST de Laravel y metodos del adaptador social.
   - **Capa 2 (Fronteras y Negativas)**: Ejecuta 30 pruebas de rechazo controlado ante entradas hostiles o invalidas (archivos de 0 bytes, encabezados corruptos, marcas de tiempo invertidas, tokens vencidos, limites de duracion de YouTube Shorts de 60s, Instagram Reels de 90s, conexiones duplicadas y estados vacios).
   - **Capa 3 (Combinaciones)**: Ejecuta 10 escenarios intermodulares verificando la composicion de descomposicion desatendida, auto-despacho multicanal, registro en bitacoras de auditoria y sincronizacion de estado de dashboard.
   - **Capa 4 (Simulacion Real)**: Ejecuta 10 etapas y escenarios de produccion completa: generacion de video base sintetico, particion vertical 1080x1920 con fondo desenfocado, validacion fisica estricta con `ffprobe`, publicacion simultanea en TikTok, Instagram y YouTube, flujos de ciclo de vida en dashboard y aislamiento en ejecucion concurrente de lotes.
   - **Capa 5 (Endurecimiento Adversarial)**: Ejecuta 15 pruebas de resistencia extrema: rafagas de peticiones secuenciales, inyeccion de sintaxis JSON corrupta, intervalos de tiempo negativos o nulos, inyecciones SQL/XSS en titulos y limites de duracion de plataforma.
3. **Consolidacion y reporte**: El runner calcula tiempos por capa, imprime una tabla de resultados en consola y exporta un documento JSON estructurado si se especifica la bandera correspondiente. Emite codigo de salida `0` si todas las capas culminan con exito.

## 6. Decisiones de diseño y gotchas
- **Restriccion de CPU de nucleo unico (`nproc = 1`)**: Los comandos de transcodificacion FFmpeg dentro de `MediaGenerator` imponen estrictamente `-threads 1` y preajuste `ultrafast` para evitar saturacion de la CPU y congelamiento del entorno de desarrollo.
- **Aislamiento sin dependencias de red**: Todos los videos de prueba son generados dinamicamente via filtros de lavfi (`testsrc`, `sine`, `color`) en el sistema de archivos local, eliminando cualquier peticion HTTP externa hacia YouTube o redes sociales.
- **Ejecucion dual (Servicio en vivo vs. Oraculo efimero)**: Los arneses de prueba detectan si existe un servidor Axum en el puerto 3000 o un backend Laravel en el puerto 8000. En su ausencia o bajo modo automatico, activan un servidor mock en segundo plano que implementa el contrato exacto, permitiendo validar la infraestructura de pruebas de forma autonoma y progresiva sin bloquearse por hitos pendientes.
- **Validacion fisica vs. Comprobacion de existencia**: No se considera suficiente verificar que un archivo `.mp4` exista en disco. `MediaValidator` invoca `ffprobe` para inspeccionar el flujo de video (anchura exacta 1080, altura exacta 1920, codec H.264), el flujo de audio (codec AAC) y la duracion cronometrada.

## 7. Estado y últimos cambios
- **2026-11-07**: Ampliacion y consolidacion integral de la suite de pruebas E2E de ReelForge segun requerimientos de descomposicion vertical 9:16, auto-despacho, conexion agil de cuentas y metricas de dashboard.
- Implementacion de suites dedicadas en Tier 1: `test_video_decomposition.py`, `test_916_geometry.py`, `test_auto_dispatch.py`, `test_social_accounts_connection.py`, `test_dashboard_metrics.py` (elevando Tier 1 a 45 pruebas).
- Implementacion de suites de frontera en Tier 2: `test_decomposition_boundaries.py`, `test_duration_and_platform_limits.py`, `test_social_connection_boundaries.py`, `test_dashboard_boundaries.py` (elevando Tier 2 a 30 pruebas).
- Implementacion de suite de interacciones en Tier 3: `test_autonomous_pipeline_combinations.py` (elevando Tier 3 a 10 pruebas).
- Implementacion de suite de produccion en Tier 4: `test_unattended_production_workflow.py` (elevando Tier 4 a 10 pruebas).
- Verificacion de ejecucion 100% exitosa del runner unificado sobre las 5 capas con un total de 110 pruebas automatizadas sin dependencias externas.

## 8. Cómo verificar
- **Verificacion automatica de documentacion y exclusiones**:
  ```bash
  python3 tests/e2e/verify_agent_docs.py
  ```
- **Ejecucion de todas las capas de prueba (Tier 1 a 5, 110 pruebas)**:
  ```bash
  ./tests/e2e/test_runner.sh
  ```
- **Ejecucion de una capa individual**:
  ```bash
  ./tests/e2e/test_runner.sh --tier 1
  ./tests/e2e/test_runner.sh --tier 2
  ./tests/e2e/test_runner.sh --tier 3
  ./tests/e2e/test_runner.sh --tier 4
  ./tests/e2e/test_runner.sh --tier 5
  ```
- **Ejecucion con trazabilidad detallada y reporte JSON**:
  ```bash
  ./tests/e2e/test_runner.sh --verbose --json-report /tmp/e2e_results.json
  ```
- **Precondiciones**: Binarios `python3`, `ffmpeg` y `ffprobe` disponibles en el `PATH` del sistema.
