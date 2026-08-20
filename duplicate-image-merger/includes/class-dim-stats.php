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
