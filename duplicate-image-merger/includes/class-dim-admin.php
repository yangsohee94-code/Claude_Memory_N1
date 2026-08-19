<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DIM_Admin {

    public function init() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        // 자동 병합 크론
        add_action( 'dim_auto_merge_cron', [ $this, 'run_auto_merge_cron' ] );
    }

    public function register_menu() {
        add_media_page(
            '중복 이미지 병합',
            '중복 이미지 병합',
            'manage_options',
            'duplicate-image-merger',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'media_page_duplicate-image-merger' ) return;

        wp_enqueue_style(
            'dim-style',
            DIM_PLUGIN_URL . 'assets/css/dim.css',
            [],
            DIM_VERSION
        );
        wp_enqueue_script(
            'dim-script',
            DIM_PLUGIN_URL . 'assets/js/dim.js',
            [ 'jquery' ],
            DIM_VERSION,
            true
        );
        wp_localize_script( 'dim-script', 'DIM', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'dim_nonce' ),
            'i18n'     => [
                'scanning'       => '스캔 중...',
                'merging'        => '병합 중...',
                'auto_merging'   => '자동 병합 중...',
                'confirm_merge'  => '선택한 이미지를 병합하시겠습니까? 삭제된 이미지는 복구할 수 없습니다.',
                'confirm_auto'   => '사용 중인 이미지를 기준으로 모든 중복 이미지를 자동 병합하시겠습니까?',
                'no_selection'   => '병합할 이미지를 하나 이상 선택해주세요.',
                'select_keep'    => '유지할 이미지를 선택해주세요.',
            ],
        ] );
    }

    public function render_page() {
        require_once DIM_PLUGIN_DIR . 'templates/admin-page.php';
    }

    /**
     * 크론으로 자동 병합 실행
     */
    public function run_auto_merge_cron() {
        $scanner = new DIM_Scanner();
        $merger  = new DIM_Merger();

        $total   = $scanner->get_total_images();
        $offset  = 0;
        $batch   = 200;

        while ( $offset < $total ) {
            $result = $scanner->scan_duplicates( $batch, $offset );
            foreach ( $result['duplicates'] as $group ) {
                $merger->auto_merge_group( $group );
            }
            if ( ! $result['has_more'] ) break;
            $offset += $batch;
        }

        update_option( 'dim_last_auto_merge', current_time( 'mysql' ) );
    }
}
