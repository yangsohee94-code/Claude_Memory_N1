<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DIM_Ajax {

    public function init() {
        $actions = [ 'scan', 'merge', 'auto_merge', 'convert_webp', 'fix_thumbnails', 'schedule' ];
        foreach ( $actions as $a ) {
            add_action( "wp_ajax_dim_{$a}", [ $this, "handle_{$a}" ] );
        }
    }

    public function handle_scan() {
        $this->auth();
        $scanner = new DIM_Scanner();
        wp_send_json_success( $scanner->scan_duplicates(
            absint( $_POST['batch']  ?? 200 ),
            absint( $_POST['offset'] ?? 0 )
        ) );
    }

    public function handle_merge() {
        $this->auth();
        $keep_id    = absint( $_POST['keep_id'] ?? 0 );
        $delete_ids = array_map( 'absint', (array)( $_POST['delete_ids'] ?? [] ) );
        if ( ! $keep_id || ! $delete_ids ) wp_send_json_error( [ 'message' => '잘못된 요청' ] );
        wp_send_json_success( ( new DIM_Merger() )->merge( $keep_id, $delete_ids ) );
    }

    public function handle_auto_merge() {
        $this->auth();
        $groups = $_POST['groups'] ?? [];
        if ( empty( $groups ) ) wp_send_json_error( [ 'message' => '그룹 없음' ] );

        $scanner = new DIM_Scanner();
        $merger  = new DIM_Merger();
        $merged  = 0;
        $errors  = [];

        foreach ( $groups as $group ) {
            foreach ( $group['items'] as &$item ) {
                $item['id']      = absint( $item['id'] );
                $item['is_used'] = $scanner->is_image_in_use( $item['id'] );
            }
            $r       = $merger->auto_merge_group( $group );
            $merged += $r['merged'];
            $errors  = array_merge( $errors, $r['errors'] );
        }

        wp_send_json_success( [ 'merged' => $merged, 'errors' => $errors ] );
    }

    public function handle_convert_webp() {
        $this->auth();
        $converter = new DIM_Converter();
        if ( ! $converter->can_convert() ) {
            wp_send_json_error( [ 'message' => '이 서버는 WebP 변환을 지원하지 않습니다 (GD/Imagick 필요).' ] );
        }
        wp_send_json_success( $converter->convert_all(
            absint( $_POST['batch']  ?? 30 ),
            absint( $_POST['offset'] ?? 0 )
        ) );
    }

    public function handle_fix_thumbnails() {
        $this->auth();
        wp_send_json_success( ( new DIM_Thumbnail() )->fix_all() );
    }

    public function handle_schedule() {
        $this->auth();
        $on = filter_var( $_POST['enable'] ?? true, FILTER_VALIDATE_BOOLEAN );
        if ( $on ) {
            if ( ! wp_next_scheduled( 'dim_optimize_cron' ) ) {
                wp_schedule_event( time(), 'daily', 'dim_optimize_cron' );
            }
            wp_send_json_success( [ 'message' => '매일 자동 최적화가 예약되었습니다.' ] );
        } else {
            wp_clear_scheduled_hook( 'dim_optimize_cron' );
            wp_send_json_success( [ 'message' => '자동 최적화 예약이 해제되었습니다.' ] );
        }
    }

    private function auth() {
        if ( ! check_ajax_referer( 'dim_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => '권한 없음' ], 403 );
        }
    }
}
