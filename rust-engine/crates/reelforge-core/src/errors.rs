use std::path::PathBuf;
use thiserror::Error;
use uuid::Uuid;

#[derive(Debug, Error)]
pub enum CoreError {
    #[error("Validation error: {0}")]
    ValidationError(String),

    #[error("Download error: {0}")]
    DownloadError(String),

    #[error("Cutter error: {0}")]
    CutterError(String),

    #[error("Probe error: {0}")]
    ProbeError(String),

    #[error("Job not found: {0}")]
    JobNotFound(Uuid),

    #[error("Invalid state transition from '{from}' to '{to}'")]
    InvalidStateTransition { from: String, to: String },

    #[error("Process execution failed for command '{command}' (exit code: {exit_code:?}): {stderr}")]
    ProcessFailed {
        command: String,
        exit_code: Option<i32>,
        stderr: String,
    },

    #[error("File not found at path: {0}")]
    FileNotFound(PathBuf),

    #[error("Job was cancelled: {0}")]
    Cancelled(String),

    #[error("Concurrency limit exceeded: {0}")]
    CapacityExceeded(String),

    #[error("I/O error: {0}")]
    Io(#[from] std::io::Error),

    #[error("JSON serialization/deserialization error: {0}")]
    Serialization(#[from] serde_json::Error),
}

pub type Result<T> = std::result::Result<T, CoreError>;

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_error_display_formatting() {
        let err = CoreError::ValidationError("URL cannot be empty".to_string());
        assert_eq!(err.to_string(), "Validation error: URL cannot be empty");

        let err2 = CoreError::InvalidStateTransition {
            from: "Pending".to_string(),
            to: "Completed".to_string(),
        };
        assert_eq!(
            err2.to_string(),
            "Invalid state transition from 'Pending' to 'Completed'"
        );
    }

    #[test]
    fn test_io_error_conversion() {
        let io_err = std::io::Error::new(std::io::ErrorKind::NotFound, "disk read failure");
        let core_err: CoreError = io_err.into();
        assert!(matches!(core_err, CoreError::Io(_)));
    }
}
