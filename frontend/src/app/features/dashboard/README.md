# Dashboard Feature Module — Frontend ReelForge

## 1. Propósito y alcance
El módulo de características Dashboard proporciona la vista analítica principal de telemetría y rendimiento de redes sociales para ReelForge, alineado con el diseño Metric Flow. Su alcance comprende la consolidación de indicadores clave de audiencia (vistas totales, reels generados, crecimiento de seguidores y retención promedio), matriz de actividad por horario (heatmap), gráfico de tendencias mensuales de visualizaciones vs objetivo, distribución por canal (TikTok, Instagram Reels, YouTube Shorts) y tabla de reels con mayor rendimiento.

## 2. Contratos y API
El módulo expone el componente standalone `DashboardComponent`:
- Ruta asociada: `/dashboard` (ruta por defecto).
- Contratos consumidos:
  - `VideoService.getVideos({ per_page: 5 })`: Obtiene los videos más recientes y el total acumulado en base de datos.
  - `PublishService.getPublications({ per_page: 1 })`: Recupera el recuento global de publicaciones tramitadas.
- Interfaces públicas expuestas:
  - `DashboardMetrics`: `totalVideos`, `totalClips`, `activeJobs`, `totalPublications`.
  - `PlatformStat`: `name`, `handle`, `views`, `reelsCount`, `growth`, `sharePercent`, `iconSvg`.
  - `TopReel`: `id`, `title`, `platform`, `views`, `engagement`, `growth`, `status`.
- Señales reactivas internas:
  - `isLoading`: `WritableSignal<boolean>` estado de carga asíncrona.
  - `recentVideos`: `WritableSignal<Video[]>` lista de videos recientes.
  - `metrics`: `WritableSignal<DashboardMetrics>` contadores consolidados.
  - `formattedTotalViews`: `Signal<string>` visualizaciones acumuladas calculadas reactivamente.

## 3. Dependencias
- `@angular/core`: Primitivas de componentes, inyección y señales reactivas.
- `@angular/common`: Directivas de renderizado condicional y listas.
- `@angular/router`: Enrutamiento y directiva `RouterModule`.
- `rxjs`: Funciones `forkJoin`, `of` y operador `catchError` para carga en paralelo tolerante a fallos.
- `frontend/src/app/core/services`: `VideoService`, `ClipService`, `SocialAccountService`, `PublishService`.
- `frontend/src/app/shared`: `StatusPillComponent`, `SkeletonLoaderComponent`, `RelativeTimePipe`.

## 4. Mapa de archivos
- `dashboard.component.ts`: Componente standalone integral que implementa la cuadrícula analítica, gráfica SVG de curvas bezier, matriz de actividad horaria, desglose de canales sociales y listado de mejores reels.

## 5. Lógica y flujo
1. Al montarse (`ngOnInit`), el componente invoca `loadDashboardData()`.
2. Se ejecuta una petición combinada mediante `forkJoin` a los endpoints de videos y publicaciones.
3. Se analizan los estados de los videos recibidos para computar el número de tareas activas (`downloading` o `processing`) y clips generados.
4. Las señales reactivas se actualizan simultáneamente finalizando el estado de carga (`isLoading.set(false)`).
5. La matriz de horario evalúa niveles de intensidad (0 a 3) en una cuadrícula de 7x7 días y horas de mayor audiencia.
6. La gráfica mensual vectorial compara las vistas reales frente a las cuotas de objetivo con resaltado en septiembre/octubre.
7. Los canales sociales vinculados muestran la participación proporcional de audiencia con barras de progreso monocromáticas.

## 6. Decisiones de diseño y gotchas
- Paleta monocromática estricta: Uso exclusivo de blanco, gris y negro (#09090b canvas, #121214 tarjetas, #18181b elevación, #27272a bordes, #ffffff acentos).
- Gráficos nativos sin dependencias pesadas: Tanto el heatmap como la gráfica de curvas mensuales se implementan mediante CSS Grid y SVG inline ligero, manteniendo cero dependencias de librerías externas de visualización.
- Tolerancia a base de datos vacía: Cuando el sistema inicia sin videos persistidos, las métricas y la tabla de top reels muestran datos base realistas con degradación elegante.

## 7. Estado y últimos cambios
- Rediseño integral de la vista de Dashboard según la referencia visual Metric Flow.
- Implementación de 4 tarjetas KPI analíticas (Visualizaciones, Reels Generados, Seguidores, Retención).
- Integración de heatmap de publicaciones por horario y gráfico vectorial de rendimiento mensual.
- Incorporación del panel de distribución por canal social y tabla de top reels con mayor engagement.
- Cero emojis y reemplazo total por iconografía SVG inline.

## 8. Cómo verificar
```bash
cd frontend
npm run build # Valida la ausencia de errores de tipado o compilación en DashboardComponent
npm test -- --watch=false # Ejecuta la suite de pruebas unitarias y de componentes
```
