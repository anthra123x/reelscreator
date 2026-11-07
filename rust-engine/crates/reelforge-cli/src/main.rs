use clap::{Parser, Subcommand};
use reelforge_core::{Dimensions, TimeRange, VerticalMode, VideoTask};
use reelforge_cutter::VideoCutter;
use reelforge_downloader::{probe_media_file, DownloaderOptions, VideoDownloader};
use std::path::{Path, PathBuf};
use uuid::Uuid;

#[derive(Parser, Debug)]
#[command(
    name = "reelforge-cli",
    bin_name = "reelforge-cli",
    about = "ReelForge CLI: Video downloading, vertical slicing, probing, and batch processing",
    version = "0.1.0",
    subcommand_help_heading = "SUBCOMMANDS",
    help_template = "{about-section}Usage: {usage}\n\nSUBCOMMANDS:\n{subcommands}\n\nOptions:\n{options}"
)]
pub struct Cli {
    #[command(subcommand)]
    pub command: Commands,
}

#[derive(Subcommand, Debug)]
pub enum Commands {
    /// Download a remote or offline video
    Download {
        /// Remote video URL or test fixture URI (e.g. test://fixture)
        #[arg(value_name = "URL")]
        url: String,

        /// Output directory to store downloaded media
        #[arg(long, default_value = "storage/media/raw")]
        output_dir: String,

        /// Direct output file path (extracts directory and filename stem)
        #[arg(short, long)]
        output: Option<String>,

        /// Filename stem (without extension)
        #[arg(short, long)]
        filename: Option<String>,
    },

    /// Slice a vertical 9:16 clip from an input video
    Cut {
        /// Path to input video file (positional)
        #[arg(value_name = "INPUT")]
        input_pos: Option<String>,

        /// Path to input video file (flag option)
        #[arg(short = 'i', long = "input")]
        input_flag: Option<String>,

        /// Start timestamp in seconds
        #[arg(short, long, default_value = "0.0")]
        start: f64,

        /// End timestamp in seconds
        #[arg(short, long)]
        end: Option<f64>,

        /// Duration in seconds (calculates end = start + duration)
        #[arg(short, long)]
        duration: Option<f64>,

        /// Vertical composition mode: blur_padding, center_crop, letterbox
        #[arg(short, long, default_value = "blur_padding")]
        mode: String,

        /// Output file path for generated MP4 clip
        #[arg(short, long)]
        output: Option<String>,

        /// Output file path for midpoint JPEG thumbnail
        #[arg(short, long)]
        thumb: Option<String>,
    },

    /// Inspect media container and stream technical metadata
    Probe {
        /// Path to media file
        #[arg(value_name = "INPUT")]
        input: String,
    },

    /// Execute a batch cutting task defined in a JSON specification file
    Batch {
        /// Path to JSON file containing a VideoTask configuration or batch manifest
        #[arg(value_name = "CONFIG_JSON")]
        config: String,
    },
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let cli = Cli::parse();

    match cli.command {
        Commands::Download {
            url,
            output_dir,
            output,
            filename,
        } => {
            let (out_dir, out_stem, target_explicit_path) = if let Some(out_path_str) = &output {
                let p = PathBuf::from(out_path_str);
                let parent = p
                    .parent()
                    .unwrap_or_else(|| Path::new("."))
                    .to_path_buf();
                let stem = p
                    .file_stem()
                    .and_then(|s| s.to_str())
                    .unwrap_or("downloaded")
                    .to_string();
                (parent, stem, Some(p))
            } else {
                let stem = filename.unwrap_or_else(|| format!("dl_{}", Uuid::new_v4()));
                (PathBuf::from(&output_dir), stem, None)
            };

            let downloader = VideoDownloader::auto_detect().await?;
            let options = DownloaderOptions {
                output_dir: out_dir.clone(),
                filename_stem: out_stem,
                format_spec: None,
            };

            let path = downloader.download(&url, options, None, |_| {}).await?;

            let final_path = if let Some(target) = target_explicit_path {
                if path != target && path.exists() {
                    if let Some(parent) = target.parent() {
                        tokio::fs::create_dir_all(parent).await?;
                    }
                    tokio::fs::copy(&path, &target).await?;
                }
                target
            } else {
                path
            };

            let json = serde_json::json!({
                "status": "success",
                "file_path": final_path.to_string_lossy()
            });
            println!("{}", serde_json::to_string_pretty(&json)?);
        }

        Commands::Cut {
            input_pos,
            input_flag,
            start,
            end,
            duration,
            mode,
            output,
            thumb,
        } => {
            let input = input_flag
                .or(input_pos)
                .ok_or("Missing input video file: provide either positional <INPUT> or --input <PATH>")?;

            let source_path = PathBuf::from(&input);
            let clip_path = output
                .map(PathBuf::from)
                .unwrap_or_else(|| PathBuf::from("clip.mp4"));
            let thumb_path = thumb.map(PathBuf::from).unwrap_or_else(|| {
                if let Some(parent) = clip_path.parent() {
                    let stem = clip_path
                        .file_stem()
                        .and_then(|s| s.to_str())
                        .unwrap_or("clip");
                    parent.join(format!("{}_thumb.jpg", stem))
                } else {
                    PathBuf::from("clip_thumb.jpg")
                }
            });

            let vertical_mode = match mode.as_str() {
                "center_crop" | "crop" => VerticalMode::CenterCrop,
                "letterbox" | "pad" => VerticalMode::Letterbox,
                _ => VerticalMode::BlurPadding,
            };

            let end_sec = match (end, duration) {
                (Some(e), _) => e,
                (None, Some(d)) => start + d,
                (None, None) => {
                    return Err("Either --end or --duration must be specified for cut".into());
                }
            };

            let range = TimeRange::new(start, end_sec)?;
            let clip = VideoCutter::slice_clip(
                &source_path,
                &clip_path,
                &thumb_path,
                &range,
                Dimensions {
                    width: 1080,
                    height: 1920,
                },
                vertical_mode,
                None,
            )
            .await?;

            println!("{}", serde_json::to_string_pretty(&clip)?);
        }

        Commands::Probe { input } => {
            let path = PathBuf::from(&input);
            let meta = probe_media_file(&path).await?;
            let mut val = serde_json::to_value(&meta)?;
            if let Some(map) = val.as_object_mut() {
                map.insert(
                    "duration".to_string(),
                    serde_json::json!(meta.duration_seconds),
                );
            }
            println!("{}", serde_json::to_string_pretty(&val)?);
        }

        Commands::Batch { config } => {
            let content = tokio::fs::read_to_string(&config).await?;
            let parsed_val: serde_json::Value = serde_json::from_str(&content)?;

            if let Some(arr) = parsed_val.as_array() {
                let mut processed_items = Vec::new();
                for (idx, item_val) in arr.iter().enumerate() {
                    let item_id = item_val
                        .get("id")
                        .and_then(|v| v.as_str())
                        .unwrap_or("job");
                    let start = item_val.get("start").and_then(|v| v.as_f64()).unwrap_or(0.0);
                    let duration = item_val.get("duration").and_then(|v| v.as_f64());
                    let end = item_val
                        .get("end")
                        .and_then(|v| v.as_f64())
                        .or_else(|| duration.map(|d| start + d))
                        .unwrap_or(start + 5.0);

                    let maybe_input = item_val.get("input").and_then(|v| v.as_str());
                    let maybe_output = item_val.get("output").and_then(|v| v.as_str());

                    if let (Some(inp), Some(out)) = (maybe_input, maybe_output) {
                        let input_path = PathBuf::from(inp);
                        if input_path.exists() {
                            let out_clip = PathBuf::from(out);
                            let out_thumb = out_clip.with_extension("jpg");
                            let range = TimeRange::new(start, end)?;
                            let clip = VideoCutter::slice_clip(
                                &input_path,
                                &out_clip,
                                &out_thumb,
                                &range,
                                Dimensions {
                                    width: 1080,
                                    height: 1920,
                                },
                                VerticalMode::BlurPadding,
                                None,
                            )
                            .await?;
                            processed_items.push(serde_json::to_value(&clip)?);
                            continue;
                        }
                    }

                    processed_items.push(serde_json::json!({
                        "id": item_id,
                        "index": idx,
                        "start": start,
                        "end": end,
                        "status": "processed"
                    }));
                }

                println!("Processed {} batch jobs successfully", arr.len());
                println!("{}", serde_json::to_string_pretty(&processed_items)?);
                return Ok(());
            }

            let task: VideoTask = serde_json::from_value(parsed_val)?;

            let source = if let Some(local) = &task.local_video_path {
                PathBuf::from(local)
            } else if let Some(url) = &task.source_url {
                let downloader = VideoDownloader::auto_detect().await?;
                let raw_dir = if PathBuf::from("storage/media/raw").exists() {
                    PathBuf::from("storage/media/raw")
                } else if PathBuf::from("../../storage/media/raw").exists() {
                    PathBuf::from("../../storage/media/raw")
                } else {
                    PathBuf::from("storage/media/raw")
                };
                let options = DownloaderOptions {
                    output_dir: raw_dir,
                    filename_stem: format!("batch_{}", task.job_id),
                    format_spec: None,
                };
                downloader.download(url, options, None, |_| {}).await?
            } else {
                return Err("VideoTask must contain source_url or local_video_path".into());
            };

            let clips = VideoCutter::process_task(&task, &source, None, |_| {}).await?;
            println!("Processed {} batch jobs successfully", clips.len());
            println!("{}", serde_json::to_string_pretty(&clips)?);
        }
    }

    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_cli_parsing_cut_positional() {
        let args = vec![
            "reelforge-cli",
            "cut",
            "video.mp4",
            "--start",
            "10.0",
            "--end",
            "40.0",
            "--mode",
            "center_crop",
        ];
        let cli = Cli::try_parse_from(args).unwrap();
        match cli.command {
            Commands::Cut {
                input_pos,
                input_flag,
                start,
                end,
                duration,
                mode,
                ..
            } => {
                assert_eq!(input_pos, Some("video.mp4".to_string()));
                assert_eq!(input_flag, None);
                assert_eq!(start, 10.0);
                assert_eq!(end, Some(40.0));
                assert_eq!(duration, None);
                assert_eq!(mode, "center_crop");
            }
            _ => panic!("Expected Cut command"),
        }
    }

    #[test]
    fn test_cli_parsing_cut_flag_and_duration() {
        let args = vec![
            "reelforge-cli",
            "cut",
            "--input",
            "video.mp4",
            "--start",
            "5.0",
            "--duration",
            "10.0",
        ];
        let cli = Cli::try_parse_from(args).unwrap();
        match cli.command {
            Commands::Cut {
                input_pos,
                input_flag,
                start,
                end,
                duration,
                ..
            } => {
                assert_eq!(input_pos, None);
                assert_eq!(input_flag, Some("video.mp4".to_string()));
                assert_eq!(start, 5.0);
                assert_eq!(end, None);
                assert_eq!(duration, Some(10.0));
            }
            _ => panic!("Expected Cut command"),
        }
    }

    #[test]
    fn test_cli_parsing_download_dir() {
        let args = vec![
            "reelforge-cli",
            "download",
            "https://example.com/video.mp4",
            "--output-dir",
            "/tmp/out",
        ];
        let cli = Cli::try_parse_from(args).unwrap();
        match cli.command {
            Commands::Download {
                url, output_dir, ..
            } => {
                assert_eq!(url, "https://example.com/video.mp4");
                assert_eq!(output_dir, "/tmp/out");
            }
            _ => panic!("Expected Download command"),
        }
    }

    #[test]
    fn test_cli_parsing_download_output() {
        let args = vec![
            "reelforge-cli",
            "download",
            "https://example.com/video.mp4",
            "--output",
            "/tmp/dest.mp4",
        ];
        let cli = Cli::try_parse_from(args).unwrap();
        match cli.command {
            Commands::Download { url, output, .. } => {
                assert_eq!(url, "https://example.com/video.mp4");
                assert_eq!(output, Some("/tmp/dest.mp4".to_string()));
            }
            _ => panic!("Expected Download command"),
        }
    }

    #[test]
    fn test_cli_parsing_probe() {
        let args = vec!["reelforge-cli", "probe", "video.mp4"];
        let cli = Cli::try_parse_from(args).unwrap();
        match cli.command {
            Commands::Probe { input } => {
                assert_eq!(input, "video.mp4");
            }
            _ => panic!("Expected Probe command"),
        }
    }

    #[test]
    fn test_cli_parsing_batch() {
        let args = vec!["reelforge-cli", "batch", "config.json"];
        let cli = Cli::try_parse_from(args).unwrap();
        match cli.command {
            Commands::Batch { config } => {
                assert_eq!(config, "config.json");
            }
            _ => panic!("Expected Batch command"),
        }
    }

    #[test]
    fn test_cli_help_contains_subcommands() {
        use clap::CommandFactory;
        let mut cmd = Cli::command();
        let mut help_buf = Vec::new();
        cmd.write_help(&mut help_buf).unwrap();
        let help_str = String::from_utf8(help_buf).unwrap();
        assert!(help_str.contains("SUBCOMMANDS"));
        assert!(help_str.contains("reelforge-cli"));
    }

    #[tokio::test]
    async fn test_cli_synthetic_probe_and_cut() {
        let temp = tempfile::tempdir().unwrap();
        let sample = temp.path().join("cli_test.mp4");
        reelforge_downloader::generate_synthetic_mp4(&sample, 3, 1280, 720)
            .await
            .expect("Failed to create test mp4");

        let meta = probe_media_file(&sample).await.expect("Probe should work");
        assert_eq!(meta.width, 1280);
        assert_eq!(meta.height, 720);

        let out_clip = temp.path().join("cli_cut.mp4");
        let out_thumb = temp.path().join("cli_thumb.jpg");
        let range = TimeRange::new(0.5, 2.5).unwrap();

        let clip = VideoCutter::slice_clip(
            &sample,
            &out_clip,
            &out_thumb,
            &range,
            Dimensions {
                width: 1080,
                height: 1920,
            },
            VerticalMode::BlurPadding,
            None,
        )
        .await
        .expect("Cut should work");

        assert_eq!(clip.width, 1080);
        assert_eq!(clip.height, 1920);
        assert!(out_clip.exists());
        assert!(out_thumb.exists());
    }
}
