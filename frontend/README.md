# Frontend SPA Module — ReelForge

## 1. Propósito y alcance
Este módulo constituye la aplicación cliente Single Page Application (SPA) para ReelForge, desarrollada en Angular 22. Su responsabilidad abarca la interfaz gráfica de usuario para la ingestión y descomposición autónoma de videos en reels verticales 9:16 (1080x1920), el panel unificado de métricas en tiempo real con sincronización periódica, la gestión simplificada y ágil de canales sociales (TikTok, Instagram Reels, YouTube Shorts) con soporte de conexión rápida en un clic, la previsualización interactiva con reproductor multimedia adaptado a marcos móviles y el despacho desatendido de publicaciones.

## 2. Contratos y API
El frontend consume la API REST provista por el backend modular Laravel en `/api`:
- `POST /api/videos`: Ingestión de video fuente (`source_url`, `strategy`, `interval_seconds`, `max_duration_seconds`, `vertical_mode`, `auto_publish`, `platforms`, `caption`).
- `POST /api/videos/decompose`: Ingestión y descomposición autónoma en reels 9:16 en una sola llamada desatendida.
- `POST /api/videos/{id}/decompose`: Descomposición de video existente en clips 9:16 con auto-publicación a redes vinculadas.
- `GET /api/videos`: Consulta paginada de videos procesados (`{ data: Video[], total: number }`).
- `GET /api/videos/{id}`: Detalle de video con clips asociados.
- `GET /api/videos/{id}/status`: Sondeo de ciclo de vida (`pending`, `downloading`, `processing`, `ready`, `failed`).
- `DELETE /api/videos/{id}`: Eliminación de video y archivos derivados.
- `GET /api/clips`: Listado global paginado de clips generados (`{ data: Clip[], total: number }`).
- `GET /api/videos/{videoId}/clips`: Listado de clips asociados a un video específico.
- `GET /api/clips/{id}`: Detalle de clip.
- `PATCH /api/clips/{id}`: Edición de metadatos de clip (título, recorte temporal).
- `DELETE /api/clips/{id}`: Supresión física y lógica de clip.
- `POST /api/clips/{id}/publish`: Despacho de publicación multicanal (`platforms`, `caption`, `scheduled_at`).
- `GET /api/publications`: Registro histórico de auditoría de publicaciones con filtros por estado.
- `POST /api/publications/{id}/retry`: Reintento de publicaciones fallidas.
- `GET /api/social-accounts`: Cuentas sociales enlazadas con mapeo de `is_active` y `status`.
- `POST /api/social-accounts/connect`: Endpoint ágil y unificado de conexión (`platform: 'all'|'tiktok'|'instagram'|'youtube'`, `mode: 'auto'|'mock'|'env'|'direct'`).
- `POST /api/social-accounts/connect/{platform}`: Inicio de autenticación OAuth interactiva.
- `POST /api/social-accounts/mock-connect`: Aprovisionamiento de cuentas simuladas individuales.
- `DELETE /api/social-accounts/{id}`: Desconexión de canal social.

## 3. Dependencias
- `@angular/core`, `@angular/common`, `@angular/router`, `@angular/forms`, `@angular/platform-browser` versión 22.x.
- `rxjs` versión ~7.8.0 para reactividad, temporizadores periódicos (`timer`) y flujos de sondeo.
- `vitest` y `@angular/build:unit-test` para ejecución de pruebas unitarias y de integración.
- `@angular/build:application` con esbuild para empaquetado optimizado en producción.
- `proxy.conf.json` para enrutamiento local de `/api` y `/storage` hacia el backend en el puerto 8000.
- `vercel.json` para reescritura de rutas en producción hacia la API de backend en Vercel.

## 4. Mapa de archivos
- `package.json`: Manifiesto de dependencias, scripts de compilación (`ng build`) y pruebas (`ng test`).
- `angular.json`: Configuración de compilación, presupuestos de bundle (máximo 500kB warning, 1MB error) y pruebas.
- `tsconfig.json`: Configuración base de TypeScript.
- `tsconfig.app.json`: Configuración de compilación de la aplicación cliente.
- `tsconfig.spec.json`: Configuración de tipos para pruebas unitarias con Vitest.
- `proxy.conf.json`: Configuración de proxy para desarrollo local.
- `src/main.ts`: Punto de arranque de la aplicación Angular standalone.
- `src/index.html`: Contenedor HTML base con metadatos y enlaces de fuentes.
- `src/styles.css`: Sistema de diseño global, paleta dark theme y utilidades CSS.
- `src/app/app.component.ts`: Componente raíz que aloja el cascarón de navegación.
- `src/app/app.config.ts`: Proveedores globales de routing, cliente HTTP e interceptores.
- `src/app/app.routes.ts`: Definición de rutas principales con carga diferida.
- `src/app/core/models/`: Modelos TypeScript de dominio (`video`, `clip`, `publication`, `social-account`, `common`).
- `src/app/core/services/`: Servicios de comunicación con el backend (`video`, `clip`, `social-account`, `publish`, `api`).
- `src/app/adversarial-m7-challenger.spec.ts`: Suite adversaria de ciclo de vida de streams reactivos (teardown de temporizador de 10s en dashboard, idempotencia de refresco, cancelación de suscripciones), valores límite en formularios de subida (toggle rápido, selección nula de plataformas con fallback, subtítulos Unicode/emojis masivos de 10.000 caracteres, validación estricta de URLs) y concurrencia en cuentas sociales.
- `src/app/adversarial-m7-stress.spec.ts`: Suite de estrés adversario en resiliencia de red, respuestas malformadas y estados de error en componentes de Milestone M7.
- `src/app/features/dashboard/dashboard.component.ts`: Vista principal analítica con KPIs dinámicos, sondeo automático y acceso a flujo autónomo.
- `src/app/features/dashboard/dashboard.component.spec.ts`: Pruebas unitarias de agregación de métricas y ciclo de vida de polling.
- `src/app/features/video-upload/video-upload.component.ts`: Formulario de ingestión con soporte de auto-publicación y visor de despacho.
- `src/app/features/video-upload/video-upload.component.spec.ts`: Pruebas unitarias de subida autónoma y visualización de publicaciones.
- `src/app/features/social-accounts/social-accounts.component.ts`: Panel de gestión social con conexión en 1 clic y etiquetas de estado.
- `src/app/features/social-accounts/social-accounts.component.spec.ts`: Pruebas unitarias de conexión ágil y renderizado de tarjetas.

## 5. Lógica y flujo
1. Inicialización: `main.ts` inicia la aplicación montando `AppComponent` con `appConfig`.
2. Enrutamiento: Las rutas son diferidas (`loadComponent`). La ruta base `/` redirige a `/dashboard`.
3. Dashboard unificado: `DashboardComponent` ejecuta una consulta concurrente vía `forkJoin` para obtener videos, clips, cuentas sociales y publicaciones. Se calcula el estado real sin valores sustitutos fijos y se programa una actualización automática cada 10 segundos mediante `timer(10000, 10000)`.
4. Ingestión y corte autónomo: En `VideoUploadComponent`, el usuario puede activar la casilla de auto-publicación y seleccionar plataformas de destino. Al completar la transcodificación, el componente recupera los registros de publicación generados y despliega su estado.
5. Vinculación ágil de cuentas: En `SocialAccountsComponent`, el usuario puede ejecutar una conexión global de todas las plataformas soportadas en un solo clic mediante `connectSimple({ platform: 'all', mode: 'auto' })` o vincular cuentas individuales con acción rápida.

## 6. Decisiones de diseño y gotchas
- Presupuesto de empaquetado estricto: La configuración de bundle impone un límite de advertencia de 500kB para el chunk inicial. El bundle compilado actual es de aproximadamente 308kB, cumpliendo holgadamente el criterio de aceptación.
- Sincronización sin fugas de memoria: Los temporizadores de sondeo en `DashboardComponent` y `VideoUploadComponent` implementan `OnDestroy` para cancelar explícitamente las suscripciones activas al desmontar el componente.
- Degradación elegante de métricas: El conteo de reels 9:16 utiliza el total devuelto por la API `/api/clips`, o la agregación de clips en los videos cargados, garantizando datos empíricos fidedignos sin recurrir a constantes cableadas en el código fuente.
- Conexión polimórfica de redes: La interfaz soporta indistintamente credenciales reales de producción y adaptadores simulados sin requerir cambios de implementación en la vista.

## 7. Estado y últimos cambios
- Milestone M7 completado:
  - Implementación de `connectSimple` en `SocialAccountService` y mapeo normalizado de `is_active` y `status`.
  - Extensión de `submitVideo` en `VideoService` para soportar `auto_publish`, `platforms` y `caption`.
  - Adición de `decomposeVideo` e `ingestAndDecompose` en `VideoService`.
  - Implementación del método `getAllClips` en `ClipService` para consulta global de clips.
  - Actualización de las 4 tarjetas KPI en `DashboardComponent` con datos dinámicos reales (Videos, Reels 9:16, Cuentas Conectadas, Publicaciones).
  - Integración de temporizador de refresco periódico de 10 segundos con indicador visual en vivo en el encabezado de dashboard.
  - Botón de acceso directo en dashboard hacia el flujo de descomposición y publicación autónoma.
  - Incorporación de casilla de auto-publicación, selector multicanal y resumen de despacho en `VideoUploadComponent`.
  - Botón de 1 clic para vinculación de todas las cuentas sociales y acciones de conexión rápida en tarjetas de `SocialAccountsComponent`.
  - Incorporación de pruebas unitarias y suites de estrés adversario completas para los servicios y componentes actualizados, alcanzando 123 pruebas exitosas distribuidas en 19 suites en el frontend.

## 8. Cómo verificar
```bash
cd frontend
bun run build # Valida compilación de producción y cumplimiento de presupuesto <= 500kB (308.17 kB)
bun run test -- --watch=false # Ejecuta las 19 suites de pruebas unitarias y de estrés (123 pruebas exitosas)
```
