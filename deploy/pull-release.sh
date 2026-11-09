#!/usr/bin/env bash
set -Eeuo pipefail
umask 027
[[ $EUID == 0 && $# == 0 ]]

root=/srv/hatid
api=https://api.github.com/repos/novel16/hatid-api
exec 9>"$root/pull.lock"
flock -n 9 || exit 0

# Finish or revert an interrupted activation even if GitHub is unavailable.
if [[ $(readlink "$root/current" || true) != $(readlink "$root/verified" || true) ]]; then
    /usr/local/sbin/hatid-release --recover
fi

# Read only the live main reference from GitHub over TLS.
main_commit() {
    curl -fsS --proto '=https' --max-time 15 "$api/git/ref/heads/main" |
        jq -er '.object.sha | select(test("^[0-9a-f]{40}$"))'
}

commit=$(main_commit)
active=$(readlink -e "$root/current" || true)
if [[ -n $active && ! $active =~ ^/srv/hatid/releases/[0-9a-f]{40}-[0-9a-f]{64}$ ]]; then
    echo 'The active release points outside Hatid releases.' >&2
    exit 1
fi
[[ $active != "$root/releases/$commit-"* ]] || exit 0

# A completed successful run must match the same repository, branch and SHA.
run=$(curl -fsS --proto '=https' --max-time 15 \
    "$api/actions/workflows/hatid-api.yml/runs?branch=main&head_sha=$commit&per_page=1" |
    jq -r --arg commit "$commit" '
        .workflow_runs[0] |
        select(.head_repository.id == 1441840200 and .head_branch == "main" and .head_sha == $commit
            and .status == "completed" and .conclusion == "success"
            and (.event == "push" or .event == "workflow_dispatch")) |
        "\(.id)/\(.run_attempt)"')
[[ $run =~ ^[0-9]+/[0-9]+$ ]] || exit 0

run_key="$commit $run"
failure="$root/failed-deploy"
if [[ -f $failure ]] && grep -Fxq "$run_key" "$failure"; then
    exit 0
fi
if (( $(df -Pk "$root" | awk 'NR==2 { print $4 }') < 450000 )); then
    echo 'Hatid needs at least 450 MB of free space to prepare a release.' >&2
    exit 1
fi

stage=$(mktemp -d /tmp/hatid-pull.XXXXXXXX)
# Remove only this attempt's temporary source, Composer cache and bundle.
cleanup() {
    rm -rf -- "$stage"
}
trap cleanup EXIT
chgrp nogroup "$stage"
chmod 0750 "$stage"
source_dir="$stage/source"
install -d -o nobody -g nogroup -m 0750 "$source_dir" "$stage/composer"

curl -fsSL --proto '=https' --proto-redir '=https' --max-time 30 \
    --max-filesize 50000000 "$api/tarball/$commit" -o "$stage/source.tar.gz"
chgrp nogroup "$stage/source.tar.gz"
chmod 0640 "$stage/source.tar.gz"
runuser -u nobody -- tar -xzf "$stage/source.tar.gz" -C "$source_dir" --strip-components=1 \
    --no-same-owner --no-same-permissions
test -z "$(find "$source_dir" -type l -print -quit)"
test ! -e "$source_dir/.env"
test ! -e "$source_dir/vendor"
test -f "$source_dir/composer.lock"

# Reuse installed packages and resolve only changes required by the verified lockfile.
if [[ -n $active && -d $active/vendor ]]; then
    cp -a -- "$active/vendor" "$source_dir/vendor"
    chown -R nobody:nogroup "$source_dir/vendor"
fi
runuser -u nobody -- env -i PATH=/usr/bin:/bin LANG=C.UTF-8 \
    COMPOSER_HOME="$stage/composer" COMPOSER_MEMORY_LIMIT=160M \
    composer --working-dir="$source_dir" install --no-dev --no-plugins --no-scripts \
        --prefer-dist --optimize-autoloader --no-interaction --no-progress

# Discard stale work if another push arrived while the release was prepared.
[[ $(main_commit) == "$commit" ]] || exit 0
bundle="$stage/release.tar.gz"
tar -czf "$bundle" -C "$source_dir" .
read -r digest _ < <(sha256sum "$bundle")
# Hold this run if activation fails or the service is interrupted.
printf '%s\n' "$run_key" > "$failure"
/usr/local/sbin/hatid-release "$commit" "$digest" "$bundle"
rm -f -- "$failure"
