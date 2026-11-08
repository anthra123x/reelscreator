# Video Upload Feature Module — Frontend ReelForge

## 1. Propósito y alcance
El módulo de características Video Upload implementa la interfaz de usuario para la ingestión, descomposición y publicación autónoma de contenidos audiovisuales en formato vertical 9:16. Su propósito es permitir a los usuarios especificar URLs de videos remotos, seleccionar estrategias de corte temporal, definir el modo de conversión vertical, parametrizar la duración de los clips resultantes, activar la auto-publicación desatendida hacia redes sociales vinculadas (TikTok, Instagram Reels, YouTube Shorts) y monitorear el progreso del pipeline y despacho en tiempo real.

## 2. Contratos y API
El módulo expone el componente standalone `VideoUploadComponent`:
- Ruta asociada: `/video-upload`.
- Parámetros de consulta soportados: `?auto_publish=true` y `?mode=autonomous` para preseleccionar la auto-publicación.
- Contratos consumidos:
  - `POST /api/videos`: Envío del formulario de ingestión con payload `VideoIngestRequest` (`source_url`, `title`, `strategy`, `vertical_mode`, `interval_seconds`, `max_duration_seconds`, `auto_publish`, `platforms`, `caption`).
  - `GET /api/videos/{id}/status`: Sondeo del ciclo de vida del proceso de transcodificación mediante `VideoService.pollVideoStatus(id, intervalMs)`.
  - `GET /api/publications`: Consulta del registro de auditoría de publicaciones para desplegar el resumen de despacho al finalizar la transcodificación.
- Señales reactivas internas:
  - `isSubmitting`: `WritableSignal<boolean>` estado de envío HTTP.
  - `isProcessing`: `WritableSignal<boolean>` estado de sondeo en curso.
  - `activeJobId`: `WritableSignal<string | null>` identificador UUID del trabajo en ejecución.
  - `jobStatus`: `WritableSignal<VideoStatus>` estado actual de la tarea.
  - `progressPercent`: `WritableSignal<number>` porcentaje de avance del proceso (10%, 30%, 65%, 100%).
  - `jobErrorMessage`: `WritableSignal<string | null>` mensaje descriptivo de error ante fallos de transcodificación.
  - `autoPublishRequested`: `WritableSignal<boolean>` indicador de que el usuario solicitó publicación desatendida.
  - `publications`: `WritableSignal<Publication[]>` registros de despacho devueltos por el backend.
  - `isLoadingPublications`: `WritableSignal<boolean>` estado de sincronización de publicaciones automáticas.

## 3. Dependencias
- `@angular/core`: Primitivas de ciclo de vida (`OnInit`, `OnDestroy`), inyección y señales.
- `@angular/forms`: `FormBuilder`, `FormGroup`, `ReactiveFormsModule`, validadores reactivos.
- `@angular/router`: `ActivatedRoute` y `RouterModule` para parámetros de consulta y navegación hacia `/clips` y `/publish`.
- `rxjs`: `Subscription` para la gestión y desuscripción del flujo de sondeo.
- `frontend/src/app/core/services`: `VideoService`, `PublishService`.
- `frontend/src/app/shared`: `StatusPillComponent`.

## 4. Mapa de archivos
- `video-upload.component.ts`: Componente standalone integral que encapsula el formulario reactivo de ingestión, selector de auto-publicación multicanal, controles deslizantes de duración y visor de despacho de publicaciones.
- `video-upload.component.spec.ts`: Suite de pruebas unitarias que valida la lectura de queryParams, la construcción de payloads con `auto_publish` y la carga de registros de publicación al completarse el procesamiento.

## 5. Lógica y flujo
1. El usuario ingresa la URL de origen y ajusta los parámetros de corte.
2. Si se activa la casilla "Auto-publicar reels generados en cuentas vinculadas", se despliega el selector multicanal (TikTok, Instagram, YouTube) y el campo opcional de pie de publicación (caption).
3. Si el usuario ingresó a través del acceso directo del Dashboard (`?auto_publish=true`), la opción se activa automáticamente en `ngOnInit`.
4. Al enviar el formulario (`onSubmit`), se ejecuta `POST /api/videos` incluyendo las plataformas seleccionadas.
5. Se inicia el sondeo reactivo cada 2000 ms mediante `VideoService.pollVideoStatus`.
6. Al alcanzar el estado `ready`: si `auto_publish` estaba habilitado, se invoca `loadPublicationStatus(id)` para cargar los registros de despacho y presentarlos en un panel resumen con sus identificadores y estados por plataforma.

## 6. Decisiones de diseño y gotchas
- Desuscripción obligatoria: `OnDestroy` detiene cualquier suscripción de sondeo activa para evitar fugas de memoria o peticiones residuales en segundo plano al cambiar de ruta.
- Formulario desacoplado de la API: Las plataformas seleccionadas se gestionan como controles independientes (`publish_tiktok`, `publish_instagram`, `publish_youtube`) y se transforman dinámicamente en el array `platforms` esperado por el backend.
- Prevención de doble envío: `onSubmit()` cuenta con una guarda de retorno temprano `if (this.isSubmitting()) return;` que bloquea peticiones concurrentes o clics dobles rápidos mientras el formulario se encuentra en vuelo.

## 7. Estado y últimos cambios
- Milestone M7: Implementación de la casilla de auto-publicación a redes vinculadas.
- Milestone M7: Incorporación de selector multicanal (TikTok, Instagram, YouTube) con toggles visuales y campo para caption.
- Milestone M7: Integración de lectura de parámetros de consulta (`auto_publish`, `mode=autonomous`) para activación contextual.
- Milestone M7: Despliegue automático de estado de despacho y registros de publicación al completarse la transcodificación (`ready`).
- Milestone M7: Creación de la suite de pruebas unitarias `video-upload.component.spec.ts`.

## 8. Cómo verificar
```bash
cd frontend
bun run build # Valida la ausencia de errores en la compilación de VideoUploadComponent
bun run test -- --watch=false # Ejecuta las pruebas unitarias del módulo Video Upload
```
