# Frontend SPA Module — ReelForge

## 1. Propósito y alcance
Este módulo constituye la aplicación cliente Single Page Application (SPA) para ReelForge, desarrollada en Angular 22. Su responsabilidad abarca la interfaz gráfica de usuario para la ingestión de contenido audiovisual de formato largo, la parametrización de corte y transformación a relación de aspecto vertical 9:16, la previsualización interactiva con reproductor multimedia adaptado a marcos móviles, la gestión de integraciones y canales sociales (TikTok, Instagram Reels, YouTube Shorts) y el despacho programado o inmediato de publicaciones.

## 2. Contratos y API
El frontend consume la API REST provista por el backend modular Laravel en `/api`:
- `POST /api/videos`: Ingestión de video fuente (`source_url`, `strategy`, `interval_seconds`, `vertical_mode`).
- `GET /api/videos`: Consulta paginada de videos procesados.
- `GET /api/videos/{id}`: Detalle de video con clips asociados.
- `GET /api/videos/{id}/status`: Sondeo de ciclo de vida (`pending`, `downloading`, `processing`, `ready`, `failed`).
- `DELETE /api/videos/{id}`: Eliminación de video y archivos derivados.
- `GET /api/videos/{videoId}/clips`: Listado de clips asociados a un video (`{ data: Clip[] }`).
- `GET /api/clips/{id}`: Detalle de clip.
- `PATCH /api/clips/{id}`: Edición de metadatos de clip (título, recorte temporal).
- `DELETE /api/clips/{id}`: Supresión física y lógica de clip.
- `POST /api/clips/{id}/publish`: Despacho de publicación multicanal (`platforms`, `caption`, `scheduled_at`).
- `GET /api/publications`: Registro histórico de auditoría de publicaciones.
- `POST /api/publications/{id}/retry`: Reintento de publicaciones fallidas.
- `GET /api/social-accounts`: Cuentas sociales enlazadas.
- `POST /api/social-accounts/connect/{platform}`: Inicio de autenticación OAuth.
- `POST /api/social-accounts/mock-connect`: Aprovisionamiento de cuentas simuladas para pruebas.
- `DELETE /api/social-accounts/{id}`: Desconexión de canal social.

## 3. Dependencias
- `@angular/core`, `@angular/common`, `@angular/router`, `@angular/forms`, `@angular/platform-browser` versión 22.x.
- `rxjs` versión ~7.8.0 para reactividad y sondeo asíncrono.
- `vitest` y `@angular/build:unit-test` para ejecución de pruebas unitarias.
- `@angular/build:application` con esbuild para empaquetado optimizado.
- `proxy.conf.json` para enrutamiento local de `/api` y `/storage` hacia el backend en el puerto 8000.

## 4. Mapa de archivos
- `package.json`: Manifiesto de dependencias, scripts de compilación y pruebas.
- `angular.json`: Configuración de compilación, presupuestos de estilos y opciones de empaquetado.
- `tsconfig.json`: Configuración base de TypeScript.
- `tsconfig.app.json`: Configuración de compilación de la aplicación.
- `tsconfig.spec.json`: Configuración de tipos para pruebas Vitest.
- `proxy.conf.json`: Configuración de proxy para desarrollo contra backend local.
- `src/main.ts`: Punto de arranque de la aplicación Angular standalone.
- `src/index.html`: Contenedor HTML base con viewport y metadatos.
- `src/styles.css`: Sistema de diseño global, paleta dark theme y utilidades CSS.
- `src/app/app.component.ts`: Componente raíz que aloja el cascarón de navegación.
- `src/app/app.config.ts`: Proveedores globales de routing, cliente HTTP e interceptores.
- `src/app/app.routes.ts`: Definición de rutas principales con carga diferida.
- `src/app/adversarial-milestone3.spec.ts`: Suite integral de pruebas adversariales para componentes y flujos de UI.

## 5. Lógica y flujo
1. Inicialización en `main.ts` cargando `AppComponent` con `appConfig`.
2. `appConfig` registra `provideHttpClient` configurado con `apiErrorInterceptor` y `provideRouter` con rutas diferidas (`loadComponent`).
3. La navegación por defecto redirige de `/` a `/dashboard`.
4. El cascarón en `LayoutComponent` estructura la barra lateral responsiva y el área de contenido principal con `RouterOutlet`.
5. Los módulos de características (`dashboard`, `video-upload`, `clips`, `social-accounts`, `publish`) operan de forma desacoplada consumiendo los servicios inyectables en `core/services/`.

## 6. Decisiones de diseño y gotchas
- Ausencia de frameworks de interfaz de usuario pesados: se emplean componentes standalone puros con CSS nativo (CSS Grid y Flexbox) para garantizar compilación rápida en entornos con un único núcleo de CPU (`nproc = 1`).
- Presupuesto de estilos en `angular.json`: se configuró `anyComponentStyle` a 8kB de advertencia y 16kB de error para componentes con hojas de estilo densas.
- Vitest como ejecutor de pruebas: reemplaza Jasmine/Karma según el estándar de Angular 22, utilizando `vi.fn()` para espías y aserciones compatibles con TypeScript.

## 7. Estado y últimos cambios
- Andamiaje inicial completado con Angular CLI 22.2.6 y gestor Bun.
- Configuración de estilos globales en `src/styles.css` con paleta oscura y diseño responsivo.
- Integración de todas las capas de negocio, servicios, layout y componentes compartidos.
- Verificación exitosa de compilación para producción sin advertencias ni errores.
- Incorporación y validación de suites de pruebas adversariales para componentes, tuberías, interceptores y flujos reactivos.
- Configuración de `proxyConfig: "proxy.conf.json"` en `angular.json` para resolución transparente de endpoints `/api` y `/storage` en desarrollo local.
- Remediación técnica integral de observaciones de revisión: refactorización a señales en `VerticalPlayerComponent`, validación estricta de fecha futura en `PublishComponent`, control de límites y feedback visual en `ClipsComponent`, y formateo de fechas futuras en `RelativeTimePipe`.
- Corrección de branding e identidad visual: generación de favicon monocromático multirresolución `favicon.svg` y `favicon.ico` (16, 32, 48px) con invalidación de caché `?v=3`, ajuste de título en `index.html` a ReelForge, dimensionamiento estricto global de iconos SVG en `styles.css` y saneamiento de estilos en componentes de cuentas sociales y subida de videos a la paleta estricta blanco, gris y negro.
- Configuración de despliegue en producción en Vercel con integración a GitHub (`https://github.com/anthra123x/reelscreator.git`): ajuste de `rootDirectory: "frontend"`, framework `angular`, configuración de rewrite para SPA en `vercel.json` y despliegue productivo verificado en `https://reelscreator-beta.vercel.app`.

## 8. Cómo verificar
```bash
cd frontend
bun run build # Debe finalizar con código de salida 0 y generar dist/frontend
bun run test -- --watch=false # Debe ejecutar y pasar el 100% de las pruebas unitarias
```
