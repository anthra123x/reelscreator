# Modulo de Base de Datos y Persistencia Relacional (PostgreSQL / Neon)

## 1. Propósito y alcance
El modulo de base de datos define y administra el esquema relacional, migraciones, fabricas de modelos y sembrado inicial de datos para la plataforma ReelForge. Su proposito es garantizar la consistencia transaccional, integridad referencial y almacenamiento de metadatos estructurados y no estructurados necesarios para el flujo completo de transformacion de videos en clips verticales y su posterior publicacion.

El alcance de este modulo comprende:
- Definicion del esquema completo de datos optimizado para PostgreSQL y endpoints serverless de Neon.
- Adopcion de identificadores unicos universales (UUID v4) como claves primarias para desacoplamiento y generacion distribuida de IDs.
- Tipos de datos nativos avanzados de PostgreSQL: `jsonb` para metadatos arbitrarios de transcodificacion y configuraciones, `double precision` para mediciones temporales de audio/video, y `timestamp with time zone` (`timestamptz`) para coordinacion de publicaciones programadas.
- Integridad referencial estricta mediante restricciones de clave foranea (`foreign key`) con reglas de eliminacion en cascada (`ON DELETE CASCADE`).
- Tablas del sistema Laravel para persistencia de sesiones, cache y colas de trabajo asincronas (`jobs`, `failed_jobs`, `job_batches`).
- Auditoria granular de intentos y estados de publicacion mediante la tabla `publishing_logs`.

Este modulo y su documentacion son de uso exclusivo para desarrollo y mantenimiento de infraestructura, quedando excluidos de artefactos empaquetados para produccion.

## 2. Contratos y API
El esquema relacional de base de datos expone y garantiza los siguientes contratos de tablas y columnas:

### Tablas de Dominio Multimedia y Publicacion

1. `users`:
   - `id`: `uuid`, clave primaria.
   - `name`: `varchar(255)`.
   - `email`: `varchar(255)`, restriccion de unicidad `users_email_unique`.
   - `email_verified_at`: `timestamp(0) without time zone`, nulable.
   - `password`: `varchar(255)`.
   - `remember_token`: `varchar(100)`, nulable.
   - `created_at`, `updated_at`: `timestamp(0) without time zone`.

2. `videos`:
   - `id`: `uuid`, clave primaria.
   - `user_id`: `uuid`, clave foranea referenciando `users(id)` con `ON DELETE CASCADE`.
   - `source_url`: `text`.
   - `title`: `varchar(500)`, nulable.
   - `status`: `varchar(50)`, valor por defecto `'pending'`.
   - `file_path`: `varchar(1024)`, nulable.
   - `duration`: `double precision`, nulable.
   - `metadata`: `jsonb`, valor por defecto `'{}'`.
   - `error_message`: `text`, nulable.
   - `created_at`, `updated_at`: `timestamp(0) without time zone`.
   - Indices: `idx_videos_user_id` en `(user_id)`, `idx_videos_status` en `(status)`.

3. `clips`:
   - `id`: `uuid`, clave primaria.
   - `video_id`: `uuid`, clave foranea referenciando `videos(id)` con `ON DELETE CASCADE`.
   - `file_path`: `varchar(1024)`.
   - `thumbnail_path`: `varchar(1024)`, nulable.
   - `title`: `varchar(500)`, nulable.
   - `start_time`: `double precision`.
   - `end_time`: `double precision`.
   - `duration`: `double precision`.
   - `status`: `varchar(50)`, valor por defecto `'ready'`.
   - `metadata`: `jsonb`, valor por defecto `'{}'`.
   - `created_at`, `updated_at`: `timestamp(0) without time zone`.
   - Indices: `idx_clips_video_id` en `(video_id)`, `idx_clips_status` en `(status)`.

4. `social_accounts`:
   - `id`: `uuid`, clave primaria.
   - `user_id`: `uuid`, clave foranea referenciando `users(id)` con `ON DELETE CASCADE`.
   - `platform`: `varchar(50)`.
   - `platform_user_id`: `varchar(255)`.
   - `platform_username`: `varchar(255)`, nulable.
   - `access_token`: `text`, almacenado bajo cifrado de aplicacion.
   - `refresh_token`: `text`, nulable, almacenado bajo cifrado de aplicacion.
   - `expires_at`: `timestamp(0) with time zone`, nulable.
   - `created_at`, `updated_at`: `timestamp(0) without time zone`.
   - Restriccion unica: `uq_user_platform_acc` en `(user_id, platform, platform_user_id)`.
   - Indices: `idx_social_accounts_user` en `(user_id)`, `idx_social_accounts_platform` en `(platform)`.

5. `publications`:
   - `id`: `uuid`, clave primaria.
   - `clip_id`: `uuid`, clave foranea referenciando `clips(id)` con `ON DELETE CASCADE`.
   - `social_account_id`: `uuid`, clave foranea referenciando `social_accounts(id)` con `ON DELETE CASCADE`.
   - `status`: `varchar(50)`, valor por defecto `'pending'`.
   - `platform_post_id`: `varchar(255)`, nulable.
   - `scheduled_at`: `timestamp(0) with time zone`, nulable.
   - `published_at`: `timestamp(0) with time zone`, nulable.
   - `error_message`: `text`, nulable.
   - `created_at`, `updated_at`: `timestamp(0) without time zone`.
   - Indices: `idx_publications_clip` en `(clip_id)`, `idx_publications_account` en `(social_account_id)`, `idx_publications_sched` en `(status, scheduled_at)`.

6. `publishing_logs`:
   - `id`: `uuid`, clave primaria.
   - `publication_id`: `uuid`, clave foranea referenciando `publications(id)` con `ON DELETE CASCADE`.
   - `clip_id`: `uuid`, clave foranea referenciando `clips(id)` con `ON DELETE CASCADE`.
   - `social_account_id`: `uuid`, clave foranea referenciando `social_accounts(id)` con `ON DELETE CASCADE`.
   - `platform`: `varchar(50)`.
   - `event`: `varchar(50)`.
   - `status`: `varchar(50)`.
   - `payload`: `jsonb`, valor por defecto `'{}'`.
   - `error_message`: `text`, nulable.
   - `created_at`, `updated_at`: `timestamp(0) without time zone`.
   - Indices: `idx_publishing_logs_pub` en `(publication_id)`, `idx_publishing_logs_clip` en `(clip_id)`, `idx_publishing_logs_acc` en `(social_account_id)`, `idx_publishing_logs_platform_event` en `(platform, event)`, `idx_publishing_logs_status_created` en `(status, created_at)`.

### Tablas de Infraestructura y Soporte de Framework

7. `jobs`: `id` (`bigserial`), `queue` (`varchar(255)` indexada), `payload` (`text`), `attempts` (`smallint`), `reserved_at` (`integer`), `available_at` (`integer`), `created_at` (`integer`).
8. `job_batches`: `id` (`varchar(255)` clave primaria), `name`, `total_jobs`, `pending_jobs`, `failed_jobs`, `failed_job_ids`, `options`, `cancelled_at`, `created_at`, `finished_at`.
9. `failed_jobs`: `id` (`bigserial`), `uuid` (`varchar(255)` unico), `connection`, `queue`, `payload`, `exception`, `failed_at`.
10. `cache` y `cache_locks`: Tablas de gestion de bloqueo distribuido y almacenamiento temporal.
11. `password_reset_tokens` y `sessions`: Autenticacion web y tokens de recuperacion.

## 3. Dependencias
- **Extensiones de PHP**:
  - `pdo_pgsql`: Requerida para la conexion PDO con PostgreSQL.
  - `pgsql`: Modulo de bajo nivel para PostgreSQL en PHP.
- **Servicios Externos de Persistencia**:
  - PostgreSQL 16+ / Neon Serverless PostgreSQL.
  - Conector SSL con soporte para SNI (`sslmode=require`).
- **Capa de Dominio Laravel**:
  - `App\Models\User`: Modelo de autenticacion y propietario de recursos.
  - `App\Modules\Videos\Models\Video`: Entidad raiz de ingesta multimedia.
  - `App\Modules\Clips\Models\Clip`: Entidad de clips recortados derivados de video.
  - `App\Modules\SocialAccounts\Models\SocialAccount`: Credenciales y tokens OAuth vinculados a usuario.
  - `App\Modules\Publishing\Models\Publication`: Registro de programacion y despacho hacia redes sociales.
  - `App\Modules\Publishing\Models\PublishingLog`: Registro inmutable de eventos y respuestas de APIs sociales.
- **Configuracion**:
  - `backend/config/database.php`: Definicion de drivers y parametros por defecto (`pgsql`).
  - `backend/.env`: Variables de entorno `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE`, `DB_URL`.

## 4. Mapa de archivos
- `database/migrations/0001_01_01_000000_create_users_table.php`: Define esquemas para `users`, `password_reset_tokens` y `sessions` con soporte UUID en `users.id`.
- `database/migrations/0001_01_01_000001_create_cache_table.php`: Define esquemas para almacenamiento de cache y mecanismos de bloqueo de concurrencia en base de datos.
- `database/migrations/0001_01_01_000002_create_jobs_table.php`: Define esquemas para colas de tareas asincronas, lotes y registro de excepciones en trabajos fallidos.
- `database/migrations/2026_03_01_000001_create_videos_table.php`: Define tabla `videos` con clave foranea a `users`, columnas de estado y documento `jsonb` de metadatos.
- `database/migrations/2026_03_01_000002_create_clips_table.php`: Define tabla `clips` vinculada a `videos`, control de limites temporales y metadatos tecnicos.
- `database/migrations/2026_03_01_000003_create_social_accounts_table.php`: Define tabla `social_accounts` con restriccion unica de canal por usuario y marcas de tiempo con zona horaria.
- `database/migrations/2026_03_01_000004_create_publications_table.php`: Define tabla `publications` asociando clip y cuenta social con seguimiento de publicacion.
- `database/migrations/2026_03_01_000005_create_publishing_logs_table.php`: Define tabla `publishing_logs` para persistencia estructurada de eventos y auditoria de integraciones externas.
- `database/factories/UserFactory.php`: Fabrica de modelos de usuario para generacion de fixtures y pruebas unitarias.
- `database/seeders/DatabaseSeeder.php`: Seeder principal para inicializar la base de datos con un operador por defecto en entornos de desarrollo.

## 5. Lógica y flujo
1. **Orden Topologico de Migracion**:
   Las migraciones se ejecutan secuencialmente respetando las dependencias foraneas:
   - `users` debe existir antes que `videos` y `social_accounts`.
   - `videos` debe existir antes que `clips`.
   - `clips` y `social_accounts` deben existir antes que `publications`.
   - `publications`, `clips` y `social_accounts` deben existir antes que `publishing_logs`.

2. **Resolucion de Integridad Referencial**:
   Cuando las solicitudes de la API o procesos de ingesta no suministran un identificador explicito de usuario (`userId`), las capas de servicio (`VideoIngestService`, `SocialAccountService`, `PublishingOrchestrator`) resuelven de forma segura la clave foranea invocando:
   `User::first() ?? User::firstOrCreate(['email' => 'default@reelforge.internal'], [...])`
   Esto garantiza la persistencia previa de un registro valido en la tabla `users` antes de ejecutar inserciones en tablas dependientes.

3. **Mecanismo de Despacho de Eventos en Logs**:
   Durante el ciclo de vida de publicacion (solicitud inicial, inicio de carga, recepcion de identificador en la red social, fallo o reintento), el orquestador y los trabajos de cola emiten registros en `publishing_logs` vinculando la publicacion, clip y cuenta social con un snapshot JSONB de la carga enviada o respuesta del proveedor.

## 6. Decisiones de diseño y gotchas
- **Migracion desde SQLite a Neon PostgreSQL**:
  El entorno de desarrollo carece del modulo `pdo_sqlite`, pero cuenta nativamente con `pdo_pgsql`. La migracion a PostgreSQL/Neon regulariza el entorno de persistencia a los estandares de produccion.
- **Requisito de `sslmode=require`**:
  Neon requiere TLS obligatorio para resolver el SNI (Server Name Indication) que enruta la conexion al contenedor o tenant correspondiente. Sin `sslmode=require`, la conexion es rechazada por el servidor proxy de Neon.
- **Endpoint Directo vs Pooler Transaccional para DDL**:
  Neon provee dos tipos de endpoints: directo (puerto 5432) y transaccional con PgBouncer (puerto 6543). Las migraciones DDL de Laravel deben ejecutarse obligatoriamente sobre el endpoint directo (puerto 5432), dado que PgBouncer en modo transaccion bloquea el uso de bloqueos consultivos (`advisory locks`) y transacciones DDL multi-sentencia.
- **Uso de UUIDs**:
  Todos los modelos de dominio usan UUID v4 generados en aplicacion mediante el trait `HasUuids` de Laravel, evitando colisiones en escenarios de replicacion o generacion distribuida.
- **Gotcha de Integridad Referencial Estricta**:
  Bajo SQLite sin `foreign_keys=ON`, era posible insertar registros con UUIDs aleatorios no existentes en `users`. En PostgreSQL, el motor valida inmediatamente la existencia del registro en la tabla padre, lanzando `SQLSTATE[23503] (foreign_key_violation)`. Por esta razon, la generacion ciega con `Str::uuid()` fue reemplazada por la resolucion persistida de usuario por defecto.

## 7. Estado y últimos cambios
- Se actualizo `backend/config/database.php` estableciendo la conexion por defecto en `pgsql`, con parametros de seguridad `sslmode => env('DB_SSLMODE', 'require')` y `database => env('DB_DATABASE', 'reelforge')`.
- Se configuro `backend/.env` con `DB_CONNECTION=pgsql`, `DB_PORT=5432` y `DB_SSLMODE=require`.
- Se implemento la octava migracion `2026_03_01_000005_create_publishing_logs_table.php` incorporando claves foraneas a `publications`, `clips` y `social_accounts`, payload JSONB e indices compuestos.
- Se creo el modelo Eloquent `App\Modules\Publishing\Models\PublishingLog` con relaciones `BelongsTo`, casteo de `payload` a `array` y atributos de asignacion masiva.
- Se actualizaron `VideoIngestService`, `SocialAccountService` y `PublishingOrchestrator` para garantizar la existencia persistida del usuario por defecto mediante `User::firstOrCreate(...)`.
- Se verifico la compilacion de las 8 migraciones contra `PostgresGrammar` sin errores de sintaxis.

## 8. Cómo verificar
Comandos para validar de forma integral el esquema y la persistencia:

1. **Validacion de drivers PHP de PostgreSQL**:
   ```bash
   php -r "if (!extension_loaded('pdo_pgsql')) { throw new Exception('Falta pdo_pgsql'); } echo 'Driver OK'.PHP_EOL;"
   ```

2. **Compilacion sintactica de todas las migraciones contra PostgreSQL**:
   ```bash
   php -r '
   require "backend/vendor/autoload.php";
   $app = require "backend/bootstrap/app.php";
   $app->make("Illuminate\Contracts\Console\Kernel")->bootstrap();
   $conn = new \Illuminate\Database\PostgresConnection(new class extends PDO { public function __construct() {} }, "reelforge", "", ["server_version" => "16.0"]);
   $conn->useDefaultSchemaGrammar();
   \Illuminate\Support\Facades\Schema::swap($conn->getSchemaBuilder());
   foreach (glob("backend/database/migrations/*.php") as $file) {
       $conn->pretend(fn() => (require $file)->up());
   }
   echo "8 migraciones compiladas exitosamente para PostgreSQL" . PHP_EOL;
   '
   ```

3. **Verificacion de pruebas unitarias y de integracion del backend**:
   ```bash
   cd backend && php artisan test
   ```

4. **Verificacion de estilo y sintaxis de codigo**:
   ```bash
   cd backend && ./vendor/bin/pint --test
   ```

5. **Ejecucion directa contra base de datos (cuando las credenciales esten provistas)**:
   ```bash
   cd backend && php artisan migrate --force
   cd backend && php artisan migrate:status
   ```
