<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DIM_Scanner {

    private $thumbnail_ids = null;

    /**
     * 스캔 + 즉시 enrich 통합: 중복 발견 시 같은 배치에서 메타 bulk 조회
     * 별도 enrich 단계 없음 — AJAX 호출 수 = 스캔 배치 수만
     */
    public function scan_duplicates( $batch_size = 100, $offset = 0 ) {
        @set_time_limit( 120 );
        global $wpdb;

        // ① 배치 내 이미지 ID 목록 (1 쿼리)
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'
             ORDER BY ID ASC LIMIT %d OFFSET %d",
            $batch_size, $offset
        ) );

        if ( empty( $ids ) ) {
            return [ 'duplicates' => [], 'total_scanned' => 0, 'has_more' => false ];
        }

        $id_list = implode( ',', array_map( 'intval', $ids ) );

        // ② 파일 경로 bulk 조회 — get_attached_file() 100회 대신 1 쿼리
        $upload   = wp_upload_dir();
        $base_dir = trailingslashit( $upload['basedir'] );
        $base_url = trailingslashit( $upload['baseurl'] );

        $file_rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE post_id IN ($id_list) AND meta_key = '_wp_attached_file'"
        );
        $file_map = [];
        $url_map  = [];
        foreach ( $file_rows as $m ) {
            $pid             = (int) $m->post_id;
            $file_map[ $pid ] = $base_dir . $m->meta_value;
            $url_map[ $pid ]  = $base_url . $m->meta_value;
        }

        // ③ MD5 해시 계산 (파일 읽기 — 피할 수 없음)
        $hash_map = [];
        foreach ( $ids as $id ) {
            $id   = (int) $id;
            $file = $file_map[ $id ] ?? null;
            if ( ! $file ) continue;
            $hash = @md5_file( $file );   // 파일 없으면 false 반환
            if ( ! $hash ) continue;
            $hash_map[ $hash ][] = $id;
        }

        // 중복 없으면 메타 조회 생략하고 바로 반환
        $dup_ids = [];
        foreach ( $hash_map as $dup ) {
            if ( count( $dup ) >= 2 ) {
                foreach ( $dup as $id ) $dup_ids[] = $id;
            }
        }

        if ( empty( $dup_ids ) ) {
            return [
                'duplicates'    => [],
                'total_scanned' => count( $ids ),
                'has_more'      => count( $ids ) === $batch_size,
            ];
        }

        $dup_list = implode( ',', $dup_ids );

        // ④ 중복 이미지 제목·날짜 bulk 조회 (1 쿼리)
        $post_rows = $wpdb->get_results(
            "SELECT ID, post_title, post_date FROM {$wpdb->posts} WHERE ID IN ($dup_list)"
        );
        $post_map = [];
        foreach ( $post_rows as $r ) {
            $post_map[ (int) $r->ID ] = $r;
        }

        // ⑤ 파일 크기 bulk 조회 (1 쿼리, WP 6.0+ 기준 filesize 키)
        $size_rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE post_id IN ($dup_list) AND meta_key = '_wp_attachment_metadata'"
        );
        $size_map = [];
        foreach ( $size_rows as $m ) {
            $data = maybe_unserialize( $m->meta_value );
            $size_map[ (int) $m->post_id ] = isset( $data['filesize'] ) ? (int) $data['filesize'] : 0;
        }

        // ⑥ 썸네일 사용 여부 (1 쿼리, 인스턴스 내 캐시)
        $thumb_used = $this->get_thumbnail_ids();

        // 결과 조합 — 이미 완전히 enriched된 형태
        $duplicates = [];
        foreach ( $hash_map as $hash => $dup ) {
            if ( count( $dup ) < 2 ) continue;
            $items = [];
            foreach ( $dup as $id ) {
                $post    = $post_map[ $id ] ?? null;
                $items[] = [
                    'id'        => $id,
                    'url'       => $url_map[ $id ] ?? '',
                    'file_size' => $size_map[ $id ] ?? 0,
                    'is_used'   => in_array( $id, $thumb_used, true ),
                    'title'     => $post ? $post->post_title : 'ID ' . $id,
                    'date'      => $post ? substr( $post->post_date, 0, 16 ) : '',
                ];
            }
            $duplicates[] = [
                'hash'  => $hash,
                'items' => $items,
                'count' => count( $items ),
            ];
        }

        return [
            'duplicates'    => $duplicates,
            'total_scanned' => count( $ids ),
            'has_more'      => count( $ids ) === $batch_size,
        ];
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
