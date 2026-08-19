<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DIM_Ajax {

    public function init() {
        add_action( 'wp_ajax_dim_scan',        [ $this, 'handle_scan' ] );
        add_action( 'wp_ajax_dim_merge',       [ $this, 'handle_merge' ] );
        add_action( 'wp_ajax_dim_auto_merge',  [ $this, 'handle_auto_merge' ] );
        add_action( 'wp_ajax_dim_check_usage', [ $this, 'handle_check_usage' ] );
        add_action( 'wp_ajax_dim_schedule_auto', [ $this, 'handle_schedule_auto' ] );
    }

    // --- 스캔 ---
    public function handle_scan() {
        $this->verify_nonce();
        $offset = absint( $_POST['offset'] ?? 0 );
        $batch  = absint( $_POST['batch']  ?? 200 );

        $scanner = new DIM_Scanner();
        $result  = $scanner->scan_duplicates( $batch, $offset );
        wp_send_json_success( $result );
    }

    // --- 수동 병합 ---
    public function handle_merge() {
        $this->verify_nonce();

        $keep_id    = absint( $_POST['keep_id'] ?? 0 );
        $delete_ids = array_map( 'absint', (array) ( $_POST['delete_ids'] ?? [] ) );

        if ( ! $keep_id || empty( $delete_ids ) ) {
            wp_send_json_error( [ 'message' => '잘못된 요청입니다.' ] );
        }

        $merger = new DIM_Merger();
        $result = $merger->merge( $keep_id, $delete_ids );
        wp_send_json_success( $result );
    }

    // --- 자동 병합 (전체) ---
    public function handle_auto_merge() {
        $this->verify_nonce();

        $scanner = new DIM_Scanner();
        $merger  = new DIM_Merger();

        $groups  = $_POST['groups'] ?? [];
        if ( empty( $groups ) ) {
            wp_send_json_error( [ 'message' => '병합할 그룹이 없습니다.' ] );
        }

        $total_merged = 0;
        $all_errors   = [];

        foreach ( $groups as $group ) {
            $items = array_map( function( $item ) use ( $scanner ) {
                $item['id']      = absint( $item['id'] );
                $item['is_used'] = $scanner->is_image_in_use( $item['id'] );
                return $item;
            }, $group['items'] );

            $group['items'] = $items;
            $r = $merger->auto_merge_group( $group );
            $total_merged += $r['merged'];
            $all_errors    = array_merge( $all_errors, $r['errors'] );
        }

        wp_send_json_success( [
            'merged' => $total_merged,
            'errors' => $all_errors,
        ] );
    }

    // --- 사용 중 여부 확인 ---
    public function handle_check_usage() {
        $this->verify_nonce();
        $id      = absint( $_POST['attachment_id'] ?? 0 );
        $scanner = new DIM_Scanner();
        wp_send_json_success( [
            'attachment_id' => $id,
            'is_used'       => $scanner->is_image_in_use( $id ),
        ] );
    }

    // --- 자동 병합 스케줄 등록/해제 ---
    public function handle_schedule_auto() {
        $this->verify_nonce();
        $action = sanitize_key( $_POST['schedule_action'] ?? 'enable' );

        if ( $action === 'enable' ) {
            if ( ! wp_next_scheduled( 'dim_auto_merge_cron' ) ) {
                wp_schedule_event( time(), 'daily', 'dim_auto_merge_cron' );
            }
            wp_send_json_success( [ 'message' => '자동 병합이 매일 실행되도록 설정되었습니다.' ] );
        } else {
            wp_clear_scheduled_hook( 'dim_auto_merge_cron' );
            wp_send_json_success( [ 'message' => '자동 병합 예약이 해제되었습니다.' ] );
        }
    }

    private function verify_nonce() {
        if ( ! check_ajax_referer( 'dim_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => '보안 검증 실패' ], 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => '권한 없음' ], 403 );
        }
    }
}
