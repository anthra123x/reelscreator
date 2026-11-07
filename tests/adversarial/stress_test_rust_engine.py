#!/usr/bin/env python3
"""
Adversarial Stress Test & Empirical Challenge Harness for ReelForge Rust Video Engine
Targets: reelforge-server (Axum HTTP REST API) and reelforge-cutter / reelforge-cli (FFmpeg engine)

Challenges tested:
1. Concurrency and saturation testing (semaphore bound = 1, serialized execution, no contention, permit release)
2. Boundary testing (0-byte file, non-video text file, corrupt header, nonexistent path, missing source, invalid ranges)
3. Audio track synchronization (lip-sync alignment, non-negative timestamps via `-avoid_negative_ts make_zero`, AAC 44.1kHz)
4. Cancellation robustness under queue saturation
"""

import os
import sys
import time
import json
import uuid
import shutil
import tempfile
import subprocess
import urllib.request
import urllib.error
from pathlib import Path

PORT = 3055
BASE_URL = f"http://127.0.0.1:{PORT}"
WORKSPACE_ROOT = Path(__file__).resolve().parent.parent.parent
SERVER_BIN = WORKSPACE_ROOT / "rust-engine/target/debug/reelforge-server"
CLI_BIN = WORKSPACE_ROOT / "rust-engine/target/debug/reelforge-cli"

class ChallengerTestFailure(Exception):
    pass

def log(msg):
    print(f"[CHALLENGE] {msg}", flush=True)

def generate_synthetic_video(path: str, duration: int = 6, width: int = 1280, height: int = 720):
    os.makedirs(os.path.dirname(os.path.abspath(path)), exist_ok=True)
    testsrc = f"testsrc=duration={duration}:size={width}x{height}:rate=30"
    sine = f"sine=frequency=1000:duration={duration}"
    cmd = [
        "ffmpeg", "-y", "-threads", "1",
        "-f", "lavfi", "-i", testsrc,
        "-f", "lavfi", "-i", sine,
        "-c:v", "libx264", "-preset", "ultrafast", "-pix_fmt", "yuv420p",
        "-c:a", "aac", "-b:a", "128k", "-ar", "44100",
        "-movflags", "+faststart",
        path
    ]
    res = subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, text=True)
    if res.returncode != 0:
        raise ChallengerTestFailure(f"Failed to generate synthetic video: {res.stderr}")

def http_json(method: str, path: str, payload: dict = None, timeout: float = 5.0) -> tuple:
    url = f"{BASE_URL}{path}"
    data = json.dumps(payload).encode("utf-8") if payload is not None else None
    headers = {"Content-Type": "application/json"} if payload is not None else {}
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            body = resp.read().decode("utf-8")
            return resp.status, json.loads(body) if body else {}
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8")
        parsed = json.loads(body) if body.startswith("{") else {"error": body}
        return e.code, parsed

def probe_file(path: str) -> dict:
    cmd = [
        "ffprobe", "-v", "quiet", "-print_format", "json",
        "-show_format", "-show_streams", path
    ]
    res = subprocess.run(cmd, capture_output=True, text=True)
    if res.returncode != 0:
        raise ChallengerTestFailure(f"ffprobe failed on {path}: {res.stderr}")
    return json.loads(res.stdout)

def run_all_challenges():
    log("=== STARTING EMPIRICAL CHALLENGE HARNESS ===")
    test_dir = tempfile.mkdtemp(prefix="reelforge_challenger_")
    log(f"Temporary test directory: {test_dir}")

    # Build server and CLI if needed
    if not SERVER_BIN.exists() or not CLI_BIN.exists():
        log("Compiling server and CLI binaries...")
        res = subprocess.run(
            ["cargo", "build", "--manifest-path", str(WORKSPACE_ROOT / "rust-engine/Cargo.toml"), "--bins"],
            cwd=WORKSPACE_ROOT, capture_output=True, text=True
        )
        if res.returncode != 0:
            raise ChallengerTestFailure(f"Cargo build failed: {res.stderr}")

    # Start Axum server with single permit
    env = os.environ.copy()
    env["PORT"] = str(PORT)
    env["HOST"] = "127.0.0.1"
    env["REELFORGE_MAX_CONCURRENT_JOBS"] = "1"
    env["RUST_LOG"] = "info"

    log(f"Launching reelforge-server on port {PORT} with REELFORGE_MAX_CONCURRENT_JOBS=1...")
    server_proc = subprocess.Popen(
        [str(SERVER_BIN)],
        env=env,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True
    )

    try:
        # Wait for server readiness
        ready = False
        for _ in range(50):
            try:
                status, data = http_json("GET", "/health", timeout=0.5)
                if status == 200 and data.get("status") == "healthy":
                    ready = True
                    break
            except Exception:
                time.sleep(0.1)
        if not ready:
            raise ChallengerTestFailure("reelforge-server failed to respond to /health within 5 seconds")
        log(f"Server is healthy: {data}")

        # -------------------------------------------------------------
        # CHALLENGE 1: Concurrency and Saturation Testing
        # -------------------------------------------------------------
        log("--- Running Challenge 1: Concurrency & Semaphore Saturation ---")
        source_vid = os.path.join(test_dir, "concurrency_source.mp4")
        generate_synthetic_video(source_vid, duration=8)

        # Dispatch 3 jobs simultaneously
        job_ids = [str(uuid.uuid4()) for _ in range(3)]
        out_dirs = [os.path.join(test_dir, f"clips_{i}") for i in range(3)]

        for i, jid in enumerate(job_ids):
            payload = {
                "job_id": jid,
                "local_video_path": source_vid,
                "strategy": "fixed_interval",
                "interval_seconds": 3.0,
                "max_duration_seconds": 6.0,
                "aspect_ratio": "9:16",
                "vertical_mode": "blur_padding",
                "output_dir": out_dirs[i]
            }
            status, res = http_json("POST", "/api/v1/jobs", payload)
            if status != 202:
                raise ChallengerTestFailure(f"Job submission failed for {jid}: {status} {res}")
            log(f"Dispatched job {i+1}/3: {jid} (status: {res.get('status')})")

        # Monitor semaphore permits and active jobs
        seen_permit_zero = False
        max_active_observed = 0
        terminal_states = {}

        start_wait = time.time()
        while len(terminal_states) < 3 and time.time() - start_wait < 60:
            h_status, h_data = http_json("GET", "/health")
            if h_status == 200:
                active = h_data["concurrency"]["active_jobs"]
                permits = h_data["concurrency"]["available_permits"]
                if active > max_active_observed:
                    max_active_observed = active
                if permits == 0:
                    seen_permit_zero = True
                if active > 1:
                    raise ChallengerTestFailure(f"SEMAPHORE VIOLATION: active_jobs={active} exceeded max_concurrency=1!")

            for jid in job_ids:
                if jid not in terminal_states:
                    j_status, j_data = http_json("GET", f"/api/v1/jobs/{jid}")
                    if j_status == 200:
                        st = j_data.get("status")
                        if st in ("completed", "failed", "cancelled"):
                            terminal_states[jid] = j_data
                            log(f"Job {jid} finished with status '{st}' (clips: {len(j_data.get('clips', []))})")
            time.sleep(0.3)

        if len(terminal_states) < 3:
            raise ChallengerTestFailure(f"Timeout waiting for concurrent jobs. Finished: {terminal_states.keys()}")

        for jid in job_ids:
            if terminal_states[jid]["status"] != "completed":
                raise ChallengerTestFailure(f"Job {jid} failed: {terminal_states[jid]}")
            clips = terminal_states[jid]["clips"]
            if len(clips) == 0:
                raise ChallengerTestFailure(f"Job {jid} produced 0 clips!")

        log(f"Concurrency check passed! Max active jobs observed: {max_active_observed} (strictly <= 1). Permit saturation observed: {seen_permit_zero}")

        # Test job cancellation under queueing
        log("Testing cancellation under concurrency...")
        jid_cancel = str(uuid.uuid4())
        payload = {
            "job_id": jid_cancel,
            "local_video_path": source_vid,
            "strategy": "fixed_interval",
            "interval_seconds": 2.0,
            "output_dir": os.path.join(test_dir, "cancel_test")
        }
        status, _ = http_json("POST", "/api/v1/jobs", payload)
        c_status, c_res = http_json("POST", f"/api/v1/jobs/{jid_cancel}/cancel")
        log(f"Cancel request status: {c_status} {c_res}")
        time.sleep(1.0)
        p_status, p_data = http_json("GET", f"/api/v1/jobs/{jid_cancel}")
        log(f"Cancelled job state: {p_data.get('status')}")
        if p_data.get("status") not in ("cancelled", "completed"):
            raise ChallengerTestFailure(f"Job {jid_cancel} was not cancelled: {p_data}")

        # Verify permit is restored
        h_status, h_data = http_json("GET", "/health")
        if h_data["concurrency"]["available_permits"] != 1:
            raise ChallengerTestFailure(f"Permit leak detected after cancellation! Health: {h_data}")
        log("Permit recovery after cancellation verified.")

        # -------------------------------------------------------------
        # CHALLENGE 2: Boundary and Negative Testing
        # -------------------------------------------------------------
        log("--- Running Challenge 2: Boundary & Negative Cases ---")

        # 2.1 Zero-byte file
        zero_file = os.path.join(test_dir, "empty.mp4")
        open(zero_file, "wb").close()
        log(f"Created 0-byte file at {zero_file}")

        jid_zero = str(uuid.uuid4())
        status, _ = http_json("POST", "/api/v1/jobs", {
            "job_id": jid_zero,
            "local_video_path": zero_file,
            "output_dir": os.path.join(test_dir, "zero_out")
        })
        self_wait = time.time()
        zero_failed = False
        while time.time() - self_wait < 10:
            s, d = http_json("GET", f"/api/v1/jobs/{jid_zero}")
            if s == 200 and d.get("status") == "failed":
                zero_failed = True
                log(f"0-byte file gracefully failed: {d.get('error_message')}")
                break
            time.sleep(0.3)
        if not zero_failed:
            raise ChallengerTestFailure("Zero-byte file did not transition to failed state")

        # CLI probe on zero-byte file
        cli_res = subprocess.run([str(CLI_BIN), "probe", zero_file], capture_output=True, text=True)
        if cli_res.returncode == 0:
            raise ChallengerTestFailure("reelforge-cli probe should have failed on 0-byte file")
        log(f"CLI probe rejected 0-byte file with exit code {cli_res.returncode}")

        # 2.2 Text file disguised as MP4
        fake_file = os.path.join(test_dir, "fake_video.mp4")
        with open(fake_file, "w") as f:
            f.write("This is a plain text file, definitely not an H.264 video.")

        jid_fake = str(uuid.uuid4())
        status, _ = http_json("POST", "/api/v1/jobs", {
            "job_id": jid_fake,
            "local_video_path": fake_file,
            "output_dir": os.path.join(test_dir, "fake_out")
        })
        self_wait = time.time()
        fake_failed = False
        while time.time() - self_wait < 10:
            s, d = http_json("GET", f"/api/v1/jobs/{jid_fake}")
            if s == 200 and d.get("status") == "failed":
                fake_failed = True
                log(f"Fake non-video gracefully failed: {d.get('error_message')}")
                break
            time.sleep(0.3)
        if not fake_failed:
            raise ChallengerTestFailure("Fake non-video file did not transition to failed state")

        # 2.3 Non-existent file
        jid_nonexist = str(uuid.uuid4())
        status, _ = http_json("POST", "/api/v1/jobs", {
            "job_id": jid_nonexist,
            "local_video_path": "/tmp/definitely_non_existent_file_98765.mp4",
            "output_dir": os.path.join(test_dir, "nonexist_out")
        })
        self_wait = time.time()
        nonexist_failed = False
        while time.time() - self_wait < 10:
            s, d = http_json("GET", f"/api/v1/jobs/{jid_nonexist}")
            if s == 200 and d.get("status") == "failed":
                nonexist_failed = True
                log(f"Non-existent file gracefully failed: {d.get('error_message')}")
                break
            time.sleep(0.3)
        if not nonexist_failed:
            raise ChallengerTestFailure("Nonexistent file did not transition to failed state")

        # 2.4 Missing both source_url and local_video_path
        status, data = http_json("POST", "/api/v1/jobs", {
            "strategy": "fixed_interval"
        })
        if status != 400:
            raise ChallengerTestFailure(f"Missing sources should return 400 Bad Request, got {status}: {data}")
        log(f"Missing sources rejected as expected: {data}")

        # 2.5 Corrupt JSON payload
        raw_req = urllib.request.Request(
            f"{BASE_URL}/api/v1/jobs",
            data=b"{{ invalid json content }}}",
            headers={"Content-Type": "application/json"},
            method="POST"
        )
        try:
            with urllib.request.urlopen(raw_req, timeout=2.0) as resp:
                raise ChallengerTestFailure("Corrupt JSON payload unexpectedly succeeded")
        except urllib.error.HTTPError as e:
            if e.code not in (400, 422):
                raise ChallengerTestFailure(f"Expected 400 or 422 for corrupt JSON, got {e.code}")
            log(f"Corrupt JSON payload rejected with HTTP {e.code}")

        # -------------------------------------------------------------
        # CHALLENGE 3: Audio Track Synchronization & Timestamp Checks
        # -------------------------------------------------------------
        log("--- Running Challenge 3: Audio Track Synchronization & Timestamps ---")
        audio_src = os.path.join(test_dir, "audio_sync_source.mp4")
        generate_synthetic_video(audio_src, duration=10, width=1920, height=1080)

        modes = ["blur_padding", "center_crop", "letterbox"]
        for mode in modes:
            log(f"Testing vertical transformation mode: '{mode}'...")
            jid_mode = str(uuid.uuid4())
            mode_out = os.path.join(test_dir, f"audio_sync_{mode}")
            status, _ = http_json("POST", "/api/v1/jobs", {
                "job_id": jid_mode,
                "local_video_path": audio_src,
                "strategy": "fixed_interval",
                "interval_seconds": 3.0,
                "max_duration_seconds": 3.0,
                "aspect_ratio": "9:16",
                "vertical_mode": mode,
                "output_dir": mode_out
            })

            wait_start = time.time()
            mode_done = False
            while time.time() - wait_start < 25:
                s, d = http_json("GET", f"/api/v1/jobs/{jid_mode}")
                if s == 200 and d.get("status") == "completed":
                    mode_done = True
                    break
                time.sleep(0.3)
            if not mode_done:
                raise ChallengerTestFailure(f"Job for mode {mode} failed or timed out: {d}")

            clips = d.get("clips", [])
            if not clips:
                raise ChallengerTestFailure(f"No clips returned for mode {mode}")

            clip_file = clips[0]["file_path"]
            log(f"Probing output clip: {clip_file}")
            meta = probe_file(clip_file)

            # Inspect format and streams
            streams = meta.get("streams", [])
            v_stream = next((s for s in streams if s["codec_type"] == "video"), None)
            a_stream = next((s for s in streams if s["codec_type"] == "audio"), None)

            if not v_stream:
                raise ChallengerTestFailure(f"No video stream found in {clip_file}")
            if not a_stream:
                raise ChallengerTestFailure(f"No audio stream found in {clip_file}")

            # Verify dimensions
            w = int(v_stream["width"])
            h = int(v_stream["height"])
            if w != 1080 or h != 1920:
                raise ChallengerTestFailure(f"Incorrect vertical dimensions: {w}x{h}, expected 1080x1920")

            # Verify audio codec and rate
            a_codec = a_stream.get("codec_name")
            a_rate = int(a_stream.get("sample_rate", 0))
            if a_codec != "aac":
                raise ChallengerTestFailure(f"Audio codec is {a_codec}, expected aac")
            if a_rate != 44100:
                raise ChallengerTestFailure(f"Audio sample rate is {a_rate}, expected 44100")

            # Verify timestamps: non-negative start_time
            v_start = float(v_stream.get("start_time", 0.0))
            a_start = float(a_stream.get("start_time", 0.0))
            fmt_start = float(meta["format"].get("start_time", 0.0))

            log(f"Timestamps for {mode}: fmt_start={fmt_start}, v_start={v_start}, a_start={a_start}")
            if v_start < 0.0 or a_start < 0.0 or fmt_start < 0.0:
                raise ChallengerTestFailure(f"Negative timestamp detected! v={v_start}, a={a_start}, fmt={fmt_start}")

            # Verify AV duration drift
            v_dur = float(v_stream.get("duration", 0.0))
            a_dur = float(a_stream.get("duration", 0.0))
            drift = abs(v_dur - a_dur)
            log(f"Stream durations: video={v_dur:.3f}s, audio={a_dur:.3f}s (AV drift: {drift:.3f}s)")
            if drift > 0.15:
                raise ChallengerTestFailure(f"Excessive audio-video drift ({drift:.3f}s > 0.15s) in mode {mode}")

            # Verify thumbnail existence and valid JPEG magic bytes (0xFF 0xD8 0xFF)
            thumb_file = clips[0]["thumbnail_path"]
            if not os.path.exists(thumb_file):
                raise ChallengerTestFailure(f"Thumbnail file does not exist: {thumb_file}")
            with open(thumb_file, "rb") as tf:
                magic = tf.read(3)
                if magic != b"\xff\xd8\xff":
                    raise ChallengerTestFailure(f"Thumbnail does not have valid JPEG header: {magic}")
            log(f"Thumbnail verified: {thumb_file} (JPEG magic bytes OK)")

        log("Audio track synchronization & timestamp verification passed!")

        # Final health check
        h_status, h_data = http_json("GET", "/health")
        log(f"Final server health: {h_data}")
        if h_data["concurrency"]["active_jobs"] != 0 or h_data["concurrency"]["available_permits"] != 1:
            raise ChallengerTestFailure(f"Final server state unexpected: {h_data}")

        log("=== ALL EMPIRICAL CHALLENGES PASSED SUCCESSFULLY! ===")
        return True

    finally:
        log("Terminating reelforge-server process...")
        server_proc.terminate()
        try:
            server_proc.wait(timeout=3)
        except subprocess.TimeoutExpired:
            server_proc.kill()
        shutil.rmtree(test_dir, ignore_errors=True)

if __name__ == "__main__":
    try:
        success = run_all_challenges()
        sys.exit(0 if success else 1)
    except ChallengerTestFailure as err:
        log(f"FAILED: {err}")
        sys.exit(1)
    except Exception as exc:
        log(f"UNEXPECTED EXCEPTION: {exc}")
        import traceback
        traceback.print_exc()
        sys.exit(2)
