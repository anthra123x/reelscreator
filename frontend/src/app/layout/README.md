# Layout Module — Frontend ReelForge

## 1. Propósito y alcance
El módulo Layout implementa el cascarón de presentación principal (App Shell) de ReelForge. Su propósito es proveer una estructura de navegación minimalista en paleta monocromática (blanco, gris y negro), responsiva y accesible a través de toda la aplicación, incluyendo barra superior con buscador y fecha formateada, barra lateral con tipografía limpia y enlaces mediante iconos SVG, y área receptora del enrutamiento de vistas.

## 2. Contratos y API
El módulo expone el componente standalone `LayoutComponent`:
- Selector: `app-layout`
- Rutas integradas:
  - `/dashboard`: Panel de telemetría y métricas de redes sociales.
  - `/video-upload`: Formulario de ingestión y corte de videos.
  - `/clips`: Biblioteca de clips verticales 9:16.
  - `/social-accounts`: Gestión de canales de redes sociales.
  - `/publish`: Centro de despacho de publicaciones y registro de auditoría.
- Señales reactivas internas:
  - `isMobileMenuOpen`: `WritableSignal<boolean>` para alternar el menú en dispositivos móviles.
  - `formattedDate`: `Signal<string>` que computa la fecha actual en formato legible.

## 3. Dependencias
- `@angular/core`: Primitivas de componentes y reactividad por Signals.
- `@angular/common`: Directivas estructurales (`ngFor`, `ngIf`, `ngClass`).
- `@angular/router`: Directivas de navegación (`RouterModule`, `RouterLink`, `RouterLinkActive`, `RouterOutlet`).

## 4. Mapa de archivos
- `layout.component.ts`: Componente standalone integral que encapsula la plantilla HTML, estilos CSS y lógica de navegación del cascarón.

## 5. Lógica y flujo
1. El componente raíz `AppComponent` aloja `<app-layout>`.
2. `LayoutComponent` inicializa la barra lateral con los enlaces de navegación y sus iconos SVG vectoriales.
3. El enlace activo es detectado y resaltado mediante `routerLinkActive="active"`.
4. En resoluciones de pantalla reducidas (< 768px), el menú lateral colapsa fuera de pantalla y se activa mediante un botón hamburguesa en la barra superior con fondo oscurecido (`backdrop`).
5. La barra superior expone buscador rápido de clips, fecha formateada actual, accesos de calendario, alertas y perfil de usuario.

## 6. Decisiones de diseño y gotchas
- Paleta monocromática estricta: Se utilizan exclusivamente tonos de blanco, gris y negro (#09090b, #121214, #18181b, #27272a, #ffffff).
- Eliminación de logo gráfico e indicadores de backend: Se reemplazó el isotipo gráfico por la marca tipográfica minimalista "ReelForge" y se retiró el widget de estado de servicios Axum/Laravel de la barra lateral.
- Cero emojis: Todos los glifos de navegación y acción se reemplazaron por SVG inline limpios y semánticos.

## 7. Estado y últimos cambios
- Rediseño de Layout según especificación visual Metric Flow monocromática.
- Eliminación del widget inferior de estado de servidores (Rust Engine y Laravel 12).
- Reemplazo de emojis por iconos SVG vectoriales en toda la navegación.
- Incorporación de barra de búsqueda y selector de fecha en la barra superior.

## 8. Cómo verificar
```bash
cd frontend
npm run build # Verifica la compilación sin errores del componente de layout
npm test -- --watch=false --include=src/app/app.component.spec.ts # Valida la integración del layout en el árbol raíz
```
