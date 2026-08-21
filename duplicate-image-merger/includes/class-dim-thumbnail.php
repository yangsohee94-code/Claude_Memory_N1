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
            if ( $this->attachment_file_exists( $thumb_id ) ) continue;

            // 같은 포스트에 연결된 다른 이미지로 대체 시도
            $alt = $this->find_first_valid_image( $post_id );
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

        if ( $this->attachment_file_exists( $thumb_id ) ) {
            return [
                'status'  => 'ok',
                'thumb_id' => $thumb_id,
                'url'      => wp_get_attachment_image_url( $thumb_id, 'thumbnail' ),
            ];
        }

        return [ 'status' => 'broken', 'thumb_id' => $thumb_id ];
    }

    /**
     * 대표이미지 없는 글에 첫 번째 유효 이미지를 자동 설정
     *
     * @param int $post_id
     * @return array { set: bool, attachment_id: int }
     */
    public function auto_set_from_content( int $post_id ) {
        $att_id = $this->find_first_valid_image( $post_id );
        if ( ! $att_id ) {
            return [ 'set' => false, 'attachment_id' => 0 ];
        }
        update_post_meta( $post_id, '_thumbnail_id', $att_id );
        return [ 'set' => true, 'attachment_id' => $att_id ];
    }

    /**
     * 포스트에서 첫 번째 유효한(파일이 실제 존재하는) 이미지 ID 반환
     * 우선순위: ① post_content Gutenberg 블록 "id":N ② post_parent 첨부파일
     */
    public function find_first_valid_image( int $post_id ): int {
        // ① Gutenberg 블록 "id":N 순서대로 검사 (image 블록만 대상)
        $content = get_post_field( 'post_content', $post_id );
        if ( $content ) {
            preg_match_all( '/<!-- wp:image[^>]*?"id"\s*:\s*(\d+)/', $content, $m );
            foreach ( $m[1] as $raw_id ) {
                $id = (int) $raw_id;
                // 이미지 MIME 타입인지 확인 (PDF·영상 등 비이미지 제외)
                if ( strpos( (string) get_post_mime_type( $id ), 'image/' ) === 0
                     && $this->attachment_file_exists( $id ) ) {
                    return $id;
                }
            }
        }

        // ② post_parent로 첨부된 이미지 순서대로 검사
        $images = get_attached_media( 'image', $post_id );
        foreach ( $images as $att_id => $att ) {
            if ( $this->attachment_file_exists( (int) $att_id ) ) {
                return (int) $att_id;
            }
        }

        return 0;
    }

    /**
     * 첨부파일이 DB에 존재하고 실제 파일도 디스크에 있는지 확인
     */
    private function attachment_file_exists( int $id ): bool {
        if ( $id <= 0 || get_post_type( $id ) !== 'attachment' ) return false;
        $file = get_attached_file( $id );
        return $file && file_exists( $file );
    }
}
