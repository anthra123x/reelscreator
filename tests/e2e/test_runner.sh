#!/usr/bin/env bash
# ==============================================================================
# ReelForge: Unified End-to-End Test Runner
# Executes Tiers 1-4 across Rust Engine, Laravel Backend, and Mock Publishers.
# ==============================================================================

set -uo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TESTS_DIR="${PROJECT_ROOT}/tests/e2e"

# Color constants
COLOR_RESET="\033[0m"
COLOR_BOLD="\033[1m"
COLOR_GREEN="\033[32m"
COLOR_RED="\033[31m"
COLOR_YELLOW="\033[33m"
COLOR_CYAN="\033[36m"

# Default flags
TIER="all"
MODE="auto"
VERBOSE=0
JSON_REPORT=""
KEEP_ARTIFACTS=0

usage() {
    cat << EOF
ReelForge E2E Test Runner

USAGE:
    ./tests/e2e/test_runner.sh [OPTIONS]

OPTIONS:
    --all                   Execute all test tiers (default)
    --tier <1|2|3|4|5>      Execute only the specified tier
    --mode <auto|live|mock> Environment target mode (default: auto)
    --verbose               Enable verbose test execution output
    --json-report <PATH>    Output structured test report to JSON file
    --keep-artifacts        Retain generated synthetic test videos
    --help, -h              Display this help message

EXAMPLES:
    ./tests/e2e/test_runner.sh
    ./tests/e2e/test_runner.sh --tier 1
    ./tests/e2e/test_runner.sh --tier 5
    ./tests/e2e/test_runner.sh --tier 4 --verbose
    ./tests/e2e/test_runner.sh --json-report /tmp/e2e_results.json
EOF
    exit 0
}

# Parse CLI options
while [[ $# -gt 0 ]]; do
    case "$1" in
        --all)
            TIER="all"
            shift
            ;;
        --tier)
            TIER="$2"
            shift 2
            ;;
        --mode)
            MODE="$2"
            shift 2
            ;;
        --verbose)
            VERBOSE=1
            shift
            ;;
        --json-report)
            JSON_REPORT="$2"
            shift 2
            ;;
        --keep-artifacts)
            KEEP_ARTIFACTS=1
            shift
            ;;
        --help|-h)
            usage
            ;;
        *)
            echo -e "${COLOR_RED}Unknown option: $1${COLOR_RESET}"
            usage
            ;;
    esac
done

export PYTHONPATH="${PROJECT_ROOT}:${PYTHONPATH:-}"
export REELFORGE_TEST_MODE="${MODE}"

echo -e "${COLOR_BOLD}${COLOR_CYAN}==============================================================================${COLOR_RESET}"
echo -e "${COLOR_BOLD}${COLOR_CYAN}  ReelForge End-to-End Test Suite Orchestrator${COLOR_RESET}"
echo -e "${COLOR_BOLD}${COLOR_CYAN}==============================================================================${COLOR_RESET}"
echo -e "Target Tier : ${COLOR_BOLD}${TIER}${COLOR_RESET}"
echo -e "Target Mode : ${COLOR_BOLD}${MODE}${COLOR_RESET}"
echo -e "Working Dir : ${PROJECT_ROOT}"
echo ""

START_TIME=$(date +%s)
TOTAL_PASS=0
TOTAL_FAIL=0
TOTAL_TESTS=0
TIER_RESULTS=()

run_tier() {
    local tier_num="$1"
    local tier_name="$2"
    local tier_path="$3"

    echo -e "${COLOR_BOLD}Executing Tier ${tier_num}: ${tier_name}${COLOR_RESET}"
    echo "------------------------------------------------------------------------------"

    local t_start=$(date +%s)
    local tmp_out=$(mktemp)
    local ret=0

    if [[ ${VERBOSE} -eq 1 ]]; then
        python3 -m unittest discover -s "${tier_path}" -p "test_*.py" -v 2>&1 | tee "${tmp_out}"
        ret=${PIPESTATUS[0]}
    else
        python3 -m unittest discover -s "${tier_path}" -p "test_*.py" -v > "${tmp_out}" 2>&1
        ret=$?
    fi

    local t_end=$(date +%s)
    local t_diff=$((t_end - t_start))

    # Parse count of tests from unittest output
    local tests_in_tier=$(grep -oP 'Ran \K[0-9]+' "${tmp_out}" | tail -n 1 || echo "0")
    if [[ -z "${tests_in_tier}" ]]; then
        tests_in_tier=0
    fi

    if [[ ${ret} -eq 0 ]]; then
        echo -e "${COLOR_GREEN}PASS${COLOR_RESET} Tier ${tier_num}: ${tier_name} (${t_diff}s)"
        TIER_RESULTS+=("Tier ${tier_num}|PASS|${t_diff}s|${tests_in_tier}")
        TOTAL_PASS=$((TOTAL_PASS + 1))
    else
        echo -e "${COLOR_RED}FAIL${COLOR_RESET} Tier ${tier_num}: ${tier_name} (${t_diff}s)"
        if [[ ${VERBOSE} -eq 0 ]]; then
            echo -e "${COLOR_RED}$(cat "${tmp_out}")${COLOR_RESET}"
        fi
        TIER_RESULTS+=("Tier ${tier_num}|FAIL|${t_diff}s|${tests_in_tier}")
        TOTAL_FAIL=$((TOTAL_FAIL + 1))
    fi
    rm -f "${tmp_out}"
    echo ""
}

# Execute requested tiers
if [[ "${TIER}" == "1" || "${TIER}" == "all" ]]; then
    run_tier "1" "Feature Coverage (Rust CLI/API, Laravel REST, Mock Adapters)" "${TESTS_DIR}/tier1_features"
fi

if [[ "${TIER}" == "2" || "${TIER}" == "all" ]]; then
    run_tier "2" "Boundaries & Negatives (Zero-byte, Bad URLs, Inverted Timestamps)" "${TESTS_DIR}/tier2_boundaries"
fi

if [[ "${TIER}" == "3" || "${TIER}" == "all" ]]; then
    run_tier "3" "Cross-Feature Combinations (Slicing + 9:16 + Thumbs, Fan-out Publish)" "${TESTS_DIR}/tier3_combinations"
fi

if [[ "${TIER}" == "4" || "${TIER}" == "all" ]]; then
    run_tier "4" "Real-World Pipeline Simulation (Synthetic video, FFprobe, Publishing)" "${TESTS_DIR}/tier4_real_world"
fi

if [[ "${TIER}" == "5" || "${TIER}" == "all" ]]; then
    run_tier "5" "Adversarial Hardening (Extreme inputs, bursts, injection, panics)" "${TESTS_DIR}/tier5_adversarial"
fi

END_TIME=$(date +%s)
TOTAL_DURATION=$((END_TIME - START_TIME))

# Print summary table
echo -e "${COLOR_BOLD}${COLOR_CYAN}==============================================================================${COLOR_RESET}"
echo -e "${COLOR_BOLD}  ReelForge E2E Execution Summary${COLOR_RESET}"
echo -e "${COLOR_BOLD}${COLOR_CYAN}==============================================================================${COLOR_RESET}"
printf "%-10s | %-8s | %-10s | %-6s\n" "Tier" "Result" "Duration" "Tests"
echo "--------------------------------------------------------"

for res in "${TIER_RESULTS[@]}"; do
    IFS="|" read -r t_name t_res t_dur t_cnt <<< "${res}"
    if [[ "${t_res}" == "PASS" ]]; then
        printf "%-10s | ${COLOR_GREEN}%-8s${COLOR_RESET} | %-10s | %-6s\n" "${t_name}" "${t_res}" "${t_dur}" "${t_cnt}"
    else
        printf "%-10s | ${COLOR_RED}%-8s${COLOR_RESET} | %-10s | %-6s\n" "${t_name}" "${t_res}" "${t_dur}" "${t_cnt}"
    fi
done

echo "--------------------------------------------------------"
echo -e "Total Duration : ${TOTAL_DURATION}s"
echo -e "Tiers Passed   : ${COLOR_GREEN}${TOTAL_PASS}${COLOR_RESET}"
echo -e "Tiers Failed   : ${TOTAL_FAIL}"

# Output JSON report if requested
if [[ -n "${JSON_REPORT}" ]]; then
    cat << JSON_EOF > "${JSON_REPORT}"
{
  "timestamp": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "total_duration_seconds": ${TOTAL_DURATION},
  "tiers_passed": ${TOTAL_PASS},
  "tiers_failed": ${TOTAL_FAIL},
  "tier_results": [
$(for i in "${!TIER_RESULTS[@]}"; do
    IFS="|" read -r t_name t_res t_dur t_cnt <<< "${TIER_RESULTS[$i]}"
    comma=","
    if [[ $i -eq $((${#TIER_RESULTS[@]} - 1)) ]]; then comma=""; fi
    echo "    {\"tier\": \"${t_name}\", \"status\": \"${t_res}\", \"duration\": \"${t_dur}\", \"tests_run\": ${t_cnt:-0}}${comma}"
done)
  ]
}
JSON_EOF
    echo -e "JSON report written to: ${COLOR_CYAN}${JSON_REPORT}${COLOR_RESET}"
fi

echo -e "${COLOR_BOLD}${COLOR_CYAN}==============================================================================${COLOR_RESET}"

if [[ ${TOTAL_FAIL} -eq 0 ]]; then
    echo -e "${COLOR_BOLD}${COLOR_GREEN}ALL REQUESTED TIERS PASSED SUCCESSFULLY!${COLOR_RESET}"
    exit 0
else
    echo -e "${COLOR_BOLD}${COLOR_RED}ONE OR MORE TIERS FAILED!${COLOR_RESET}"
    exit 1
fi
