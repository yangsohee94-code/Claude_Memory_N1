<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DIM_Admin {

    public function init() {
        add_action( 'admin_menu',                [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts',     [ $this, 'enqueue_assets' ] );
        add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
        add_action( 'admin_head',                [ $this, 'media_library_styles' ] );
        add_action( 'dim_optimize_cron',         [ $this, 'run_cron' ] );
    }

    public function register_menu() {
        add_media_page( '이미지 최적화 관리', '이미지 최적화', 'manage_options',
            'duplicate-image-merger', [ $this, 'render_page' ] );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'media_page_duplicate-image-merger' ) return;

        wp_enqueue_style(  'dim-style',  DIM_PLUGIN_URL . 'assets/css/dim.css', [], DIM_VERSION );
        wp_enqueue_script( 'dim-script', DIM_PLUGIN_URL . 'assets/js/dim.js', [ 'jquery' ], DIM_VERSION, true );
        // can_convert() 는 프로브 파일을 쓰므로 transient 로 캐싱 (AJAX와 동일한 키 공유)
        $cap = get_transient( 'dim_can_webp' );
        if ( $cap === false ) {
            $cap = ( new DIM_Converter() )->can_convert() ? '1' : '0';
            set_transient( 'dim_can_webp', $cap, 5 * MINUTE_IN_SECONDS );
        }

        wp_localize_script( 'dim-script', 'DIM', [
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
            'admin_url' => admin_url(),
            'nonce'     => wp_create_nonce( 'dim_nonce' ),
            'can_webp'  => ( $cap === '1' ),
            'cron_on'   => (bool) wp_next_scheduled( 'dim_optimize_cron' ),
            'last_run'  => get_option( 'dim_last_cron_run', '' ),
        ] );
    }

    public function enqueue_editor_assets() {
        wp_enqueue_script(
            'dim-editor',
            DIM_PLUGIN_URL . 'assets/js/dim-editor.js',
            [ 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'jquery' ],
            DIM_VERSION,
            true
        );
        wp_localize_script( 'dim-editor', 'DIM_EDITOR', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'dim_nonce' ),
        ] );
    }

    public function media_library_styles() {
        global $pagenow;
        if ( $pagenow !== 'upload.php' ) return;
        ?>
        <style id="dim-media-styles">
        /* DIM: 미디어 라이브러리 썸네일 크게 (약 4개씩) */
        .media-frame-content .attachments-browser .attachments .attachment {
            width: 230px !important;
        }
        .media-frame-content .attachments-browser .attachments .attachment .thumbnail {
            width: 230px !important;
            height: 210px !important;
        }
        .media-frame-content .attachments-browser .attachments .attachment .thumbnail img {
            max-width: 100% !important;
            max-height: 210px !important;
            width: auto !important;
            height: auto !important;
        }
        .media-frame-content .attachments-browser .attachments .attachment:focus::after,
        .media-frame-content .attachments-browser .attachments .attachment.selected::after {
            box-shadow: inset 0 0 0 3px #007cba, inset 0 0 0 7px #fff;
        }
        </style>
        <?php
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
        // nothing_done: converted=0 & skipped=0 & 에러만 있을 때도 무한루프 방지
        if ( $converter->can_convert() ) {
            while ( true ) {
                $r = $converter->convert_all( 50, 0 );
                $nothing_done = ( $r['converted'] === 0 && $r['skipped'] === 0 );
                if ( ! $r['has_more'] || $nothing_done ) break;
            }
        }

        // 2. 대표이미지 정합성
        $thumbnail->fix_all();

        update_option( 'dim_last_cron_run', current_time( 'mysql' ) );
    }
}
