use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};
use uuid::Uuid;

use crate::errors::{CoreError, Result};
use crate::models::Clip;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum JobState {
    #[serde(alias = "pending")]
    Queued,
    Downloading,
    Analyzing,
    Cutting,
    Completed,
    Failed,
    Cancelled,
}

impl JobState {
    #[allow(non_upper_case_globals)]
    pub const Pending: JobState = JobState::Queued;

    pub fn is_terminal(&self) -> bool {
        matches!(self, Self::Completed | Self::Failed | Self::Cancelled)
    }

    pub fn can_transition_to(&self, next: &JobState) -> bool {
        if self == next {
            return true;
        }

        match (self, next) {
            (JobState::Queued, JobState::Downloading) => true,
            (JobState::Queued, JobState::Analyzing) => true,
            (JobState::Downloading, JobState::Analyzing) => true,
            (JobState::Analyzing, JobState::Cutting) => true,
            (JobState::Cutting, JobState::Completed) => true,

            // Any non-terminal state can fail or be cancelled
            (s, JobState::Failed) if !s.is_terminal() => true,
            (s, JobState::Cancelled) if !s.is_terminal() => true,

            _ => false,
        }
    }
}

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct JobStatusRecord {
    pub job_id: Uuid,
    pub status: JobState,
    pub progress_percent: u8,
    pub clips: Vec<Clip>,
    pub error_message: Option<String>,
    pub created_at: DateTime<Utc>,
    pub updated_at: DateTime<Utc>,
    pub execution_duration_ms: Option<u64>,
}

impl JobStatusRecord {
    pub fn new(job_id: Uuid) -> Self {
        let now = Utc::now();
        Self {
            job_id,
            status: JobState::Queued,
            progress_percent: 0,
            clips: Vec::new(),
            error_message: None,
            created_at: now,
            updated_at: now,
            execution_duration_ms: None,
        }
    }

    pub fn transition_to(&mut self, next: JobState) -> Result<()> {
        if !self.status.can_transition_to(&next) {
            return Err(CoreError::InvalidStateTransition {
                from: format!("{:?}", self.status),
                to: format!("{:?}", next),
            });
        }
        self.status = next;
        self.updated_at = Utc::now();
        if next == JobState::Completed {
            self.progress_percent = 100;
        }
        Ok(())
    }

    pub fn fail(&mut self, error_msg: impl Into<String>) {
        self.status = JobState::Failed;
        self.error_message = Some(error_msg.into());
        self.updated_at = Utc::now();
    }

    pub fn cancel(&mut self) {
        self.status = JobState::Cancelled;
        self.updated_at = Utc::now();
    }

    pub fn set_progress(&mut self, percent: u8) {
        self.progress_percent = percent.min(100);
        self.updated_at = Utc::now();
    }

    pub fn complete(&mut self, clips: Vec<Clip>, duration_ms: u64) -> Result<()> {
        self.transition_to(JobState::Completed)?;
        self.clips = clips;
        self.execution_duration_ms = Some(duration_ms);
        self.progress_percent = 100;
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_valid_lifecycle_transitions() {
        let mut job = JobStatusRecord::new(Uuid::new_v4());
        assert_eq!(job.status, JobState::Queued);
        assert_eq!(job.status, JobState::Pending);

        let json = serde_json::to_string(&job).unwrap();
        assert!(json.contains(r#""status":"queued""#));

        // Deserializing "queued" works
        let deserialized: JobStatusRecord = serde_json::from_str(&json).unwrap();
        assert_eq!(deserialized.status, JobState::Queued);

        // Deserializing legacy "pending" also works
        let legacy_json = json.replace(r#""status":"queued""#, r#""status":"pending""#);
        let from_legacy: JobStatusRecord = serde_json::from_str(&legacy_json).unwrap();
        assert_eq!(from_legacy.status, JobState::Queued);

        job.transition_to(JobState::Downloading).unwrap();
        assert_eq!(job.status, JobState::Downloading);

        job.transition_to(JobState::Analyzing).unwrap();
        assert_eq!(job.status, JobState::Analyzing);

        job.transition_to(JobState::Cutting).unwrap();
        assert_eq!(job.status, JobState::Cutting);

        job.complete(vec![], 1200).unwrap();
        assert_eq!(job.status, JobState::Completed);
        assert_eq!(job.progress_percent, 100);
        assert_eq!(job.execution_duration_ms, Some(1200));
    }

    #[test]
    fn test_invalid_lifecycle_transition() {
        let mut job = JobStatusRecord::new(Uuid::new_v4());
        let res = job.transition_to(JobState::Completed);
        assert!(res.is_err());
        assert!(matches!(res, Err(CoreError::InvalidStateTransition { .. })));
    }

    #[test]
    fn test_cancellation_from_any_active_state() {
        let mut job = JobStatusRecord::new(Uuid::new_v4());
        job.transition_to(JobState::Downloading).unwrap();
        job.cancel();
        assert_eq!(job.status, JobState::Cancelled);
        assert!(job.status.is_terminal());

        // Cancelled cannot transition to completed
        assert!(!job.status.can_transition_to(&JobState::Completed));
    }

    #[test]
    fn test_failure_from_active_state() {
        let mut job = JobStatusRecord::new(Uuid::new_v4());
        job.transition_to(JobState::Analyzing).unwrap();
        job.fail("Corrupt metadata");
        assert_eq!(job.status, JobState::Failed);
        assert_eq!(job.error_message.as_deref(), Some("Corrupt metadata"));
    }
}
