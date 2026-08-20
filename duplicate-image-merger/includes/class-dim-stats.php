<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DIM_Stats {

    /**
     * 이미지 용량 통계
     */
    public function get_storage_stats() {
        global $wpdb;

        $attachments = $wpdb->get_results(
            "SELECT ID, post_mime_type FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
        );

        $stats = [
            'total_count' => 0, 'total_size' => 0,
            'webp_count'  => 0, 'webp_size'  => 0,
            'other_count' => 0, 'other_size' => 0,
            'by_type'     => [],
        ];

        foreach ( $attachments as $att ) {
            $file = get_attached_file( $att->ID );
            if ( ! $file || ! file_exists( $file ) ) continue;
            $size = (int) filesize( $file );
            $type = str_replace( 'image/', '', $att->post_mime_type );

            $stats['total_count']++;
            $stats['total_size'] += $size;

            if ( $att->post_mime_type === 'image/webp' ) {
                $stats['webp_count']++;
                $stats['webp_size'] += $size;
            } else {
                $stats['other_count']++;
                $stats['other_size'] += $size;
            }

            if ( ! isset( $stats['by_type'][ $type ] ) ) {
                $stats['by_type'][ $type ] = [ 'count' => 0, 'size' => 0 ];
            }
            $stats['by_type'][ $type ]['count']++;
            $stats['by_type'][ $type ]['size'] += $size;
        }

        arsort( $stats['by_type'] );
        return $stats;
    }

    /**
     * 대표이미지 없는 발행된 글/페이지
     */
    public function get_posts_without_thumbnail( $limit = 50, $offset = 0 ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type, p.post_date
             FROM {$wpdb->posts} p
             WHERE p.post_type IN ('post','page')
               AND p.post_status = 'publish'
               AND p.ID NOT IN (
                   SELECT post_id FROM {$wpdb->postmeta}
                   WHERE meta_key = '_thumbnail_id'
               )
             ORDER BY p.post_date DESC
             LIMIT %d OFFSET %d",
            $limit, $offset
        ) );

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type IN ('post','page')
               AND post_status = 'publish'
               AND ID NOT IN (
                   SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'
               )"
        );

        return [
            'items'    => $rows,
            'total'    => $total,
            'has_more' => count( $rows ) === $limit,
        ];
    }

    /**
     * WebP가 아닌 이미지 목록
     */
    public function get_nonwebp_images( $limit = 50, $offset = 0 ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_title, post_mime_type, post_date
             FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type IN ('image/jpeg','image/png','image/gif')
             ORDER BY ID DESC
             LIMIT %d OFFSET %d",
            $limit, $offset
        ) );

        $items = [];
        foreach ( $rows as $row ) {
            $file = get_attached_file( $row->ID );
            $items[] = [
                'id'        => (int) $row->ID,
                'title'     => $row->post_title,
                'type'      => str_replace( 'image/', '', $row->post_mime_type ),
                'url'       => wp_get_attachment_image_url( $row->ID, 'thumbnail' ),
                'file_size' => ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0,
                'date'      => $row->post_date,
                'edit_url'  => get_edit_post_link( $row->ID, 'raw' ),
            ];
        }

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type IN ('image/jpeg','image/png','image/gif')"
        );

        return [ 'items' => $items, 'total' => $total, 'has_more' => count( $rows ) === $limit ];
    }

    /**
     * 미사용 이미지 목록
     * 사용 기준: ① _thumbnail_id ② post_parent(기존 글) ③ post_content URL ④ Gutenberg 블록 ID
     */
    public function get_unused_images( $limit = 50, $offset = 0 ) {
        global $wpdb;

        // ① 썸네일로 쓰이는 ID 목록
        $thumb_ids = $wpdb->get_col(
            "SELECT DISTINCT CAST(meta_value AS UNSIGNED)
             FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id' AND meta_value REGEXP '^[0-9]+$'"
        );
        $thumb_in = empty( $thumb_ids ) ? '0' : implode( ',', array_map( 'absint', $thumb_ids ) );

        // ② 후보: 썸네일 아님 + (post_parent=0 또는 부모 글이 삭제/휴지통)
        $candidates = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_mime_type, p.post_date
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type LIKE 'image/%%'
               AND p.ID NOT IN ({$thumb_in})
               AND (
                   p.post_parent = 0
                   OR NOT EXISTS (
                       SELECT 1 FROM {$wpdb->posts} pp
                       WHERE pp.ID = p.post_parent
                         AND pp.post_status NOT IN ('trash','auto-draft')
                   )
               )
             ORDER BY p.post_date DESC
             LIMIT %d OFFSET %d",
            $limit, $offset
        ) );

        if ( empty( $candidates ) ) {
            return [ 'items' => [], 'total' => 0, 'total_size' => 0, 'has_more' => false ];
        }

        // ③ 본문 파일명·Gutenberg ID 캐시 (5분)
        $content_data   = $this->build_content_index();
        $used_filenames = $content_data['filenames'];
        $used_ids       = $content_data['ids'];

        $items = [];
        foreach ( $candidates as $row ) {
            $id = (int) $row->ID;
            if ( isset( $used_ids[ $id ] ) ) continue;

            $file = get_attached_file( $id );
            if ( ! $file ) continue;
            if ( isset( $used_filenames[ basename( $file ) ] ) ) continue;

            $items[] = [
                'id'        => $id,
                'title'     => $row->post_title,
                'type'      => str_replace( 'image/', '', $row->post_mime_type ),
                'url'       => wp_get_attachment_image_url( $id, 'thumbnail' ) ?: wp_get_attachment_url( $id ),
                'file_size' => ( file_exists( $file ) ) ? (int) filesize( $file ) : 0,
                'date'      => $row->post_date,
                'edit_url'  => get_edit_post_link( $id, 'raw' ),
            ];
        }

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type LIKE 'image/%%'
               AND p.ID NOT IN ({$thumb_in})
               AND (
                   p.post_parent = 0
                   OR NOT EXISTS (
                       SELECT 1 FROM {$wpdb->posts} pp
                       WHERE pp.ID = p.post_parent
                         AND pp.post_status NOT IN ('trash','auto-draft')
                   )
               )"
        );

        $total_size = array_sum( array_column( $items, 'file_size' ) );

        return [
            'items'      => $items,
            'total'      => $total,
            'total_size' => $total_size,
            'has_more'   => ( $offset + $limit ) < $total,
        ];
    }

    /**
     * 본문에서 이미지 파일명·Gutenberg 블록 ID 인덱스 빌드 (5분 캐시)
     */
    private function build_content_index() {
        $cached = get_transient( 'dim_content_index' );
        if ( $cached !== false ) return $cached;

        global $wpdb;
        $rows = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft','private','inherit')
               AND (post_content LIKE '%/uploads/%' OR post_content LIKE '%\"id\":%')"
        );

        $filenames = [];
        $ids       = [];
        foreach ( $rows as $content ) {
            // URL 기반 (클래식 에디터 / src 속성)
            preg_match_all( '/\/uploads\/[^"\'>\s\)]+\.(jpe?g|png|gif|webp)/i', $content, $um );
            foreach ( $um[0] as $path ) $filenames[ basename( $path ) ] = true;

            // Gutenberg 블록 이미지 ID ("id":123)
            preg_match_all( '/"id"\s*:\s*(\d+)/', $content, $im );
            foreach ( $im[1] as $bid ) $ids[ (int) $bid ] = true;
        }

        $result = [ 'filenames' => $filenames, 'ids' => $ids ];
        set_transient( 'dim_content_index', $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    /**
     * 여러 첨부파일 삭제
     */
    public function delete_attachments( array $ids ) {
        $deleted = 0;
        $errors  = [];
        foreach ( $ids as $id ) {
            $id = absint( $id );
            if ( wp_delete_attachment( $id, true ) ) {
                $deleted++;
            } else {
                $errors[] = "ID {$id} 삭제 실패";
            }
        }
        return [ 'deleted' => $deleted, 'errors' => $errors ];
    }
}
