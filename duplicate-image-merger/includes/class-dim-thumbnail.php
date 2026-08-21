<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DIM_Thumbnail {

    /**
     * 모든 포스트의 대표이미지가 실제 존재하는 첨부파일을 가리키는지 검사 후 수정
     * - 존재하지 않는 ID → 메타 삭제
     * - 중복 병합으로 삭제된 이미지 → 대체 이미지 자동 연결 (화질 최우선)
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

            // 같은 포스트에 연결된 다른 이미지로 대체 시도 (화질 최우선)
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
     * 대표이미지 없는 글에 화질 최우선 이미지를 자동 설정
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
     * 대표이미지 없는 모든 발행 글/페이지에 화질 최우선 이미지를 일괄 설정
     *
     * 속도 개선: Gutenberg 블록 ID·post_parent 첨부·파일경로·해상도를
     *            배치 JOIN 쿼리로 사전 로드 → 이미지당 N+1 쿼리 없음
     * 화질 기준: 가로×세로 픽셀 수 내림차순 (동점이면 파일 크기 내림차순)
     *
     * @return array { filled, skipped, total, truncated }
     */
    public function fill_all_thumbnails(): array {
        global $wpdb;

        $start_time  = microtime( true );
        $upload_base = trailingslashit( wp_upload_dir()['basedir'] );

        // ① 대표이미지 없는 발행 글 ID + 본문 일괄 로드
        $posts = $wpdb->get_results(
            "SELECT p.ID, p.post_content FROM {$wpdb->posts} p
             WHERE p.post_type IN ('post','page')
               AND p.post_status = 'publish'
               AND p.ID NOT IN (
                   SELECT post_id FROM {$wpdb->postmeta}
                   WHERE meta_key = '_thumbnail_id'
                   AND meta_value > 0
               )
             ORDER BY p.ID ASC"
        );

        if ( empty( $posts ) ) {
            return [ 'filled' => 0, 'skipped' => 0, 'total' => 0, 'truncated' => false ];
        }

        $total    = count( $posts );
        $post_ids = array_column( $posts, 'ID' );

        // ② Gutenberg wp:image 블록 이미지 ID 수집
        $post_att_ids = []; // post_id => [att_id, ...]
        $all_att_ids  = [];

        foreach ( $posts as $post ) {
            $ids = [];
            if ( preg_match_all( '/<!-- wp:image[^>]*?"id"\s*:\s*(\d+)/', $post->post_content, $m ) ) {
                foreach ( $m[1] as $raw ) {
                    $ids[] = (int) $raw;
                }
            }
            $post_att_ids[ $post->ID ] = array_values( array_unique( $ids ) );
            foreach ( $ids as $id ) $all_att_ids[ $id ] = true;
        }

        // ③ post_parent 첨부 이미지 배치 로드
        $pp_ph   = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $pa_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_parent, ID FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                   AND post_mime_type LIKE 'image/%%'
                   AND post_parent IN ({$pp_ph})
                 ORDER BY post_parent ASC, ID ASC",
                ...$post_ids
            )
        );
        foreach ( $pa_rows as $row ) {
            $pid = (int) $row->post_parent;
            $aid = (int) $row->ID;
            if ( ! in_array( $aid, $post_att_ids[ $pid ] ?? [], true ) ) {
                $post_att_ids[ $pid ][] = $aid;
            }
            $all_att_ids[ $aid ] = true;
        }

        if ( empty( $all_att_ids ) ) {
            return [ 'filled' => 0, 'skipped' => $total, 'total' => $total, 'truncated' => false ];
        }

        // ④ 첨부파일 경로 + 해상도 + 파일크기 배치 로드
        $att_ids = array_keys( $all_att_ids );
        $id_ph   = implode( ',', array_fill( 0, count( $att_ids ), '%d' ) );

        // 파일 경로
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $file_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id IN ({$id_ph}) AND meta_key = '_wp_attached_file'",
                ...$att_ids
            )
        );
        $att_file = [];
        foreach ( $file_rows as $r ) {
            $att_file[ (int) $r->post_id ] = $r->meta_value;
        }

        // 해상도 (픽셀 수)
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $meta_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id IN ({$id_ph}) AND meta_key = '_wp_attachment_metadata'",
                ...$att_ids
            )
        );
        $att_pixels = []; // att_id => width×height
        $att_fsize  = []; // att_id => file_size (보조 기준)
        foreach ( $meta_rows as $r ) {
            $meta = maybe_unserialize( $r->meta_value );
            $att_pixels[ (int) $r->post_id ] = (int) ( $meta['width'] ?? 0 ) * (int) ( $meta['height'] ?? 0 );
        }

        // 파일 존재 여부 + 크기 (픽셀 동점 시 보조 기준)
        // _wp_attached_file 이 절대경로로 잘못 저장된 경우도 방어적으로 처리
        $att_valid = [];
        foreach ( $att_ids as $aid ) {
            $rel = $att_file[ $aid ] ?? '';
            $abs = dim_resolve_upload_path( $rel );
            if ( $abs && file_exists( $abs ) ) {
                $att_valid[ $aid ] = true;
                $att_fsize[ $aid ] = (int) filesize( $abs );
            }
        }

        // ⑤ 각 글에 화질 최우선 이미지 설정
        $filled  = 0;
        $skipped = 0;

        foreach ( $posts as $post ) {
            if ( microtime( true ) - $start_time > 260 ) {
                return [ 'filled' => $filled, 'skipped' => $skipped, 'total' => $total, 'truncated' => true ];
            }

            $valid = array_filter(
                $post_att_ids[ $post->ID ] ?? [],
                fn( $id ) => ! empty( $att_valid[ $id ] )
            );

            if ( empty( $valid ) ) {
                $skipped++;
                continue;
            }

            // 픽셀 내림차순, 동점이면 파일 크기 내림차순
            usort( $valid, function ( $a, $b ) use ( $att_pixels, $att_fsize ) {
                $pa = $att_pixels[ $a ] ?? 0;
                $pb = $att_pixels[ $b ] ?? 0;
                if ( $pb !== $pa ) return $pb <=> $pa;
                return ( $att_fsize[ $b ] ?? 0 ) <=> ( $att_fsize[ $a ] ?? 0 );
            } );

            update_post_meta( $post->ID, '_thumbnail_id', (int) $valid[0] );
            $filled++;
        }

        return [ 'filled' => $filled, 'skipped' => $skipped, 'total' => $total, 'truncated' => false ];
    }

    /**
     * 포스트에서 화질 최우선(가로×세로 픽셀 수 기준) 유효한 이미지 ID 반환
     * 우선순위 수집: ① Gutenberg wp:image 블록 ID ② post_parent 첨부
     * 정렬 기준: 픽셀 수 내림차순 (동점이면 파일 크기 내림차순)
     */
    public function find_first_valid_image( int $post_id ): int {
        $seen       = [];
        $candidates = [];

        // ① Gutenberg 블록 wp:image 오프너 내 ID 수집 (이미지 MIME 확인 포함)
        $content = get_post_field( 'post_content', $post_id );
        if ( $content ) {
            preg_match_all( '/<!-- wp:image[^>]*?"id"\s*:\s*(\d+)/', $content, $m );
            foreach ( $m[1] as $raw_id ) {
                $id = (int) $raw_id;
                if ( isset( $seen[ $id ] ) ) continue;
                if ( strpos( (string) get_post_mime_type( $id ), 'image/' ) === 0
                     && $this->attachment_file_exists( $id ) ) {
                    $seen[ $id ]  = true;
                    $candidates[] = $id;
                }
            }
        }

        // ② post_parent 첨부 이미지 (중복 제외)
        $images = get_attached_media( 'image', $post_id );
        foreach ( $images as $att_id => $att ) {
            $att_id = (int) $att_id;
            if ( isset( $seen[ $att_id ] ) ) continue;
            if ( $this->attachment_file_exists( $att_id ) ) {
                $seen[ $att_id ] = true;
                $candidates[]    = $att_id;
            }
        }

        if ( empty( $candidates ) ) return 0;
        if ( count( $candidates ) === 1 ) return $candidates[0];

        // 픽셀 수 내림차순 정렬 (동점이면 파일 크기 내림차순)
        $upload_base = trailingslashit( wp_upload_dir()['basedir'] );
        usort( $candidates, function ( $a, $b ) use ( $upload_base ) {
            $ma = wp_get_attachment_metadata( $a );
            $mb = wp_get_attachment_metadata( $b );
            $pa = (int) ( $ma['width'] ?? 0 ) * (int) ( $ma['height'] ?? 0 );
            $pb = (int) ( $mb['width'] ?? 0 ) * (int) ( $mb['height'] ?? 0 );
            if ( $pb !== $pa ) return $pb <=> $pa;
            // 동점: 파일 크기 비교
            $fa = get_attached_file( $a );
            $fb = get_attached_file( $b );
            $sa = ( $fa && file_exists( $fa ) ) ? (int) filesize( $fa ) : 0;
            $sb = ( $fb && file_exists( $fb ) ) ? (int) filesize( $fb ) : 0;
            return $sb <=> $sa;
        } );

        return (int) $candidates[0];
    }

    /**
     * SEO 플러그인 SNS OG 이미지 메타 일괄 수정
     * - 파일이 존재하지 않는 OG 이미지 URL → WebP 버전이 있으면 교체, 없으면 메타 삭제
     * - 메타 삭제 시 Rank Math / Yoast 는 featured image 를 자동 사용
     *
     * @return array { fixed, cleared, total_checked }
     */
    public function fix_og_images(): array {
        global $wpdb;

        $upload_url  = trailingslashit( wp_upload_dir()['baseurl'] );
        $upload_base = trailingslashit( wp_upload_dir()['basedir'] );

        $og_keys = [
            'rank_math_facebook_image', 'rank_math_twitter_image',
            '_yoast_wpseo_opengraph-image', '_yoast_wpseo_twitter-image',
        ];
        $ph = implode( ',', array_fill( 0, count( $og_keys ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, post_id, meta_key, meta_value
                 FROM {$wpdb->postmeta}
                 WHERE meta_key IN ({$ph})
                   AND meta_value != ''",
                ...$og_keys
            )
        );

        $fixed   = 0;
        $cleared = 0;

        foreach ( $rows as $row ) {
            $url = $row->meta_value;

            // 업로드 디렉토리 외부 URL은 건드리지 않음
            if ( strpos( $url, $upload_url ) === false ) continue;

            $rel = ltrim( str_replace( $upload_url, '', $url ), '/' );
            $abs = $upload_base . $rel;

            if ( file_exists( $abs ) ) continue; // 정상

            // WebP 버전 확인
            $webp_rel = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $rel );
            $webp_abs = $upload_base . $webp_rel;

            if ( $webp_rel !== $rel && file_exists( $webp_abs ) ) {
                // WebP 파일로 URL 교체
                $wpdb->update(
                    $wpdb->postmeta,
                    [ 'meta_value' => $upload_url . $webp_rel ],
                    [ 'meta_id'   => (int) $row->meta_id ],
                    [ '%s' ], [ '%d' ]
                );
                wp_cache_delete( (int) $row->post_id, 'post_meta' );
                $fixed++;
            } else {
                // 파일 없음 → 메타 삭제 (SEO 플러그인이 featured image로 폴백)
                $wpdb->delete( $wpdb->postmeta, [ 'meta_id' => (int) $row->meta_id ], [ '%d' ] );
                wp_cache_delete( (int) $row->post_id, 'post_meta' );
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
     * 첨부파일이 DB에 존재하고 실제 파일도 디스크에 있는지 확인
     */
    private function attachment_file_exists( int $id ): bool {
        if ( $id <= 0 || get_post_type( $id ) !== 'attachment' ) return false;
        $file = get_attached_file( $id );
        return $file && file_exists( $file );
    }
}
