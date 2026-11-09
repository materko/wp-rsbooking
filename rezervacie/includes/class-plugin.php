<?php
namespace Rzv;

defined('ABSPATH') || exit;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        add_action('rest_api_init', [Rest::class, 'register']);
        add_action('rzv_cron', [Mailer::class, 'cron']);
        add_action('init', [Frontend::class, 'init']);
        add_action('init', [Ratings::class, 'init']);
        if (is_admin()) {
            Admin\Admin::init();
        }
        do_action('rzv_loaded');
    }
}
