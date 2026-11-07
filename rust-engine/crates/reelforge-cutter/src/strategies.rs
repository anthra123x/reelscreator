use reelforge_core::{CutStrategy, Result, TimeRange};
use tracing::debug;

pub fn plan_cut_intervals(
    strategy: CutStrategy,
    total_duration_seconds: f64,
    interval_seconds: f64,
    max_duration_seconds: f64,
    custom_ranges: Option<&[TimeRange]>,
) -> Result<Vec<TimeRange>> {
    let mut intervals = Vec::new();

    if total_duration_seconds <= 0.0 {
        return Ok(intervals);
    }

    match strategy {
        CutStrategy::ManualTimestamps => {
            if let Some(ranges) = custom_ranges {
                for r in ranges {
                    let start = r.start.max(0.0).min(total_duration_seconds);
                    let end = r.end.max(0.0).min(total_duration_seconds);
                    if end > start + 0.5 {
                        intervals.push(TimeRange { start, end });
                    }
                }
            }
        }
        CutStrategy::FixedInterval | CutStrategy::SceneDetection | CutStrategy::SilenceDetection => {
            let step = if interval_seconds.is_finite() && interval_seconds > 0.0 {
                interval_seconds
            } else {
                30.0
            };

            let max_clip_duration = if max_duration_seconds.is_finite() && max_duration_seconds > 0.0 {
                max_duration_seconds
            } else {
                60.0
            };

            let effective_step = step.min(max_clip_duration).max(1.0);
            let mut current = 0.0;

            while current < total_duration_seconds {
                let end = (current + effective_step).min(total_duration_seconds);
                if end - current >= 1.0 {
                    intervals.push(TimeRange {
                        start: current,
                        end,
                    });
                }
                current += effective_step;
            }
        }
    }

    debug!(
        "Planned {} cut intervals for duration {:.2}s using strategy {:?}",
        intervals.len(),
        total_duration_seconds,
        strategy
    );

    Ok(intervals)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_fixed_interval_planning() {
        let ranges = plan_cut_intervals(CutStrategy::FixedInterval, 65.0, 30.0, 60.0, None).unwrap();
        assert_eq!(ranges.len(), 3);
        assert_eq!(ranges[0], TimeRange { start: 0.0, end: 30.0 });
        assert_eq!(ranges[1], TimeRange { start: 30.0, end: 60.0 });
        assert_eq!(ranges[2], TimeRange { start: 60.0, end: 65.0 });
    }

    #[test]
    fn test_manual_timestamps_planning() {
        let input = vec![
            TimeRange { start: 2.0, end: 12.0 },
            TimeRange { start: 20.0, end: 50.0 },
        ];
        let ranges =
            plan_cut_intervals(CutStrategy::ManualTimestamps, 40.0, 30.0, 60.0, Some(&input))
                .unwrap();
        assert_eq!(ranges.len(), 2);
        assert_eq!(ranges[0], TimeRange { start: 2.0, end: 12.0 });
        assert_eq!(ranges[1], TimeRange { start: 20.0, end: 40.0 }); // clamped to duration 40.0
    }

    #[test]
    fn test_subnormal_and_tiny_interval_does_not_infinite_loop() {
        // Subnormal float like 1e-18 must be safely clamped to minimum 1.0 step
        let ranges =
            plan_cut_intervals(CutStrategy::FixedInterval, 5.0, 1e-18, 60.0, None).unwrap();
        assert_eq!(ranges.len(), 5);

        // NaN interval falls back safely
        let ranges_nan =
            plan_cut_intervals(CutStrategy::FixedInterval, 65.0, f64::NAN, 60.0, None).unwrap();
        assert_eq!(ranges_nan.len(), 3);
    }
}
