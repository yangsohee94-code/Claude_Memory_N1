<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DIM_Scanner {

    // 세션 내 캐시: 썸네일 ID 목록 (한 번만 로드)
    private $thumbnail_ids = null;

    /**
     * 스캔: MD5 해시만으로 중복 그룹 구성. is_used 체크 없음 (속도 최적화)
     */
    public function scan_duplicates( $batch_size = 100, $offset = 0 ) {
        @set_time_limit( 120 );

        global $wpdb;

        $attachments = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type LIKE 'image/%'
             ORDER BY ID ASC
             LIMIT %d OFFSET %d",
            $batch_size, $offset
        ) );

        $hash_map = [];

        foreach ( $attachments as $att ) {
            $file = get_attached_file( $att->ID );
            if ( ! $file || ! file_exists( $file ) ) continue;

            $hash = md5_file( $file );
            if ( ! $hash ) continue;

            $hash_map[ $hash ][] = (int) $att->ID;
        }

        // 중복(2개 이상)인 ID 목록만 반환 — is_used는 이 단계에서 하지 않음
        $duplicates = [];
        foreach ( $hash_map as $hash => $ids ) {
            if ( count( $ids ) >= 2 ) {
                $duplicates[] = [ 'hash' => $hash, 'ids' => $ids ];
            }
        }

        return [
            'duplicates'    => $duplicates,
            'total_scanned' => count( $attachments ),
            'has_more'      => count( $attachments ) === $batch_size,
        ];
    }

    /**
     * 중복 그룹 ID 목록 → 상세 정보(is_used 포함) 한꺼번에 조회
     * 3개의 bulk 쿼리만 사용 — 개별 WP API 호출 없음
     */
    public function enrich_groups( array $groups ) {
        if ( empty( $groups ) ) return [];

        global $wpdb;

        $all_ids = [];
        foreach ( $groups as $g ) {
            foreach ( $g['ids'] as $id ) $all_ids[] = (int) $id;
        }
        $all_ids = array_unique( $all_ids );

        // 안전한 정수 목록 (prepare 대신 intval 사용)
        $id_list = implode( ',', $all_ids );

        // ① 게시물 제목·날짜·URL (1 쿼리)
        $post_rows = $wpdb->get_results(
            "SELECT ID, post_title, post_date, guid
             FROM {$wpdb->posts} WHERE ID IN ($id_list)"
        );
        $post_map = [];
        foreach ( $post_rows as $r ) {
            $post_map[ (int) $r->ID ] = $r;
        }

        // ② 파일 경로 (_wp_attached_file, 1 쿼리)
        $upload   = wp_upload_dir();
        $base_dir = trailingslashit( $upload['basedir'] );
        $base_url = trailingslashit( $upload['baseurl'] );

        $meta_rows = $wpdb->get_results(
            "SELECT post_id, meta_value
             FROM {$wpdb->postmeta}
             WHERE post_id IN ($id_list) AND meta_key = '_wp_attached_file'"
        );
        $file_map = [];
        $url_map  = [];
        foreach ( $meta_rows as $m ) {
            $id = (int) $m->post_id;
            $file_map[ $id ] = $base_dir . $m->meta_value;
            $url_map[ $id ]  = $base_url . $m->meta_value;
        }

        // ③ 썸네일로 사용 중인 ID (1 쿼리)
        $thumb_used = $this->get_thumbnail_ids();

        $enriched = [];
        foreach ( $groups as $g ) {
            $items = [];
            foreach ( $g['ids'] as $id ) {
                $id   = (int) $id;
                $file = $file_map[ $id ] ?? null;
                $post = $post_map[ $id ] ?? null;

                $items[] = [
                    'id'        => $id,
                    'file_size' => ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0,
                    'url'       => $url_map[ $id ] ?? '',
                    'is_used'   => in_array( $id, $thumb_used, true ),
                    'title'     => $post ? $post->post_title : '',
                    'date'      => $post ? substr( $post->post_date, 0, 16 ) : '',
                ];
            }
            $enriched[] = [
                'hash'  => $g['hash'],
                'items' => $items,
                'count' => count( $items ),
            ];
        }

        return $enriched;
    }

    /**
     * 특정 첨부파일 단건 사용 여부 확인 (병합/자동병합 시 사용)
     */
    public function is_image_in_use( $attachment_id ) {
        global $wpdb;

        $thumb_used = $this->get_thumbnail_ids();
        if ( in_array( (int) $attachment_id, $thumb_used, true ) ) return true;

        $url = wp_get_attachment_url( $attachment_id );
        if ( ! $url ) return false;

        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_status NOT IN ('trash','auto-draft')
               AND post_content LIKE %s",
            '%' . $wpdb->esc_like( $url ) . '%'
        ) );
    }

    // ── 내부 헬퍼 ──

    private function get_thumbnail_ids(): array {
        if ( $this->thumbnail_ids !== null ) return $this->thumbnail_ids;
        global $wpdb;
        $rows = $wpdb->get_col(
            "SELECT DISTINCT CAST(meta_value AS UNSIGNED) FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id' AND meta_value != ''"
        );
        $this->thumbnail_ids = array_map( 'intval', $rows );
        return $this->thumbnail_ids;
    }

    public function get_total_images() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
        );
    }
}
