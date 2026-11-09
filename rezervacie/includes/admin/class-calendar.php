<?php
namespace Rzv\Admin;

defined('ABSPATH') || exit;

class Calendar
{
    public static function render(): void
    {
        echo '<div class="wrap rzv-admin">';
        echo '<h1 class="wp-heading-inline">Kalendár</h1> <a href="#" class="page-title-action" data-rzv-new>Nová rezervácia</a>';
        echo '<hr class="wp-header-end">';
        echo '<div id="rzv-calendar" class="rzv-cal-app"></div>';
        echo '<p class="description">Kliknutím do voľného miesta vytvoríte rezerváciu alebo blokáciu, kliknutím na rezerváciu ju upravíte.</p>';
        echo '</div>';
    }
}
