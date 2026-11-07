use reelforge_core::{validate_path_security, CoreError, Result};
use std::path::{Path, PathBuf};
use tokio::process::Command;
use tracing::info;

/// Validate that a local path exists and resides within authorized application or scratch roots.
pub fn validate_local_media_boundary(path: &Path) -> Result<PathBuf> {
    let canonical = match path.canonicalize() {
        Ok(c) => c,
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => {
            return Err(CoreError::FileNotFound(path.to_path_buf()));
        }
        Err(e) => {
            return Err(CoreError::ValidationError(format!(
                "Failed to canonicalize path {:?}: {}", path, e
            )));
        }
    };

    // Run security check on canonical string
    reelforge_core::validate_path_security(&canonical.to_string_lossy(), "local_media_path")?;

    // Allowlist boundary check: must be inside workspace, media root, or temp scratch root
    let cwd = std::env::current_dir().unwrap_or_else(|_| PathBuf::from("."));
    let canonical_cwd = cwd.canonicalize().unwrap_or(cwd);
    let temp_root = std::env::temp_dir().canonicalize().unwrap_or_else(|_| std::env::temp_dir());

    let mut allowed_roots = vec![canonical_cwd, temp_root];
    if let Ok(media_root) = std::env::var("REELFORGE_MEDIA_ROOT") {
        if let Ok(canon_media) = PathBuf::from(&media_root).canonicalize() {
            allowed_roots.push(canon_media);
        }
    }

    let is_within_allowed = allowed_roots.iter().any(|root| canonical.starts_with(root));
    if !is_within_allowed {
        return Err(CoreError::ValidationError(format!(
            "Path {:?} resolves outside authorized media boundaries ({:?})",
            path, canonical
        )));
    }

    Ok(canonical)
}

pub fn is_offline_or_test_url(url: &str) -> bool {
    if url.starts_with("test://")
        || url.starts_with("mock://")
        || url.starts_with("fixture://")
        || std::env::var("REELFORGE_MOCK_DOWNLOAD")
            .map(|v| v == "1" || v == "true")
            .unwrap_or(false)
    {
        return true;
    }

    if url.len() >= 7 && url[..7].eq_ignore_ascii_case("file://") {
        let path_str = &url[7..];
        if validate_path_security(path_str, "file_url").is_err() {
            return false;
        }
        let p = Path::new(path_str);
        if p.exists() {
            return validate_local_media_boundary(p).is_ok();
        }
        return true;
    }

    if !url.starts_with("http://") && !url.starts_with("https://") {
        let p = Path::new(url);
        if p.exists() && validate_path_security(url, "url").is_ok() {
            return validate_local_media_boundary(p).is_ok();
        }
    }

    false
}

pub async fn generate_synthetic_mp4(
    output_path: &Path,
    duration_secs: u32,
    width: u32,
    height: u32,
) -> Result<PathBuf> {
    if let Some(parent) = output_path.parent() {
        tokio::fs::create_dir_all(parent).await?;
    }

    info!(
        "Generating synthetic MP4 at {:?} ({}s, {}x{})",
        output_path, duration_secs, width, height
    );

    let testsrc = format!(
        "testsrc=duration={}:size={}x{}:rate=30",
        duration_secs, width, height
    );
    let sine = format!("sine=frequency=1000:duration={}", duration_secs);

    let cmd_fut = Command::new("ffmpeg")
        .args([
            "-y",
            "-protocol_whitelist",
            "file,crypto,data,lavfi",
            "-threads",
            "1",
            "-f",
            "lavfi",
            "-i",
            &testsrc,
            "-f",
            "lavfi",
            "-i",
            &sine,
            "-c:v",
            "libx264",
            "-preset",
            "ultrafast",
            "-pix_fmt",
            "yuv420p",
            "-c:a",
            "aac",
            "-b:a",
            "128k",
            "-movflags",
            "+faststart",
            "--",
            output_path.to_str().ok_or_else(|| {
                CoreError::ValidationError("Invalid output path string".to_string())
            })?,
        ])
        .output();

    let status = match tokio::time::timeout(std::time::Duration::from_secs(60), cmd_fut).await {
        Ok(res) => res?,
        Err(_) => {
            return Err(CoreError::ProcessFailed {
                command: "ffmpeg (synthetic generator)".to_string(),
                exit_code: None,
                stderr: "FFmpeg synthetic generator timed out after 60 seconds".to_string(),
            });
        }
    };

    if !status.status.success() {
        let stderr = String::from_utf8_lossy(&status.stderr).to_string();
        return Err(CoreError::ProcessFailed {
            command: "ffmpeg (synthetic generator)".to_string(),
            exit_code: status.status.code(),
            stderr,
        });
    }

    if !output_path.exists() {
        return Err(CoreError::FileNotFound(output_path.to_path_buf()));
    }

    Ok(output_path.to_path_buf())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_is_offline_or_test_url() {
        assert!(is_offline_or_test_url("test://sample.mp4"));
        assert!(is_offline_or_test_url("mock://video-123"));
        assert!(is_offline_or_test_url("fixture://fixture1"));
        assert!(is_offline_or_test_url("file:///tmp/vid.mp4"));
        assert!(!is_offline_or_test_url("https://www.youtube.com/watch?v=123"));
    }

    #[tokio::test]
    async fn test_generate_synthetic_mp4() {
        let temp_dir = tempfile::tempdir().unwrap();
        let out_file = temp_dir.path().join("synthetic_sample.mp4");

        let path = generate_synthetic_mp4(&out_file, 2, 640, 360)
            .await
            .expect("Should generate synthetic video");

        assert!(path.exists());
        let meta = tokio::fs::metadata(&path).await.unwrap();
        assert!(meta.len() > 1000);
    }
}
