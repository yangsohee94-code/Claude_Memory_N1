<?php
/**
 * Plugin Name: Admin Post URL Copy Button
 * Description: 관리자 글 목록에서 발행된 글에만 URL 복사 버튼을 행 액션으로 추가합니다.
 * Version: 1.0.0
 * Author: Sohee Yang
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 발행된 글에만 행 액션에 'URL 복사' 링크 추가
 */
function apucb_add_row_action( $actions, $post ) {
    if ( $post->post_status !== 'publish' ) {
        return $actions;
    }

    $url = esc_url( get_permalink( $post->ID ) );

    $actions['copy_url'] = sprintf(
        '<a href="#" class="apucb-copy-btn" data-url="%s" title="%s">%s</a>',
        $url,
        esc_attr__( 'URL 복사', 'apucb' ),
        esc_html__( 'URL 복사', 'apucb' )
    );

    return $actions;
}
add_filter( 'post_row_actions', 'apucb_add_row_action', 10, 2 );

/**
 * 관리자 글 목록 페이지에서만 인라인 JS/CSS 로드
 */
function apucb_enqueue_admin_assets( $hook ) {
    if ( $hook !== 'edit.php' ) {
        return;
    }

    ?>
    <style>
        .apucb-copy-btn {
            color: #2271b1;
            cursor: pointer;
        }
        .apucb-copy-btn:hover {
            color: #135e96;
            text-decoration: underline;
        }
        .apucb-toast {
            position: fixed;
            bottom: 32px;
            left: 50%;
            transform: translateX(-50%) translateY(20px);
            background: #1d2327;
            color: #fff;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 13px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease, transform 0.25s ease;
            z-index: 99999;
        }
        .apucb-toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
    </style>
    <div id="apucb-toast" class="apucb-toast">URL이 복사되었습니다</div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var toast = document.getElementById('apucb-toast');
        var toastTimer;

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.apucb-copy-btn');
            if (!btn) return;

            e.preventDefault();
            var url = btn.getAttribute('data-url');

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(showToast);
            } else {
                // 구형 브라우저 폴백
                var ta = document.createElement('textarea');
                ta.value = url;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (err) {}
                document.body.removeChild(ta);
                showToast();
            }
        });

        function showToast() {
            clearTimeout(toastTimer);
            toast.classList.add('show');
            toastTimer = setTimeout(function () {
                toast.classList.remove('show');
            }, 2000);
        }
    });
    </script>
    <?php
}
add_action( 'admin_footer', 'apucb_enqueue_admin_assets' );
