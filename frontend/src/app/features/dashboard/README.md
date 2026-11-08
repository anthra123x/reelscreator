# Dashboard Feature Module — Frontend ReelForge

## 1. Propósito y alcance
El módulo de características Dashboard proporciona la vista analítica principal de telemetría y rendimiento de redes sociales para ReelForge, alineado con el diseño Metric Flow. Su alcance comprende la consolidación de indicadores clave de actividad (videos procesados, reels 9:16 generados, cuentas conectadas y publicaciones realizadas), sincronización automática periódica mediante sondeo no bloqueante, matriz de actividad por horario (heatmap), gráfico de tendencias mensuales de visualizaciones vs objetivo, distribución por canal (TikTok, Instagram Reels, YouTube Shorts) y acceso rápido al flujo autónomo de descomposición y publicación.

## 2. Contratos y API
El módulo expone el componente standalone `DashboardComponent`:
- Ruta asociada: `/dashboard` (ruta por defecto).
- Contratos consumidos:
  - `VideoService.getVideos({ per_page: 5 })`: Obtiene los videos más recientes y el total acumulado en base de datos.
  - `ClipService.getAllClips({ per_page: 1 })`: Recupera el total empírico de reels verticales 9:16 generados en la plataforma.
  - `SocialAccountService.getAccounts()`: Lista las cuentas de redes sociales para computar canales activos y conectados.
  - `PublishService.getPublications({ per_page: 20 })`: Recupera el recuento global y el estado de publicaciones tramitadas.
- Interfaces públicas expuestas:
  - `DashboardMetrics`: `totalVideos`, `totalClips`, `activeJobs`, `totalPublications`, `connectedAccounts`, `successfulPublications`.
  - `PlatformStat`: `name`, `handle`, `views`, `reelsCount`, `growth`, `sharePercent`.
  - `TopReel`: `id`, `title`, `platform`, `views`, `engagement`, `growth`, `status`.
- Señales reactivas internas:
  - `isLoading`: `WritableSignal<boolean>` estado de carga asíncrona.
  - `recentVideos`: `WritableSignal<Video[]>` lista de videos recientes.
  - `metrics`: `WritableSignal<DashboardMetrics>` contadores consolidados reales.
  - `formattedTotalViews`: `Signal<string>` visualizaciones acumuladas calculadas reactivamente.

## 3. Dependencias
- `@angular/core`: Primitivas de componentes, inyección, ciclo de vida (`OnInit`, `OnDestroy`) y señales reactivas.
- `@angular/common`: Directivas de renderizado condicional y listas.
- `@angular/router`: Enrutamiento y directiva `RouterModule`.
- `rxjs`: Funciones `forkJoin`, `timer`, `of` y operadores `catchError` para carga concurrente tolerante a fallos y refresco periódico.
- `frontend/src/app/core/services`: `VideoService`, `ClipService`, `SocialAccountService`, `PublishService`.
- `frontend/src/app/shared`: `StatusPillComponent`, `SkeletonLoaderComponent`, `RelativeTimePipe`.

## 4. Mapa de archivos
- `dashboard.component.ts`: Componente standalone integral que implementa la cuadrícula analítica, gráfica SVG de curvas bezier, matriz de actividad horaria, desglose de canales sociales, badges de polling y botón de flujo autónomo.
- `dashboard.component.spec.ts`: Suite de pruebas unitarias que verifica la agregación de métricas sin valores sustitutos fijos, el ciclo de vida del temporizador de sondeo y los elementos de interfaz.

## 5. Lógica y flujo
1. Al montarse (`ngOnInit`), el componente invoca `loadDashboardData()` e inicializa `startAutoRefresh()` programando un temporizador cada 10 segundos con `timer(10000, 10000)`.
2. Se ejecuta una petición combinada mediante `forkJoin` consultando videos, clips globales, cuentas sociales y publicaciones.
3. Se analizan los estados para computar tareas activas, reels reales, cuentas activas (`is_active !== false && status !== 'expired'`) y publicaciones exitosas.
4. Las señales reactivas se actualizan simultáneamente finalizando el estado de carga (`isLoading.set(false)`).
5. El encabezado despliega un indicador visual en vivo (`live-dot`) que refleja la sincronización periódica activa.
6. El botón "Descomposición y Publicación Autónoma" enlaza a `/video-upload?auto_publish=true&mode=autonomous` para permitir la activación inmediata del pipeline desatendido.
7. Al desmontarse el componente (`ngOnDestroy`), se invoca `stopAutoRefresh()` cancelando la suscripción al temporizador.

## 6. Decisiones de diseño y gotchas
- Ausencia de datos estáticos simulados en tarjetas KPI: Se eliminaron valores constantes prefijados como `1240` en clips generados o números fijos de seguidores. Las 4 tarjetas reflejan datos reales devueltos por la API de persistencia.
- Manejo de ciclo de vida del temporizador: La suscripción a `timer` se resguarda en `pollSubscription` y se desuscribe explícitamente en `ngOnDestroy` para evitar fugas de memoria en cambios de vista.
- Tolerancia a endpoints con error: Cada observable dentro de `forkJoin` incorpora `catchError(() => of(...))` para permitir que el panel cargue parcialmente si algún servicio auxiliar responde con error transitorio.

## 7. Estado y últimos cambios
- Milestone M7: Actualización completa de las 4 tarjetas KPI para mostrar métricas reales (Videos Procesados, Reels 9:16 Generados, Cuentas Conectadas, Publicaciones Realizadas).
- Milestone M7: Integración de actualización periódica cada 10 segundos con indicador visual en vivo.
- Milestone M7: Botón de acceso directo hacia el flujo autónomo de descomposición y publicación.
- Milestone M7: Creación de la suite de pruebas unitarias `dashboard.component.spec.ts`.

## 8. Cómo verificar
```bash
cd frontend
bun run build # Valida la ausencia de errores de tipado o compilación en DashboardComponent
bun run test -- --watch=false # Ejecuta las pruebas unitarias del módulo Dashboard
```
