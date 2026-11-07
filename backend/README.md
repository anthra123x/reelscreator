# Backend Modular de ReelForge (Laravel)

## 1. Propósito y alcance
El backend de ReelForge constituye la capa de coordinacion de dominio, persistencia relacional, orquestacion asincrona de colas y gestion de integraciones externas para la plataforma. Construido sobre Laravel 12 y estructurado modularmente bajo `app/Modules/`, provee la exposicion de servicios REST consumidos por la SPA en Angular, persiste la integridad referencial en PostgreSQL mediante identificadores UUID y metadatos JSONB, y se comunica con el motor de alto rendimiento en Rust (`reelforge-server`) a traves de `RustProcessorClient`.

Su alcance funcional incluye:
- Ingesta y validacion de contenido audiovisual para transformacion a formato vertical 9:16 (`Videos`).
- Almacenamiento, ajuste temporal y resolucion de medios recortados (`Clips`).
- Autenticacion OAuth 2.0 y resguardo cifrado de credenciales para TikTok, Instagram y YouTube (`SocialAccounts`).
- Orquestacion de publicacion multi-plataforma mediante adaptadores polimorficos y soporte mock de cero credenciales (`Publishing`).

## 2. Contratos y API
El backend expone las siguientes interfaces de aplicacion agrupadas por prefijo:

### Videos (`/api/videos`)
- `POST /api/videos`: Inicia ingesta (`201 Created` / `422 Unprocessable`).
- `GET /api/videos`: Listado paginado de videos con clips (`200 OK`).
- `GET /api/videos/{id}`: Detalle de video (`200 OK` / `404 Not Found`).
- `GET /api/videos/{id}/status`: Sondeo rapido de estado de procesamiento (`200 OK`).
- `DELETE /api/videos/{id}`: Eliminacion de video y archivos fisicos (`204 No Content`).
- `POST /api/internal/jobs/{id}/callback`: Webhook de finalizacion desde motor Rust (`200 OK`).

### Clips (`/api/clips` y `/api/videos/{videoId}/clips`)
- `GET /api/videos/{videoId}/clips`: Listado de clips generados (`200 OK`).
- `GET /api/clips/{id}`: Metadatos y URLs computadas de reproduccion (`200 OK`).
- `PATCH /api/clips/{id}`: Actualizacion de limites o titulos (`200 OK`).
- `DELETE /api/clips/{id}`: Borrado de clip y medios asociados (`204 No Content`).

### Social Accounts (`/api/social-accounts`)
- `GET /api/social-accounts`: Cuentas conectadas con indicador `is_mock` (`200 OK`).
- `POST /api/social-accounts/connect/{platform}`: Generacion de URL OAuth con PKCE (`200 OK`).
- `GET /api/social-accounts/callback/{platform}`: Intercambio de codigo y persistencia (`200 OK`).
- `POST /api/social-accounts/mock-connect`: Vinculacion inmediata de cuenta de prueba (`201 Created`).
- `DELETE /api/social-accounts/{id}`: Desconexion de cuenta social (`204 No Content`).

### Publishing (`/api/publications` y `/api/clips/{id}/publish`)
- `POST /api/clips/{id}/publish`: Despacho inmediato o programado (`202 Accepted`).
- `GET /api/publications`: Auditoria historica de publicaciones (`200 OK`).
- `POST /api/publications/{id}/retry`: Reintento de publicacion fallida (`202 Accepted`).

## 3. Dependencias
- **Sistemas externos e inter-servicio**:
  - Motor Axum Rust (`http://localhost:3000`): Gestionado mediante `App\Services\RustProcessorClient`.
  - Base de Datos Relacional: PostgreSQL con extension `uuid-ossp` o `gen_random_uuid()`.
  - Broker de Colas: Redis (con soporte de driver `sync` para pruebas locales).
  - Almacenamiento Compartido: Directorio `/storage/media/` (`raw/`, `clips/`, `thumbnails/`).
- **Paquetes principales de Composer**:
  - `laravel/framework`: ^12.0
  - `guzzlehttp/guzzle`: ^7.16
  - `phpunit/phpunit`: ^11.5

## 4. Mapa de archivos
- `app/Models/User.php`: Modelo de usuario del sistema con clave primaria UUID y relaciones.
- `app/Modules/Videos/`: Modulo de ingesta, estados y comunicacion con el motor de corte.
- `app/Modules/Clips/`: Modulo de gestion y reproduccion de clips 9:16 recortados.
- `app/Modules/SocialAccounts/`: Modulo de autenticacion OAuth y resguardo cifrado de credenciales.
- `app/Modules/Publishing/`: Modulo de publicacion multi-plataforma con patron adaptador y mock.
- `app/Services/RustProcessorClient.php`: Cliente HTTP tipado para comunicacion con el motor Axum.
- `config/social.php`: Configuraciones de credenciales OAuth y bandera de simulacion `mock_mode`.
- `config/services.php`: Parametros de conexion para servicios terceros y motor Rust.
- `database/migrations/`: Migraciones para `users`, `videos`, `clips`, `social_accounts` y `publications`.
- `routes/api.php`: Manifiesto agregador de rutas modulares.
- `tests/`: Bateria de pruebas unitarias y de integracion.

## 5. Lógica y flujo
1. Recepcion de URL o archivo local via `POST /api/videos`.
2. Persistencia en `videos` con estado inicial `pending` y despacho a cola mediante `DownloadVideoJob`.
3. Invocacion asincrona de `POST /api/v1/jobs` en el motor Axum con indicacion de webhook.
4. Finalizacion en Rust y emision de webhook a `POST /api/internal/jobs/{id}/callback`.
5. Persistencia automatica de entidades `Clip` con sus marcas de tiempo y transicion del video a `ready`.
6. Publicacion multi-canal via `POST /api/clips/{id}/publish` que instancia los adaptadores correspondientes segun la plataforma o delega en `MockSocialAdapter` en caso de ausencia de credenciales.

## 6. Decisiones de diseño y gotchas
- **Arquitectura de Dominio Modular**: Cada subdirectorio en `app/Modules/` es autosuficiente con sus propios modelos, controladores, servicios, DTOs y definiciones de ruta, reduciendo el acoplamiento y facilitando mantenimiento paralelo.
- **Tokens de Acceso Cifrados en Reposo**: Se utiliza el casteo `encrypted` nativo de Eloquent en `SocialAccount` respaldado por `APP_KEY`, impidiendo filtraciones de secretos en volcados de datos.
- **Mock Social Publisher de Primer Nivel**: `MockSocialAdapter` cumple la interfaz `SocialPublisherAdapterInterface` y permite probar flujos de publicacion de extremo a extremo sin depender de aprobaciones de desarrollador de TikTok, Meta o Google.

## 7. Estado y últimos cambios
- Inicializacion del proyecto Laravel 12 y configuracion de rutas modulares.
- Creacion de migraciones PostgreSQL con claves foraneas en cascada, UUIDs e indices compuestos.
- Desarrollo completo de los 4 modulos (`Videos`, `Clips`, `SocialAccounts`, `Publishing`).
- Implementacion de `RustProcessorClient` con manejo de excepciones y timeouts.
- Habilitacion de despliegue serverless para produccion en Vercel con el runtime `vercel-php@0.9.0`, conexion directa al endpoint de Neon PostgreSQL sin parametros de startup conflictivos, resolucion de prefijo de rutas en `api/index.php` y persistencia relacional activa.

## 8. Cómo verificar
Ejecutar la suite completa de pruebas:
```bash
php artisan test
```
Verificar formateo de codigo:
```bash
./vendor/bin/pint --test
```
Comprobar registro de rutas:
```bash
php artisan route:list
```
