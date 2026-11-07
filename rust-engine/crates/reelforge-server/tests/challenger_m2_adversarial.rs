use reelforge_core::{validate_path_security, CoreError};
use reelforge_downloader::fixtures::is_offline_or_test_url;
use reelforge_downloader::ytdlp::{DownloaderOptions, VideoDownloader, YtDlpCommand};
use tempfile::tempdir;

/// Adversarial Test Suite 1: Argument Injection in yt-dlp
#[tokio::test]
async fn test_adversarial_ytdlp_argument_injection_vectors() {
    let cmd = YtDlpCommand::detect().await.expect("yt-dlp command detection");
    let downloader = VideoDownloader::new(cmd);

    let tmp = tempdir().unwrap();
    let options = DownloaderOptions {
        output_dir: tmp.path().to_path_buf(),
        filename_stem: "adv_injection".to_string(),
        format_spec: None,
    };

    let injection_vectors = vec![
        "--exec=\"calc\"",
        "--exec=touch /tmp/pwned",
        "--config-location /etc/passwd",
        "-v",
        "-F",
        "-o /tmp/pwned",
        "  --exec=id",
        "\t-v",
        "\n--version",
        "---custom-flag",
        "-h",
        "  -v --dump-json",
    ];

    for vector in injection_vectors {
        let res = downloader
            .download(vector, options.clone(), None, |_| {})
            .await;
        assert!(
            res.is_err(),
            "Injection vector '{}' was not rejected by validation!",
            vector
        );
        match res.unwrap_err() {
            CoreError::ValidationError(msg) => {
                assert!(
                    msg.contains("URL cannot start with a dash")
                        || msg.contains("Invalid URL scheme"),
                    "Expected validation error on injection vector '{}', got: {}",
                    vector,
                    msg
                );
            }
            other => panic!(
                "Expected CoreError::ValidationError for vector '{}', got: {:?}",
                vector, other
            ),
        }
    }
}

/// Adversarial Test Suite 2: Required Prompt LFI Vectors
#[test]
fn test_adversarial_required_lfi_vectors() {
    let required_inputs = vec![
        "file:///etc/passwd",
        "../../.env",
        "/root/.ssh",
        "/var/run",
        "/tmp",
    ];

    for input in required_inputs {
        let res = validate_path_security(input, "required_input");
        assert!(
            res.is_err(),
            "Required vector '{}' was NOT rejected by validate_path_security! Got: {:?}",
            input,
            res
        );
    }
}

/// Adversarial Test Suite 3: LFI / Traversal Rejection Verification
#[test]
fn test_adversarial_path_security_bypasses() {
    let file_uri_bypass = validate_path_security("file:///etc/hosts", "path");
    let var_run_bypass = validate_path_security("file:///var/run", "path");
    let double_slash_bypass = validate_path_security("//etc/hosts", "path");
    let dot_slash_bypass = validate_path_security("/./etc/hosts", "path");
    let backslash_bypass = validate_path_security(r"..\..\etc\passwd", "path");

    assert!(file_uri_bypass.is_err(), "file:///etc/hosts must be rejected");
    assert!(var_run_bypass.is_err(), "file:///var/run must be rejected");
    assert!(double_slash_bypass.is_err(), "//etc/hosts must be rejected");
    assert!(dot_slash_bypass.is_err(), "/./etc/hosts must be rejected");
    assert!(backslash_bypass.is_err(), r"..\..\etc\passwd must be rejected");
}

/// Adversarial Test Suite 4: End-to-End LFI Exploit Prevention Verification
#[tokio::test]
async fn test_adversarial_end_to_end_lfi_exfiltration() {
    let cmd = YtDlpCommand::detect().await.expect("yt-dlp detection");
    let downloader = VideoDownloader::new(cmd);

    let tmp = tempdir().unwrap();
    let options = DownloaderOptions {
        output_dir: tmp.path().to_path_buf(),
        filename_stem: "exfiltrated_hosts".to_string(),
        format_spec: None,
    };

    let exploit_url = "file:////etc/hosts";
    assert!(
        !is_offline_or_test_url(exploit_url),
        "Exploit URL must NOT be accepted as offline test url"
    );

    let download_result = downloader.download(exploit_url, options, None, |_| {}).await;
    assert!(
        download_result.is_err(),
        "Exploit download must be rejected with CoreError::ValidationError!"
    );
}

#[test]
fn test_adversarial_lfi_exploit_dot_slash_etc_hosts() {
    let res = validate_path_security("file:///./etc/hosts", "path");
    assert!(res.is_err(), "file:///./etc/hosts must be rejected");
}

#[test]
fn test_adversarial_lfi_exploit_double_slash_etc_hosts() {
    let res = validate_path_security("file:////etc/hosts", "path");
    assert!(res.is_err(), "file:////etc/hosts must be rejected");
}

/// Adversarial Test Suite 5: Process Timeout Wrapping Verification
#[tokio::test]
async fn test_adversarial_process_timeout_mechanics() {
    // Verify that tokio::time::timeout correctly intercepts a sleeping child process
    let mut child = tokio::process::Command::new("sleep")
        .arg("5")
        .stdout(std::process::Stdio::null())
        .stderr(std::process::Stdio::null())
        .spawn()
        .expect("Spawn sleep process");

    let timeout_duration = std::time::Duration::from_millis(100);
    let result = tokio::time::timeout(timeout_duration, child.wait()).await;

    assert!(
        result.is_err(),
        "Process must time out after 100ms when sleeping for 5s"
    );

    let kill_result = child.start_kill();
    assert!(kill_result.is_ok(), "Child process must be killable on timeout");
}
