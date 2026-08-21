<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DIM_Thumbnail {

    /**
     * 모든 포스트의 대표이미지가 실제 존재하는 첨부파일을 가리키는지 검사 후 수정
     * - 존재하지 않는 ID → 메타 삭제
     * - 중복 병합으로 삭제된 이미지 → 대체 이미지 자동 연결 (같은 포스트 첨부파일 중 첫 번째)
     *
     * @return array { fixed, cleared, total_checked }
     */
    public function fix_all() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value AS thumb_id
             FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id'"
        );

        $fixed   = 0;
        $cleared = 0;

        foreach ( $rows as $row ) {
            $post_id  = (int) $row->post_id;
            $thumb_id = (int) $row->thumb_id;

            // 첨부파일이 정상적으로 존재하는지 확인
            if ( $this->attachment_exists( $thumb_id ) ) continue;

            // 같은 포스트에 연결된 다른 이미지로 대체 시도
            $alt = $this->find_attached_image( $post_id );
            if ( $alt ) {
                update_post_meta( $post_id, '_thumbnail_id', $alt );
                $fixed++;
            } else {
                delete_post_meta( $post_id, '_thumbnail_id' );
                $cleared++;
            }
        }

        return [
            'fixed'         => $fixed,
            'cleared'       => $cleared,
            'total_checked' => count( $rows ),
        ];
    }

    /**
     * 개별 포스트의 대표이미지 상태 반환
     */
    public function check_post( int $post_id ) {
        $thumb_id = (int) get_post_thumbnail_id( $post_id );
        if ( ! $thumb_id ) return [ 'status' => 'none' ];

        if ( $this->attachment_exists( $thumb_id ) ) {
            return [
                'status'  => 'ok',
                'thumb_id' => $thumb_id,
                'url'      => wp_get_attachment_image_url( $thumb_id, 'thumbnail' ),
            ];
        }

        return [ 'status' => 'broken', 'thumb_id' => $thumb_id ];
    }

    private function attachment_exists( int $id ): bool {
        return $id > 0 && get_post_type( $id ) === 'attachment';
    }

    private function find_attached_image( int $post_id ): int {
        $images = get_attached_media( 'image', $post_id );
        if ( $images ) {
            return (int) array_key_first( $images );
        }
        return 0;
    }
}
