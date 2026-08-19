<?php
/**
 * Plugin Name: Duplicate Image Merger
 * Plugin URI:  https://github.com/yangsohee94-code/claude_memory_n1
 * Description: 워드프레스 미디어 라이브러리의 중복 이미지를 찾아 병합합니다.
 * Version:     1.0.0
 * Author:      Claude Memory N1
 * License:     GPL-2.0+
 * Text Domain: duplicate-image-merger
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DIM_VERSION', '1.0.0' );
define( 'DIM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DIM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once DIM_PLUGIN_DIR . 'includes/class-dim-scanner.php';
require_once DIM_PLUGIN_DIR . 'includes/class-dim-merger.php';
require_once DIM_PLUGIN_DIR . 'includes/class-dim-admin.php';
require_once DIM_PLUGIN_DIR . 'includes/class-dim-ajax.php';

function dim_init() {
    $admin = new DIM_Admin();
    $admin->init();

    $ajax = new DIM_Ajax();
    $ajax->init();
}
add_action( 'plugins_loaded', 'dim_init' );

register_activation_hook( __FILE__, 'dim_activate' );
function dim_activate() {
    global $wpdb;
    $table = $wpdb->prefix . 'dim_scan_results';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        hash varchar(64) NOT NULL,
        attachment_id bigint(20) NOT NULL,
        file_path text NOT NULL,
        file_size bigint(20) DEFAULT 0,
        is_used tinyint(1) DEFAULT 0,
        scanned_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY hash (hash),
        KEY attachment_id (attachment_id)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

register_deactivation_hook( __FILE__, 'dim_deactivate' );
function dim_deactivate() {
    wp_clear_scheduled_hook( 'dim_auto_merge_cron' );
}
