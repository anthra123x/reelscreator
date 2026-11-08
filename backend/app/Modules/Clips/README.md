# Modulo Clips

## 1. Propósito y alcance
El modulo `Clips` administra los fragmentos de video cortos generados en proporcion vertical 9:16 (reels, shorts) a partir de los videos fuente procesados por el motor Rust o por el servicio de descomposicion autonoma de Laravel. Su responsabilidad comprende el almacenamiento de metadatos de reproduccion (marcas de tiempo de inicio, fin y duracion acotada a un maximo de 60 segundos), resolucion 1080x1920, rutas de archivo y miniaturas en almacenamiento persistente, resolucion de URLs publicas de streaming para la interfaz web, edicion de limites y titulos, y eliminacion sincronizada en base de datos y disco.

## 2. Contratos y API
### Endpoints HTTP
- `GET /api/clips`: Obtiene la lista paginada de todos los clips existentes en el sistema con relacion al video asociado.
  - Parametro de consulta: `per_page` (entero entre 1 y 100, predeterminado 50).
  - Respuesta: `200 OK` (Paginador JSON con `data`, `current_page`, `total`, etc.).
- `GET /api/videos/{videoId}/clips`: Obtiene la lista completa de clips generados para un video especifico.
  - Parametro de ruta: `videoId` (UUID).
  - Respuesta: `200 OK` (`{"data": [{"id": uuid, "video_id": uuid, "file_url": string, "thumbnail_url": string, "duration": float, "start_time": float, "end_time": float, "status": "ready", "metadata": {"width": 1080, "height": 1920, "aspect_ratio": "9:16"}}]}`).
- `GET /api/clips/{id}`: Detalle de un clip individual con URLs computadas de reproduccion y miniatura.
  - Parametro de ruta: `id` (UUID).
  - Respuestas: `200 OK`, `404 Not Found`.
- `PATCH /api/clips/{id}`: Actualizacion de titulo, metadatos y limites temporales.
  - Payload: `{"title"?: string, "start_time"?: float, "end_time"?: float, "metadata"?: array}`
  - Respuestas: `200 OK` (Modelo actualizado), `404 Not Found`, `422 Unprocessable Content`.
- `DELETE /api/clips/{id}`: Eliminacion definitiva del clip en base de datos y archivos fisicos asociados.
  - Respuestas: `204 No Content`, `404 Not Found`.

### DTOs
- `UpdateClipDTO`: Objeto inmutable que sanitiza campos modificables (`title`, `startTime`, `endTime`, `metadata`).

## 3. Dependencias
- **Modulos internos**:
  - `App\Modules\Videos`: Relacion `belongsTo` hacia el video original.
  - `App\Modules\Publishing`: Relacion `hasMany` hacia las publicaciones programadas o ejecutadas.
- **Paquetes externos**:
  - `illuminate/database`, `illuminate/support`, `illuminate/filesystem`.

## 4. Mapa de archivos
- `Controllers/ClipController.php`: Controlador REST para consulta, modificacion y eliminacion de clips.
- `DTOs/UpdateClipDTO.php`: Objeto de transferencia para sanitizacion de atributos editables.
- `Models/Clip.php`: Modelo Eloquent con clave UUID, metadatos JSONB (resolucion 1080x1920, `aspect_ratio: '9:16'`) y accessors de URLs (`file_url`, `thumbnail_url`, `embed_url`).
- `Routes/api.php`: Rutas REST asociadas a clips y agrupacion por video.
- `Services/ClipStorageService.php`: Logica de negocio para recuperacion, actualizacion y depuracion de archivos de medios.

## 5. Lógica y flujo
1. El pipeline de corte (Rust Axum o `VideoIngestService::decomposeVideo`) genera entidades `Clip` vinculadas al `video_id` con geometria vertical estricta (1080x1920, 9:16) y duracion unitaria <= 60s.
2. La interfaz de usuario consulta `GET /api/videos/{videoId}/clips` o el dashboard consume `GET /api/clips` para computar metricas agregadas de reels producidos.
3. El modelo `Clip` calcula dinamicamente `file_url` y `thumbnail_url` usando el driver de almacenamiento configurado (`local`, `s3` o URLs externas directas).
4. Si el usuario ajusta el inicio/fin del clip en el reproductor interactivo, se despacha `PATCH /api/clips/{id}` recalculando la duracion resultante.
5. Al eliminar un clip con `DELETE /api/clips/{id}`, `ClipStorageService` borra tanto el archivo MP4 como el JPG de miniatura antes de eliminar la fila en PostgreSQL.

## 6. Decisiones de diseño y gotchas
- **Normalizacion de resolucion 1080x1920 y 9:16**: Todos los clips creados, ya sea mediante webhook de Axum o particionado directo en Laravel, incorporan en su columna `metadata` los atributos `width: 1080`, `height: 1920` y `aspect_ratio: "9:16"`.
- **Compatibilidad con topes de duracion de redes**: Ningun clip se genera con duracion superior a 60 segundos para evitar fallos de rechazo en YouTube Shorts.
- **Accessors dinamicos en serializacion JSON**: `file_url` y `thumbnail_url` se incluyen en `$appends` para que cualquier llamada API entregue URLs absolutas o relativas al frontend sin requerir transformaciones manuales en cada controlador.
- **Limpieza de disco no bloqueante**: La eliminacion de medios se ejecuta comprobando `Storage::exists` para prevenir fallos por archivos previamente depurados o reubicados.

## 7. Estado y últimos cambios
- **Estado**: Funcionalidad verificada e integrada con el pipeline autonomo de descomposicion 9:16.
- **Cambios recientes**:
  - Incorporacion del accessor `embed_url` en `Clip.php` con resolucion segura para YouTube y Vimeo con marcas de tiempo `start` y `end`.
  - Resolucion robusta en `getThumbnailUrlAttribute` con fallback automatico a miniaturas de YouTube CDN (`hqdefault.jpg`) para entornos serverless.
  - Soporte para fuentes de streaming multimedia directas con fragmentos `#t=start,end` en `file_url`.
  - Carga impaciente (`with('video')`) en `ClipStorageService::listByVideo` para resolucion eficiente de URLs.
  - Validacion y persistencia de geometria vertical 1080x1920 y proporcion 9:16 en metadatos de clips generados por descomposicion autonoma.
  - Garantia de limite maximo de duracion de 60 segundos por clip para compatibilidad con YouTube Shorts e Instagram Reels.
  - Incorporacion de endpoint `GET /api/clips` y metodo `listAll` en `ClipStorageService`.

## 8. Cómo verificar
Ejecutar la suite de pruebas unitarias y de integracion:
```bash
php artisan test --filter=Clip
```
Comandos de verificacion estatica y formateo:
```bash
./vendor/bin/pint --test
```
