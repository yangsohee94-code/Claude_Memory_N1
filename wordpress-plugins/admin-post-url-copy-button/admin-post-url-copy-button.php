<?php
/**
 * Plugin Name: Admin Post URL Copy Button
 * Description: 관리자 글 목록에서 발행된 글에만 체크박스와 제목 사이에 URL 복사 버튼(컬럼)을 추가합니다.
 * Version: 1.1.0
 * Author: Sohee Yang
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 체크박스(cb) 다음에 'copy_url' 컬럼 삽입
 */
function apucb_add_column( $columns ) {
    $new = array();
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'cb' ) {
            $new['copy_url'] = '<span class="apucb-col-head" title="URL 복사"></span>';
        }
    }
    return $new;
}
add_filter( 'manage_posts_columns', 'apucb_add_column' );

/**
 * 컬럼 내용 출력: 발행됨 상태일 때만 복사 버튼 표시
 */
function apucb_render_column( $column, $post_id ) {
    if ( $column !== 'copy_url' ) return;

    $post = get_post( $post_id );
    if ( ! $post || $post->post_status !== 'publish' ) return;

    $url = esc_url( get_permalink( $post_id ) );
    printf(
        '<button type="button" class="apucb-btn" data-url="%s" title="URL 복사">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
            </svg>
        </button>',
        $url
    );
}
add_action( 'manage_posts_custom_column', 'apucb_render_column', 10, 2 );

/**
 * 컬럼 너비 고정 + 버튼/토스트 스타일 + 복사 스크립트
 */
function apucb_admin_assets( $hook ) {
    if ( $hook !== 'edit.php' ) return;
    ?>
    <style>
        .column-copy_url {
            width: 32px !important;
            padding: 8px 4px !important;
            text-align: center;
            vertical-align: middle;
        }
        .apucb-col-head {
            display: block;
            width: 14px;
            margin: 0 auto;
        }
        .apucb-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: #8c8f94;
            padding: 2px;
            line-height: 1;
            border-radius: 3px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: color 0.15s, background 0.15s;
        }
        .apucb-btn:hover {
            color: #2271b1;
            background: #f0f6fc;
        }
        .apucb-btn.copied {
            color: #00a32a;
        }
        .apucb-toast {
            position: fixed;
            bottom: 32px;
            left: 50%;
            transform: translateX(-50%) translateY(12px);
            background: #1d2327;
            color: #fff;
            padding: 9px 18px;
            border-radius: 6px;
            font-size: 13px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
            z-index: 99999;
        }
        .apucb-toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
    </style>
    <div id="apucb-toast" class="apucb-toast">URL이 복사되었습니다 ✓</div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var toast   = document.getElementById('apucb-toast');
        var timer;

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.apucb-btn');
            if (!btn) return;

            var url = btn.getAttribute('data-url');

            function onCopied() {
                btn.classList.add('copied');
                setTimeout(function () { btn.classList.remove('copied'); }, 1500);

                clearTimeout(timer);
                toast.classList.add('show');
                timer = setTimeout(function () { toast.classList.remove('show'); }, 2000);
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(onCopied).catch(fallback);
            } else {
                fallback();
            }

            function fallback() {
                var ta = document.createElement('textarea');
                ta.value = url;
                ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                try { document.execCommand('copy'); onCopied(); } catch(err) {}
                document.body.removeChild(ta);
            }
        });
    });
    </script>
    <?php
}
add_action( 'admin_footer', 'apucb_admin_assets' );
