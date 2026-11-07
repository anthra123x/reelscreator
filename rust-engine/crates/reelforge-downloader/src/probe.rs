use reelforge_core::{CoreError, Result, VideoMetadata};
use serde_json::Value;
use std::path::Path;
use tokio::process::Command;

pub async fn probe_media_file(path: &Path) -> Result<VideoMetadata> {
    if !path.exists() {
        return Err(CoreError::FileNotFound(path.to_path_buf()));
    }

    let cmd_fut = Command::new("ffprobe")
        .args([
            "-v",
            "quiet",
            "-protocol_whitelist",
            "file,crypto,data",
            "-print_format",
            "json",
            "-show_format",
            "-show_streams",
            "--",
            path.to_str().ok_or_else(|| {
                CoreError::ValidationError("Path contains invalid UTF-8".to_string())
            })?,
        ])
        .output();

    let output = match tokio::time::timeout(std::time::Duration::from_secs(30), cmd_fut).await {
        Ok(res) => res?,
        Err(_) => {
            return Err(CoreError::ProcessFailed {
                command: "ffprobe".to_string(),
                exit_code: None,
                stderr: "ffprobe execution timed out after 30 seconds".to_string(),
            });
        }
    };

    if !output.status.success() {
        let stderr = String::from_utf8_lossy(&output.stderr).to_string();
        return Err(CoreError::ProcessFailed {
            command: "ffprobe".to_string(),
            exit_code: output.status.code(),
            stderr,
        });
    }

    let json: Value = serde_json::from_slice(&output.stdout)?;

    let streams = json["streams"]
        .as_array()
        .ok_or_else(|| CoreError::ProbeError("Missing 'streams' in ffprobe output".to_string()))?;

    let video_stream = streams
        .iter()
        .find(|s| s["codec_type"].as_str() == Some("video"))
        .ok_or_else(|| CoreError::ProbeError("No video stream found".to_string()))?;

    let width = video_stream["width"]
        .as_u64()
        .ok_or_else(|| CoreError::ProbeError("Missing video width".to_string()))?
        as u32;

    let height = video_stream["height"]
        .as_u64()
        .ok_or_else(|| CoreError::ProbeError("Missing video height".to_string()))?
        as u32;

    let video_codec = video_stream["codec_name"]
        .as_str()
        .unwrap_or("unknown")
        .to_string();

    let audio_stream = streams
        .iter()
        .find(|s| s["codec_type"].as_str() == Some("audio"));
    let audio_codec = audio_stream
        .and_then(|s| s["codec_name"].as_str())
        .map(|s| s.to_string());

    let format_obj = &json["format"];
    let format_name = format_obj["format_name"]
        .as_str()
        .unwrap_or("unknown")
        .to_string();

    let duration_seconds = format_obj["duration"]
        .as_str()
        .and_then(|s| s.parse::<f64>().ok())
        .or_else(|| {
            video_stream["duration"]
                .as_str()
                .and_then(|s| s.parse::<f64>().ok())
        })
        .unwrap_or(0.0);

    let bitrate_bps = format_obj["bit_rate"]
        .as_str()
        .and_then(|s| s.parse::<u64>().ok());

    Ok(VideoMetadata {
        duration_seconds,
        width,
        height,
        video_codec,
        audio_codec,
        bitrate_bps,
        format_name,
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::fixtures::generate_synthetic_mp4;

    #[tokio::test]
    async fn test_probe_synthetic_video() {
        let temp = tempfile::tempdir().unwrap();
        let sample = temp.path().join("probe_test.mp4");
        generate_synthetic_mp4(&sample, 3, 1280, 720)
            .await
            .expect("Failed to create probe test video");

        let meta = probe_media_file(&sample).await.expect("Probe failed");
        assert_eq!(meta.width, 1280);
        assert_eq!(meta.height, 720);
        assert_eq!(meta.video_codec, "h264");
        assert_eq!(meta.audio_codec.as_deref(), Some("aac"));
        assert!((meta.duration_seconds - 3.0).abs() < 0.5);
    }

    #[tokio::test]
    async fn test_probe_non_existent_file() {
        let path = Path::new("/tmp/non_existent_never_12345.mp4");
        let res = probe_media_file(path).await;
        assert!(res.is_err());
        assert!(matches!(res, Err(CoreError::FileNotFound(_))));
    }
}
