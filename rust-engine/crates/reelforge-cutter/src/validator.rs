use reelforge_core::{CoreError, Dimensions, Result, VideoMetadata};
use reelforge_downloader::probe_media_file;
use std::path::Path;

pub struct ClipValidationSpec {
    pub expected_dimensions: Dimensions,
    pub expected_duration_seconds: f64,
    pub duration_tolerance_seconds: f64,
    pub require_audio: bool,
}

impl Default for ClipValidationSpec {
    fn default() -> Self {
        Self {
            expected_dimensions: Dimensions {
                width: 1080,
                height: 1920,
            },
            expected_duration_seconds: 30.0,
            duration_tolerance_seconds: 0.75,
            require_audio: false,
        }
    }
}

pub async fn validate_cut_clip(
    clip_path: &Path,
    spec: &ClipValidationSpec,
) -> Result<VideoMetadata> {
    if !clip_path.exists() {
        return Err(CoreError::FileNotFound(clip_path.to_path_buf()));
    }

    let file_meta = tokio::fs::metadata(clip_path).await?;
    if file_meta.len() == 0 {
        return Err(CoreError::CutterError(format!(
            "Cut clip {:?} has 0 bytes",
            clip_path
        )));
    }

    let meta = probe_media_file(clip_path).await?;

    if meta.width != spec.expected_dimensions.width
        || meta.height != spec.expected_dimensions.height
    {
        return Err(CoreError::CutterError(format!(
            "Dimension mismatch: expected {}x{}, got {}x{}",
            spec.expected_dimensions.width, spec.expected_dimensions.height, meta.width, meta.height
        )));
    }

    let duration_diff = (meta.duration_seconds - spec.expected_duration_seconds).abs();
    if duration_diff > spec.duration_tolerance_seconds {
        return Err(CoreError::CutterError(format!(
            "Duration mismatch: expected {:.2}s (+/- {:.2}s), got {:.2}s (diff {:.2}s)",
            spec.expected_duration_seconds,
            spec.duration_tolerance_seconds,
            meta.duration_seconds,
            duration_diff
        )));
    }

    if spec.require_audio && meta.audio_codec.is_none() {
        return Err(CoreError::CutterError(format!(
            "Clip {:?} is missing audio stream",
            clip_path
        )));
    }

    Ok(meta)
}

#[cfg(test)]
mod tests {
    use super::*;
    use reelforge_downloader::generate_synthetic_mp4;

    #[tokio::test]
    async fn test_validate_matching_clip() {
        let temp = tempfile::tempdir().unwrap();
        let clip_path = temp.path().join("valid_clip.mp4");
        generate_synthetic_mp4(&clip_path, 3, 1080, 1920)
            .await
            .unwrap();

        let spec = ClipValidationSpec {
            expected_dimensions: Dimensions {
                width: 1080,
                height: 1920,
            },
            expected_duration_seconds: 3.0,
            duration_tolerance_seconds: 0.5,
            require_audio: true,
        };

        let res = validate_cut_clip(&clip_path, &spec).await;
        assert!(res.is_ok(), "Validation failed: {:?}", res.err());
    }

    #[tokio::test]
    async fn test_validate_dimension_mismatch() {
        let temp = tempfile::tempdir().unwrap();
        let clip_path = temp.path().join("mismatch_clip.mp4");
        generate_synthetic_mp4(&clip_path, 2, 640, 360)
            .await
            .unwrap();

        let spec = ClipValidationSpec {
            expected_dimensions: Dimensions {
                width: 1080,
                height: 1920,
            },
            expected_duration_seconds: 2.0,
            duration_tolerance_seconds: 0.5,
            require_audio: false,
        };

        let res = validate_cut_clip(&clip_path, &spec).await;
        assert!(res.is_err());
        assert!(res.unwrap_err().to_string().contains("Dimension mismatch"));
    }
}
