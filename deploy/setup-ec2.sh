#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

if [[ $EUID != 0 ]]; then
    echo 'Run setup-ec2.sh with sudo on the Hatid EC2 instance.' >&2
    exit 2
fi

root=/srv/hatid
site=/etc/nginx/sites-available/hatid-api
env_file="$root/shared/.env"
acme=/var/lib/hatid-acme

# Keep installation and small request spikes within the t3.micro memory budget.
prepare_runtime() {
    if ! swapon --show=NAME --noheadings | grep -Fxq /var/swap-hatid; then
        if [[ ! -e /var/swap-hatid ]]; then
            fallocate -l 768M /var/swap-hatid
            chmod 0600 /var/swap-hatid
            mkswap /var/swap-hatid >/dev/null
        fi
        swapon /var/swap-hatid
    fi
    grep -qE '^/var/swap-hatid[[:space:]]' /etc/fstab ||
        printf '/var/swap-hatid none swap sw 0 0\n' >> /etc/fstab

    install -d -m 0755 /etc/mysql /etc/mysql/mysql.conf.d
    cat > /etc/mysql/mysql.conf.d/zz-hatid.cnf <<'MYSQL'
[mysqld]
bind-address = 127.0.0.1
mysqlx = OFF
performance_schema = OFF
innodb_buffer_pool_size = 64M
max_connections = 20
MYSQL
    chmod 0644 /etc/mysql/mysql.conf.d/zz-hatid.cnf
    apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        nginx php8.5-fpm php8.5-cli php8.5-mysql php8.5-mbstring php8.5-xml \
        php8.5-curl php8.5-zip mysql-server composer jq
    apt-get clean
    if [[ $(mysql --protocol=socket --batch --skip-column-names -e 'SELECT @@bind_address') != 127.0.0.1 ]]; then
        systemctl restart mysql
    fi
    test "$(mysql --protocol=socket --batch --skip-column-names -e 'SELECT @@bind_address')" = 127.0.0.1

    cat > /etc/php/8.5/fpm/pool.d/zz-hatid.conf <<'PHP'
[www]
pm = ondemand
pm.max_children = 3
pm.process_idle_timeout = 15s
php_admin_value[memory_limit] = 96M
PHP
    php-fpm8.5 -t
    systemctl reload php8.5-fpm
}

# Initialize this deployment's database and preserve its generated secrets on retries.
prepare_app() {
    install -d -o root -g www-data -m 0750 "$root" "$root/releases" "$root/shared"
    install -d -m 0700 "$root/backups"
    install -d -o www-data -g www-data -m 0750 \
        "$root/shared/storage" "$root/shared/storage/app/private" "$root/shared/storage/app/public" \
        "$root/shared/storage/framework/cache/data" "$root/shared/storage/framework/cache/locks" \
        "$root/shared/storage/framework/sessions" "$root/shared/storage/framework/views" "$root/shared/storage/logs"
    chown -R www-data:www-data "$root/shared/storage"

    if [[ ! -f $env_file ]]; then
        test "$(mysql --protocol=socket --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='hatid'")" = 0
        local key password
        key=$(openssl rand -base64 32)
        password=$(openssl rand -hex 32)
        cat > "$env_file" <<ENV
APP_NAME=Hatid
APP_ENV=production
APP_KEY=base64:$key
APP_DEBUG=false
APP_URL=http://127.0.0.1:8080
FRONTEND_URL=http://localhost:3011
ADMIN_URL=http://localhost:3012
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hatid
DB_USERNAME=hatid_api
DB_PASSWORD=$password
CACHE_STORE=database
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
MAIL_MAILER=array
LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=7
ENV
    fi
    chown root:www-data "$env_file"
    chmod 0640 "$env_file"

    local db_password
    db_password=$(sed -n 's/^DB_PASSWORD=//p' "$env_file")
    [[ $db_password =~ ^[0-9a-f]{64}$ ]]
    mysql --protocol=socket <<SQL
CREATE DATABASE IF NOT EXISTS hatid CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS 'hatid_api'@'127.0.0.1' IDENTIFIED BY '$db_password';
GRANT ALL ON hatid.* TO 'hatid_api'@'127.0.0.1';
SQL
}

# Render an API-only virtual host. ACME validation has its own HTTP location.
write_site() {
    local ip=${1:-}
    local tls=${2:-}
    {
        if [[ -n $ip ]]; then
            cat <<HTTP
server {
    listen 80;
    server_name $ip;
    location ^~ /.well-known/acme-challenge/ {
        root $acme;
        try_files \$uri =404;
    }
HTTP
            if [[ $tls == yes ]]; then
                printf '    location / { return 308 https://%s$request_uri; }\n' "$ip"
            else
                printf '    location / { return 404; }\n'
            fi
            printf '}\n'
        fi
        printf 'server {\n'
        if [[ $tls == yes ]]; then
            cat <<TLS
    listen 443 ssl;
    server_name $ip;
    ssl_certificate /etc/letsencrypt/live/$ip/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$ip/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
TLS
        else
            printf '    listen 127.0.0.1:8080;\n    server_name _;\n'
        fi
        cat <<'NGINX'
    root /srv/hatid/current/public;
    location = /up { rewrite ^ /index.php last; }
    location /api/ { rewrite ^ /index.php last; }
    location = /index.php {
        internal;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
    }
    location / { return 404; }
}
NGINX
    } > "$site"
    ln -sfn "$site" /etc/nginx/sites-enabled/hatid-api
    if [[ $(readlink -f /etc/nginx/sites-enabled/default || true) == /etc/nginx/sites-available/default ]]; then
        unlink /etc/nginx/sites-enabled/default
    fi
    nginx -t
    systemctl reload nginx
}

# Install the pull timer separately from enabling it after the HTTPS smoke check.
install_release_tools() {
    local scripts
    scripts=$(dirname "$0")
    install -m 0755 "$scripts/release.sh" /usr/local/sbin/hatid-release
    install -m 0755 "$scripts/pull-release.sh" /usr/local/sbin/hatid-pull-release

    cat > /etc/systemd/system/hatid-api-pull.service <<'UNIT'
[Unit]
Description=Deploy the latest verified Hatid API main commit
After=network-online.target mysql.service nginx.service
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/hatid-pull-release
TimeoutStartSec=10min
UNIT
    cat > /etc/systemd/system/hatid-api-pull.timer <<'TIMER'
[Unit]
Description=Check GitHub for verified Hatid API commits

[Timer]
OnBootSec=1min
OnUnitInactiveSec=3min
AccuracySec=15s

[Install]
WantedBy=timers.target
TIMER
    systemctl daemon-reload
}

case ${1:-} in
    prepare)
        [[ $# == 1 ]]
        prepare_runtime
        prepare_app
        if [[ ! -f /etc/hatid-release.conf ]] ||
            grep -Fxq 'HATID_ORIGIN=http://127.0.0.1:8080' /etc/hatid-release.conf; then
            write_site
            printf 'HATID_ORIGIN=http://127.0.0.1:8080\n' > /etc/hatid-release.conf
            chmod 0600 /etc/hatid-release.conf
        fi
        install_release_tools
        ;;
    https)
        [[ $# == 2 && $2 =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]
        test -f "$env_file"
        ip=$2
        install -d -m 0755 "$acme"
        if [[ ! -x /snap/bin/certbot ]]; then
            snap install --classic certbot
        fi
        if [[ ! -s /etc/letsencrypt/live/$ip/fullchain.pem ]]; then
            write_site "$ip"
        fi
        /snap/bin/certbot certonly --non-interactive --agree-tos --register-unsafely-without-email \
            --keep-until-expiring --cert-name "$ip" --webroot --webroot-path "$acme" --ip-address "$ip" \
            --required-profile shortlived --deploy-hook 'systemctl reload nginx'
        sed -i "s|^APP_URL=.*|APP_URL=https://$ip|" "$env_file"
        if [[ -L $root/current ]]; then
            (cd "$root/current"; runuser -u www-data -- php8.5 artisan config:cache --no-interaction)
        fi
        write_site "$ip" yes
        printf 'HATID_ORIGIN=https://%s\n' "$ip" > /etc/hatid-release.conf
        chmod 0600 /etc/hatid-release.conf
        ;;
    *)
        echo 'Usage: sudo setup-ec2.sh prepare | https ELASTIC_IPV4' >&2
        exit 2
        ;;
esac
