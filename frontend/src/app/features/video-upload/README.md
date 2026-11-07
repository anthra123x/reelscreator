# Video Upload Feature Module — Frontend ReelForge

## 1. Propósito y alcance
El módulo de características Video Upload implementa la interfaz de usuario para la ingestión y configuración del proceso de corte y transformación vertical de contenidos audiovisuales. Su propósito es permitir a los usuarios especificar URLs de videos remotos (vía yt-dlp o descarga directa), seleccionar estrategias de corte temporal, definir el modo de conversión vertical a relación 9:16, parametrizar la duración de los clips resultantes y monitorear el progreso del pipeline en tiempo real.

## 2. Contratos y API
El módulo expone el componente standalone `VideoUploadComponent`:
- Ruta asociada: `/video-upload`.
- Contratos consumidos:
  - `POST /api/videos`: Envío del formulario de ingestión con payload `VideoIngestRequest` (`source_url`, `title`, `strategy`, `vertical_mode`, `interval_seconds`, `max_duration_seconds`).
  - `GET /api/videos/{id}/status`: Sondeo del ciclo de vida del proceso de transcodificación mediante `VideoService.pollVideoStatus(id, intervalMs)`.
- Señales reactivas internas:
  - `isSubmitting`: `WritableSignal<boolean>` estado de envío HTTP.
  - `isProcessing`: `WritableSignal<boolean>` estado de sondeo en curso.
  - `activeJobId`: `WritableSignal<string | null>` identificador UUID del trabajo en ejecución.
  - `jobStatus`: `WritableSignal<VideoStatus>` estado actual de la tarea.
  - `progressPercent`: `WritableSignal<number>` porcentaje de avance del proceso (10%, 30%, 65%, 100%).
  - `jobErrorMessage`: `WritableSignal<string | null>` mensaje descriptivo de error ante fallos de transcodificación.

## 3. Dependencias
- `@angular/core`: Primitivas de ciclo de vida (`OnInit`, `OnDestroy`), inyección y señales.
- `@angular/forms`: `FormBuilder`, `FormGroup`, `ReactiveFormsModule`, validadores reactivos.
- `@angular/router`: Directiva `RouterModule` para enlaces hacia `/clips`.
- `rxjs`: `Subscription` para la gestión y desuscripción del flujo de sondeo.
- `frontend/src/app/core/services`: `VideoService`.
- `frontend/src/app/shared`: `StatusPillComponent`.

## 4. Mapa de archivos
- `video-upload.component.ts`: Componente standalone integral que encapsula el formulario reactivo de ingestión, validaciones de URL, controles deslizantes de duración y panel de progreso en vivo.

## 5. Lógica y flujo
1. El usuario ingresa la URL de origen y ajusta los parámetros de corte (estrategia, modo vertical e intervalos).
2. El formulario valida sintácticamente la URL contra el patrón `^https?://.+`.
3. Al enviar el formulario (`onSubmit`), se ejecuta `POST /api/videos`.
4. Tras recibir la respuesta `201 Created` con el identificador del trabajo, el componente inicia el sondeo reactivo cada 2000 ms mediante `VideoService.pollVideoStatus`.
5. La barra de progreso visual avanza según los umbrales reportados por el backend (10% encolado, 30% descarga yt-dlp, 65% corte FFmpeg, 100% completado).
6. Al alcanzar el estado `ready`, se despliega una tarjeta de éxito que enlaza directamente a `/clips?videoId={id}`.

## 6. Decisiones de diseño y gotchas
- Desuscripción obligatoria: `OnDestroy` detiene cualquier suscripción de sondeo activa para evitar fugas de memoria o peticiones residuales en segundo plano al cambiar de ruta.
- Validación de límites de tiempo: Los deslizadores imponen un rango estricto entre 5 y 600 segundos según las reglas de negocio del backend.
- Feedback de error granular: Los errores de validación HTTP 422 o de conectividad de red son capturados y desplegados en alertas claras sin bloquear la interfaz.
- Prevención de doble envío: `onSubmit()` cuenta con una guarda de retorno temprano `if (this.isSubmitting()) return;` que bloquea peticiones concurrentes o clics dobles rápidos mientras el formulario se encuentra en vuelo.

## 7. Estado y últimos cambios
- Adición de guarda de retorno temprano `isSubmitting` para evitar envíos duplicados por doble clic.
- Implementación del formulario reactivo con controles para todas las estrategias de corte y modos verticales.
- Integración de sondeo en tiempo real con barra de progreso animada.
- Tarjeta de confirmación y navegación contextual hacia la biblioteca de clips.

## 8. Cómo verificar
```bash
cd frontend
bun run build # Valida la ausencia de errores en la compilación de VideoUploadComponent
```
