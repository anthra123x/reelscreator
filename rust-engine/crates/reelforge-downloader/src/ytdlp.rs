use reelforge_core::{CoreError, DownloadProgress, Result};
use std::future;
use std::path::PathBuf;
use std::process::Stdio;
use tokio::io::{AsyncBufReadExt, BufReader};
use tokio::process::Command;
use tokio_util::sync::CancellationToken;
use tracing::{debug, info, warn};

use crate::fixtures::{generate_synthetic_mp4, is_offline_or_test_url};
use crate::parser::parse_progress_line;

#[derive(Debug, Clone)]
pub struct YtDlpCommand {
    pub binary: String,
    pub prefix_args: Vec<String>,
}

impl YtDlpCommand {
    pub async fn detect() -> Result<Self> {
        if let Ok(custom_path) = std::env::var("YT_DLP_PATH") {
            if !custom_path.trim().is_empty() {
                return Ok(Self {
                    binary: custom_path,
                    prefix_args: Vec::new(),
                });
            }
        }

        // 1. Try system yt-dlp
        if let Ok(status) = Command::new("yt-dlp")
            .arg("--version")
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status()
            .await
        {
            if status.success() {
                return Ok(Self {
                    binary: "yt-dlp".to_string(),
                    prefix_args: Vec::new(),
                });
            }
        }

        // 2. Try uvx yt-dlp
        if let Ok(status) = Command::new("uvx")
            .args(["yt-dlp", "--version"])
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status()
            .await
        {
            if status.success() {
                return Ok(Self {
                    binary: "uvx".to_string(),
                    prefix_args: vec!["yt-dlp".to_string()],
                });
            }
        }

        Err(CoreError::DownloadError(
            "Neither 'yt-dlp' nor 'uvx yt-dlp' could be found or executed on system".to_string(),
        ))
    }
}

#[derive(Debug, Clone)]
pub struct DownloaderOptions {
    pub output_dir: PathBuf,
    pub filename_stem: String,
    pub format_spec: Option<String>,
}

pub struct VideoDownloader {
    cmd: YtDlpCommand,
}

impl VideoDownloader {
    pub fn new(cmd: YtDlpCommand) -> Self {
        Self { cmd }
    }

    pub async fn auto_detect() -> Result<Self> {
        let cmd = YtDlpCommand::detect().await?;
        Ok(Self::new(cmd))
    }

    pub async fn download<F>(
        &self,
        url: &str,
        options: DownloaderOptions,
        cancel_token: Option<CancellationToken>,
        mut progress_cb: F,
    ) -> Result<PathBuf>
    where
        F: FnMut(DownloadProgress) + Send + 'static,
    {
        // Prevent argument injection: disallow URLs starting with dash
        if url.trim().starts_with('-') {
            return Err(CoreError::ValidationError(
                "URL cannot start with a dash or hyphen".to_string(),
            ));
        }

        reelforge_core::validate_path_security(
            &options.output_dir.to_string_lossy(),
            "output_dir",
        )?;

        tokio::fs::create_dir_all(&options.output_dir).await?;

        // 1. Handle offline / mock / test URLs or environment fallback
        if is_offline_or_test_url(url) {
            info!("Processing offline/test URL: {}", url);
            let target_file = options
                .output_dir
                .join(format!("{}.mp4", options.filename_stem));

            progress_cb(DownloadProgress {
                percent: 0.0,
                speed_bytes_sec: Some(10_000_000),
                eta_seconds: Some(1),
            });

            if let Some(token) = &cancel_token {
                if token.is_cancelled() {
                    return Err(CoreError::Cancelled("Download cancelled".to_string()));
                }
            }

            if url.len() >= 7 && url[..7].eq_ignore_ascii_case("file://") {
                let path_str = &url[7..];
                reelforge_core::validate_path_security(path_str, "file_url")?;
                let local_path = PathBuf::from(path_str);
                let secure_path = crate::fixtures::validate_local_media_boundary(&local_path)?;
                tokio::fs::copy(&secure_path, &target_file).await?;
            } else if std::path::Path::new(url).exists() {
                reelforge_core::validate_path_security(url, "url")?;
                let local_path = PathBuf::from(url);
                let secure_path = crate::fixtures::validate_local_media_boundary(&local_path)?;
                tokio::fs::copy(&secure_path, &target_file).await?;
            } else {
                generate_synthetic_mp4(&target_file, 10, 1280, 720).await?;
            }

            progress_cb(DownloadProgress {
                percent: 50.0,
                speed_bytes_sec: Some(10_000_000),
                eta_seconds: Some(0),
            });

            progress_cb(DownloadProgress {
                percent: 100.0,
                speed_bytes_sec: None,
                eta_seconds: None,
            });

            return Ok(target_file);
        }

        // 2. Real remote download using yt-dlp
        if !url.starts_with("http://") && !url.starts_with("https://") {
            return Err(CoreError::ValidationError(format!(
                "Invalid URL scheme for remote download: {}",
                url
            )));
        }

        let output_template = options
            .output_dir
            .join(format!("{}.%(ext)s", options.filename_stem))
            .to_string_lossy()
            .to_string();

        let format = options.format_spec.unwrap_or_else(|| {
            "bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best".to_string()
        });

        let mut cmd = Command::new(&self.cmd.binary);
        for arg in &self.cmd.prefix_args {
            cmd.arg(arg);
        }

        cmd.args([
            "--newline",
            "--no-colors",
            "-f",
            &format,
            "-o",
            &output_template,
            "--",
            url,
        ])
        .stdout(Stdio::piped())
        .stderr(Stdio::piped());

        info!("Spawning yt-dlp command for URL: {}", url);
        let mut child = cmd.spawn().map_err(|e| {
            CoreError::DownloadError(format!("Failed to spawn yt-dlp process: {}", e))
        })?;

        let stdout = child
            .stdout
            .take()
            .ok_or_else(|| CoreError::DownloadError("Failed to open child stdout".to_string()))?;

        let mut reader = BufReader::new(stdout).lines();
        let timeout_duration = std::time::Duration::from_secs(180);

        let execution_fut = async {
            loop {
                tokio::select! {
                    line_res = reader.next_line() => {
                        match line_res {
                            Ok(Some(line)) => {
                                debug!("yt-dlp stdout: {}", line);
                                if let Some(prog) = parse_progress_line(&line) {
                                    progress_cb(prog);
                                }
                            }
                            Ok(None) => break,
                            Err(e) => {
                                warn!("Error reading yt-dlp stdout line: {}", e);
                                break;
                            }
                        }
                    }
                    _ = async {
                        if let Some(token) = &cancel_token {
                            token.cancelled().await
                        } else {
                            future::pending().await
                        }
                    } => {
                        info!("Cancellation requested for yt-dlp download: {}", url);
                        let _ = child.start_kill();
                        return Err(CoreError::Cancelled("Download cancelled by client".to_string()));
                    }
                }
            }

            let status = child.wait().await?;
            if !status.success() {
                return Err(CoreError::DownloadError(format!(
                    "yt-dlp exited with status code: {:?}",
                    status.code()
                )));
            }

            Ok(())
        };

        tokio::select! {
            res = execution_fut => res?,
            _ = tokio::time::sleep(timeout_duration) => {
                let _ = child.start_kill();
                return Err(CoreError::ProcessFailed {
                    command: "yt-dlp".to_string(),
                    exit_code: None,
                    stderr: "yt-dlp download timed out after 180 seconds".to_string(),
                });
            }
        }

        // Find the resulting file
        let mut read_dir = tokio::fs::read_dir(&options.output_dir).await?;
        while let Some(entry) = read_dir.next_entry().await? {
            let path = entry.path();
            if let Some(stem) = path.file_stem() {
                if stem.to_string_lossy() == options.filename_stem {
                    return Ok(path);
                }
            }
        }

        Err(CoreError::FileNotFound(options.output_dir.join(format!(
            "{}.mp4",
            options.filename_stem
        ))))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::atomic::{AtomicU32, Ordering};
    use std::sync::Arc;

    #[tokio::test]
    async fn test_yt_dlp_detection() {
        let cmd = YtDlpCommand::detect().await;
        assert!(cmd.is_ok(), "YtDlp detection failed: {:?}", cmd.err());
    }

    #[tokio::test]
    async fn test_offline_fixture_download() {
        let cmd = YtDlpCommand::detect().await.unwrap();
        let downloader = VideoDownloader::new(cmd);

        let temp = tempfile::tempdir().unwrap();
        let options = DownloaderOptions {
            output_dir: temp.path().to_path_buf(),
            filename_stem: "test_vid_123".to_string(),
            format_spec: None,
        };

        let progress_count = Arc::new(AtomicU32::new(0));
        let progress_count_clone = Arc::clone(&progress_count);

        let path = downloader
            .download(
                "test://offline-video-fixture",
                options,
                None,
                move |prog| {
                    if prog.percent > 0.0 {
                        progress_count_clone.fetch_add(1, Ordering::SeqCst);
                    }
                },
            )
            .await
            .expect("Download should succeed for offline fixture");

        assert!(path.exists());
        assert!(progress_count.load(Ordering::SeqCst) > 0);
    }

    #[tokio::test]
    async fn test_cancellation_during_download() {
        let cmd = YtDlpCommand::detect().await.unwrap();
        let downloader = VideoDownloader::new(cmd);

        let temp = tempfile::tempdir().unwrap();
        let options = DownloaderOptions {
            output_dir: temp.path().to_path_buf(),
            filename_stem: "cancel_vid".to_string(),
            format_spec: None,
        };

        let token = CancellationToken::new();
        token.cancel(); // Pre-cancelled

        let res = downloader
            .download("test://cancelled", options, Some(token), |_| {})
            .await;

        assert!(res.is_err());
        assert!(matches!(res, Err(CoreError::Cancelled(_))));
    }

    #[tokio::test]
    async fn test_argument_injection_rejection() {
        let cmd = YtDlpCommand::detect().await.unwrap();
        let downloader = VideoDownloader::new(cmd);

        let temp = tempfile::tempdir().unwrap();
        let options = DownloaderOptions {
            output_dir: temp.path().to_path_buf(),
            filename_stem: "injection_test".to_string(),
            format_spec: None,
        };

        let res = downloader
            .download("--exec=touch /tmp/pwned", options.clone(), None, |_| {})
            .await;
        assert!(res.is_err());
        assert!(matches!(res, Err(CoreError::ValidationError(_))));

        let res2 = downloader
            .download("-o /tmp/pwned", options, None, |_| {})
            .await;
        assert!(res2.is_err());
        assert!(matches!(res2, Err(CoreError::ValidationError(_))));
    }

    #[tokio::test]
    async fn test_lfi_restricted_path_rejection() {
        let cmd = YtDlpCommand::detect().await.unwrap();
        let downloader = VideoDownloader::new(cmd);

        let temp = tempfile::tempdir().unwrap();
        let options = DownloaderOptions {
            output_dir: temp.path().to_path_buf(),
            filename_stem: "lfi_test".to_string(),
            format_spec: None,
        };

        // Attempting to read /etc/passwd via file://
        let res = downloader
            .download("file:///etc/passwd", options.clone(), None, |_| {})
            .await;
        assert!(res.is_err());

        // Attempting to read /etc/hosts via redundant slashes and dot segments
        let res_hosts1 = downloader
            .download("file:////etc/hosts", options.clone(), None, |_| {})
            .await;
        assert!(res_hosts1.is_err());

        let res_hosts2 = downloader
            .download("file:///./etc/hosts", options.clone(), None, |_| {})
            .await;
        assert!(res_hosts2.is_err());

        let res_hosts3 = downloader
            .download("file:///etc/hosts", options.clone(), None, |_| {})
            .await;
        assert!(res_hosts3.is_err());

        // Attempting to read backend/.env
        let res2 = downloader
            .download("file://backend/.env", options.clone(), None, |_| {})
            .await;
        assert!(res2.is_err());

        // Attempting path traversal
        let res3 = downloader
            .download("file://../../etc/shadow", options, None, |_| {})
            .await;
        assert!(res3.is_err());
    }
}
