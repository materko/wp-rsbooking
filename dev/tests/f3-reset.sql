-- vyčistí dáta, ktoré vytvára dev/tests/f3.php (aby sa dal spustiť opakovane)
DELETE FROM wp_rzv_ratings WHERE booking_id IN (SELECT b.id FROM wp_rzv_bookings b JOIN wp_rzv_customers c ON c.id = b.customer_id WHERE c.email IN ('rent@example.test','verna@example.test'));
DELETE b FROM wp_rzv_bookings b JOIN wp_rzv_customers c ON c.id = b.customer_id WHERE c.email IN ('rent@example.test','verna@example.test');
DELETE FROM wp_rzv_service_resource WHERE service_id IN (SELECT id FROM wp_rzv_services WHERE name = 'Prenájom salónika');
DELETE FROM wp_rzv_services WHERE name = 'Prenájom salónika';
DELETE FROM wp_rzv_resources WHERE name = 'Salónik';
