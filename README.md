# ReelForge

Plataforma integral y automatizada para la generación de clips verticales (Reels, TikToks, Shorts) a partir de videos de larga duración mediante un motor de procesamiento en Rust, backend modular en Laravel e interfaz de usuario en Angular.

## Mapa de módulos

| Módulo | Ruta | Responsabilidad | README local |
| :--- | :--- | :--- | :--- |
| **Rust Engine Workspace** | `rust-engine/` | Espacio de trabajo virtual multi-crate para procesamiento multimedia | [rust-engine/README.md](rust-engine/README.md) |
| **reelforge-core** | `rust-engine/crates/reelforge-core/` | Tipos compartidos, modelos de dominio, máquina de estados y errores | [reelforge-core/README.md](rust-engine/crates/reelforge-core/README.md) |
| **reelforge-downloader** | `rust-engine/crates/reelforge-downloader/` | Ingesta de video vía yt-dlp, metadatos ffprobe y fixtures offline | [reelforge-downloader/README.md](rust-engine/crates/reelforge-downloader/README.md) |
| **reelforge-cutter** | `rust-engine/crates/reelforge-cutter/` | Transcodificación FFmpeg, composición vertical 9:16 y miniaturas | [reelforge-cutter/README.md](rust-engine/crates/reelforge-cutter/README.md) |
| **reelforge-server** | `rust-engine/crates/reelforge-server/` | Microservicio REST Axum, semáforo de concurrencia y webhooks | [reelforge-server/README.md](rust-engine/crates/reelforge-server/README.md) |
| **reelforge-cli** | `rust-engine/crates/reelforge-cli/` | Herramienta CLI independiente (`download`, `cut`, `probe`, `batch`) | [reelforge-cli/README.md](rust-engine/crates/reelforge-cli/README.md) |
| **Backend Core** | `backend/` | Orquestación general, persistencia PostgreSQL, colas y cliente Axum | [backend/README.md](backend/README.md) |
| **Videos Module** | `backend/app/Modules/Videos/` | Ingesta de video, validación de corte, ciclo de vida y webhooks | [Videos/README.md](backend/app/Modules/Videos/README.md) |
| **Clips Module** | `backend/app/Modules/Clips/` | Gestión de clips 9:16, recorte temporal, URLs públicas y almacenamiento | [Clips/README.md](backend/app/Modules/Clips/README.md) |
| **SocialAccounts Module** | `backend/app/Modules/SocialAccounts/` | Autenticación OAuth2, credenciales cifradas y mock connect | [SocialAccounts/README.md](backend/app/Modules/SocialAccounts/README.md) |
| **Publishing Module** | `backend/app/Modules/Publishing/` | Publicación multi-plataforma, adaptadores sociales y reintentos | [Publishing/README.md](backend/app/Modules/Publishing/README.md) |
| **Frontend SPA** | `frontend/` | Aplicación SPA en Angular 22 para gestión multimedia y publicación | [frontend/README.md](frontend/README.md) |
| **Frontend Core** | `frontend/src/app/core/` | Servicios singleton, modelos tipados, tokens e interceptor de errores | [frontend/src/app/core/README.md](frontend/src/app/core/README.md) |
| **Frontend Layout** | `frontend/src/app/layout/` | Cascarón de navegación responsivo, barra lateral y estado de servicios | [frontend/src/app/layout/README.md](frontend/src/app/layout/README.md) |
| **Frontend Shared** | `frontend/src/app/shared/` | Reproductor vertical 9:16, píldoras de estado, esqueletos y tuberías | [frontend/src/app/shared/README.md](frontend/src/app/shared/README.md) |
| **Dashboard Feature** | `frontend/src/app/features/dashboard/` | Panel de telemetría de KPIs y cronología de procesos de video | [frontend/src/app/features/dashboard/README.md](frontend/src/app/features/dashboard/README.md) |
| **Video Upload Feature** | `frontend/src/app/features/video-upload/` | Ingestión de URL, selección de estrategia 9:16 y barra de progreso | [frontend/src/app/features/video-upload/README.md](frontend/src/app/features/video-upload/README.md) |
| **Clips Feature** | `frontend/src/app/features/clips/` | Galería de clips 9:16, edición de recorte en línea y reproducción | [frontend/src/app/features/clips/README.md](frontend/src/app/features/clips/README.md) |
| **Social Accounts Feature** | `frontend/src/app/features/social-accounts/` | Gestión de canales TikTok, IG Reels, YouTube Shorts y modo mock | [frontend/src/app/features/social-accounts/README.md](frontend/src/app/features/social-accounts/README.md) |
| **Publish Feature** | `frontend/src/app/features/publish/` | Despacho multicanal, programación y tabla de auditoría con reintentos | [frontend/src/app/features/publish/README.md](frontend/src/app/features/publish/README.md) |
| **E2E Test Suite** | `tests/e2e/` | Suite unificada de pruebas de extremo a extremo Tiers 1-5 | [tests/e2e/README.md](tests/e2e/README.md) |

## Verificación

```bash
python3 tests/e2e/verify_agent_docs.py
cargo build --manifest-path rust-engine/Cargo.toml --workspace
cargo test --manifest-path rust-engine/Cargo.toml --workspace -- --test-threads=1
cd backend && php artisan test
cd backend && ./vendor/bin/pint --test
cd frontend && bun run build
cd frontend && bun run test -- --watch=false
./tests/e2e/test_runner.sh --tier 1
./tests/e2e/test_runner.sh
```
