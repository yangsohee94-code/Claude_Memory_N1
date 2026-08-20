<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DIM_Ajax {

    public function init() {
        $actions = [
            'scan', 'enrich', 'merge', 'auto_merge', 'convert_webp', 'fix_thumbnails',
            'schedule', 'get_counts',
            'get_stats', 'get_no_thumb_posts', 'get_nonwebp', 'delete_images',
        ];
        foreach ( $actions as $a ) {
            add_action( "wp_ajax_dim_{$a}", [ $this, "handle_{$a}" ] );
        }
    }

    public function handle_scan() {
        $this->auth();
        $scanner = new DIM_Scanner();
        wp_send_json_success( $scanner->scan_duplicates(
            absint( $_POST['batch']  ?? 100 ),
            absint( $_POST['offset'] ?? 0 )
        ) );
    }

    // 스캔 완료 후 중복 그룹 상세정보(is_used 등) 일괄 조회
    public function handle_enrich() {
        $this->auth();
        @set_time_limit( 25 );   // JS 타임아웃(30s)보다 짧게 설정
        @ini_set( 'memory_limit', '256M' );

        $groups = isset( $_POST['groups'] ) && is_array( $_POST['groups'] )
                  ? $_POST['groups'] : [];

        if ( empty( $groups ) ) {
            wp_send_json_success( [ 'groups' => [] ] );
        }

        $scanner = new DIM_Scanner();
        try {
            $result = $scanner->enrich_groups( $groups );
            wp_send_json_success( [ 'groups' => $result ] );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => 'enrich 오류: ' . $e->getMessage() ] );
        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => 'enrich 오류: ' . $e->getMessage() ] );
        }
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

        // 단건 변환 (JS convertSelected에서 single_id로 호출)
        $single_id = absint( $_POST['single_id'] ?? 0 );
        if ( $single_id ) {
            $r = $converter->convert_to_webp( $single_id );
            if ( $r === true ) {
                wp_send_json_success( [ 'converted' => 1, 'skipped' => 0, 'errors' => [], 'unlink_failed' => 0 ] );
            } elseif ( $r === 'unlink' ) {
                wp_send_json_success( [ 'converted' => 1, 'skipped' => 0, 'errors' => [], 'unlink_failed' => 1 ] );
            } elseif ( $r === 'skip' ) {
                wp_send_json_success( [ 'converted' => 0, 'skipped' => 1, 'errors' => [], 'unlink_failed' => 0 ] );
            } else {
                wp_send_json_success( [ 'converted' => 0, 'skipped' => 0, 'errors' => [ "ID {$single_id}: {$r}" ], 'unlink_failed' => 0 ] );
            }
            return;
        }

        // 배치 변환 (전체 변환 버튼, 50개씩)
        wp_send_json_success( $converter->convert_all(
            absint( $_POST['batch']  ?? 50 ),
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
            wp_clear_scheduled_hook( 'dim_optimize_cron' ); // 기존 예약 초기화 후 재등록
            wp_schedule_event( self::next_3am(), 'daily', 'dim_optimize_cron' );
            wp_send_json_success( [ 'message' => '매일 새벽 3시에 자동 최적화가 실행됩니다.' ] );
        } else {
            wp_clear_scheduled_hook( 'dim_optimize_cron' );
            wp_send_json_success( [ 'message' => '자동 최적화 예약이 해제되었습니다.' ] );
        }
    }

    /**
     * 다음 새벽 3시 타임스탬프 (서버 로컬 시간 기준)
     */
    private static function next_3am(): int {
        $now    = current_time( 'timestamp' );
        $target = mktime( 3, 0, 0, (int) date( 'n', $now ), (int) date( 'j', $now ), (int) date( 'Y', $now ) );
        // 이미 오늘 3시가 지났으면 내일 3시
        if ( $target <= $now ) {
            $target = strtotime( '+1 day', $target );
        }
        return $target;
    }

    public function handle_get_stats() {
        $this->auth();
        wp_send_json_success( ( new DIM_Stats() )->get_storage_stats() );
    }

    public function handle_get_no_thumb_posts() {
        $this->auth();
        wp_send_json_success( ( new DIM_Stats() )->get_posts_without_thumbnail(
            absint( $_POST['limit']  ?? 50 ),
            absint( $_POST['offset'] ?? 0 )
        ) );
    }

    public function handle_get_nonwebp() {
        $this->auth();
        wp_send_json_success( ( new DIM_Stats() )->get_nonwebp_images(
            absint( $_POST['limit']  ?? 50 ),
            absint( $_POST['offset'] ?? 0 )
        ) );
    }

    public function handle_delete_images() {
        $this->auth();
        $ids = array_map( 'absint', (array)( $_POST['ids'] ?? [] ) );
        if ( empty( $ids ) ) wp_send_json_error( [ 'message' => '삭제할 이미지가 없습니다.' ] );
        wp_send_json_success( ( new DIM_Stats() )->delete_attachments( $ids ) );
    }

    public function handle_get_counts() {
        $this->auth();
        global $wpdb;

        $total_images = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
        );
        $total_nonwebp = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type IN ('image/jpeg','image/png','image/gif')"
        );

        wp_send_json_success( [
            'total_images'  => $total_images,
            'total_nonwebp' => $total_nonwebp,
        ] );
    }

    private function auth() {
        if ( ! check_ajax_referer( 'dim_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => '권한 없음' ], 403 );
        }
    }
}
