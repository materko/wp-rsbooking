<?php
/**
 * Len pre lokálny vývoj: všetky e-maily idú do Mailpitu (http://localhost:8025).
 */
add_action('phpmailer_init', function ($m) {
    $m->isSMTP();
    $m->Host = 'mailpit';
    $m->Port = 1025;
    $m->SMTPAuth = false;
    $m->SMTPAutoTLS = false;
});
