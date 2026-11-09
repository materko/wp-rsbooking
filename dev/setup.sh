#!/bin/sh
# Inštalácia WordPressu, aktivácia pluginu a demo dáta (podľa Bookio – Salonik AKTA).
# Spustenie:  docker compose run --rm cli sh /dev-scripts/setup.sh
set -e
cd /var/www/html

until wp db check >/dev/null 2>&1; do echo "čakám na DB…"; sleep 2; done

if ! wp core is-installed 2>/dev/null; then
  wp core install --url=http://localhost:8080 --title="Salonik AKTA" \
    --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
  wp language core install sk_SK --activate || true
  wp option update timezone_string "Europe/Bratislava"
  wp option update date_format "j. n. Y"
  wp option update time_format "H:i"
  wp rewrite structure '/%postname%/' --hard
fi

wp plugin activate rezervacie

P=$(wp db prefix)rzv_
if [ "$(wp db query "SELECT COUNT(*) FROM ${P}services" --skip-column-names)" = "0" ]; then
  echo "Vkladám demo dáta…"
  wp db query "
    INSERT INTO ${P}categories (id,name,priority,visible) VALUES (1,'Laser',1,1),(2,'Pleť',0,1);
    INSERT INTO ${P}workers (id,name,email,phone,active,visible,priority,notify,color) VALUES
      (1,'Made me','madebyme@example.test','+421902000000',1,1,1,1,'#c2185b'),
      (2,'Jurko Malý','jurko@example.test','',1,1,0,1,'#1565c0');
    INSERT INTO ${P}services (id,category_id,name,description,duration,price,capacity,auto_confirm,active,visible,priority,color) VALUES
      (1,1,'Hĺbkové čistenie pleti','',75,45,1,1,1,1,2,'#ad1457'),
      (2,2,'Ošetrenie pleti s maskou','',45,30,1,1,1,1,1,'#6a1b9a'),
      (3,1,'Rýchly','na rýchlo',120,100,1,0,1,1,0,'#00838f');
    INSERT INTO ${P}service_worker VALUES (1,1),(2,1),(3,1),(1,2);
    INSERT INTO ${P}addons (id,name,duration,price,active) VALUES (1,'Masáž tváre',15,10,1);
    INSERT INTO ${P}service_addon VALUES (1,1),(2,1);
    INSERT INTO ${P}resources (id,name,quantity,active) VALUES (1,'Laser',1,1);
    INSERT INTO ${P}service_resource VALUES (1,1),(3,1);
    INSERT INTO ${P}fields (id,label,type,options,required,all_services,active) VALUES
      (1,'Ste u nás prvýkrát?','yesno','',0,1,1),
      (2,'Typ pleti','select','Normálna\nSuchá\nMastná\nCitlivá',0,1,1);
  "
fi
echo "Hotovo: http://localhost:8080/rezervacia/  admin: http://localhost:8080/wp-admin (admin/admin)  e-maily: http://localhost:8025"
