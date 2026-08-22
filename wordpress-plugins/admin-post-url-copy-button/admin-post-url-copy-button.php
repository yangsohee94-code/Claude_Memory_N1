<?php
/**
 * Plugin Name: Admin Post URL Copy Button
 * Description: 관리자 글 목록에서 발행된 글에만 체크박스 옆에 URL 복사 버튼 컬럼을 추가합니다.
 * Version: 1.2.0
 * Author: Sohee Yang
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── 1. 체크박스(cb) 바로 다음에 컬럼 삽입 ─────────────────────── */
function apucb_add_column( $columns ) {
    $new = array();
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'cb' ) {
            // 빈 헤더 – 컬럼 너비는 CSS로 고정
            $new['copy_url'] = '';
        }
    }
    return $new;
}
add_filter( 'manage_posts_columns', 'apucb_add_column' );

/* ── 2. 발행됨 글에만 버튼 출력 ──────────────────────────────────── */
function apucb_render_column( $column, $post_id ) {
    if ( $column !== 'copy_url' ) return;
    if ( get_post_status( $post_id ) !== 'publish' ) return;

    $url = esc_url( get_permalink( $post_id ) );
    echo '<button type="button" class="apucb-btn" data-url="' . $url . '" title="URL 복사">'
       . '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">'
       . '<rect x="9" y="9" width="13" height="13" rx="2"/>'
       . '<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>'
       . '</svg></button>';
}
add_action( 'manage_posts_custom_column', 'apucb_render_column', 10, 2 );

/* ── 3. 스타일은 admin_head 에서 출력 (너비 확실히 적용) ─────────── */
function apucb_head_style( $hook ) {
    if ( $hook !== 'edit.php' ) return;
    ?>
    <style>
        /* 체크박스 컬럼과 동일한 너비로 고정 */
        th.column-copy_url,
        td.column-copy_url {
            width: 2.2em !important;
            max-width: 2.2em !important;
            min-width: 2.2em !important;
            padding: 8px 0 !important;
            text-align: center !important;
        }

        .apucb-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: #8c8f94;
            padding: 2px 3px;
            border-radius: 3px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            vertical-align: middle;
            line-height: 1;
        }
        .apucb-btn:hover { color: #2271b1; }
        .apucb-btn.apucb-ok  { color: #00a32a; }

        #apucb-toast {
            position: fixed;
            bottom: 28px;
            left: 50%;
            transform: translateX(-50%) translateY(10px);
            background: #1d2327;
            color: #fff;
            padding: 8px 16px;
            border-radius: 5px;
            font-size: 13px;
            opacity: 0;
            pointer-events: none;
            transition: opacity .2s, transform .2s;
            z-index: 99999;
        }
        #apucb-toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
    </style>
    <?php
}
add_action( 'admin_head', 'apucb_head_style' );

/* ── 4. JS 는 admin_footer 에서 출력 ────────────────────────────── */
function apucb_footer_script( $hook ) {
    if ( $hook !== 'edit.php' ) return;
    ?>
    <div id="apucb-toast">URL 복사 완료!</div>
    <script>
    (function(){
        var toastEl = document.getElementById('apucb-toast');
        var toastTimer;

        function showToast() {
            clearTimeout(toastTimer);
            toastEl.classList.add('show');
            toastTimer = setTimeout(function(){ toastEl.classList.remove('show'); }, 2000);
        }

        function doCopy(btn) {
            var url = btn.getAttribute('data-url');

            function onSuccess() {
                btn.classList.add('apucb-ok');
                setTimeout(function(){ btn.classList.remove('apucb-ok'); }, 1500);
                showToast();
            }

            if (window.isSecureContext && navigator.clipboard) {
                navigator.clipboard.writeText(url).then(onSuccess, fallback);
            } else {
                fallback();
            }

            function fallback() {
                var ta = document.createElement('textarea');
                ta.value = url;
                ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0';
                document.body.appendChild(ta);
                ta.select();
                ta.setSelectionRange(0, 99999);
                var ok = false;
                try { ok = document.execCommand('copy'); } catch(e){}
                document.body.removeChild(ta);
                if (ok) onSuccess();
            }
        }

        // 직접 핸들러 부착 (이벤트 위임 없이)
        function attachHandlers() {
            document.querySelectorAll('.apucb-btn').forEach(function(btn){
                btn.addEventListener('click', function(e){
                    e.preventDefault();
                    doCopy(btn);
                });
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', attachHandlers);
        } else {
            attachHandlers();
        }
    })();
    </script>
    <?php
}
add_action( 'admin_footer', 'apucb_footer_script' );
