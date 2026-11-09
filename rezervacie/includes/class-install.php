<?php
namespace Rzv;

defined('ABSPATH') || exit;

class Install
{
    public static function activate(): void
    {
        self::create_tables();
        self::add_caps();
        Settings::ensure_defaults();
        self::create_booking_page();
        self::create_voucher_page();
        if (!wp_next_scheduled('rzv_cron')) {
            wp_schedule_event(time() + 60, 'rzv_quarter', 'rzv_cron');
        }
        update_option('rzv_db_version', RZV_DB_VERSION);
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('rzv_cron');
    }

    public static function maybe_upgrade(): void
    {
        if ((int) get_option('rzv_db_version') < RZV_DB_VERSION) {
            self::create_tables();
            self::add_caps();
            Settings::ensure_defaults();
            if ((int) get_option('rzv_db_version') < 2) {
                self::create_voucher_page();
            }
            update_option('rzv_db_version', RZV_DB_VERSION);
        }
    }

    public static function add_caps(): void
    {
        foreach (['administrator', 'editor'] as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->add_cap('rzv_manage');
            }
        }
        if (!get_role('rzv_worker')) {
            add_role('rzv_worker', 'Pracovník (rezervácie)', ['read' => true, 'rzv_worker' => true]);
        }
    }

    private static function create_voucher_page(): void
    {
        $id = (int) Settings::get('voucher_page');
        if ($id && get_post($id)) {
            return;
        }
        $id = wp_insert_post([
            'post_title'   => 'Darčekové poukážky',
            'post_name'    => 'poukazky',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '<!-- wp:shortcode -->[rezervacie_poukazky]<!-- /wp:shortcode -->',
        ]);
        if ($id && !is_wp_error($id)) {
            Settings::set('voucher_page', $id);
        }
    }

    private static function create_booking_page(): void
    {
        $id = (int) Settings::get('booking_page');
        if ($id && get_post($id)) {
            return;
        }
        $id = wp_insert_post([
            'post_title'   => 'Rezervácia',
            'post_name'    => 'rezervacia',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '<!-- wp:shortcode -->[rezervacie]<!-- /wp:shortcode -->',
        ]);
        if ($id && !is_wp_error($id)) {
            Settings::set('booking_page', $id);
        }
    }

    public static function create_tables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'rzv_';

        $sql = [];

        $sql[] = "CREATE TABLE {$p}categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            description TEXT NULL,
            priority INT NOT NULL DEFAULT 0,
            visible TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}services (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            category_id BIGINT UNSIGNED NULL,
            kind VARCHAR(10) NOT NULL DEFAULT 'service',
            name VARCHAR(190) NOT NULL,
            description TEXT NULL,
            duration INT NOT NULL DEFAULT 60,
            buffer_before INT NOT NULL DEFAULT 0,
            buffer_after INT NOT NULL DEFAULT 0,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            deposit DECIMAL(10,2) NOT NULL DEFAULT 0,
            capacity INT NOT NULL DEFAULT 1,
            auto_confirm TINYINT(1) NOT NULL DEFAULT 1,
            visible TINYINT(1) NOT NULL DEFAULT 1,
            active TINYINT(1) NOT NULL DEFAULT 1,
            priority INT NOT NULL DEFAULT 0,
            color VARCHAR(20) NULL,
            PRIMARY KEY  (id),
            KEY category_id (category_id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}workers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            name VARCHAR(190) NOT NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(50) NULL,
            description TEXT NULL,
            photo VARCHAR(255) NULL,
            hours LONGTEXT NULL,
            color VARCHAR(20) NULL,
            notify TINYINT(1) NOT NULL DEFAULT 1,
            visible TINYINT(1) NOT NULL DEFAULT 1,
            active TINYINT(1) NOT NULL DEFAULT 1,
            priority INT NOT NULL DEFAULT 0,
            PRIMARY KEY  (id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}service_worker (
            service_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (service_id,worker_id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}resources (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}service_resource (
            service_id BIGINT UNSIGNED NOT NULL,
            resource_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (service_id,resource_id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}addons (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            description TEXT NULL,
            duration INT NOT NULL DEFAULT 0,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            priority INT NOT NULL DEFAULT 0,
            PRIMARY KEY  (id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}service_addon (
            service_id BIGINT UNSIGNED NOT NULL,
            addon_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (service_id,addon_id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}fields (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            label VARCHAR(255) NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'text',
            options TEXT NULL,
            required TINYINT(1) NOT NULL DEFAULT 0,
            all_services TINYINT(1) NOT NULL DEFAULT 1,
            active TINYINT(1) NOT NULL DEFAULT 1,
            priority INT NOT NULL DEFAULT 0,
            PRIMARY KEY  (id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}service_field (
            service_id BIGINT UNSIGNED NOT NULL,
            field_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (service_id,field_id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}blocked (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            worker_id BIGINT UNSIGNED NULL,
            resource_id BIGINT UNSIGNED NULL,
            start_at DATETIME NOT NULL,
            end_at DATETIME NOT NULL,
            repeat_weekly TINYINT(1) NOT NULL DEFAULT 0,
            repeat_until DATE NULL,
            note VARCHAR(255) NULL,
            PRIMARY KEY  (id),
            KEY start_at (start_at)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}customers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NOT NULL DEFAULT '',
            email VARCHAR(190) NOT NULL DEFAULT '',
            phone VARCHAR(50) NOT NULL DEFAULT '',
            note TEXT NULL,
            tags VARCHAR(255) NULL,
            marketing TINYINT(1) NOT NULL DEFAULT 0,
            blocked TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY email (email),
            KEY phone (phone)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}bookings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token VARCHAR(64) NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            service_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NOT NULL,
            start_at DATETIME NOT NULL,
            end_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            discount DECIMAL(10,2) NOT NULL DEFAULT 0,
            paid DECIMAL(10,2) NOT NULL DEFAULT 0,
            coupon_code VARCHAR(50) NULL,
            voucher_id BIGINT UNSIGNED NULL,
            voucher_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            pay_online DECIMAL(10,2) NOT NULL DEFAULT 0,
            expires_at DATETIME NULL,
            loyalty_level VARCHAR(30) NULL,
            addons LONGTEXT NULL,
            answers LONGTEXT NULL,
            note TEXT NULL,
            internal_note TEXT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'web',
            reminder_sent TINYINT(1) NOT NULL DEFAULT 0,
            rating_sent TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY worker_start (worker_id,start_at),
            KEY start_at (start_at),
            KEY customer_id (customer_id),
            KEY status (status)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}waitlist (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NOT NULL,
            service_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NULL,
            date DATE NOT NULL,
            time_from TIME NULL,
            time_to TIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'waiting',
            notified_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY date (date)
        ) $c;";

        // ---- F2: zľavové kódy, poukážky, platby ----
        $sql[] = "CREATE TABLE {$p}coupons (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(50) NOT NULL,
            note VARCHAR(255) NULL,
            type VARCHAR(10) NOT NULL DEFAULT 'percent',
            value DECIMAL(10,2) NOT NULL DEFAULT 0,
            scope VARCHAR(10) NOT NULL DEFAULT 'all',
            valid_from DATE NULL,
            valid_to DATE NULL,
            max_uses INT NULL,
            uses INT NOT NULL DEFAULT 0,
            per_customer INT NULL,
            min_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}coupon_service (
            coupon_id BIGINT UNSIGNED NOT NULL,
            service_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (coupon_id,service_id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}voucher_types (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            description TEXT NULL,
            kind VARCHAR(10) NOT NULL DEFAULT 'value',
            value DECIMAL(10,2) NOT NULL DEFAULT 0,
            service_id BIGINT UNSIGNED NULL,
            entries INT NOT NULL DEFAULT 1,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            validity_days INT NOT NULL DEFAULT 365,
            image VARCHAR(255) NULL,
            color VARCHAR(20) NULL,
            sell_online TINYINT(1) NOT NULL DEFAULT 1,
            active TINYINT(1) NOT NULL DEFAULT 1,
            priority INT NOT NULL DEFAULT 0,
            PRIMARY KEY  (id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}vouchers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(30) NOT NULL,
            token VARCHAR(64) NOT NULL,
            type_id BIGINT UNSIGNED NULL,
            kind VARCHAR(10) NOT NULL DEFAULT 'value',
            name VARCHAR(190) NOT NULL DEFAULT '',
            service_id BIGINT UNSIGNED NULL,
            initial_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            balance DECIMAL(10,2) NOT NULL DEFAULT 0,
            entries_total INT NOT NULL DEFAULT 0,
            entries_left INT NOT NULL DEFAULT 0,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            discount DECIMAL(10,2) NOT NULL DEFAULT 0,
            coupon_code VARCHAR(50) NULL,
            buyer_name VARCHAR(190) NULL,
            buyer_email VARCHAR(190) NULL,
            buyer_phone VARCHAR(50) NULL,
            recipient VARCHAR(190) NULL,
            message TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
            valid_to DATE NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'web',
            created_at DATETIME NOT NULL,
            activated_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            UNIQUE KEY token (token)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}voucher_uses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            voucher_id BIGINT UNSIGNED NOT NULL,
            booking_id BIGINT UNSIGNED NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            entries INT NOT NULL DEFAULT 0,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY voucher_id (voucher_id),
            KEY booking_id (booking_id)
        ) $c;";

        $sql[] = "CREATE TABLE {$p}payments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id BIGINT UNSIGNED NULL,
            voucher_id BIGINT UNSIGNED NULL,
            method VARCHAR(20) NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            reference VARCHAR(64) NULL,
            provider_ref VARCHAR(255) NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            paid_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY booking_id (booking_id),
            KEY voucher_id (voucher_id),
            KEY provider_ref (provider_ref(64))
        ) $c;";

        // ---- F3: hodnotenia ----
        $sql[] = "CREATE TABLE {$p}ratings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            service_id BIGINT UNSIGNED NULL,
            worker_id BIGINT UNSIGNED NULL,
            stars TINYINT NOT NULL,
            comment TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            reply TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY booking_id (booking_id),
            KEY worker_id (worker_id),
            KEY status (status)
        ) $c;";

        foreach ($sql as $q) {
            dbDelta($q);
        }
    }
}
