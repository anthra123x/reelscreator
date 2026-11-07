use reelforge_core::{CoreError, Result};
use std::path::{Path, PathBuf};
use tokio::process::Command;

pub async fn extract_midpoint_thumbnail(
    clip_path: &Path,
    output_thumb_path: &Path,
    clip_duration_seconds: f64,
) -> Result<PathBuf> {
    if !clip_path.exists() {
        return Err(CoreError::FileNotFound(clip_path.to_path_buf()));
    }

    if let Some(parent) = output_thumb_path.parent() {
        tokio::fs::create_dir_all(parent).await?;
    }

    let midpoint = (clip_duration_seconds / 2.0).max(0.0);

    let cmd_fut = Command::new("ffmpeg")
        .args([
            "-y",
            "-protocol_whitelist",
            "file,crypto,data",
            "-threads",
            "1",
            "-ss",
            &format!("{:.3}", midpoint),
            "-i",
            clip_path.to_str().ok_or_else(|| {
                CoreError::ValidationError("Clip path contains invalid UTF-8".to_string())
            })?,
            "-vframes",
            "1",
            "-q:v",
            "2",
            "--",
            output_thumb_path.to_str().ok_or_else(|| {
                CoreError::ValidationError("Thumb path contains invalid UTF-8".to_string())
            })?,
        ])
        .output();

    let status = match tokio::time::timeout(std::time::Duration::from_secs(30), cmd_fut).await {
        Ok(res) => res?,
        Err(_) => {
            return Err(CoreError::ProcessFailed {
                command: "ffmpeg (thumbnail extraction)".to_string(),
                exit_code: None,
                stderr: "FFmpeg thumbnail extraction timed out after 30 seconds".to_string(),
            });
        }
    };

    if !status.status.success() {
        let stderr = String::from_utf8_lossy(&status.stderr).to_string();
        return Err(CoreError::ProcessFailed {
            command: "ffmpeg (thumbnail extraction)".to_string(),
            exit_code: status.status.code(),
            stderr,
        });
    }

    if !output_thumb_path.exists() {
        return Err(CoreError::FileNotFound(output_thumb_path.to_path_buf()));
    }

    let bytes = tokio::fs::read(output_thumb_path).await?;
    if bytes.len() < 4 {
        return Err(CoreError::CutterError(
            "Generated thumbnail file is empty or corrupted".to_string(),
        ));
    }

    // Verify JPEG magic bytes FF D8 FF
    if bytes[0] != 0xFF || bytes[1] != 0xD8 || bytes[2] != 0xFF {
        return Err(CoreError::CutterError(
            "Generated thumbnail does not have valid JPEG header".to_string(),
        ));
    }

    Ok(output_thumb_path.to_path_buf())
}

#[cfg(test)]
mod tests {
    use super::*;
    use reelforge_downloader::generate_synthetic_mp4;

    #[tokio::test]
    async fn test_thumbnail_extraction() {
        let temp = tempfile::tempdir().unwrap();
        let clip_path = temp.path().join("clip.mp4");
        let thumb_path = temp.path().join("thumb.jpg");

        generate_synthetic_mp4(&clip_path, 2, 640, 360)
            .await
            .unwrap();

        let res = extract_midpoint_thumbnail(&clip_path, &thumb_path, 2.0).await;
        assert!(res.is_ok(), "Thumbnail extraction failed: {:?}", res.err());
        assert!(thumb_path.exists());
        let meta = tokio::fs::metadata(&thumb_path).await.unwrap();
        assert!(meta.len() > 100);
    }
}
