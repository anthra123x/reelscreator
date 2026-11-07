use reelforge_core::{
    Clip, CoreError, Dimensions, Result, TimeRange, VerticalMode, VideoTask,
};
use reelforge_downloader::probe_media_file;
use std::path::{Path, PathBuf};
use std::process::Stdio;
use tokio::io::AsyncReadExt;
use tokio::process::Command;
use tokio_util::sync::CancellationToken;
use tracing::info;
use uuid::Uuid;

use crate::filtergraph::build_vertical_filtergraph;
use crate::strategies::plan_cut_intervals;
use crate::thumbnail::extract_midpoint_thumbnail;
use crate::validator::{validate_cut_clip, ClipValidationSpec};

pub struct VideoCutter;

impl VideoCutter {
    pub async fn slice_clip(
        source_path: &Path,
        clip_path: &Path,
        thumb_path: &Path,
        range: &TimeRange,
        dimensions: Dimensions,
        vertical_mode: VerticalMode,
        cancel_token: Option<CancellationToken>,
    ) -> Result<Clip> {
        if !source_path.exists() {
            return Err(CoreError::FileNotFound(source_path.to_path_buf()));
        }

        if let Some(parent) = clip_path.parent() {
            tokio::fs::create_dir_all(parent).await?;
        }
        if let Some(parent) = thumb_path.parent() {
            tokio::fs::create_dir_all(parent).await?;
        }

        let filtergraph = build_vertical_filtergraph(vertical_mode, dimensions);

        let mut cmd = Command::new("ffmpeg");
        cmd.args([
            "-y",
            "-protocol_whitelist",
            "file,crypto,data",
            "-threads",
            "1",
            "-ss",
            &format!("{:.3}", range.start),
            "-to",
            &format!("{:.3}", range.end),
            "-i",
            source_path.to_str().ok_or_else(|| {
                CoreError::ValidationError("Source path invalid UTF-8".to_string())
            })?,
            "-filter_complex",
            &filtergraph,
            "-c:v",
            "libx264",
            "-preset",
            "fast",
            "-crf",
            "23",
            "-c:a",
            "aac",
            "-b:a",
            "128k",
            "-ar",
            "44100",
            "-avoid_negative_ts",
            "make_zero",
            "-movflags",
            "+faststart",
            "--",
            clip_path.to_str().ok_or_else(|| {
                CoreError::ValidationError("Clip path invalid UTF-8".to_string())
            })?,
        ])
        .stdout(Stdio::null())
        .stderr(Stdio::piped());

        info!(
            "Cutting clip [{:.2}s - {:.2}s] to {:?} (mode: {:?}, res: {}x{})",
            range.start, range.end, clip_path, vertical_mode, dimensions.width, dimensions.height
        );

        let mut child = cmd.spawn().map_err(|e| {
            CoreError::CutterError(format!("Failed to spawn ffmpeg cutter: {}", e))
        })?;

        let err_pipe = child.stderr.take();
        let timeout_duration = std::time::Duration::from_secs(300);

        let (exit_status, stderr_bytes) = tokio::select! {
            res = async {
                if let Some(token) = cancel_token {
                    tokio::select! {
                        status_res = child.wait() => {
                            let s = status_res?;
                            let mut stderr_buf = Vec::new();
                            if let Some(mut pipe) = err_pipe {
                                let _ = pipe.read_to_end(&mut stderr_buf).await;
                            }
                            Ok((s, stderr_buf))
                        }
                        _ = token.cancelled() => {
                            let _ = child.start_kill();
                            Err(CoreError::Cancelled("Cutter operation cancelled by client".to_string()))
                        }
                    }
                } else {
                    let s = child.wait().await?;
                    let mut stderr_buf = Vec::new();
                    if let Some(mut pipe) = err_pipe {
                        let _ = pipe.read_to_end(&mut stderr_buf).await;
                    }
                    Ok((s, stderr_buf))
                }
            } => res?,
            _ = tokio::time::sleep(timeout_duration) => {
                let _ = child.start_kill();
                return Err(CoreError::ProcessFailed {
                    command: "ffmpeg (cut slice)".to_string(),
                    exit_code: None,
                    stderr: "FFmpeg cutting process timed out after 300 seconds".to_string(),
                });
            }
        };

        if !exit_status.success() {
            let stderr = String::from_utf8_lossy(&stderr_bytes).to_string();
            return Err(CoreError::ProcessFailed {
                command: "ffmpeg (cut slice)".to_string(),
                exit_code: exit_status.code(),
                stderr,
            });
        }

        let clip_duration = range.duration();

        // Extract thumbnail at midpoint
        extract_midpoint_thumbnail(clip_path, thumb_path, clip_duration).await?;

        // Post-render verification
        let validation_spec = ClipValidationSpec {
            expected_dimensions: dimensions,
            expected_duration_seconds: clip_duration,
            duration_tolerance_seconds: 1.0,
            require_audio: false,
        };
        let meta = validate_cut_clip(clip_path, &validation_spec).await?;

        let clip_id = Uuid::new_v4();
        Ok(Clip {
            clip_id,
            file_path: clip_path.to_string_lossy().to_string(),
            thumbnail_path: thumb_path.to_string_lossy().to_string(),
            start_time: range.start,
            end_time: range.end,
            duration: meta.duration_seconds,
            width: meta.width,
            height: meta.height,
        })
    }

    pub async fn process_task<F>(
        task: &VideoTask,
        source_media_path: &Path,
        cancel_token: Option<CancellationToken>,
        mut progress_cb: F,
    ) -> Result<Vec<Clip>>
    where
        F: FnMut(u8) + Send,
    {
        let meta = probe_media_file(source_media_path).await?;
        let intervals = plan_cut_intervals(
            task.strategy,
            meta.duration_seconds,
            task.interval_seconds,
            task.max_duration_seconds,
            task.custom_ranges.as_deref(),
        )?;

        if intervals.is_empty() {
            return Err(CoreError::CutterError(
                "No valid cut intervals could be generated from source".to_string(),
            ));
        }

        let output_base = PathBuf::from(&task.output_dir);
        let clips_dir = output_base.clone();
        let thumbs_dir = if task.output_dir.contains("media/clips/") {
            PathBuf::from(task.output_dir.replace("media/clips/", "media/thumbnails/"))
        } else {
            output_base.join("thumbnails")
        };

        tokio::fs::create_dir_all(&clips_dir).await?;
        tokio::fs::create_dir_all(&thumbs_dir).await?;

        let dimensions = task.aspect_ratio.target_dimensions();
        let mut clips = Vec::with_capacity(intervals.len());
        let total_count = intervals.len() as f32;

        for (idx, range) in intervals.iter().enumerate() {
            if let Some(token) = &cancel_token {
                if token.is_cancelled() {
                    return Err(CoreError::Cancelled("Task cancelled".to_string()));
                }
            }

            let clip_path = clips_dir.join(format!("clip_{:03}.mp4", idx + 1));
            let thumb_path = thumbs_dir.join(format!("thumb_{:03}.jpg", idx + 1));

            let clip = Self::slice_clip(
                source_media_path,
                &clip_path,
                &thumb_path,
                range,
                dimensions,
                task.vertical_mode,
                cancel_token.clone(),
            )
            .await?;

            clips.push(clip);

            let percent = (((idx + 1) as f32 / total_count) * 100.0) as u8;
            progress_cb(percent);
        }

        Ok(clips)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use reelforge_core::{AspectRatio, CutStrategy};
    use reelforge_downloader::generate_synthetic_mp4;

    #[tokio::test]
    async fn test_slice_clip_blur_padding() {
        let temp = tempfile::tempdir().unwrap();
        let source = temp.path().join("source.mp4");
        generate_synthetic_mp4(&source, 6, 1280, 720)
            .await
            .expect("Failed to generate source video");

        let clip_path = temp.path().join("clip_001.mp4");
        let thumb_path = temp.path().join("thumb_001.jpg");
        let range = TimeRange::new(1.0, 4.0).unwrap();

        let clip = VideoCutter::slice_clip(
            &source,
            &clip_path,
            &thumb_path,
            &range,
            Dimensions {
                width: 1080,
                height: 1920,
            },
            VerticalMode::BlurPadding,
            None,
        )
        .await
        .expect("Should slice clip with blur padding");

        assert_eq!(clip.width, 1080);
        assert_eq!(clip.height, 1920);
        assert!((clip.duration - 3.0).abs() < 0.5);
        assert!(clip_path.exists());
        assert!(thumb_path.exists());
    }

    #[tokio::test]
    async fn test_process_task_cuts() {
        let temp = tempfile::tempdir().unwrap();
        let source = temp.path().join("source_task.mp4");
        generate_synthetic_mp4(&source, 10, 1280, 720).await.unwrap();

        let task = VideoTask {
            job_id: Uuid::new_v4(),
            source_url: None,
            local_video_path: Some(source.to_string_lossy().to_string()),
            strategy: CutStrategy::FixedInterval,
            interval_seconds: 4.0,
            max_duration_seconds: 8.0,
            aspect_ratio: AspectRatio::Ratio9x16,
            vertical_mode: VerticalMode::CenterCrop,
            output_dir: temp.path().join("clips").to_string_lossy().to_string(),
            webhook_url: None,
            custom_ranges: None,
            created_at: chrono::Utc::now(),
        };

        let clips = VideoCutter::process_task(&task, &source, None, |_| {})
            .await
            .expect("Should process entire task");

        assert!(!clips.is_empty());
        for clip in &clips {
            assert_eq!(clip.width, 1080);
            assert_eq!(clip.height, 1920);
        }
    }
}
