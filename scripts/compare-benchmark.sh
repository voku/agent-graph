#!/usr/bin/env bash
# Benchmarks a git ref (default HEAD) against the working tree and diffs timings and checksums.
# Usage: scripts/compare-benchmark.sh [base-ref] [-- benchmark.php options]
# Differing checksums mean the change altered query results, not just speed.
# Requires: git, php, jq. Rebuilds the dataset on both sides. Uses a temporary worktree with its own vendor copy.
set -euo pipefail

base_ref='HEAD'
if [[ $# -gt 0 && "$1" != '--' ]]; then base_ref="$1"; shift; fi
[[ "${1:-}" == '--' ]] && shift

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work="$(mktemp -d)"
trap 'git -C "${root}" worktree remove --force "${work}/base" >/dev/null 2>&1 || true; rm -rf "${work}"' EXIT

git -C "${root}" worktree add --quiet --detach "${work}/base" "${base_ref}"
# A copy, not a symlink: Composer's autoloader resolves src/ relative to the real vendor path.
cp -r "${root}/vendor" "${work}/base/vendor"
mkdir -p "${work}/base/scripts"
cp "${root}/scripts/benchmark.php" "${work}/base/scripts/benchmark.php"

# Each side builds its own database (schemas may differ between refs) from the same deterministic data.
php "${work}/base/scripts/benchmark.php" --database="${work}/base.sqlite" --rebuild --json "$@" > "${work}/base.json"
php "${root}/scripts/benchmark.php" --database="${work}/head.sqlite" --rebuild --json "$@" > "${work}/head.json"

printf '%-30s %12s %12s %9s\n' 'metric (ms)' "${base_ref}" 'working tree' 'change'
jq -r --slurpfile head "${work}/head.json" '
  (.timings_ms // {}) as $base | ($head[0].timings_ms // {}) as $current
  | (($base | keys) + ($current | keys) | unique[]) as $key
  | [$key, ($base[$key] // ""), ($current[$key] // "")]
  | @tsv
' "${work}/base.json" |
  awk -F'\t' '{
    if ($2 == "") printf "%-30s %12s %12.3f %9s\n", $1, "-", $3, "new";
    else if ($3 == "") printf "%-30s %12.3f %12s %9s\n", $1, $2, "-", "missing";
    else if ($2 == 0) printf "%-30s %12.3f %12.3f %9s\n", $1, $2, $3, "-";
    else printf "%-30s %12.3f %12.3f %+8.0f%%\n", $1, $2, $3, ($3 - $2) / $2 * 100;
  }'
echo
# Existing queries require exact parity; head-only queries need independent tests.
if jq -e --slurpfile head "${work}/head.json" '
    (.checksums // {}) as $base | ($head[0].checksums // {}) as $current
    | ($base | to_entries | all(.[]; $current[.key] == .value))
  ' "${work}/base.json" >/dev/null; then
  echo 'shared-query checksums: identical'
else
  echo 'shared-query checksums: DIFFER (including missing keys)' >&2
  jq -n --slurpfile base "${work}/base.json" --slurpfile head "${work}/head.json" '
    $base[0].checksums as $b | $head[0].checksums as $h
    | [($b | keys[]) as $key | select($b[$key] != $h[$key]) | {query: $key, base: $b[$key], head: $h[$key]}]
  ' >&2
  exit 1
fi
jq -r --slurpfile base "${work}/base.json" '
  ($base[0].checksums // {}) as $previous
  | (.checksums // {}) | keys[] as $key
  | select(($previous | has($key)) | not)
  | "head-only checksum (no base equivalent): " + $key
' "${work}/head.json"
