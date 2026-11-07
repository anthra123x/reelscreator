# ReelForge: End-to-End Testing Infrastructure & Architecture Specification

## 1. Testing Philosophy & Principles

ReelForge enforces an opaque-box, requirement-driven testing philosophy designed to guarantee reliability, contract stability, and media fidelity across all technology pillars (Rust video engine, modular Laravel backend, and modern Angular frontend).

### 1.1 Core Principles
1. **Opaque-Box Requirement Verification**: Tests interact exclusively with public contracts, standard CLI entrypoints, HTTP REST endpoints, mock social adapters, and filesystem media outputs. Tests never couple to private internal helper classes or implementation details.
2. **Deterministic & Isolated Execution**: Every test sets up its own temporary environment, creates deterministic synthetic media fixtures where needed, and guarantees cleanup. No test depends on the side effects of another.
3. **No External Network Dependencies**: All video downloads and social media publishing interactions are fully testable offline using dynamic FFmpeg test fixtures (`testsrc`, `sine`), local file references, and pluggable mock publishing adapters (`MockSocialAdapter`).
4. **Single-Core Resource Awareness**: In alignment with host constraints (`nproc = 1`), test fixtures and transcoding harnesses enforce single-threaded FFmpeg execution (`-threads 1`) and bounded concurrency to prevent CPU starvation.
5. **Strict Media Validation**: Transcoded clips are verified not merely for file existence, but for physical media properties via `ffprobe` (1080x1920 vertical resolution, 9:16 aspect ratio, H.264 video codec, AAC audio codec, keyframe thumbnail validity).

---

## 2. Feature Inventory & Test Tier Architecture

The test suite is structured into four sequential, cumulative tiers:

```
tests/e2e/
├── test_runner.sh                  # Unified executable test orchestrator
├── README.md                       # Agent local documentation (8 mandatory sections)
├── common/                         # Shared testing harnesses & utilities
│   ├── contracts.py                # Interface contract schemas & validation logic
│   ├── media_generator.py          # Synthetic video and corrupt fixture generation via FFmpeg
│   ├── media_validator.py          # Media stream analysis and ffprobe inspection
│   └── mock_server.py              # Ephemeral reference mock server for offline verification
├── tier1_features/                 # Tier 1: Feature unit and contract coverage (>= 5 tests/feature)
│   ├── test_rust_cli.py            # CLI commands: help, version, probe, cut, download, batch
│   ├── test_rust_axum_api.py       # Axum REST endpoints: /health, job dispatch, status, concurrency
│   ├── test_laravel_api.py         # Laravel REST endpoints: video ingest, status, clips, publishing
│   └── test_mock_social_adapter.py # MockSocialAdapter: platforms, publishing, validation, token refresh
├── tier2_boundaries/               # Tier 2: Boundary, edge case, and negative tests
│   ├── test_video_boundaries.py    # Zero-byte files, inverted timestamps, corrupt headers, bad URLs
│   └── test_backend_boundaries.py  # Expired OAuth tokens, duration limits, 404s, auth rejection
├── tier3_combinations/             # Tier 3: Cross-feature integration scenarios
│   └── test_cross_feature.py       # Slicing + 9:16 + thumbs, multi-platform publishing, client pipeline
└── tier4_real_world/               # Tier 4: Real-world end-to-end workflows
    └── test_real_world_pipeline.py # Synthetic video generation, vertical transcoding, ffprobe, publishing
```

### 2.1 Tier Overview

| Tier | Focus | Scope | Min Tests | Key Verifications |
|------|-------|-------|-----------|-------------------|
| **Tier 1** | Feature Coverage | Crate CLI/API, Backend REST, Mock Adapters | ≥ 20 (5 per feature) | CLI commands, REST status codes, JSON payload schemas, mock publishing results |
| **Tier 2** | Boundaries & Negatives | Edge inputs, corrupt files, invalid state | ≥ 10 | Zero-byte rejection, out-of-bounds timestamps, malformed URLs, token expiry, platform duration limits |
| **Tier 3** | Cross-Feature Scenarios | Integration flows across boundaries | ≥ 5 | Multi-platform publishing fan-out, video slicing + vertical padding + thumbnailing, ingestion-to-clip pipeline |
| **Tier 4** | Real-World Workflows | Full production simulation | ≥ 5 | Dynamic FFmpeg testsrc generation, 1080x1920 vertical MP4 transcoding, ffprobe stream validation, mock publishing logs |

---

## 3. Test Runner Invocation & CLI Interface

The test suite is driven by `/home/omicron/Documentos/creador de reels/tests/e2e/test_runner.sh`.

### 3.1 Basic Usage
```bash
# Run all test tiers (Tier 1 through Tier 4)
./tests/e2e/test_runner.sh

# Run a specific tier
./tests/e2e/test_runner.sh --tier 1
./tests/e2e/test_runner.sh --tier 2
./tests/e2e/test_runner.sh --tier 3
./tests/e2e/test_runner.sh --tier 4

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
| `--tier <1-4>` | Execute only the specified tier | None |
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

#### 2. `media_validator.py`
Inspects media files using `ffprobe` JSON output:
- `probe_media(file_path)`: Returns structured metadata (container format, streams, bitrates).
- `assert_vertical_9_16(file_path)`: Asserts width is 1080, height is 1920, display aspect ratio is 9:16.
- `assert_video_codec(file_path, expected="h264")`: Asserts primary video stream codec.
- `assert_audio_codec(file_path, expected="aac")`: Asserts audio stream exists with specified codec.
- `assert_duration_within(file_path, expected, tolerance=0.5)`: Asserts clip duration matches target range.
- `assert_valid_jpeg(file_path, expected_width=1080, expected_height=1920)`: Probes thumbnail image validity.

#### 3. `contracts.py`
Formal schemas and validation logic for interface contracts defined in `PROJECT.md`:
- Axum `POST /api/v1/jobs` request and `202 Accepted` response.
- Axum `GET /api/v1/jobs/:id` completed response schema with `clips` array.
- Axum `GET /health` response schema with concurrency indicators.
- Laravel `POST /api/videos` and `GET /api/videos/:id/clips` schemas.
- Social publishing request and `MockSocialAdapter` result schemas.

#### 4. `mock_server.py`
An embedded HTTP mock server implementing the full Axum and Laravel REST API contract specifications. Enables self-contained, deterministic testing when running offline or prior to full daemon bootstrapping. Automatically yields to live daemons when available in `live` mode.

---

## 5. Coverage Thresholds & Quality Gates

The E2E test suite adheres to strict quality thresholds:

1. **Pass Rate**: 100% pass rate across all active test cases. Zero skipped or unhandled failures permitted.
2. **Feature Coverage**:
   - Rust Video Engine CLI & API: ≥ 10 test cases (5 CLI + 5 REST API).
   - Laravel Backend REST API: ≥ 5 test cases.
   - Social Publisher & Mock Adapter: ≥ 5 test cases.
   - Boundary & Negative Cases: ≥ 10 test cases.
   - Cross-Feature Combinations: ≥ 5 test scenarios.
   - Real-World Synthetic Pipeline: ≥ 5 end-to-end verification stages.
3. **Media Conformance**: All generated vertical clips must pass strict `ffprobe` verification (1080x1920, H.264, AAC).
4. **Execution Performance**: Full test suite execution must complete within 60 seconds on single-core host CPU using synthetic fixtures.
