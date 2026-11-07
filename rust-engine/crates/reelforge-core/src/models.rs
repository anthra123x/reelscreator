use std::path::{Component, Path, PathBuf};
use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};
use uuid::Uuid;

use crate::errors::{CoreError, Result};

#[derive(Debug, Clone, Copy, PartialEq, Eq, Default, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum CutStrategy {
    #[default]
    FixedInterval,
    SceneDetection,
    SilenceDetection,
    ManualTimestamps,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Default, Serialize, Deserialize)]
pub enum AspectRatio {
    #[serde(rename = "9:16")]
    #[default]
    Ratio9x16,
    #[serde(rename = "16:9")]
    Ratio16x9,
    #[serde(rename = "1:1")]
    Ratio1x1,
    #[serde(rename = "4:5")]
    Ratio4x5,
}

impl AspectRatio {
    pub fn target_dimensions(&self) -> Dimensions {
        match self {
            Self::Ratio9x16 => Dimensions {
                width: 1080,
                height: 1920,
            },
            Self::Ratio16x9 => Dimensions {
                width: 1920,
                height: 1080,
            },
            Self::Ratio1x1 => Dimensions {
                width: 1080,
                height: 1080,
            },
            Self::Ratio4x5 => Dimensions {
                width: 1080,
                height: 1350,
            },
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Default, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum VerticalMode {
    #[default]
    BlurPadding,
    CenterCrop,
    Letterbox,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
pub struct Dimensions {
    pub width: u32,
    pub height: u32,
}

impl Default for Dimensions {
    fn default() -> Self {
        Self {
            width: 1080,
            height: 1920,
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Serialize, Deserialize)]
pub struct TimeRange {
    pub start: f64,
    pub end: f64,
}

impl TimeRange {
    pub fn new(start: f64, end: f64) -> Result<Self> {
        if start < 0.0 {
            return Err(CoreError::ValidationError(
                "Start time must be non-negative".to_string(),
            ));
        }
        if end <= start {
            return Err(CoreError::ValidationError(
                "End time must be strictly greater than start time".to_string(),
            ));
        }
        Ok(Self { start, end })
    }

    pub fn duration(&self) -> f64 {
        self.end - self.start
    }
}

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct VideoMetadata {
    pub duration_seconds: f64,
    pub width: u32,
    pub height: u32,
    pub video_codec: String,
    pub audio_codec: Option<String>,
    pub bitrate_bps: Option<u64>,
    pub format_name: String,
}

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct DownloadProgress {
    pub percent: f32,
    pub speed_bytes_sec: Option<u64>,
    pub eta_seconds: Option<u32>,
}

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct Clip {
    pub clip_id: Uuid,
    pub file_path: String,
    pub thumbnail_path: String,
    pub start_time: f64,
    pub end_time: f64,
    pub duration: f64,
    pub width: u32,
    pub height: u32,
}

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct VideoTask {
    pub job_id: Uuid,
    pub source_url: Option<String>,
    pub local_video_path: Option<String>,
    #[serde(default)]
    pub strategy: CutStrategy,
    #[serde(default = "default_interval")]
    pub interval_seconds: f64,
    #[serde(default = "default_max_duration")]
    pub max_duration_seconds: f64,
    #[serde(default)]
    pub aspect_ratio: AspectRatio,
    #[serde(default)]
    pub vertical_mode: VerticalMode,
    pub output_dir: String,
    pub webhook_url: Option<String>,
    pub custom_ranges: Option<Vec<TimeRange>>,
    #[serde(default = "Utc::now")]
    pub created_at: DateTime<Utc>,
}

fn default_interval() -> f64 {
    30.0
}

fn default_max_duration() -> f64 {
    60.0
}

/// Validate path against directory traversal, sensitive files, and restricted system locations.
pub fn validate_path_security(path_str: &str, field_name: &str) -> Result<()> {
    let trimmed = path_str.trim();
    if trimmed.is_empty() {
        return Err(CoreError::ValidationError(format!(
            "{} cannot be empty", field_name
        )));
    }

    // 1. Strip file:// scheme if present (case-insensitive)
    let stripped = if trimmed.len() >= 7 && trimmed[..7].eq_ignore_ascii_case("file://") {
        &trimmed[7..]
    } else {
        trimmed
    };
    let stripped_trimmed = stripped.trim();
    if stripped_trimmed.is_empty() {
        return Err(CoreError::ValidationError(format!(
            "{} cannot be empty", field_name
        )));
    }

    // 2. Reject user home expansion
    if stripped_trimmed.starts_with('~') {
        return Err(CoreError::ValidationError(format!(
            "{} must not target user home directory ('~')", field_name
        )));
    }

    // 3. Normalize all backslashes to forward slashes before parsing
    let normalized_str = stripped_trimmed.replace('\\', "/");

    // 4. Reject encoded traversal sequences and null bytes
    if normalized_str.contains("%2e")
        || normalized_str.contains("%2E")
        || normalized_str.contains("%2f")
        || normalized_str.contains("%2F")
        || normalized_str.contains("%00")
        || normalized_str.contains('\0')
    {
        return Err(CoreError::ValidationError(format!(
            "{} contains invalid or encoded path sequences", field_name
        )));
    }

    // 5. Canonicalize components using std::path::Component
    let path = Path::new(&normalized_str);
    let mut norm_buf = PathBuf::new();

    for comp in path.components() {
        match comp {
            Component::Prefix(p) => norm_buf.push(p.as_os_str()),
            Component::RootDir => norm_buf.push("/"),
            Component::CurDir => {
                // Eliminate current-directory dots (./ or /./)
            }
            Component::ParentDir => {
                return Err(CoreError::ValidationError(format!(
                    "{} must not contain parent directory traversal ('..')", field_name
                )));
            }
            Component::Normal(c) => {
                let s = c.to_string_lossy();
                if s == ".env"
                    || s.starts_with(".env.")
                    || s == ".git"
                    || s == ".ssh"
                    || s == "passwd"
                    || s == "shadow"
                    || s == "hosts"
                {
                    return Err(CoreError::ValidationError(format!(
                        "{} must not reference sensitive files or directories ('{}')", field_name, s
                    )));
                }
                norm_buf.push(c);
            }
        }
    }

    // 6. Direct filesystem root rejection
    if norm_buf == Path::new("/") {
        return Err(CoreError::ValidationError(format!(
            "{} cannot target root filesystem directory ('/')", field_name
        )));
    }

    // 7. Standalone root temp directory write restriction (/tmp or //tmp or /./tmp)
    if norm_buf == Path::new("/tmp") {
        return Err(CoreError::ValidationError(format!(
            "{} cannot directly target root '/tmp'; a dedicated subdirectory is required", field_name
        )));
    }

    // 8. Restricted system directory prefix checks against canonical normalized path
    let restricted_prefixes = [
        "/etc", "/root", "/bin", "/sbin", "/usr", "/var", "/proc", "/sys", "/dev", "/boot", "/lib", "/lib64",
    ];

    for prefix in &restricted_prefixes {
        let prefix_path = Path::new(prefix);
        if norm_buf.starts_with(prefix_path) {
            return Err(CoreError::ValidationError(format!(
                "{} targets restricted system directory ('{}')", field_name, prefix
            )));
        }
    }

    // 9. Relative path prefix checks (e.g. etc/hosts or root/.ssh)
    if let Some(Component::Normal(c)) = norm_buf.components().next() {
        let s = c.to_string_lossy();
        let restricted_names = [
            "etc", "root", "bin", "sbin", "usr", "var", "proc", "sys", "dev", "boot", "lib", "lib64",
        ];
        if restricted_names.contains(&s.as_ref()) {
            return Err(CoreError::ValidationError(format!(
                "{} targets restricted system directory ('{}')", field_name, s
            )));
        }
    }

    Ok(())
}

impl VideoTask {
    pub fn validate(&self) -> Result<()> {
        if self.source_url.is_none() && self.local_video_path.is_none() {
            return Err(CoreError::ValidationError(
                "Either source_url or local_video_path must be provided".to_string(),
            ));
        }
        if !self.interval_seconds.is_finite() || self.interval_seconds < 1.0 {
            return Err(CoreError::ValidationError(
                "interval_seconds must be a finite number >= 1.0".to_string(),
            ));
        }
        if !self.max_duration_seconds.is_finite() || self.max_duration_seconds < 1.0 {
            return Err(CoreError::ValidationError(
                "max_duration_seconds must be a finite number >= 1.0".to_string(),
            ));
        }
        validate_path_security(&self.output_dir, "output_dir")?;
        if let Some(local_path) = &self.local_video_path {
            validate_path_security(local_path, "local_video_path")?;
        }
        if let Some(ranges) = &self.custom_ranges {
            for range in ranges {
                if !range.start.is_finite()
                    || !range.end.is_finite()
                    || range.start < 0.0
                    || range.end <= range.start
                {
                    return Err(CoreError::ValidationError(format!(
                        "Invalid custom range [{}, {}]",
                        range.start, range.end
                    )));
                }
            }
        }
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_task_serialization_roundtrip() {
        let job_id = Uuid::new_v4();
        let task = VideoTask {
            job_id,
            source_url: Some("https://example.com/video.mp4".to_string()),
            local_video_path: None,
            strategy: CutStrategy::FixedInterval,
            interval_seconds: 30.0,
            max_duration_seconds: 60.0,
            aspect_ratio: AspectRatio::Ratio9x16,
            vertical_mode: VerticalMode::BlurPadding,
            output_dir: "media/clips/test".to_string(),
            webhook_url: None,
            custom_ranges: None,
            created_at: Utc::now(),
        };

        task.validate().expect("Task should be valid");

        let json = serde_json::to_string(&task).unwrap();
        let deserialized: VideoTask = serde_json::from_str(&json).unwrap();
        assert_eq!(deserialized.job_id, job_id);
        assert_eq!(deserialized.strategy, CutStrategy::FixedInterval);
        assert_eq!(deserialized.aspect_ratio, AspectRatio::Ratio9x16);
    }

    #[test]
    fn test_task_validation_missing_source() {
        let task = VideoTask {
            job_id: Uuid::new_v4(),
            source_url: None,
            local_video_path: None,
            strategy: CutStrategy::FixedInterval,
            interval_seconds: 30.0,
            max_duration_seconds: 60.0,
            aspect_ratio: AspectRatio::Ratio9x16,
            vertical_mode: VerticalMode::BlurPadding,
            output_dir: "media/clips/test".to_string(),
            webhook_url: None,
            custom_ranges: None,
            created_at: Utc::now(),
        };

        assert!(task.validate().is_err());
    }

    #[test]
    fn test_aspect_ratio_dimensions() {
        assert_eq!(
            AspectRatio::Ratio9x16.target_dimensions(),
            Dimensions {
                width: 1080,
                height: 1920
            }
        );
        assert_eq!(
            AspectRatio::Ratio16x9.target_dimensions(),
            Dimensions {
                width: 1920,
                height: 1080
            }
        );
    }

    #[test]
    fn test_time_range_validation() {
        assert!(TimeRange::new(-1.0, 10.0).is_err());
        assert!(TimeRange::new(10.0, 5.0).is_err());
        assert!(TimeRange::new(5.0, 5.0).is_err());
        let tr = TimeRange::new(0.0, 15.5).unwrap();
        assert_eq!(tr.duration(), 15.5);
    }

    #[test]
    fn test_task_validation_hardening() {
        let mut task = VideoTask {
            job_id: Uuid::new_v4(),
            source_url: Some("https://example.com/video.mp4".to_string()),
            local_video_path: None,
            strategy: CutStrategy::FixedInterval,
            interval_seconds: 30.0,
            max_duration_seconds: 60.0,
            aspect_ratio: AspectRatio::Ratio9x16,
            vertical_mode: VerticalMode::BlurPadding,
            output_dir: "media/clips/test".to_string(),
            webhook_url: None,
            custom_ranges: None,
            created_at: Utc::now(),
        };

        // Baseline valid
        assert!(task.validate().is_ok());

        // NaN interval rejection
        task.interval_seconds = f64::NAN;
        assert!(task.validate().is_err());

        // Sub-1.0 interval rejection
        task.interval_seconds = 0.5;
        assert!(task.validate().is_err());
        task.interval_seconds = 30.0;

        // NaN max_duration rejection
        task.max_duration_seconds = f64::NAN;
        assert!(task.validate().is_err());

        // Sub-1.0 max_duration rejection
        task.max_duration_seconds = 0.2;
        assert!(task.validate().is_err());
        task.max_duration_seconds = 60.0;

        // Path traversal in output_dir
        task.output_dir = "media/clips/../../etc".to_string();
        assert!(task.validate().is_err());

        // Restricted system directories in output_dir
        task.output_dir = "/etc/reelforge".to_string();
        assert!(task.validate().is_err());
        task.output_dir = "/root/secret".to_string();
        assert!(task.validate().is_err());
        task.output_dir = "/var/log".to_string();
        assert!(task.validate().is_err());
        task.output_dir = "/tmp".to_string();
        assert!(task.validate().is_err());
        task.output_dir = "~/my_media".to_string();
        assert!(task.validate().is_err());

        // Sensitive files
        task.output_dir = "storage/.env".to_string();
        assert!(task.validate().is_err());
        task.output_dir = "storage/passwd".to_string();
        assert!(task.validate().is_err());

        // Reset output_dir to valid
        task.output_dir = "media/clips/test".to_string();
        assert!(task.validate().is_ok());

        // local_video_path validation
        task.local_video_path = Some("/etc/passwd".to_string());
        assert!(task.validate().is_err());
        task.local_video_path = Some("../sensitive/vid.mp4".to_string());
        assert!(task.validate().is_err());
        task.local_video_path = Some("storage/media/valid.mp4".to_string());
        assert!(task.validate().is_ok());
    }

    #[test]
    fn test_validate_path_security_edge_cases() {
        assert!(validate_path_security("", "test").is_err());
        assert!(validate_path_security("   ", "test").is_err());
        assert!(validate_path_security("/bin/bash", "test").is_err());
        assert!(validate_path_security("/sbin/iptables", "test").is_err());
        assert!(validate_path_security("/usr/bin", "test").is_err());
        assert!(validate_path_security("/proc/cpuinfo", "test").is_err());
        assert!(validate_path_security("/sys/kernel", "test").is_err());
        assert!(validate_path_security("/dev/null", "test").is_err());
        assert!(validate_path_security("/tmp", "test").is_err());
        assert!(validate_path_security("/tmp/", "test").is_err());
        assert!(validate_path_security("//tmp", "test").is_err());
        assert!(validate_path_security("/./tmp", "test").is_err());
        assert!(validate_path_security("/", "test").is_err());
        assert!(validate_path_security("///", "test").is_err());
        assert!(validate_path_security("/tmp/reelforge_scratch", "test").is_ok());
        assert!(validate_path_security("media/clips/123", "test").is_ok());
        assert!(validate_path_security("file:///etc/hosts", "test").is_err());
        assert!(validate_path_security("file:////etc/hosts", "test").is_err());
        assert!(validate_path_security("file:///./etc/hosts", "test").is_err());
        assert!(validate_path_security("//etc/hosts", "test").is_err());
        assert!(validate_path_security("/./etc/hosts", "test").is_err());
        assert!(validate_path_security(r"..\..\etc\passwd", "test").is_err());
        assert!(validate_path_security("file:///var/run", "test").is_err());
        assert!(validate_path_security("storage/hosts", "test").is_err());
    }
}
