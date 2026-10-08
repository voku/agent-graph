#!/usr/bin/env bash
# Vendors a sqlite-vec release into resources/sqlite-vec and updates the manifest
# and third-party notice. Usage: scripts/update-sqlite-vec.sh [latest|vX.Y.Z] [--check]
#   --check  only report whether the vendored version is current; change nothing.
# Requires: curl, jq, tar, sha256sum, python3. Honors GITHUB_OUTPUT when set.
set -euo pipefail

requested='latest'
check_only=0
for arg in "$@"; do
  case "${arg}" in
    --check) check_only=1 ;;
    *) requested="${arg}" ;;
  esac
done

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${root}"

tag_pattern='^v[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$'
if [[ "${requested}" != 'latest' && ! "${requested}" =~ ${tag_pattern} ]]; then
  echo "Unsupported sqlite-vec version input: ${requested}" >&2
  exit 1
fi

temporary="$(mktemp -d)"
trap 'rm -rf "${temporary}"' EXIT

if [[ "${requested}" == 'latest' ]]; then
  release_api='https://api.github.com/repos/asg017/sqlite-vec/releases/latest'
else
  release_api="https://api.github.com/repos/asg017/sqlite-vec/releases/tags/${requested}"
fi

curl_auth=()
if [[ -n "${GITHUB_TOKEN:-}" ]]; then
  curl_auth=(--header "Authorization: Bearer ${GITHUB_TOKEN}")
fi

curl --fail --location --silent --show-error \
  --header 'Accept: application/vnd.github+json' "${curl_auth[@]}" \
  "${release_api}" --output "${temporary}/release.json"

version="$(jq -er '.tag_name' "${temporary}/release.json")"
source_url="$(jq -er '.html_url' "${temporary}/release.json")"
if [[ ! "${version}" =~ ${tag_pattern} ]]; then
  echo "Upstream returned an unexpected sqlite-vec tag: ${version}" >&2
  exit 1
fi

manifest='resources/sqlite-vec/manifest.json'
current="$(jq -er '.version' "${manifest}")"
echo "vendored: ${current}  target: ${version}"

emit() { [[ -n "${GITHUB_OUTPUT:-}" ]] && echo "$1=$2" >> "${GITHUB_OUTPUT}" || true; }
emit target_version "${version}"
emit source_url "${source_url}"

if [[ "${check_only}" == 1 ]]; then
  if [[ "${current}" == "${version}" ]]; then echo 'up to date'; else echo 'update available'; exit 3; fi
  exit 0
fi

x86_asset="$(jq -er '[.assets[].name | select(endswith("-loadable-linux-x86_64.tar.gz"))][0]' "${temporary}/release.json")"
arm64_asset="$(jq -er '[.assets[].name | select(endswith("-loadable-linux-aarch64.tar.gz"))][0]' "${temporary}/release.json")"

vendor_binary() {
  local platform="$1" asset="$2" digest url archive unpacked binary destination expected_archive_sha256
  digest="$(jq -er --arg a "${asset}" '.assets[] | select(.name == $a) | .digest' "${temporary}/release.json")"
  if [[ ! "${digest}" =~ ^sha256:([0-9a-fA-F]{64})$ ]]; then
    echo "No valid upstream SHA-256 release digest found for ${asset}" >&2
    exit 1
  fi
  expected_archive_sha256="${BASH_REMATCH[1]}"
  url="$(jq -er --arg a "${asset}" '.assets[] | select(.name == $a) | .browser_download_url' "${temporary}/release.json")" || return 1
  archive="${temporary}/${asset}"
  unpacked="${temporary}/unpacked-${platform}"
  destination="resources/sqlite-vec/${platform}/vec0.so"

  # Explicit checks: command substitutions do not inherit errexit by default.
  curl --fail --location --silent --show-error "${url}" --output "${archive}" || return 1
  if ! printf '%s  %s\n' "${expected_archive_sha256}" "${archive}" | sha256sum --check --strict --status; then
    echo "SHA-256 mismatch for ${asset}" >&2
    return 1
  fi
  mkdir -p "${unpacked}" || return 1
  tar -xzf "${archive}" -C "${unpacked}" || return 1
  binary="$(find "${unpacked}" -type f -name 'vec0.so' -print -quit)" || return 1
  if [[ -z "${binary}" ]]; then
    echo "vec0.so missing in ${asset}" >&2
    return 1
  fi
  mkdir -p "$(dirname "${destination}")" || return 1
  install -m 0644 "${binary}" "${destination}" || return 1
  sha256sum "${destination}" | awk '{ print $1 }' || return 1
}

x86_sha256="$(vendor_binary 'linux-gnu-x86_64' "${x86_asset}")" || exit 1
arm64_sha256="$(vendor_binary 'linux-gnu-arm64' "${arm64_asset}")" || exit 1

jq \
  --arg version "${version}" --arg source "${source_url}" \
  --arg x86_asset "${x86_asset}" --arg x86_sha256 "${x86_sha256}" \
  --arg arm64_asset "${arm64_asset}" --arg arm64_sha256 "${arm64_sha256}" \
  '.version = $version
   | .source = $source
   | .binaries["linux-gnu-x86_64"].asset = $x86_asset
   | .binaries["linux-gnu-x86_64"].sha256 = $x86_sha256
   | .binaries["linux-gnu-arm64"].asset = $arm64_asset
   | .binaries["linux-gnu-arm64"].sha256 = $arm64_sha256' \
  "${manifest}" > "${temporary}/manifest.json"
mv "${temporary}/manifest.json" "${manifest}"

TARGET_VERSION="${version}" TARGET_SOURCE_URL="${source_url}" python3 - <<'PY'
import os, pathlib, re

path = pathlib.Path('docs/reference/third-party-notices.md')
text = path.read_text()
text, v = re.subn(r'^- Pinned version: `[^`]+`$', f"- Pinned version: `{os.environ['TARGET_VERSION']}`", text, count=1, flags=re.M)
text, s = re.subn(r'^- Source release: `[^`]+`$', f"- Source release: `{os.environ['TARGET_SOURCE_URL']}`", text, count=1, flags=re.M)
if v != 1 or s != 1:
    raise SystemExit('Unable to update sqlite-vec third-party notice deterministically.')
path.write_text(text)
PY

for platform in linux-gnu-x86_64 linux-gnu-arm64; do
  printf '%s  %s\n' "$(jq -er --arg p "${platform}" '.binaries[$p].sha256' "${manifest}")" \
    "resources/sqlite-vec/${platform}/vec0.so" | sha256sum --check --strict
done

if git diff --quiet -- resources/sqlite-vec docs/reference/third-party-notices.md; then
  emit changed false
  echo "sqlite-vec ${version} is already vendored exactly."
else
  emit changed true
  echo "sqlite-vec updated to ${version}."
fi
