#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

commit=${1:-}
digest=${2:-}
if [[ $EUID != 0 ]]; then
    echo 'Run the Hatid release helper with sudo.' >&2
    exit 2
fi
if [[ $# != 1 || $commit != --recover ]]; then
    if [[ $# != 3 || ! $commit =~ ^[0-9a-f]{40}$ || ! $digest =~ ^[0-9a-f]{64}$ ]]; then
        echo 'Usage: sudo hatid-release COMMIT SHA256 LOCAL_ARCHIVE | --recover' >&2
        exit 2
    fi
fi

root=/srv/hatid
# The host setup writes this file with root-only permissions.
source /etc/hatid-release.conf
exec 9>"$root/deploy.lock"
flock -n 9

# Check the configured hostname and certificate while connecting to this host.
healthy() {
    local status
    status=$(curl --noproxy '*' --connect-to '::127.0.0.1:' --silent --show-error \
        --max-time 8 -o /dev/null -w '%{http_code}' "$HATID_ORIGIN/up") || return 1
    [[ $status == 200 ]]
}

# Accept a release pointer only when it resolves to an owned release directory.
read_release() {
    local link="$root/$1" path
    [[ -e $link || -L $link ]] || return 0
    path=$(readlink -e "$link")
    if [[ ! -L $link || ! -d $path || ! $path =~ ^/srv/hatid/releases/[0-9a-f]{40}-[0-9a-f]{64}$ ]]; then
        echo 'The release pointer is invalid.' >&2
        return 1
    fi
    printf '%s' "$path"
}

# Replace a pointer atomically on the same filesystem.
set_release() {
    ln -sfn "$2" "$root/.next-$1"
    mv -Tf "$root/.next-$1" "$root/$1"
}

previous=$(read_release current)
verified=$(read_release verified)
if [[ $previous != "$verified" ]]; then
    if [[ -n $previous ]] && healthy; then
        set_release verified "$previous"
    elif [[ -n $verified ]]; then
        set_release current "$verified"
        previous=$verified
        echo 'Restored the last verified release after an interrupted activation.'
    else
        unlink "$root/current"
        previous=
    fi
fi
[[ $commit != --recover ]] || exit 0

grep -Fxq 'APP_ENV=production' "$root/shared/.env"
grep -Fxq 'DB_DATABASE=hatid' "$root/shared/.env"
grep -Fxq 'DB_HOST=127.0.0.1' "$root/shared/.env"
if grep -qE '^DB_URL=.+' "$root/shared/.env"; then
    echo 'The release requires the local Hatid MySQL database.' >&2
    exit 1
fi

release="$root/releases/$commit-$digest"
if [[ $previous == "$release" ]]; then
    healthy
    printf 'Release %s is already active.\n' "$commit"
    exit 0
fi

free_kib=$(df -Pk "$root" | awk 'NR==2 { print $4 }')
if (( free_kib < 350000 )); then
    echo 'Hatid needs at least 350 MB of disk space to stage a release.' >&2
    exit 1
fi

stage=$(mktemp -d "$root/.stage.XXXXXXXX")
candidate=
# Remove temporary material from this attempt even if a check fails.
cleanup() {
    if [[ -n $candidate && $(readlink -e "$root/current" || true) != "$candidate" ]]; then
        rm -rf -- "$candidate"
    fi
    rm -rf -- "$stage"
}
trap cleanup EXIT
chgrp www-data "$stage"
chmod 0750 "$stage"

cp -- "$3" "$stage/release.tar.gz"
if ! printf '%s  %s\n' "$digest" "$stage/release.tar.gz" | sha256sum --check --status; then
    echo 'Release archive checksum does not match.' >&2
    exit 1
fi
chgrp www-data "$stage/release.tar.gz"
chmod 0640 "$stage/release.tar.gz"

# Extract the build as the unprivileged app user, then make source read-only.
install -d -o www-data -g www-data -m 0750 "$stage/app"
runuser -u www-data -- tar -xzf "$stage/release.tar.gz" -C "$stage/app" \
    --no-same-owner --no-same-permissions
chown -R root:www-data "$stage/app"
test -z "$(find "$stage/app" -type l -print -quit)"
chmod -R u=rwX,g=rX,o= "$stage/app"
test ! -e "$stage/app/.env"
test -f "$stage/app/vendor/autoload.php"
test -f "$stage/app/public/index.php"

mv "$stage/app/storage" "$stage/storage"
ln -s "$root/shared/.env" "$stage/app/.env"
ln -s "$root/shared/storage" "$stage/app/storage"
chown www-data:www-data "$stage/app/bootstrap/cache"
chmod 0750 "$stage/app/bootstrap/cache"
if [[ -e $release ]]; then
    rm -rf -- "$release"
fi
candidate=$release
mv "$stage/app" "$release"

# Use only the server's environment file and the web process identity.
artisan() {
    (cd "$release"; runuser -u www-data -- env -i PATH=/usr/bin:/bin LANG=C.UTF-8 \
        php8.5 artisan "$@")
}

artisan config:cache --no-interaction
backup=
if [[ -n $previous ]]; then
    backup="$root/backups/${previous##*/}.sql.gz"
    if [[ ! -s $backup ]]; then
        mysqldump --protocol=socket --single-transaction --quick --no-tablespaces \
            --set-gtid-purged=OFF hatid | gzip > "$stage/hatid.sql.gz"
        mv "$stage/hatid.sql.gz" "$backup"
    fi
fi
artisan migrate --force --no-interaction
artisan migrate:status --no-interaction

set_release current "$release"
if ! healthy; then
    if [[ -n $previous ]]; then
        set_release current "$previous"
    else
        unlink "$root/current"
    fi
    echo 'The release failed its health check. The previous web release was restored if present.' >&2
    exit 1
fi
set_release verified "$release"

# Keep the active and previous code releases within the small EC2 disk budget.
for old in "$root"/releases/*; do
    if [[ $old =~ ^/srv/hatid/releases/[0-9a-f]{40}-[0-9a-f]{64}$ && -d $old && $old != "$release" && $old != "$previous" ]]; then
        rm -rf -- "$old"
    fi
done

for old in "$root"/backups/*.sql.gz; do
    if [[ $old =~ ^/srv/hatid/backups/[0-9a-f]{40}-[0-9a-f]{64}\.sql\.gz$ && $old != "$backup" ]]; then
        rm -f -- "$old"
    fi
done

printf 'Active Hatid commit %s\n' "$commit"
