use reelforge_core::JobStatusRecord;
use std::collections::HashMap;
use std::sync::Arc;
use std::time::Instant;
use tokio::sync::{RwLock, Semaphore};
use tokio_util::sync::CancellationToken;
use uuid::Uuid;

pub const DEFAULT_MAX_JOB_RETENTION: usize = 1000;
pub const DEFAULT_JOB_TTL_SECS: i64 = 3600; // 1 hour

#[derive(Clone)]
pub struct AppState {
    pub jobs: Arc<RwLock<HashMap<Uuid, JobStatusRecord>>>,
    pub semaphore: Arc<Semaphore>,
    pub max_concurrent_jobs: usize,
    pub cancellations: Arc<RwLock<HashMap<Uuid, CancellationToken>>>,
    pub start_time: Instant,
}

impl AppState {
    pub fn new(max_concurrent_jobs: usize) -> Self {
        Self {
            jobs: Arc::new(RwLock::new(HashMap::new())),
            semaphore: Arc::new(Semaphore::new(max_concurrent_jobs)),
            max_concurrent_jobs,
            cancellations: Arc::new(RwLock::new(HashMap::new())),
            start_time: Instant::now(),
        }
    }

    pub fn available_permits(&self) -> usize {
        self.semaphore.available_permits()
    }

    pub fn active_jobs_count(&self) -> usize {
        self.max_concurrent_jobs.saturating_sub(self.semaphore.available_permits())
    }

    pub fn uptime_seconds(&self) -> u64 {
        self.start_time.elapsed().as_secs()
    }

    /// Prune completed/failed jobs based on default TTL and retention limits.
    pub async fn prune_jobs(&self) {
        self.prune_jobs_with_policy(DEFAULT_JOB_TTL_SECS, DEFAULT_MAX_JOB_RETENTION)
            .await;
    }

    /// Prune terminal jobs with explicit TTL and size limits.
    pub async fn prune_jobs_with_policy(&self, ttl_seconds: i64, max_jobs: usize) {
        let mut jobs = self.jobs.write().await;
        let now = chrono::Utc::now();

        // 1. Evict terminal jobs older than TTL
        jobs.retain(|_, record| {
            if record.status.is_terminal() {
                let age = (now - record.updated_at).num_seconds();
                age < ttl_seconds
            } else {
                true // Retain active and queued jobs
            }
        });

        // 2. If count exceeds max_jobs, evict oldest terminal jobs
        if jobs.len() > max_jobs {
            let mut terminal_jobs: Vec<(Uuid, chrono::DateTime<chrono::Utc>)> = jobs
                .iter()
                .filter(|(_, r)| r.status.is_terminal())
                .map(|(&id, r)| (id, r.updated_at))
                .collect();

            terminal_jobs.sort_by_key(|(_, updated_at)| *updated_at);

            let excess = jobs.len().saturating_sub(max_jobs);
            for (id, _) in terminal_jobs.into_iter().take(excess) {
                jobs.remove(&id);
            }
        }

        // 3. Prune orphaned and terminal cancellation tokens
        let active_ids: std::collections::HashSet<Uuid> = jobs
            .iter()
            .filter(|(_, r)| !r.status.is_terminal())
            .map(|(&id, _)| id)
            .collect();

        // Drop jobs write lock before acquiring cancellations lock to avoid lock inversion
        drop(jobs);

        let mut cancellations = self.cancellations.write().await;
        cancellations.retain(|id, _| active_ids.contains(id));
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use chrono::Duration;
    use reelforge_core::JobState;

    #[tokio::test]
    async fn test_prune_expired_terminal_jobs() {
        let state = AppState::new(1);
        let id1 = Uuid::new_v4();
        let id2 = Uuid::new_v4();

        let mut rec1 = JobStatusRecord::new(id1);
        rec1.status = JobState::Completed;
        rec1.updated_at = chrono::Utc::now() - Duration::seconds(7200); // 2h ago

        let mut rec2 = JobStatusRecord::new(id2);
        rec2.status = JobState::Queued;
        rec2.updated_at = chrono::Utc::now() - Duration::seconds(7200);

        {
            let mut jobs = state.jobs.write().await;
            jobs.insert(id1, rec1);
            jobs.insert(id2, rec2);
        }

        state.prune_jobs_with_policy(3600, 100).await;

        let jobs = state.jobs.read().await;
        assert!(!jobs.contains_key(&id1), "Expired completed job must be pruned");
        assert!(jobs.contains_key(&id2), "Queued job must NOT be pruned");
    }

    #[tokio::test]
    async fn test_prune_excess_terminal_jobs() {
        let state = AppState::new(1);
        let id_old = Uuid::new_v4();
        let id_new = Uuid::new_v4();

        let mut rec_old = JobStatusRecord::new(id_old);
        rec_old.status = JobState::Completed;
        rec_old.updated_at = chrono::Utc::now() - Duration::seconds(50);

        let mut rec_new = JobStatusRecord::new(id_new);
        rec_new.status = JobState::Completed;
        rec_new.updated_at = chrono::Utc::now();

        {
            let mut jobs = state.jobs.write().await;
            jobs.insert(id_old, rec_old);
            jobs.insert(id_new, rec_new);
        }

        // Limit to 1 max job with generous TTL
        state.prune_jobs_with_policy(3600, 1).await;

        let jobs = state.jobs.read().await;
        assert_eq!(jobs.len(), 1);
        assert!(!jobs.contains_key(&id_old), "Oldest terminal job must be evicted");
        assert!(jobs.contains_key(&id_new), "Newer terminal job must be retained");
    }
}
