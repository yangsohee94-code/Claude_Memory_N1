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
     * 스캔 완료 후 중복 그룹에 한해서만 호출하므로 쿼리 수가 대폭 감소
     */
    public function enrich_groups( array $groups ) {
        if ( empty( $groups ) ) return [];

        // 필요한 ID만 수집
        $all_ids = [];
        foreach ( $groups as $g ) {
            foreach ( $g['ids'] as $id ) $all_ids[] = $id;
        }
        $all_ids = array_unique( $all_ids );

        // 썸네일로 사용 중인 ID 목록 (단 1쿼리)
        $thumb_used = $this->get_thumbnail_ids();

        // 본문에서 사용 중인 attachment ID (단 1쿼리로 URL 기반 검색)
        $content_used = $this->get_content_used_ids( $all_ids );

        $enriched = [];
        foreach ( $groups as $g ) {
            $items = [];
            foreach ( $g['ids'] as $id ) {
                $file    = get_attached_file( $id );
                $is_used = in_array( $id, $thumb_used, true )
                        || in_array( $id, $content_used, true );

                $items[] = [
                    'id'        => $id,
                    'file_size' => ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0,
                    'url'       => wp_get_attachment_url( $id ),
                    'is_used'   => $is_used,
                    'title'     => get_the_title( $id ),
                    'date'      => get_the_date( 'Y-m-d H:i', $id ),
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

    private function get_content_used_ids( array $ids ): array {
        global $wpdb;
        $used = [];
        foreach ( $ids as $id ) {
            $url = wp_get_attachment_url( $id );
            if ( ! $url ) continue;
            $found = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_status NOT IN ('trash','auto-draft')
                   AND post_content LIKE %s LIMIT 1",
                '%' . $wpdb->esc_like( basename( $url ) ) . '%'
            ) );
            if ( $found ) $used[] = (int) $id;
        }
        return $used;
    }

    public function get_total_images() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
        );
    }
}
