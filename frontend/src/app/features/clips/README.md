# Clips Feature Module — Frontend ReelForge

## 1. Propósito y alcance
El módulo de características Clips administra la galería y edición de clips verticales generados (9:16) en ReelForge. Su alcance incluye la visualización en cuadrícula responsiva de miniaturas, filtrado por video de origen, edición en línea de títulos y marcas de corte temporal (start_time / end_time), previsualización interactiva a través del reproductor en marco móvil, eliminación de clips y acceso directo al flujo de publicación multicanal.

## 2. Contratos y API
El módulo expone el componente standalone `ClipsComponent`:
- Ruta asociada: `/clips` (acepta parámetro opcional de consulta `?videoId={uuid}`).
- Contratos consumidos:
  - `GET /api/videos/{videoId}/clips`: Obtención de clips asociados a un video específico.
  - `GET /api/videos`: Listado de videos para poblar el selector de filtrado.
  - `PATCH /api/clips/{id}`: Actualización de metadatos (`title`, `start_time`, `end_time`).
  - `DELETE /api/clips/{id}`: Eliminación física del clip y sus miniaturas asociadas.
- Señales reactivas internas:
  - `isLoading`: `WritableSignal<boolean>` indicador de carga de clips.
  - `isUpdating`: `WritableSignal<boolean>` estado de guardado de edición en línea.
  - `clips`: `WritableSignal<Clip[]>` lista de clips actualmente presentados.
  - `availableVideos`: `WritableSignal<Video[]>` lista de videos disponibles para filtrado.
  - `selectedVideoId`: `WritableSignal<string>` identificador del video seleccionado en el filtro.
  - `previewClip`: `WritableSignal<Clip | null>` clip activo en el modal de reproducción vertical.

## 3. Dependencias
- `@angular/core`: Primitivas de componentes, inyección y señales.
- `@angular/common`: Directivas de plantilla y renderizado.
- `@angular/forms`: `FormsModule` para enlace bidireccional (`ngModel`) en la edición en línea.
- `@angular/router`: `ActivatedRoute` y `Router` para sincronización de queryParams.
- `frontend/src/app/core/services`: `ClipService`, `VideoService`.
- `frontend/src/app/shared`: `StatusPillComponent`, `SkeletonLoaderComponent`, `VerticalPlayerComponent`, `DurationPipe`.

## 4. Mapa de archivos
- `clips.component.ts`: Componente standalone integral que implementa la cuadrícula de tarjetas 9:16, edición de recortes, modal de reproducción y eliminación de clips.

## 5. Lógica y flujo
1. Al cargarse, el componente inspecciona `route.queryParams` en búsqueda de un parámetro `videoId`.
2. Se cargan los videos disponibles para alimentar el menú desplegable de filtrado.
3. Si existe un filtro activo por `videoId`, se consultan sus clips con `ClipService.getClipsByVideo(videoId)`; de lo contrario, se consolida la lista global de clips.
4. Cada tarjeta representa un clip en formato vertical 9:16 con su miniatura, píldora de duración y rango de tiempo (`start_time` - `end_time`).
5. El botón de edición activa un formulario en línea para modificar el título y recortar los segundos de inicio y fin, despachando `PATCH /api/clips/{id}`.
6. El botón "Preview" abre el modal `VerticalPlayerComponent` con el archivo de video real.
7. El botón "Publish" redirige al módulo `/publish` con el identificador del clip en `queryParams`.

## 6. Decisiones de diseño y gotchas
- Proporción estricta de tarjeta: Se aplica `aspect-ratio: 9 / 16` a los contenedores multimedia para replicar fielmente el formato de TikTok, Shorts y Reels.
- Sincronización de ruta bidireccional: Al cambiar el filtro de video en el desplegable, se actualiza la URL del navegador vía `Router.navigate` para permitir marcadores y compartición de enlaces directos.
- Fallback de miniatura: Si la miniatura no se encuentra disponible o falla al cargar, un manejador de evento oculta la imagen rota y activa un contenedor sustituto estilizado.

## 7. Estado y últimos cambios
- Implementación completa de la cuadrícula de tarjetas de clips 9:16.
- Integración de edición en línea de títulos y recortes de duración.
- Vinculación con el modal `VerticalPlayerComponent` y flujo de navegación hacia `/publish`.
- Validación en cliente de límites de recorte (`Number(editEndTime) > Number(editStartTime)`) y manejo explícito de errores en `saveEdit()` desplegando avisos visuales inline mediante la señal `editErrorMessage`.

## 8. Cómo verificar
```bash
cd frontend
bun run build # Valida la ausencia de errores en la compilación de ClipsComponent
```
