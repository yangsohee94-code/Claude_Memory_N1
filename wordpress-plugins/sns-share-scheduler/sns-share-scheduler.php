<?php
/**
 * Plugin Name: SNS Share Scheduler
 * Plugin URI: https://night-lab.soheeya.co.kr
 * Description: 워드프레스 글 발행 후 X, Threads, Pinterest, Facebook에 4시간 간격으로 자동 예약 공유
 * Version: 1.0.0
 * Author: Sohee Yang
 * Author URI: https://night-lab.soheeya.co.kr
 * Text Domain: sns-share-scheduler
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SNS_SCHEDULER_VERSION', '1.0.0' );
define( 'SNS_SCHEDULER_PATH', plugin_dir_path( __FILE__ ) );
define( 'SNS_SCHEDULER_URL', plugin_dir_url( __FILE__ ) );

require_once SNS_SCHEDULER_PATH . 'includes/class-content-generator.php';
require_once SNS_SCHEDULER_PATH . 'includes/class-scheduler.php';
require_once SNS_SCHEDULER_PATH . 'includes/class-bulk-schedule.php';
require_once SNS_SCHEDULER_PATH . 'includes/class-admin.php';
require_once SNS_SCHEDULER_PATH . 'includes/apis/class-twitter-api.php';
require_once SNS_SCHEDULER_PATH . 'includes/apis/class-threads-api.php';
require_once SNS_SCHEDULER_PATH . 'includes/apis/class-pinterest-api.php';
require_once SNS_SCHEDULER_PATH . 'includes/apis/class-facebook-api.php';

function sns_scheduler_init() {
    new SNS_Admin();
    new SNS_Scheduler();
    new SNS_Bulk_Schedule();
}
add_action( 'plugins_loaded', 'sns_scheduler_init' );

register_activation_hook( __FILE__, 'sns_scheduler_activate' );
function sns_scheduler_activate() {
    global $wpdb;
    $table = $wpdb->prefix . 'sns_share_queue';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        platform varchar(20) NOT NULL,
        content text NOT NULL,
        image_url varchar(500) DEFAULT '',
        post_url varchar(500) DEFAULT '',
        scheduled_at datetime NOT NULL,
        status varchar(20) DEFAULT 'pending',
        result text DEFAULT '',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY post_id (post_id),
        KEY platform (platform),
        KEY status (status),
        KEY scheduled_at (scheduled_at)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

register_deactivation_hook( __FILE__, 'sns_scheduler_deactivate' );
function sns_scheduler_deactivate() {
    wp_clear_scheduled_hook( 'sns_scheduler_process_queue' );
}
