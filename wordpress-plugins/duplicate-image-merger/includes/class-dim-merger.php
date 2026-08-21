<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DIM_Merger {

    private $scanner;

    public function __construct() {
        $this->scanner = new DIM_Scanner();
    }

    /**
     * 중복 그룹에서 keep_id를 원본으로, 나머지를 삭제하며 참조를 이전
     *
     * @param int   $keep_id    유지할 첨부파일 ID
     * @param array $delete_ids 삭제할 첨부파일 ID 배열
     * @return array { merged: int, errors: array }
     */
    public function merge( $keep_id, array $delete_ids ) {
        $keep_url  = wp_get_attachment_url( $keep_id );
        $merged    = 0;
        $errors    = [];

        foreach ( $delete_ids as $del_id ) {
            $del_id  = absint( $del_id );
            if ( ! $del_id || $del_id === $keep_id ) continue;

            $del_url = wp_get_attachment_url( $del_id );
            if ( ! $del_url ) {
                $errors[] = "ID {$del_id}: URL 조회 실패";
                continue;
            }

            // 1. 본문 URL 교체
            $this->replace_url_in_content( $del_url, $keep_url );

            // 2. 특성 이미지(썸네일) ID 교체
            $this->replace_thumbnail_id( $del_id, $keep_id );

            // 3. 포스트메타 ID 교체
            $this->replace_meta_id( $del_id, $keep_id );

            // 4. 첨부파일 삭제
            $result = wp_delete_attachment( $del_id, true );
            if ( $result === false ) {
                $errors[] = "ID {$del_id}: 삭제 실패";
            } else {
                $merged++;
            }
        }

        return [ 'merged' => $merged, 'errors' => $errors ];
    }

    /**
     * 사용 중인 이미지를 원본으로 정해서 자동 병합
     *
     * @param array $group { hash, items[] }
     * @return array
     */
    public function auto_merge_group( array $group ) {
        $items = $group['items'];

        // 사용 중인 이미지 중 가장 오래된(ID가 작은) 것을 원본으로
        usort( $items, fn( $a, $b ) => $a['id'] - $b['id'] );

        $keep = null;
        foreach ( $items as $item ) {
            if ( $item['is_used'] ) {
                $keep = $item;
                break;
            }
        }
        // 사용 중인 이미지가 없으면 가장 오래된 것 유지
        if ( ! $keep ) {
            $keep = $items[0];
        }

        $delete_ids = array_column(
            array_filter( $items, fn( $i ) => $i['id'] !== $keep['id'] ),
            'id'
        );

        return $this->merge( $keep['id'], $delete_ids );
    }

    // --- 내부 헬퍼 ---

    private function replace_url_in_content( $old_url, $new_url ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->posts}
             SET post_content = REPLACE(post_content, %s, %s)
             WHERE post_content LIKE %s",
            $old_url, $new_url,
            '%' . $wpdb->esc_like( $old_url ) . '%'
        ) );
    }

    private function replace_thumbnail_id( $old_id, $new_id ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->postmeta,
            [ 'meta_value' => $new_id ],
            [ 'meta_key' => '_thumbnail_id', 'meta_value' => $old_id ]
        );
    }

    private function replace_meta_id( $old_id, $new_id ) {
        global $wpdb;
        // 갤러리 숏코드 등 메타에 첨부 ID 직접 저장된 경우
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->postmeta}
             SET meta_value = REPLACE(meta_value, %s, %s)
             WHERE meta_value LIKE %s",
            (string) $old_id, (string) $new_id,
            '%' . $wpdb->esc_like( (string) $old_id ) . '%'
        ) );
    }
}
