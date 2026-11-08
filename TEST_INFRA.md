# ReelForge: End-to-End Testing Infrastructure & Architecture Specification

## 1. Testing Philosophy & Principles

ReelForge enforces an opaque-box, requirement-driven testing philosophy designed to guarantee reliability, contract stability, media fidelity, and production readiness across all technology pillars (Rust video engine, modular Laravel backend, social media publishers, and Angular frontend).

### 1.1 Core Principles
1. **Opaque-Box Requirement Verification**: Tests interact exclusively with public contracts, standard CLI entrypoints, HTTP REST endpoints, mock social adapters, and filesystem media outputs. Tests never couple to private internal helper classes or implementation details.
2. **Deterministic & Isolated Execution**: Every test sets up its own temporary environment, creates deterministic synthetic media fixtures where needed, and guarantees cleanup. No test depends on the side effects of another.
3. **No External Network Dependencies**: All video downloads and social media publishing interactions are fully testable offline using dynamic FFmpeg test fixtures (`testsrc`, `sine`), local file references, and pluggable mock publishing adapters (`MockSocialAdapter`).
4. **Single-Core Resource Awareness**: In alignment with host constraints (`nproc = 1`), test fixtures and transcoding harnesses enforce single-threaded FFmpeg execution (`-threads 1`) and bounded concurrency to prevent CPU starvation.
5. **Strict Media Validation**: Transcoded clips are verified not merely for file existence, but for physical media properties via `ffprobe` (1080x1920 vertical resolution, 9:16 aspect ratio, H.264 video codec, AAC audio codec, keyframe thumbnail validity).
6. **Progressive Testability**: Tests run reliably across development, isolated CI/CD environments, and against live production endpoints via configurable target modes (`auto`, `live`, `mock`).

---

## 2. Feature Inventory & Test Tier Architecture

The test suite is structured into five sequential, cumulative tiers:

```
tests/e2e/
├── test_runner.sh                              # Unified executable test orchestrator
├── README.md                                   # Agent local documentation (8 mandatory sections)
├── verify_agent_docs.py                        # Automated documentation and exclusion verification
├── common/                                     # Shared testing harnesses & utilities
│   ├── contracts.py                            # Interface contract schemas & validation logic
│   ├── media_generator.py                      # Synthetic video, custom filtergraphs & corrupt fixture generation
│   ├── media_validator.py                      # Media stream analysis and ffprobe inspection
│   └── mock_server.py                          # Ephemeral reference mock server & adapter oracle
├── tier1_features/                             # Tier 1: Feature unit and contract coverage (>= 5 tests/feature)
│   ├── test_video_decomposition.py             # Autonomous decomposition (POST /api/videos/{id}/decompose, /decompose)
│   ├── test_916_geometry.py                    # 9:16 vertical geometry (1080x1920, blur_padding, center_crop, letterbox)
│   ├── test_auto_dispatch.py                   # Auto-dispatch (auto_publish=true, batch clips, active channel discovery)
│   ├── test_social_accounts_connection.py      # Social connection (POST /api/social-accounts/connect, auto|env|mock|direct)
│   ├── test_dashboard_metrics.py               # Dashboard metrics (totalVideos, totalClips, connectedAccounts, publications)
│   ├── test_rust_cli.py                        # CLI commands: help, version, probe, cut, download, batch
│   ├── test_rust_axum_api.py                   # Axum REST endpoints: /health, job dispatch, status, concurrency
│   ├── test_laravel_api.py                     # Laravel REST endpoints: video ingest, status, clips, publishing
│   └── test_mock_social_adapter.py             # MockSocialAdapter: platforms, publishing, validation, token refresh
├── tier2_boundaries/                           # Tier 2: Boundary, edge case, and negative tests (>= 5 tests/feature)
│   ├── test_decomposition_boundaries.py        # 404 nonexistent IDs, 422 missing URLs, bad protocols, negative intervals
│   ├── test_duration_and_platform_limits.py    # YouTube Shorts <=60s cap, Instagram Reels <=90s cap, minimum <1s duration
│   ├── test_social_connection_boundaries.py    # Expired tokens, duplicate connections, invalid modes, missing direct tokens
│   ├── test_dashboard_boundaries.py            # Empty state metrics, rapid concurrent polling, query parameter sanitization
│   ├── test_video_boundaries.py                # Zero-byte files, inverted timestamps, corrupt headers, bad URLs
│   └── test_backend_boundaries.py              # Expired OAuth tokens, platform duration limits, 404s, auth rejection
├── tier3_combinations/                         # Tier 3: Cross-feature integration scenarios (Pairwise interactions)
│   ├── test_autonomous_pipeline_combinations.py# Decomposition -> Auto-Dispatch -> Multi-Platform Publication -> Audit Logs
│   └── test_cross_feature.py                   # Slicing + 9:16 + thumbs, multi-platform publishing, client pipeline
├── tier4_real_world/                           # Tier 4: Real-world end-to-end workflows (Production scenarios)
│   ├── test_unattended_production_workflow.py  # Multi-clip unattended production, dashboard lifecycle, batch isolation
│   └── test_real_world_pipeline.py             # Dynamic synthetic video, vertical blur transcoding, ffprobe, publishing
└── tier5_adversarial/                          # Tier 5: Adversarial hardening & stress verification
    ├── test_adversarial_cli.py                 # Hostile flags, malformed JSON manifests, zero-byte inputs
    └── test_adversarial_rest_pipeline.py       # Rapid sequential bursts, broken JSON syntax, SQLi/emojis, UUID validation
```

### 2.1 Tier Overview

| Tier | Focus | Scope | Min Tests | Executed Tests | Key Verifications |
|------|-------|-------|-----------|----------------|-------------------|
| **Tier 1** | Feature Coverage | Decomposition, 9:16 Geometry, Auto-Dispatch, Social Connect, Dashboard Metrics, Rust CLI/API, Laravel REST | ≥ 25 (5 per core feature) | **45** | REST status codes, JSON payload schemas, 1080x1920 clip entities, 1-click connect, KPI metrics |
| **Tier 2** | Boundaries & Negatives | Platform duration limits, expired tokens, corrupt media, invalid parameters, empty states | ≥ 25 (5 per feature area) | **30** | YouTube Shorts 60s cap, Instagram Reels 90s cap, zero-byte rejection, duplicate idempotency, 404/422 handling |
| **Tier 3** | Cross-Feature Scenarios | Pairwise interactions across subsystems | ≥ 5 | **10** | Decomposition -> auto-dispatch -> multi-channel publishing -> audit logs; token expiry resilience |
| **Tier 4** | Real-World Workflows | Multi-clip unattended production flows | ≥ 5 | **10** | Synthetic 16:9 master -> 9:16 vertical reels -> ffprobe stream validation -> multi-channel publishing |
| **Tier 5** | Adversarial Hardening | Extreme stress, bursts, injection | ≥ 10 | **15** | Rapid 25-request bursts, broken syntax, SQLi/XSS titles, extreme timestamps, panic-free CLI |
| **TOTAL** | **Full E2E Suite** | **All Technology Pillars** | **≥ 70** | **110** | **Complete end-to-end requirement and production readiness verification** |

---

## 3. Test Runner Invocation & CLI Interface

The test suite is driven by `/home/omicron/Documentos/creador de reels/tests/e2e/test_runner.sh`.

### 3.1 Basic Usage
```bash
# Run all test tiers (Tier 1 through Tier 5)
./tests/e2e/test_runner.sh

# Run a specific tier
./tests/e2e/test_runner.sh --tier 1
./tests/e2e/test_runner.sh --tier 2
./tests/e2e/test_runner.sh --tier 3
./tests/e2e/test_runner.sh --tier 4
./tests/e2e/test_runner.sh --tier 5

# Run against live running services (Axum on :3000, Laravel on :8000)
./tests/e2e/test_runner.sh --mode live

# Run with verbose output and test trace logs
./tests/e2e/test_runner.sh --verbose

# Produce structured JSON report
./tests/e2e/test_runner.sh --json-report /tmp/reelforge_e2e_results.json
```

### 3.2 Command-Line Options
| Flag | Description | Default |
|------|-------------|---------|
| `--all` | Run all test tiers sequentially | Active by default if no tier specified |
| `--tier <1-5>` | Execute only the specified tier | None |
| `--mode <auto\|live\|mock>` | Target environment (`live` connects to daemons, `mock` uses local oracle) | `auto` |
| `--keep-artifacts` | Retain generated test video fixtures in temporary directory | False (auto-cleaned) |
| `--verbose` | Output detailed assertion and sub-command traces | False |
| `--json-report <path>` | Write machine-readable JSON results to target path | None |
| `--help` | Display usage and available options | - |

### 3.3 Exit Codes
- `0`: All executed test cases passed successfully.
- `1`: One or more test cases failed or threw assertion errors.
- `2`: Configuration or environment setup error (e.g., missing required binaries).

---

## 4. Test Infrastructure Architecture

### 4.1 Common Test Utilities (`tests/e2e/common/`)

#### 1. `media_generator.py`
Provides synthetic fixture generation using local FFmpeg filters:
- `generate_synthetic_video(duration=10, width=1920, height=1080, fps=30)`: Generates test video with color test pattern (`testsrc`) and sine audio tone (`sine=frequency=1000`).
- `generate_zero_byte_file(path)`: Creates empty 0-byte file for boundary verification.
- `generate_corrupt_video(path)`: Creates file with broken MP4 atom headers to test parser resilience.
- `generate_vertical_video(duration=5, width=1080, height=1920)`: Generates native 9:16 vertical test fixture.
- `transcode_to_vertical_blur(input, output, start, duration)`: Reference 9:16 vertical blur-padding filtergraph.
- `transcode_with_filtergraph(input, output, filtergraph, start, duration)`: Custom filtergraph transcoding (CenterCrop, Letterbox).
- `generate_thumbnail_image(output, width=1080, height=1920, color="blue")`: Generates JPEG thumbnail image.

#### 2. `media_validator.py`
Inspects media files using `ffprobe` JSON output:
- `probe_media(file_path)`: Returns structured metadata (container format, streams, bitrates).
- `probe_video(file_path)`: Returns parsed video and audio metadata (width, height, aspect_ratio, codecs, duration).
- `probe_image(file_path)`: Returns parsed image metadata (width, height, codec).
- `assert_vertical_9_16(file_path)`: Asserts width is 1080, height is 1920, display aspect ratio is 9:16.
- `assert_video_codec(file_path, expected="h264")`: Asserts primary video stream codec.
- `assert_audio_codec(file_path, expected="aac")`: Asserts audio stream exists with specified codec.
- `assert_duration_within(file_path, expected, tolerance=0.5)`: Asserts clip duration matches target range.
- `assert_valid_jpeg(file_path, expected_width=1080, expected_height=1920)`: Probes thumbnail image validity.

#### 3. `contracts.py`
Formal schemas and validation logic for interface contracts defined in `PROJECT.md` and `ORIGINAL_REQUEST.md`:
- `validate_video_decomposition_response`: Validates `POST /api/videos/{id}/decompose` and `/decompose` response.
- `validate_social_accounts_connect_response`: Validates `POST /api/social-accounts/connect` response.
- `validate_dashboard_metrics_response`: Validates aggregated dashboard KPI object.
- `validate_axum_job_accepted_response`: Validates Axum `POST /api/v1/jobs` `202 Accepted` response.
- `validate_axum_job_completed_response`: Validates Axum completed job payload with 1080x1920 clip entities.
- `validate_axum_health_response`: Validates Axum `GET /health` concurrency schema.
- `validate_laravel_video_created_response`: Validates Laravel `POST /api/videos` response.
- `validate_laravel_clips_response`: Validates Laravel `GET /api/videos/:id/clips` response.
- `validate_laravel_publish_accepted_response`: Validates Laravel publication dispatch response.
- `validate_mock_social_publish_result`: Validates `PublishResult` schema across TikTok, Instagram, and YouTube.

#### 4. `mock_server.py`
An embedded HTTP mock server implementing the full Axum and Laravel REST API contract specifications:
- Manages thread-safe in-memory state for jobs, videos, clips, social accounts, and publications.
- Implements `POST /api/videos/{id}/decompose`, `POST /api/videos/decompose`, `POST /api/social-accounts/connect`, `GET /api/dashboard/metrics`.
- Implements platform duration constraints: YouTube Shorts <= 60.0s, Instagram Reels <= 90.0s, TikTok <= 180.0s, Minimum >= 1.0s.
- Enables self-contained, deterministic testing when running offline or prior to full daemon bootstrapping.
- Automatically yields to live daemons when available in `live` mode.

---

## 5. Coverage Thresholds & Quality Gates

The E2E test suite adheres to strict quality thresholds:

1. **Pass Rate**: 100% pass rate across all active test cases. Zero skipped or unhandled failures permitted.
2. **Feature Coverage**:
   - Video Decomposition: ≥ 5 test cases (Tier 1) + ≥ 5 boundary test cases (Tier 2).
   - 9:16 Aspect Ratio Geometry: ≥ 5 test cases (Tier 1).
   - Auto-Dispatch & Multi-Channel Publishing: ≥ 5 test cases (Tier 1) + ≥ 5 boundary test cases (Tier 2).
   - Social Accounts Connection: ≥ 5 test cases (Tier 1) + ≥ 5 boundary test cases (Tier 2).
   - Dashboard Metrics: ≥ 5 test cases (Tier 1) + ≥ 5 boundary test cases (Tier 2).
   - Rust Video Engine CLI & API: ≥ 10 test cases (5 CLI + 5 REST API).
   - Cross-Feature Combinations: ≥ 10 test scenarios.
   - Real-World Unattended Production Pipeline: ≥ 10 end-to-end verification stages.
   - Adversarial Hardening: ≥ 15 test cases.
3. **Media Conformance**: All generated vertical clips must pass strict `ffprobe` verification (1080x1920, H.264, AAC).
4. **Execution Performance**: Full test suite execution (110 tests) must complete within ~80 seconds on single-core host CPU using synthetic fixtures.
