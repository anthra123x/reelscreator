# Core Module — Frontend ReelForge

## 1. Propósito y alcance
El módulo Core centraliza los servicios singleton, modelos de dominio tipados, tokens de inyección de dependencias e interceptores HTTP para toda la aplicación frontend de ReelForge. Su propósito es proveer una capa de abstracción uniforme sobre el protocolo REST del backend, gestionar el ciclo de vida del estado compartido mediante Angular Signals y normalizar los errores de validación devueltos por la API.

## 2. Contratos y API
El módulo expone servicios inyectables en la raíz (`providedIn: 'root'`) y modelos tipados:
- `ApiService`: Envoltura HTTP sobre `HttpClient` con resolución de URLs base y parámetros de consulta.
- `VideoService`: Ingestión de videos (`submitVideo`), consulta paginada (`getVideos`), detalle (`getVideo`), sondeo (`pollVideoStatus`) y eliminación (`deleteVideo`).
- `ClipService`: Listado por video (`getClipsByVideo`), detalle (`getClip`), actualización de metadatos (`updateClip`) y eliminación (`deleteClip`).
- `SocialAccountService`: Consulta de cuentas vinculadas (`getAccounts`), inicio de OAuth (`connectPlatform`), vinculación simulada (`mockConnect`) y desvinculación (`disconnectAccount`).
- `PublishService`: Despacho multicanal de clips (`publishClip`), consulta histórica paginada (`getPublications`) y reintentos (`retryPublication`).
- `apiErrorInterceptor`: Interceptor funcional que captura respuestas HTTP 422 y extrae errores de validación en la clase `AppApiError`.
- `API_BASE_URL`: Token de inyección que define la ruta base (`/api`) con capacidad de sobrescritura.

## 3. Dependencias
- `@angular/core`: Decoradores e inyección de dependencias.
- `@angular/common/http`: `HttpClient`, `HttpParams`, `HttpErrorResponse`.
- `rxjs`: Operadores reactivos (`switchMap`, `takeWhile`, `catchError`, `tap`, `interval`, `startWith`).
- `frontend/src/app/core/models`: Modelos de entidades y DTOs internos.

## 4. Mapa de archivos
- `models/common.model.ts`: Tipos de unión (`CuttingStrategy`, `VerticalMode`, `SocialPlatform`, `VideoStatus`, `ClipStatus`, `PublicationStatus`) y contratos de paginación (`PaginatedResponse<T>`).
- `models/video.model.ts`: Interfaces de la entidad `Video`, `VideoIngestRequest`, `VideoIngestResponse` y `VideoStatusResponse`.
- `models/clip.model.ts`: Interfaces de la entidad `Clip`, `UpdateClipRequest` y contenedor `ClipListResponse`.
- `models/social-account.model.ts`: Entidad `SocialAccount`, `MockConnectRequest` y `ConnectPlatformResponse`.
- `models/publication.model.ts`: Entidad `Publication`, `PublishClipRequest` y respuestas de publicación.
- `models/index.ts`: Punto de exportación unificado de todos los modelos.
- `tokens/api-url.token.ts`: Definición del `InjectionToken<string>` para `API_BASE_URL`.
- `interceptors/api-error.interceptor.ts`: Interceptor funcional HTTP y clase de excepción `AppApiError`.
- `interceptors/api-error.interceptor.spec.ts`: Pruebas unitarias y de estrés adversarial para el interceptor de errores HTTP.
- `services/api.service.ts`: Cliente HTTP base parametrizado.
- `services/video.service.ts`: Servicio del dominio de videos y sondeo reactivo.
- `services/video-poll.adversarial.spec.ts`: Pruebas adversariales de sondeo reactivo (completitud inmediata, fallas, reintentos).
- `services/clip.service.ts`: Servicio de gestión y persistencia de clips.
- `services/social-account.service.ts`: Servicio de gestión de cuentas y adaptadores sociales.
- `services/publish.service.ts`: Servicio de orquestación de publicaciones y auditoría.
- `services/index.ts`: Punto de exportación unificado de todos los servicios.
- `services/*.spec.ts`: Pruebas unitarias de cada servicio usando Vitest.

## 5. Lógica y flujo
1. Los componentes de características inyectan los servicios de dominio correspondientes (`VideoService`, `ClipService`, etc.).
2. Cada servicio delega las llamadas REST en `ApiService`, el cual compone la URL completa empleando `API_BASE_URL`.
3. `apiErrorInterceptor` monitorea el flujo de salida y captura errores de red o del servidor. Ante errores de validación HTTP 422, formatea los mensajes específicos por campo.
4. En operaciones asíncronas de larga duración (como el procesamiento de video en Rust/FFmpeg), `VideoService.pollVideoStatus` ejecuta sondeos periódicos con terminación automática cuando el estado alcanza `ready` o `failed`.

## 6. Decisiones de diseño y gotchas
- Reactividad híbrida: Se combinan Observables RxJS para flujos de datos asíncronos y transferencias HTTP con Angular Signals (`signal<T>`) para la sincronización inmediata del estado en la interfaz de usuario.
- Desempaquetado de sobres: Endpoints como `/api/videos/{videoId}/clips` entregan `{ data: Clip[] }`; el servicio `ClipService` desenvuelve automáticamente el arreglo mediante el operador `map(res => res.data)`.
- Compatibilidad de sondeo en single-core: El intervalo de sondeo se configuró en 2000 ms con desuscripción obligatoria para evitar sobrecargar la CPU del host.

## 7. Estado y últimos cambios
- Se implementaron y validaron todos los modelos TypeScript, tokens y servicios según los contratos expuestos por Laravel.
- Se adaptaron las pruebas unitarias para Vitest eliminando dependencias obsoletas de Jasmine.
- Cobertura completa de pruebas unitarias verificada exitosamente.
- Agregadas pruebas adversariales para apiErrorInterceptor (estados HTTP 0, 400, 422, 500) y sondeo reactivo en VideoService.pollVideoStatus.

## 8. Cómo verificar
```bash
cd frontend
bun run test -- --watch=false --filter=src/app/core/services
```
