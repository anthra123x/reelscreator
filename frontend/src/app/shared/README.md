# Shared Module — Frontend ReelForge

## 1. Propósito y alcance
El módulo Shared reúne componentes de presentación reutilizables, tuberías de transformación de datos (pipes) y utilidades visuales compartidas por las diferentes vistas y módulos de características de ReelForge. Su propósito es estandarizar la representación visual de elementos multimedia en formato vertical 9:16, estados de procesamiento del ciclo de vida y formato temporal.

## 2. Contratos y API
El módulo expone:
- `VerticalPlayerComponent` (`app-vertical-player`):
  - Entradas (`@Input`):
    - `src`: URL del archivo de video MP4 o fuente de medios.
    - `poster`: URL de la miniatura estática.
    - `title`: Título del clip en reproducción.
    - `loop`: Bandera de repetición continua (por defecto `true`).
    - `showPublish`: Habilita botón de publicación rápida dentro del reproductor.
    - `clip`: Objeto de clip completo (`Clip`) con metadatos de inicio, fin y relación con el video original.
    - `sourceUrl`: URL de la fuente de video (YouTube, Vimeo o enlace directo).
    - `startTime`: Segundo de inicio del corte.
    - `endTime`: Segundo de finalización del corte.
    - `embedUrl`: URL segura pre-calculada de inserción IFrame para proveedores externos.
  - Salidas (`@Output`):
    - `close`: Emite evento al cerrar el modal (`EventEmitter<void>`).
    - `publish`: Emite evento para iniciar publicación del clip (`EventEmitter<void>`).
- `StatusPillComponent` (`app-status-pill`):
  - Entradas:
    - `status`: Cadena que representa el estado (`pending`, `downloading`, `processing`, `ready`, `published`, `scheduled`, `failed`).
    - `pulse`: Activa animación de pulso para estados en progreso.
- `SkeletonLoaderComponent` (`app-skeleton-loader`):
  - Entradas:
    - `shape`: Forma del elemento de carga (`card-9-16`, `table-row`, `metric-widget`, `text`).
    - `count`: Número de elementos a proyectar.
- `DurationPipe` (`duration`):
  - Transforma segundos numéricos en formato `mm:ss` o `hh:mm:ss`.
- `RelativeTimePipe` (`relativeTime`):
  - Transforma marcas de tiempo ISO-8601 en textos de tiempo relativo ("Just now", "5m ago", "2h ago").

## 3. Dependencias
- `@angular/core`: Decoradores de componentes, inputs, outputs y referencias de elementos DOM (`ElementRef`, `ViewChild`).
- `@angular/common`: Módulo común y directivas de control de flujo.

## 4. Mapa de archivos
- `components/vertical-player/vertical-player.component.ts`: Reproductor vertical con marco de smartphone 9:16 y controles HTML5.
- `components/status-pill/status-pill.component.ts`: Indicador de estado con código de color semántico y animación.
- `components/skeleton-loader/skeleton-loader.component.ts`: Componente de carga visual que previene saltos acumulados de diseño.
- `pipes/duration.pipe.ts`: Tubería de formato de duración en segundos.
- `pipes/duration.pipe.spec.ts`: Pruebas unitarias de `DurationPipe`.
- `pipes/relative-time.pipe.ts`: Tubería de formato de fechas relativas.
- `pipes/relative-time.pipe.spec.ts`: Pruebas unitarias de `RelativeTimePipe`.
- `pipes/pipes.adversarial.spec.ts`: Pruebas de estrés y casos extremos para tuberías (valores negativos, extremos, fechas futuras, epoch 0).
- `index.ts`: Punto de exportación unificado de componentes y tuberías compartidos.

## 5. Lógica y flujo
1. Los componentes de características importan los componentes y tuberías compartidos según sus necesidades de presentación.
2. `VerticalPlayerComponent` inicializa el elemento `<video>` con el atributo `muted = true` para cumplir con las políticas de reproducción automática de los navegadores modernos.
3. El usuario puede alternar la reproducción haciendo clic directamente sobre el área del video o en el botón de reproducción flotante.
4. Las tuberías procesan datos síncronamente durante los ciclos de detección de cambios de Angular sin provocar efectos secundarios.

## 6. Decisiones de diseño y gotchas
- Geometría estricta 9:16: El reproductor vertical mantiene una relación fija de aspecto (`aspect-ratio: 9 / 16`) con altura máxima acotada al 85% del viewport para evitar desplazamientos verticales excesivos en pantallas de escritorio.
- Gestión de errores de carga multimedia: Si la fuente del video o miniatura no es accesible, se renderiza automáticamente una capa de aviso con estado alternativo amigable.
- Manejo de valores nulos en pipes: Las tuberías manejan defensivamente entradas indefinidas o nulas devolviendo valores de sustitución estables.

## 7. Estado y últimos cambios
- Soporte para reproducción híbrida de previsualización en `VerticalPlayerComponent` con IFrame seguro y padding difuminado 9:16 para YouTube y Vimeo sincronizado con los segundos de inicio y fin del reel.
- Recuperación automática en `onError` hacia reproductor embebido cuando se detecta origen de YouTube, evitando bloqueos por almacenamiento estático inexistente en entornos serverless.
- Implementación y prueba de `VerticalPlayerComponent`, `StatusPillComponent` y `SkeletonLoaderComponent`.
- Creación de pruebas unitarias exhaustivas para `DurationPipe` y `RelativeTimePipe` con 100% de aserciones válidas.
- Agregada suite de pruebas adversariales para DurationPipe y RelativeTimePipe comprobando valores negativos, 100+ horas, NaN, fechas futuras y epoch 0.
- Refactorización integral de `VerticalPlayerComponent` migrando propiedades mutables a señales reactivas de Angular (`isPlaying`, `isMuted`, `duration`, `currentTime`, `hasError`) garantizando reactividad zoneless estricta y paso del 100% de pruebas adversariales.
- Extensión de `RelativeTimePipe` para formateo semántico de fechas futuras (`diff < 0`) mediante prefijo temporal ("in Xs", "in Xm", "in Xh", "in Xd").

## 8. Cómo verificar
```bash
cd frontend
bun run test -- --watch=false --filter=src/app/shared/pipes
```
