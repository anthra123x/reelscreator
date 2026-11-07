# reelforge-core

## 1. Propósito y alcance
El crate `reelforge-core` constituye el núcleo de dominio y contratos fundamentales del motor de procesamiento de video ReelForge. Su propósito primordial es proveer definiciones de tipos compartidos, modelos de tareas de corte, representaciones canónicas de clips, metadatos de video, máquina de estados finitos del ciclo de vida de trabajos y una jerarquía fuertemente tipada de errores de ejecución.

Este componente opera como una biblioteca pura sin dependencias de sistemas de entrada/salida pesados, garantizando la interoperabilidad sin acoplamiento entre los módulos de descarga, corte, servicio HTTP Axum e interfaz de línea de comandos.

## 2. Contratos y API
El crate expone las siguientes estructuras de datos, enumeraciones y tipos de error:

- **Estructuras de Dominio**:
  - `VideoTask`: Especificación completa de un trabajo de procesamiento (identificador `Uuid`, URL de origen, ruta local opcional, estrategia de corte, intervalos, modo vertical, directorio de salida y webhook opcional).
  - `Clip`: Metadatos del clip generado (`clip_id`, `file_path`, `thumbnail_path`, `start_time`, `end_time`, `duration`, `width`, `height`).
  - `VideoMetadata`: Información técnica extraída por sondas (`duration_seconds`, `width`, `height`, codecs de audio y video, tasa de bits y formato).
  - `TimeRange`: Intervalo cerrado `[start, end]` con validación estricta de límites temporales.
  - `Dimensions`: Ancho y alto en píxeles.
  - `DownloadProgress`: Porcentaje de descarga, velocidad en bytes por segundo y tiempo estimado de finalización.
- **Funciones de Seguridad**:
  - `validate_path_security(path_str: &str, field_name: &str) -> Result<()>`: Validación canónica y prevención de traversals (`..`), caracteres de expansión de usuario (`~`), archivos sensibles (`.env`, `.git`, `.ssh`, `passwd`, `shadow`) y directorios de sistema restringidos (`/etc`, `/root`, `/bin`, `/sbin`, `/usr`, `/var`, `/proc`, `/sys`, `/dev`, `/boot`, `/lib`, `/tmp`).
- **Enumeraciones y Máquinas de Estado**:
  - `JobState`: Estados `Queued` (serializado como `"queued"`, con alias `Pending` para compatibilidad), `Downloading`, `Analyzing`, `Cutting`, `Completed`, `Failed`, `Cancelled`.
  - `JobStatusRecord`: Registro de seguimiento del ciclo de vida con marcas de tiempo `DateTime<Utc>`, progreso porcentual y lista de clips.
  - `CutStrategy`: Estrategias `FixedInterval`, `SceneDetection`, `SilenceDetection`, `ManualTimestamps`.
  - `AspectRatio`: Relaciones `Ratio9x16` (1080x1920), `Ratio16x9` (1920x1080), `Ratio1x1` (1080x1080), `Ratio4x5` (1080x1350).
  - `VerticalMode`: Estrategias de encuadre `BlurPadding`, `CenterCrop`, `Letterbox`.
- **Errores**:
  - `CoreError`: Jerarquía exhaustiva implementada con `thiserror` (`ValidationError`, `DownloadError`, `CutterError`, `ProbeError`, `JobNotFound`, `InvalidStateTransition`, `ProcessFailed`, `FileNotFound`, `Cancelled`, `CapacityExceeded`, `Io`, `Serialization`).

## 3. Dependencias
- **Internas**: Ninguna (nodo raíz del grafo de dependencias).
- **Externas**:
  - `serde`: Serialización y deserialización derive para formatos JSON.
  - `serde_json`: Manipulación y validación de estructuras JSON.
  - `thiserror`: Generación declarativa de jerarquías de errores tipados.
  - `uuid`: Manejo seguro de identificadores universales (v4).
  - `chrono`: Marcas de tiempo UTC para trazabilidad de estados.

## 4. Mapa de archivos
- `Cargo.toml`: Manifiesto del crate con dependencias de espacio de trabajo.
- `src/lib.rs`: Punto de entrada del crate y re-exportación de símbolos públicos.
- `src/models.rs`: Modelos de dominio (`VideoTask`, `Clip`, `CutStrategy`, `AspectRatio`, `VerticalMode`, `Dimensions`, `VideoMetadata`, `TimeRange`).
- `src/state.rs`: Máquina de estados `JobState`, ciclo de vida y estructura `JobStatusRecord`.
- `src/errors.rs`: Tipos de error `CoreError` y alias `Result<T>`.

## 5. Lógica y flujo
El flujo operativo gobernado por `reelforge-core` implementa las siguientes transiciones estrictas:
1. Creación de `VideoTask` y validación de campos obligatorios (`source_url` o `local_video_path`, tiempos finitos mayores o iguales a 1.0 segundo, ausencia de secuencias de escape de directorio en `output_dir`).
2. Inicialización de `JobStatusRecord` en estado `Queued` (serializado como `"queued"`).
3. Transición controlada a `Downloading` o `Analyzing` (si el archivo local ya existe).
4. Avance a `Cutting` tras determinar los puntos de corte.
5. Finalización en `Completed` al registrar todos los clips generados, o derivación a `Failed` o `Cancelled` ante anomalías o interrupciones.
6. Prohibición de transiciones inválidas (por ejemplo, de `Completed` a `Cutting` o de `Pending` directo a `Completed`).

## 6. Decisiones de diseño y gotchas
- **Inmutabilidad de identificadores**: Los identificadores de tareas y clips utilizan `Uuid` v4 generado de forma determinista o aleatoria para garantizar unicidad global.
- **Serialización canónica para APIs**: Los nombres de campos y variantes de enumeraciones se serializan en `snake_case` o con valores textuales normalizados (ejemplo: `"9:16"` para `AspectRatio::Ratio9x16`) para alinearse con los esquemas de backend Laravel y frontend Angular.
- **Gotcha**: Las transiciones de estado hacia `Failed` o `Cancelled` solo están permitidas desde estados no terminales; cualquier intento de re-transicionar un estado terminal produce un `CoreError::InvalidStateTransition`.
- **Validación numérica estricta**: En IEEE 754, `NaN <= 0.0` resulta falso; por tanto, la validación exige explícitamente `is_finite()` y valores `>= 1.0` tanto en intervalos como duraciones máximas.
- **Aislamiento de rutas y sistema de archivos**: La validación `validate_path_security` se centraliza en el crate núcleo para impedir accesos o escrituras a rutas del sistema host (`/etc`, `/root`, `/bin`, `/var`, `/tmp` directo) y archivos sensibles como `.env` o credenciales.

## 7. Estado y últimos cambios
- **Estado**: Implementación completa y verificada con pruebas unitarias.
- **Cambios recientes**:
  - Remediación de seguridad en `validate_path_security`: normalización canónica de componentes basada en `std::path::Component`, eliminación de prefijos `file://`, colapso de barras redundantes (`//` a `/`), depuración de componentes de directorio actual (`.`), rechazo estricto de secuencias de escape (`..`), validación de archivos sensibles (`hosts`, `.env`, `passwd`, `shadow`, `.git`, `.ssh`), y bloqueo de prefijos de sistema y directorio raíz `/tmp`.
  - Implementación previa de `validate_path_security` para auditar traversals, expansión de tilde, archivos sensibles y prefijos de sistema host.
  - Integración obligatoria de `validate_path_security` en `VideoTask::validate()` para `output_dir` y `local_video_path`.
  - Endurecimiento adversarial de `VideoTask::validate()`: validación de finitud numérica y umbral `>= 1.0` en `interval_seconds` y `max_duration_seconds`.
  - Remediación del estado inicial `JobState::Queued` con serialización `"queued"` y alias retrocompatible `Pending`.
  - Implementación de modelos de tareas, clips y metadatos.
  - Formalización de la máquina de estados y transiciones válidas.
  - Implementación de la jerarquía de errores basada en `thiserror`.

## 8. Cómo verificar
Ejecutar el comando de pruebas unitarias en el crate:
```bash
cargo test -p reelforge-core
```
Precondiciones: Entorno Rust toolchain 1.99+ instalado.
