# Modulo de Pruebas End-to-End (E2E) y Suite de Aceptacion

## 1. Propósito y alcance
El modulo `tests/e2e` constituye la infraestructura de pruebas end-to-end de caja opaca para la plataforma ReelForge. Su alcance cubre la verificacion integral de requerimientos funcionales, limites de dominio, combinaciones entre servicios y flujos de produccion reales sin acoplamiento a detalles internos de implementacion. El marco evalua de forma exhaustiva los tres pilares de la arquitectura: el motor de video en Rust (`reelforge-cli` y `reelforge-server`), la API modular en Laravel 12 y las estrategias de publicacion social a traves de adaptadores simulados (`MockSocialAdapter`).

## 2. Contratos y API
La suite de pruebas interactua exclusivamente a traves de las interfaces publicas y contratos formales documentados en `PROJECT.md`:
- **CLI de Rust (`reelforge-cli`)**: Comandos `--help`, `--version`, `probe <archivo>`, `cut --input <in> --output <out> --start <s> --duration <d>`, `download <url|fixture>`, `batch <manifest.json>`.
- **API Axum de Rust (`reelforge-server`)**:
  - `GET /health` -> `200 OK` con esquema de estado de salud, version y metricas de concurrencia (`active_jobs`, `max_concurrent_jobs`, `available_permits`).
  - `POST /api/v1/jobs` -> `202 Accepted` con `{"job_id": UUID, "status": "queued", "message": "..."}`.
  - `GET /api/v1/jobs/:id` -> `200 OK` con entidad de trabajo completo y arreglo `clips` (dimensiones `1080x1920`, rutas de archivos MP4 y miniaturas JPEG).
- **API REST Modular de Laravel**:
  - `POST /api/videos` -> `201 Created` con identificador UUID y estado inicial `pending`.
  - `GET /api/videos/:id/status` -> `200 OK` con porcentaje de progreso y estado del ciclo de vida.
  - `GET /api/videos/:id/clips` -> `200 OK` con coleccion de entidades de clips transformados en relacion 9:16.
  - `POST /api/clips/:id/publish` -> `202 Accepted` con lista de identificadores de publicacion encolados.
  - `GET /api/social-accounts` -> `200 OK` con listado de plataformas conectadas y banderas `is_mock`.
  - `POST /api/social-accounts/mock-connect` -> `201 Created` con cuenta sintetica asociada.
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
- `common/media_generator.py`: Generador de medios sintetizados (`testsrc`, `sine`), patrones de color, miniaturas JPEG y archivos de frontera corruptos o de cero bytes.
- `common/media_validator.py`: Validador de propiedades fisicas mediante `ffprobe` (resolucion 1080x1920, codecs h264/aac, duraciones y validez de imagenes JPEG).
- `common/contracts.py`: Validadores formales de esquemas JSON y aserciones de tipos segun especificaciones de `PROJECT.md`.
- `common/mock_server.py`: Servidor HTTP efimero y adaptador mock de referencia para pruebas deterministicas fuera de linea.
- `common/__init__.py`: Declaracion de paquete Python para utilidades compartidas.
- `tier1_features/test_rust_cli.py`: Pruebas de cobertura funcional para los comandos de la CLI de Rust (5 casos).
- `tier1_features/test_rust_axum_api.py`: Pruebas de cobertura para los endpoints REST del servidor Axum (5 casos).
- `tier1_features/test_laravel_api.py`: Pruebas de cobertura para los endpoints REST de Laravel (5 casos).
- `tier1_features/test_mock_social_adapter.py`: Pruebas de cobertura para `MockSocialAdapter` en TikTok, Instagram y YouTube (5 casos).
- `tier2_boundaries/test_video_boundaries.py`: Pruebas de valores limite para archivos vacios, duraciones extremas, URLs corruptas y timestamps invertidos (5 casos).
- `tier2_boundaries/test_backend_boundaries.py`: Pruebas de valores limite para tokens vencidos, limites de duracion de YouTube Shorts (60s), errores 404 y saturacion de concurrencia (5 casos).
- `tier3_combinations/test_cross_feature.py`: Pruebas de integracion multicomponente: corte + miniatura + relacion 9:16, publicacion simultanea multiplataforma y pipeline Laravel-Axum (5 casos).
- `tier4_real_world/test_real_world_pipeline.py`: Flujo de simulacion real extremo a extremo desde patron sintetico FFmpeg hasta verificacion de streams `ffprobe` y publicacion social (5 casos).
- `tier5_adversarial/test_adversarial_cli.py`: Pruebas de endurecimiento adversarial sobre CLI de Rust ante flags hostiles, archivos corruptos y marcas de tiempo extremas (7 casos).
- `tier5_adversarial/test_adversarial_rest_pipeline.py`: Pruebas de endurecimiento adversarial sobre contratos REST y pipeline ante rafagas masivas, JSON malformado y limites de plataforma (8 casos).
- `tier5_adversarial/__init__.py`: Declaracion de paquete para el modulo de pruebas adversariales.

## 5. Lógica y flujo
1. **Inicializacion y orquestacion**: `test_runner.sh` procesa argumentos CLI (`--tier`, `--mode`, `--verbose`, `--json-report`). Establece la variable `PYTHONPATH` al directorio raiz del proyecto.
2. **Descubrimiento y ejecucion por capas**:
   - **Capa 1 (Funcionalidades)**: Ejecuta 20 pruebas unitarias de contrato sobre CLI, endpoints REST de Axum, endpoints REST de Laravel y metodos del adaptador social.
   - **Capa 2 (Fronteras y Negativas)**: Ejecuta 10 pruebas de rechazo controlado ante entradas hostiles o invalidas (archivos de 0 bytes, encabezados corruptos, marcas de tiempo invertidas, tokens vencidos, limites de plataforma de 60s).
   - **Capa 3 (Combinaciones)**: Ejecuta 5 escenarios intermodulares verificando la composicion simultanea de transformacion visual, generacion de miniaturas y despacho de publicaciones.
   - **Capa 4 (Simulacion Real)**: Ejecuta las 5 etapas del flujo completo de produccion: generacion de video base de 10 segundos, particion en dos reels verticales de 5 segundos a 1080x1920 con fondo desenfocado, extraccion de miniaturas, validacion fisica con `ffprobe` y publicacion en TikTok e Instagram Reels.
   - **Capa 5 (Endurecimiento Adversarial)**: Ejecuta 15 pruebas de resistencia extrema: rafagas de peticiones secuenciales, inyeccion de sintaxis JSON corrupta, intervalos de tiempo negativos o nulos, inyecciones SQL/XSS en titulos y limites de duracion para YouTube Shorts (60s).
3. **Consolidacion y reporte**: El runner calcula tiempos por capa, imprime una tabla de resultados en consola y exporta un documento JSON estructurado si se especifica la bandera correspondiente. Emite codigo de salida `0` si todas las capas culminan con exito.

## 6. Decisiones de diseño y gotchas
- **Restriccion de CPU de nucleo unico (`nproc = 1`)**: Los comandos de transcodificacion FFmpeg dentro de `MediaGenerator` imponen estrictamente `-threads 1` y preajuste `ultrafast` para evitar saturacion de la CPU y congelamiento del entorno de desarrollo.
- **Aislamiento sin dependencias de red**: Todos los videos de prueba son generados dinamicamente via filtros de lavfi (`testsrc`, `sine`, `color`) en el sistema de archivos local, eliminando cualquier peticion HTTP externa hacia YouTube o redes sociales.
- **Ejecucion dual (Servicio en vivo vs. Oraculo efimero)**: Los arneses de prueba detectan si existe un servidor Axum en el puerto 3000 o un backend Laravel en el puerto 8000. En su ausencia, activan un servidor mock en segundo plano que implementa el contrato exacto, permitiendo validar la infraestructura de pruebas de forma autonoma y progresiva sin bloquearse por hitos pendientes.
- **Validacion fisica vs. Comprobacion de existencia**: No se considera suficiente verificar que un archivo `.mp4` exista en disco. `MediaValidator` invoca `ffprobe` para inspeccionar el flujo de video (anchura exacta 1080, altura exacta 1920, codec H.264), el flujo de audio (codec AAC) y la duracion cronometrada.

## 7. Estado y últimos cambios
- **2026-11-07**: Creacion y configuracion inicial de la suite de pruebas E2E de ReelForge.
- Implementacion de arneses compartidos: generador de medios FFmpeg, validador de streams ffprobe, validador de contratos formales y servidor mock efimero.
- Implementacion de 20 pruebas de funcionalidad en Tier 1, 10 pruebas de frontera en Tier 2, 5 escenarios de combinacion en Tier 3 y 5 etapas de pipeline real en Tier 4 (40 pruebas totales).
- Implementacion del runner maestro unificado `test_runner.sh` con soporte para ejecucion selectiva por capas, generacion de reportes JSON y codigos de retorno estandarizados.
- Incorporacion de `verify_agent_docs.py` para comprobacion automatizada de las 8 secciones obligatorias de regla 4 y reglas de exclusion en Docker.
- Optimizacion quirurgica en `test_runner.sh` para captura continua y conteo preciso de pruebas tanto en modo estandar como en modo detallado (`--verbose`).
- Incorporacion de Tier 5: suite de endurecimiento adversarial (`tier5_adversarial`) con 15 pruebas cubriendo CLI de Rust y contratos REST/publicacion, elevando la suite E2E a 55 pruebas totales.

## 8. Cómo verificar
- **Verificacion automatica de documentacion y exclusiones**:
  ```bash
  python3 tests/e2e/verify_agent_docs.py
  ```
- **Ejecucion de todas las capas de prueba (Tier 1 a 5)**:
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
