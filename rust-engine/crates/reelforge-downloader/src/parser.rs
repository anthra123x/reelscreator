use regex::Regex;
use reelforge_core::DownloadProgress;
use std::sync::LazyLock;

static RE_PERCENT: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"(?i)\[download\]\s+([0-9]+(?:\.[0-9]+)?)\s*%").expect("Invalid percent regex")
});

static RE_SPEED: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"(?i)at\s+([0-9]+(?:\.[0-9]+)?)\s*([KMGT]?i?B)/s").expect("Invalid speed regex")
});

static RE_ETA: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"(?i)ETA\s+(?:([0-9]{1,2}):)?([0-9]{2}):([0-9]{2})").expect("Invalid ETA regex")
});

pub fn parse_progress_line(line: &str) -> Option<DownloadProgress> {
    let percent_caps = RE_PERCENT.captures(line)?;
    let percent = percent_caps.get(1)?.as_str().parse::<f32>().ok()?;

    let speed_bytes_sec = RE_SPEED.captures(line).and_then(|caps| {
        let val = caps.get(1)?.as_str().parse::<f64>().ok()?;
        let unit = caps.get(2)?.as_str();
        let multiplier: f64 = match unit {
            "B" => 1.0,
            "KB" => 1_000.0,
            "MB" => 1_000_000.0,
            "GB" => 1_000_000_000.0,
            "KiB" => 1024.0,
            "MiB" => 1024.0 * 1024.0,
            "GiB" => 1024.0 * 1024.0 * 1024.0,
            _ => 1024.0 * 1024.0,
        };
        Some((val * multiplier) as u64)
    });

    let eta_seconds = RE_ETA.captures(line).and_then(|caps| {
        let (hours, mins, secs) = match (caps.get(1), caps.get(2), caps.get(3)) {
            (Some(h), Some(m), Some(s)) => (
                h.as_str().parse::<u32>().ok()?,
                m.as_str().parse::<u32>().ok()?,
                s.as_str().parse::<u32>().ok()?,
            ),
            (None, Some(m), Some(s)) => (
                0,
                m.as_str().parse::<u32>().ok()?,
                s.as_str().parse::<u32>().ok()?,
            ),
            _ => return None,
        };
        Some(hours * 3600 + mins * 60 + secs)
    });

    Some(DownloadProgress {
        percent,
        speed_bytes_sec,
        eta_seconds,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_parse_standard_progress() {
        let line = "[download]  45.2% of 12.50MiB at 2.10MiB/s ETA 00:03";
        let prog = parse_progress_line(line).expect("Should parse");
        assert!((prog.percent - 45.2).abs() < f32::EPSILON);
        assert_eq!(prog.eta_seconds, Some(3));
        assert!(prog.speed_bytes_sec.is_some());
        assert_eq!(prog.speed_bytes_sec.unwrap(), (2.10 * 1024.0 * 1024.0) as u64);
    }

    #[test]
    fn test_parse_100_percent_completion() {
        let line = "[download] 100% of 12.50MiB in 00:05";
        let prog = parse_progress_line(line).expect("Should parse");
        assert!((prog.percent - 100.0).abs() < f32::EPSILON);
        assert_eq!(prog.speed_bytes_sec, None);
        assert_eq!(prog.eta_seconds, None);
    }

    #[test]
    fn test_parse_with_hours_eta() {
        let line = "[download]  10.0% of 1.00GiB at 1.50MiB/s ETA 01:05:30";
        let prog = parse_progress_line(line).expect("Should parse");
        assert_eq!(prog.eta_seconds, Some(3600 + 5 * 60 + 30));
    }

    #[test]
    fn test_parse_approximate_size() {
        let line = "[download]   5.0% of ~50.00MiB at 500.00KiB/s ETA 01:30";
        let prog = parse_progress_line(line).expect("Should parse");
        assert!((prog.percent - 5.0).abs() < f32::EPSILON);
        assert_eq!(prog.speed_bytes_sec.unwrap(), (500.0 * 1024.0) as u64);
        assert_eq!(prog.eta_seconds, Some(90));
    }

    #[test]
    fn test_parse_non_progress_line() {
        let line = "[download] Destination: /tmp/test.mp4";
        assert!(parse_progress_line(line).is_none());

        let line2 = "[youtube] Extracting URL: https://example.com/video";
        assert!(parse_progress_line(line2).is_none());
    }
}
