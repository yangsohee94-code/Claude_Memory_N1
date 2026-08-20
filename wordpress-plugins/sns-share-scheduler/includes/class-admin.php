<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SNS_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_assets' ] );
        add_action( 'wp_ajax_sns_oauth_callback', [ $this, 'handle_oauth_callback' ] );
    }

    public function add_menu() {
        add_options_page(
            'SNS 공유 설정',
            '📣 SNS 공유',
            'manage_options',
            'sns-share-scheduler',
            [ $this, 'render_settings_page' ]
        );
        // 퀵 메뉴 (글 목록 옆)
        add_submenu_page(
            'edit.php',
            'SNS 공유 현황',
            'SNS 공유 현황',
            'edit_posts',
            'sns-share-status',
            [ $this, 'render_status_page' ]
        );
        add_submenu_page(
            'edit.php',
            'SNS 예약 달력',
            '📅 SNS 달력',
            'edit_posts',
            'sns-share-calendar',
            [ $this, 'render_calendar_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'sns_scheduler_options', 'sns_scheduler_options', [ $this, 'sanitize_options' ] );
    }

    public function sanitize_options( $input ) {
        $safe = [];
        $text_fields = [
            'twitter_api_key', 'twitter_api_secret',
            'twitter_access_token', 'twitter_access_secret',
            'threads_access_token', 'threads_user_id',
            'pinterest_access_token', 'pinterest_board_id',
            'facebook_page_access_token', 'facebook_page_id',
            'twitter_content_template', 'threads_content_template',
            'pinterest_content_template', 'facebook_content_template',
        ];
        foreach ( $text_fields as $field ) {
            $safe[ $field ] = sanitize_textarea_field( $input[ $field ] ?? '' );
        }
        return $safe;
    }

    public function enqueue_settings_assets( $hook ) {
        if ( strpos( $hook, 'sns-share-scheduler' ) === false && strpos( $hook, 'sns-share-status' ) === false ) return;
        wp_enqueue_style( 'sns-admin-style', SNS_SCHEDULER_URL . 'assets/css/sns-admin.css', [], SNS_SCHEDULER_VERSION );
    }

    public function render_settings_page() {
        include SNS_SCHEDULER_PATH . 'templates/settings-page.php';
    }

    public function render_status_page() {
        include SNS_SCHEDULER_PATH . 'templates/status-page.php';
    }

    public function render_calendar_page() {
        include SNS_SCHEDULER_PATH . 'templates/calendar-page.php';
    }
}
