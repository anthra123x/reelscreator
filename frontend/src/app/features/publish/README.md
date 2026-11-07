# Publish Feature Module — Frontend ReelForge

## 1. Propósito y alcance
El módulo de características Publish implementa la consola de despacho multicanal y auditoría de publicaciones de ReelForge. Su propósito abarca la selección de clips para su difusión simultánea en múltiples redes sociales (TikTok, Instagram Reels, YouTube Shorts), la personalización de leyendas y etiquetas (hashtags), la programación temporal diferida o despacho inmediato y la supervisión del registro de auditoría con opciones de reintento ante fallos.

## 2. Contratos y API
El módulo expone el componente standalone `PublishComponent`:
- Ruta asociada: `/publish` (acepta parámetro opcional de consulta `?clipId={uuid}`).
- Contratos consumidos:
  - `POST /api/clips/{id}/publish`: Encola publicaciones multicanal (`platforms`, `caption`, `scheduled_at`). Devuelve `202 Accepted` con `{"publication_ids": string[], "status": "queued"}`.
  - `GET /api/publications`: Consulta el registro histórico paginado de auditoría (`PaginatedResponse<Publication>`).
  - `POST /api/publications/{id}/retry`: Reintenta la publicación de un trabajo fallido.
  - `GET /api/videos`: Recupera clips disponibles para el selector de origen.
  - `GET /api/social-accounts`: Recupera canales vinculados para validar disponibilidad de publicación.
- Señales reactivas internas:
  - `availableClips`: `WritableSignal<Clip[]>` lista de clips listos para ser publicados.
  - `connectedAccounts`: `WritableSignal<SocialAccount[]>` canales sociales configurados.
  - `publications`: `WritableSignal<Publication[]>` entradas del registro de auditoría.
  - `isLoadingPublications`: `WritableSignal<boolean>` indicador de carga del historial.
  - `isPublishing`: `WritableSignal<boolean>` estado de envío de la solicitud de publicación.
  - `publishSuccessMessage`: `WritableSignal<string | null>` mensaje de confirmación con identificadores generados.
  - `publishErrorMessage`: `WritableSignal<string | null>` mensaje descriptivo de error.

## 3. Dependencias
- `@angular/core`: Primitivas de componentes, inyección y señales.
- `@angular/common`: Directivas estructurales (`ngFor`, `ngIf`, `ngClass`).
- `@angular/forms`: `FormsModule` para enlace bidireccional de formulario (`ngModel`).
- `@angular/router`: `ActivatedRoute` para lectura de parámetros de consulta (`clipId`).
- `frontend/src/app/core/services`: `PublishService`, `ClipService`, `VideoService`, `SocialAccountService`.
- `frontend/src/app/shared`: `StatusPillComponent`, `SkeletonLoaderComponent`, `DurationPipe`, `RelativeTimePipe`.

## 4. Mapa de archivos
- `publish.component.ts`: Componente standalone integral que implementa el formulario de despacho multicanal, selector de plataformas, generador de leyendas, selector de fecha/hora de programación y tabla de auditoría con reintentos.

## 5. Lógica y flujo
1. Al inicializarse, el componente consulta clips producidos, cuentas conectadas e historial de publicaciones.
2. Si la ruta incluye `?clipId=...`, dicho clip es seleccionado automáticamente y se despliega su tarjeta de vista previa (miniatura 9:16 y duración).
3. El usuario marca las plataformas de destino (al menos una es obligatoria).
4. El usuario redacta la leyenda del clip o agrega etiquetas rápidas haciendo clic en los chips (`#reels`, `#shorts`, etc.).
5. El modo de despacho permite envío inmediato (`scheduled_at: null`) o programado (`scheduled_at: ISO-8601`).
6. Al pulsar "Dispatch Publication", se envía la petición `POST /api/clips/{id}/publish`.
7. Tras la respuesta exitosa, se refresca automáticamente la tabla de auditoría para reflejar los trabajos recién encolados.
8. En la tabla de auditoría, las entradas en estado `failed` presentan un botón "Retry" para reencolar el despacho de inmediato.

## 6. Decisiones de diseño y gotchas
- Fallback automático a cuentas mock: Si un usuario selecciona publicar en una plataforma que aún no tiene una cuenta vinculada, el backend sintetiza automáticamente un adaptador mock, y la interfaz lo señala claramente como `(Auto-Mock Fallback)` para no bloquear la operación.
- Validación de fecha mínima: En el modo programado, el selector `<input type="datetime-local">` garantiza que la fecha seleccionada sea posterior a la hora actual.
- Límite de caracteres: El contador dinámico (`caption.length / 2200`) previene exceder las restricciones de longitud de texto de las plataformas sociales.
- Prevención de doble despacho: `onPublishSubmit()` incorpora una guarda defensiva `if (this.isPublishing()) return;` que bloquea llamadas duplicadas concurrentes mientras una publicación se encuentra en vuelo.

## 7. Estado y últimos cambios
- Adición de guarda de retorno temprano `isPublishing` para neutralizar dobles clics y peticiones de publicación redundantes.
- Implementación del formulario multicanal con selección múltiple de plataformas y selector de fecha.
- Integración de la tabla de auditoría histórica con píldoras de estado semántico y reintento con un clic.
- Sincronización reactiva con parámetros de ruta procedentes de la biblioteca de clips.
- Blindaje contra publicación inmediata no intencional: validación de `scheduledAt` obligatorio y estrictamente en el futuro para `scheduleMode === 'later'`, deshabilitación reactiva del botón de envío si falta la fecha, restricción HTML `[min]` con fecha/hora actual y aborto defensivo en `onPublishSubmit` con mensaje descriptivo al usuario.

## 8. Cómo verificar
```bash
cd frontend
bun run build # Valida la ausencia de errores en la compilación de PublishComponent
```
