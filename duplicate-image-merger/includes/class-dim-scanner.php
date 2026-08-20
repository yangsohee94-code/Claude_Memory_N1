<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DIM_Scanner {

    /**
     * 미디어 라이브러리 전체 스캔 후 중복 그룹 반환
     */
    public function scan_duplicates( $batch_size = 200, $offset = 0 ) {
        global $wpdb;

        $attachments = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, guid FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type LIKE 'image/%'
             ORDER BY ID ASC
             LIMIT %d OFFSET %d",
            $batch_size, $offset
        ) );

        $hash_map = [];

        foreach ( $attachments as $att ) {
            $file = get_attached_file( $att->ID );
            if ( ! $file || ! file_exists( $file ) ) {
                continue;
            }
            $hash = md5_file( $file );
            if ( ! $hash ) continue;

            $used = $this->is_image_in_use( $att->ID );

            $hash_map[ $hash ][] = [
                'id'        => $att->ID,
                'file'      => $file,
                'file_size' => filesize( $file ),
                'url'       => wp_get_attachment_url( $att->ID ),
                'is_used'   => $used,
                'title'     => get_the_title( $att->ID ),
                'date'      => get_the_date( 'Y-m-d H:i', $att->ID ),
            ];
        }

        // 중복(2개 이상)인 그룹만 반환
        $duplicates = [];
        foreach ( $hash_map as $hash => $items ) {
            if ( count( $items ) >= 2 ) {
                $duplicates[] = [
                    'hash'  => $hash,
                    'items' => $items,
                    'count' => count( $items ),
                ];
            }
        }

        return [
            'duplicates'  => $duplicates,
            'total_scanned' => count( $attachments ),
            'has_more'    => count( $attachments ) === $batch_size,
        ];
    }

    /**
     * 특정 첨부파일이 게시물/페이지/메타에서 사용 중인지 확인
     */
    public function is_image_in_use( $attachment_id ) {
        global $wpdb;

        // 특성 이미지(썸네일)로 사용 중인지
        $as_thumbnail = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
            $attachment_id
        ) );
        if ( $as_thumbnail > 0 ) return true;

        // 본문 콘텐츠에 URL이 포함되어 있는지
        $url = wp_get_attachment_url( $attachment_id );
        if ( ! $url ) return false;

        $in_content = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_status NOT IN ('trash','auto-draft')
               AND post_content LIKE %s",
            '%' . $wpdb->esc_like( $url ) . '%'
        ) );
        if ( $in_content > 0 ) return true;

        // 메타값(갤러리 등)에 ID가 포함되어 있는지
        $in_meta = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_value LIKE %s",
            '%' . $wpdb->esc_like( $attachment_id ) . '%'
        ) );
        if ( $in_meta > 0 ) return true;

        return false;
    }

    /**
     * 전체 미디어 수 반환
     */
    public function get_total_images() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
        );
    }
}
