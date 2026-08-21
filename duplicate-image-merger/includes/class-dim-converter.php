<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DIM_Converter {

    /**
     * 실제로 1×1 WebP 파일을 생성해 변환 가능 여부를 검증
     */
    public function can_convert() {
        $tmp = wp_upload_dir()['basedir'] . '/dim_webp_probe_' . time() . '.webp';

        if ( extension_loaded( 'imagick' ) ) {
            try {
                $img = new Imagick();
                $img->newImage( 1, 1, new ImagickPixel( 'white' ) );
                $img->setImageFormat( 'webp' );
                $img->writeImage( $tmp );
                $img->destroy();
                if ( file_exists( $tmp ) ) { @unlink( $tmp ); return true; }
            } catch ( Exception $e ) {}
        }

        if ( function_exists( 'imagewebp' ) ) {
            $img = @imagecreatetruecolor( 1, 1 );
            if ( $img ) {
                $ok = @imagewebp( $img, $tmp, 82 );
                imagedestroy( $img );
                if ( $ok && file_exists( $tmp ) ) { @unlink( $tmp ); return true; }
            }
        }

        return false;
    }

    /**
     * 전체 이미지 WebP 변환 (배치 처리)
     *
     * 속도 개선: 파일경로·메타데이터를 배치 JOIN 쿼리 2개로 사전 로드
     * → 이미지당 쿼리 8회 → 2회로 감소
     * 부작용 제거: wp_update_post() → 직접 DB 업데이트
     * → save_post 훅 미발동 → Gutenberg 에디터 오염 방지
     */
    public function convert_all( $batch = 10, $offset = 0 ) {
        global $wpdb;

        $start_time = microtime( true );
        $upload     = wp_upload_dir();
        $base_dir   = trailingslashit( $upload['basedir'] );
        $base_url   = trailingslashit( $upload['baseurl'] );

        // ① 파일경로·MIME을 단일 JOIN으로 — get_attached_file() N+1 제거
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_mime_type, m.meta_value AS rel_path
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type IN ('image/jpeg','image/png','image/gif')
             ORDER BY p.ID ASC LIMIT %d OFFSET %d",
            $batch, $offset
        ) );

        if ( empty( $rows ) ) {
            return [ 'converted' => 0, 'skipped' => 0, 'errors' => [], 'unlink_failed' => 0, 'has_more' => false ];
        }

        // ② _wp_attachment_metadata 배치 로드 — 이미지당 1쿼리 제거
        $all_ids  = array_map( fn( $r ) => (int) $r->ID, $rows );
        $ph       = implode( ',', array_fill( 0, count( $all_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $meta_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id IN ({$ph}) AND meta_key = '_wp_attachment_metadata'",
                ...$all_ids
            )
        );
        $meta_map = [];
        foreach ( $meta_rows as $mr ) {
            $meta_map[ (int) $mr->post_id ] = maybe_unserialize( $mr->meta_value );
        }

        $result = [
            'converted'     => 0,
            'skipped'       => 0,
            'errors'        => [],
            'unlink_failed' => 0,
            'has_more'      => count( $rows ) === $batch,
        ];

        foreach ( $rows as $row ) {
            // 서버 타임아웃(60초) 전에 안전하게 중단 — 다음 배치에서 이어서 처리
            if ( microtime( true ) - $start_time > 60 ) {
                $result['has_more'] = true;
                break;
            }
            $id       = (int) $row->ID;
            $rel_path = $row->rel_path;
            $mime     = $row->post_mime_type;

            if ( ! $rel_path || $mime === 'image/webp' ) { $result['skipped']++; continue; }

            $file     = $base_dir . $rel_path;
            $webp_rel = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $rel_path );

            if ( $webp_rel === $rel_path ) { $result['skipped']++; continue; }
            if ( ! file_exists( $file ) ) {
                $result['errors'][] = "ID {$id}: 원본 파일 없음: " . basename( $file );
                continue;
            }

            $webp_file = $base_dir . $webp_rel;

            if ( ! file_exists( $webp_file ) ) {
                $ok = $this->do_convert( $file, $webp_file, $mime );
                if ( $ok !== true ) { $result['errors'][] = "ID {$id}: {$ok}"; continue; }
            }

            $old_url = $base_url . $rel_path;
            $new_url = $base_url . $webp_rel;

            // ③ 직접 DB 업데이트 — wp_update_post() 훅 미발동 (save_post 없음)
            // _wp_attached_file 은 상대경로를 저장해야 함 (WordPress 표준)
            $wpdb->update( $wpdb->posts,    [ 'post_mime_type' => 'image/webp' ], [ 'ID' => $id ], [ '%s' ], [ '%d' ] );
            $wpdb->update( $wpdb->postmeta, [ 'meta_value' => $webp_rel ],
                [ 'post_id' => $id, 'meta_key' => '_wp_attached_file' ], [ '%s' ], [ '%d', '%s' ] );

            // ④ 사전 로드된 메타에서 file 키만 교체 — wp_get_attachment_metadata() 쿼리 없음
            $meta = $meta_map[ $id ] ?? null;
            if ( is_array( $meta ) ) {
                $meta['file'] = $webp_rel;
                $wpdb->update( $wpdb->postmeta, [ 'meta_value' => maybe_serialize( $meta ) ],
                    [ 'post_id' => $id, 'meta_key' => '_wp_attachment_metadata' ], [ '%s' ], [ '%d', '%s' ] );
            }

            // 오브젝트 캐시 무효화 (직접 DB 업데이트 후 필수)
            wp_cache_delete( $id, 'posts' );
            wp_cache_delete( $id, 'post_meta' );

            // ⑤ 본문 이미지 URL 교체
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)
                 WHERE post_content LIKE %s",
                $old_url, $new_url, '%' . $wpdb->esc_like( basename( $file ) ) . '%'
            ) );

            // ⑥ SEO 플러그인 SNS OG 이미지 URL 동기화 (Rank Math / Yoast)
            $like = '%' . $wpdb->esc_like( basename( $file ) ) . '%';
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->postmeta}
                 SET meta_value = REPLACE(meta_value, %s, %s)
                 WHERE meta_key IN (
                     'rank_math_facebook_image','rank_math_twitter_image',
                     '_yoast_wpseo_opengraph-image','_yoast_wpseo_twitter-image'
                 )
                 AND meta_value LIKE %s",
                $old_url, $new_url, $like
            ) );

            // ⑦ 원본 삭제
            if ( file_exists( $file ) && ! @unlink( $file ) ) {
                $result['converted']++;
                $result['unlink_failed']++;
            } else {
                $result['converted']++;
            }
        }

        return $result;
    }

    /**
     * 단일 첨부파일 → WebP 변환 (선택 변환 시 호출)
     * @return true|'skip'|'unlink'|string(오류)
     */
    public function convert_to_webp( int $id ) {
        global $wpdb;

        $file = get_attached_file( $id );
        if ( ! $file ) return 'skip';
        if ( ! file_exists( $file ) ) return '원본 파일 없음: ' . basename( $file );

        $mime = get_post_mime_type( $id );
        if ( $mime === 'image/webp' ) return 'skip';

        $webp_file = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $file );
        if ( $webp_file === $file ) return 'skip';

        if ( ! file_exists( $webp_file ) ) {
            $ok = $this->do_convert( $file, $webp_file, $mime );
            if ( $ok !== true ) return $ok;
        }

        // 단일 변환은 wp_get_attachment_url() 사용 (CDN 필터 적용 보장)
        $old_url  = wp_get_attachment_url( $id );
        $new_url  = str_replace( basename( $file ), basename( $webp_file ), $old_url );
        $upload   = wp_upload_dir();
        $rel_path = ltrim( str_replace( trailingslashit( $upload['basedir'] ), '', $webp_file ), '/' );

        // wp_update_post() → 직접 DB 업데이트 (save_post 훅 미발동)
        // _wp_attached_file 은 상대경로를 저장해야 함 (WordPress 표준)
        $wpdb->update( $wpdb->posts,    [ 'post_mime_type' => 'image/webp' ], [ 'ID' => $id ], [ '%s' ], [ '%d' ] );
        $wpdb->update( $wpdb->postmeta, [ 'meta_value' => $rel_path ],
            [ 'post_id' => $id, 'meta_key' => '_wp_attached_file' ], [ '%s' ], [ '%d', '%s' ] );

        $meta = wp_get_attachment_metadata( $id );
        if ( $meta ) {
            $meta['file'] = $rel_path;
            $wpdb->update( $wpdb->postmeta, [ 'meta_value' => maybe_serialize( $meta ) ],
                [ 'post_id' => $id, 'meta_key' => '_wp_attachment_metadata' ], [ '%s' ], [ '%d', '%s' ] );
        }

        // 오브젝트 캐시 무효화
        wp_cache_delete( $id, 'posts' );
        wp_cache_delete( $id, 'post_meta' );

        // 본문 URL 교체
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)
             WHERE post_content LIKE %s",
            $old_url, $new_url, '%' . $wpdb->esc_like( basename( $file ) ) . '%'
        ) );

        // SEO 플러그인 SNS OG 이미지 URL 동기화 (Rank Math / Yoast)
        $like = '%' . $wpdb->esc_like( basename( $file ) ) . '%';
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->postmeta}
             SET meta_value = REPLACE(meta_value, %s, %s)
             WHERE meta_key IN (
                 'rank_math_facebook_image','rank_math_twitter_image',
                 '_yoast_wpseo_opengraph-image','_yoast_wpseo_twitter-image'
             )
             AND meta_value LIKE %s",
            $old_url, $new_url, $like
        ) );

        if ( file_exists( $file ) && ! @unlink( $file ) ) return 'unlink';

        return true;
    }

    private function do_convert( string $src, string $dest, string $mime ) {
        if ( extension_loaded( 'imagick' ) ) {
            try {
                $img = new Imagick( $src );
                $img->setImageFormat( 'webp' );
                $img->setImageCompressionQuality( 82 );
                $img->writeImage( $dest );
                $img->destroy();
                return true;
            } catch ( \Throwable $e ) {
                // Imagick 실패 → GD로 폴백
            }
        }

        if ( ! function_exists( 'imagewebp' ) ) return 'WebP 미지원 서버 (GD imagewebp 없음)';

        error_clear_last();
        if ( $mime === 'image/jpeg' ) {
            $img = @imagecreatefromjpeg( $src );
        } elseif ( $mime === 'image/png' ) {
            $img = @imagecreatefrompng( $src );
            if ( $img ) {
                if ( ! imageistruecolor( $img ) ) {
                    $tc = imagecreatetruecolor( imagesx( $img ), imagesy( $img ) );
                    imagealphablending( $tc, false );
                    imagesavealpha( $tc, true );
                    imagefill( $tc, 0, 0, imagecolorallocatealpha( $tc, 0, 0, 0, 127 ) );
                    imagecopy( $tc, $img, 0, 0, 0, 0, imagesx( $img ), imagesy( $img ) );
                    imagedestroy( $img );
                    $img = $tc;
                }
                imagealphablending( $img, false );
                imagesavealpha( $img, true );
            }
        } elseif ( $mime === 'image/gif' ) {
            $img = @imagecreatefromgif( $src );
        } else {
            return '지원하지 않는 형식: ' . $mime;
        }

        if ( ! $img ) {
            $err = error_get_last();
            return '이미지 로드 실패' . ( $err ? ' (' . $err['message'] . ')' : '' );
        }

        error_clear_last();
        $ok = @imagewebp( $img, $dest, 82 );
        imagedestroy( $img );

        if ( ! $ok ) {
            $err = error_get_last();
            return 'WebP 쓰기 실패' . ( $err ? ' (' . $err['message'] . ')' : '' );
        }
        return true;
    }
}
