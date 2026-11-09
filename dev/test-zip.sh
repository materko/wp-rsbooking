#!/bin/sh
# Overí balík dist/rezervacie-<verzia>.zip čistou inštaláciou do dočasného WordPressu (DB wp_ziptest).
# Spustenie z koreňa projektu:  sh dev/test-zip.sh 0.3.0
set -e
V="${1:?verzia, napr. 0.3.0}"
docker compose exec -T db mariadb -uroot -proot -e "DROP DATABASE IF EXISTS wp_ziptest; CREATE DATABASE wp_ziptest; GRANT ALL ON wp_ziptest.* TO 'wp'@'%';"
docker compose run --rm -q -e WORDPRESS_DB_NAME=wp_ziptest -e V="$V" -v "$PWD/dist:/dist:ro" cli sh -s <<'EOS'
set -e
cp -r /var/www/html/. /tmp/site 2>/dev/null || true
rm -rf /tmp/site/wp-content/plugins/rezervacie /tmp/site/wp-content/debug.log
cd /tmp/site
wp core install --url=http://ziptest.local --title=ZipTest --admin_user=admin --admin_password=admin --admin_email=a@example.test --skip-email --quiet
wp plugin install "/dist/rezervacie-$V.zip" --activate
wp plugin list --name=rezervacie --fields=name,status,version
cat > /tmp/check.php <<'PHP'
<?php
global $wpdb;
echo 'DB verzia: ', get_option('rzv_db_version'), PHP_EOL;
echo 'Tabuľky: ', count($wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}rzv_%'")), PHP_EOL;
echo 'Cron: ', wp_next_scheduled('rzv_cron') ? 'naplánovaný' : 'CHÝBA', PHP_EOL;
$s = Rzv\Settings::all();
foreach (['booking_page', 'voucher_page'] as $k) {
    echo $k, ': ', $s[$k] ? get_post($s[$k])->post_name : 'CHÝBA', PHP_EOL;
}
$r = rest_do_request(new WP_REST_Request('GET', '/rzv/v1/catalog'));
echo 'REST catalog: ', $r->get_status(), PHP_EOL;
echo 'Shortcode: ', strpos(do_shortcode('[rezervacie]'), 'rzv-widget') !== false ? 'OK' : 'CHYBA', PHP_EOL;
PHP
wp eval-file /tmp/check.php
if [ -s wp-content/debug.log ]; then echo "--- debug.log:"; tail -5 wp-content/debug.log; else echo "debug.log: čistý"; fi
EOS
docker compose exec -T db mariadb -uroot -proot -e "DROP DATABASE wp_ziptest;"
