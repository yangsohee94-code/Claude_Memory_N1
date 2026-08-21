<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DIM_Admin {

    public function init() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'dim_optimize_cron',     [ $this, 'run_cron' ] );
    }

    public function register_menu() {
        add_media_page( '이미지 최적화 관리', '이미지 최적화', 'manage_options',
            'duplicate-image-merger', [ $this, 'render_page' ] );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'media_page_duplicate-image-merger' ) return;

        wp_enqueue_style(  'dim-style',  DIM_PLUGIN_URL . 'assets/css/dim.css', [], DIM_VERSION );
        wp_enqueue_script( 'dim-script', DIM_PLUGIN_URL . 'assets/js/dim.js', [ 'jquery' ], DIM_VERSION, true );
        wp_localize_script( 'dim-script', 'DIM', [
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
            'admin_url' => admin_url(),
            'nonce'     => wp_create_nonce( 'dim_nonce' ),
            'can_webp'  => ( new DIM_Converter() )->can_convert(),
            'cron_on'   => (bool) wp_next_scheduled( 'dim_optimize_cron' ),
            'last_run'  => get_option( 'dim_last_cron_run', '' ),
        ] );
    }

    public function render_page() {
        require_once DIM_PLUGIN_DIR . 'templates/admin-page.php';
    }

    /**
     * 자동 크론: WebP 변환 → 대표이미지 정합성 수정
     */
    public function run_cron() {
        $converter = new DIM_Converter();
        $thumbnail = new DIM_Thumbnail();

        // 1. WebP 변환 — 변환 후 mime_type이 webp로 바뀌므로 항상 offset=0
        if ( $converter->can_convert() ) {
            while ( true ) {
                $r = $converter->convert_all( 50, 0 );
                $nothing_done = ( $r['converted'] === 0 && $r['skipped'] === 0 && empty( $r['errors'] ) );
                if ( ! $r['has_more'] || $nothing_done ) break;
            }
        }

        // 2. 대표이미지 정합성
        $thumbnail->fix_all();

        update_option( 'dim_last_cron_run', current_time( 'mysql' ) );
    }
}
