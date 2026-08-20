(function ($) {
    'use strict';

    var postId   = snsShare.postId;
    var nonce    = snsShare.nonce;
    var ajax     = snsShare.ajaxUrl;
    var currentPlatform = null;
    var autoContent     = '';

    var platformNames = {
        twitter:   '𝕏 X (Twitter)',
        threads:   '⊕ Threads',
        pinterest: '𝑷 Pinterest',
        facebook:  'f Facebook'
    };
    var platformLimits = {
        twitter: 280,
        threads: 500,
        pinterest: 500,
        facebook: 2000
    };

    // ── Step 1: 버튼 클릭 → 자동 문구 생성 후 편집 패널 열기 ──
    $(document).on('click', '.sns-btn', function () {
        var $btn = $(this);
        if ( $btn.hasClass('disabled') || $btn.is(':disabled') || $btn.hasClass('loading') ) return;

        currentPlatform = $btn.data('platform');
        $btn.addClass('loading');

        $.post(ajax, {
            action:   'sns_preview_content',
            nonce:    nonce,
            post_id:  postId,
            platform: currentPlatform
        }).done(function (res) {
            if ( res.success ) {
                autoContent = res.data.content;
                openEditPanel( currentPlatform, autoContent );
            } else {
                showMsg('error', '❌ ' + res.data);
            }
        }).fail(function () {
            showMsg('error', '❌ 서버 오류가 발생했습니다.');
        }).always(function () {
            $btn.removeClass('loading');
        });
    });

    function openEditPanel( platform, content ) {
        $('#sns-edit-platform-label').text( platformNames[platform] + ' 공유 문구' );
        $('#sns-edit-content').val( content );
        updateCharCount();
        $('#sns-edit-panel').slideDown(150);
        $('#sns-edit-content').focus();
    }

    // 문자수 카운터
    $('#sns-edit-content').on('input', updateCharCount);
    function updateCharCount() {
        var len   = $('#sns-edit-content').val().length;
        var limit = platformLimits[currentPlatform] || 2000;
        var $el   = $('#sns-char-count');
        $el.text( len + ' / ' + limit + '자' );
        $el.toggleClass('over-limit', len > limit);
    }

    // 자동생성 문구로 되돌리기
    $(document).on('click', '#sns-edit-reset', function () {
        $('#sns-edit-content').val(autoContent);
        updateCharCount();
    });

    // 편집 패널 닫기
    $(document).on('click', '#sns-edit-close', function () {
        closeEditPanel();
    });

    function closeEditPanel() {
        $('#sns-edit-panel').slideUp(150);
        currentPlatform = null;
    }

    // ── Step 2: 예약 확정 ──
    $(document).on('click', '#sns-edit-confirm', function () {
        var content  = $('#sns-edit-content').val().trim();
        var limit    = platformLimits[currentPlatform] || 2000;

        if ( !content ) {
            alert('공유 문구를 입력해주세요.');
            return;
        }
        if ( content.length > limit ) {
            if ( !confirm(content.length + '자로 ' + limit + '자 초과입니다. 그래도 예약하시겠습니까?') ) return;
        }

        var $btn = $(this).prop('disabled', true).text('예약 중...');

        $.post(ajax, {
            action:   'sns_schedule_share',
            nonce:    nonce,
            post_id:  postId,
            platform: currentPlatform,
            content:  content
        }).done(function (res) {
            if ( res.success ) {
                showMsg('success', '✅ ' + platformNames[currentPlatform] + ' — ' + res.data.message);
                closeEditPanel();
                loadQueue();
            } else {
                showMsg('error', '❌ ' + res.data);
            }
        }).fail(function () {
            showMsg('error', '❌ 서버 오류가 발생했습니다.');
        }).always(function () {
            $btn.prop('disabled', false).text('📅 이 문구로 예약하기');
        });
    });

    // 예약 취소
    $(document).on('click', '.sns-q-cancel', function () {
        var itemId = $(this).data('item-id');
        if ( !confirm('이 예약을 취소하시겠습니까?') ) return;
        $.post(ajax, {
            action:  'sns_cancel_queue_item',
            nonce:   nonce,
            item_id: itemId
        }).done(function (res) {
            if ( res.success ) loadQueue();
        });
    });

    // 큐 새로고침
    $(document).on('click', '#sns-refresh-queue', function (e) {
        e.preventDefault();
        loadQueue();
    });

    function showMsg( type, text ) {
        var $el = $('#sns-result-msg');
        $el.removeClass('success error').addClass(type).html(text).show();
        setTimeout(function () { $el.fadeOut(); }, 7000);
    }

    function loadQueue() {
        var $list = $('#sns-queue-list');
        $list.html('<em>로딩 중...</em>');

        $.post(ajax, {
            action:  'sns_get_queue',
            nonce:   nonce,
            post_id: postId
        }).done(function (res) {
            if ( !res.success || !res.data.length ) {
                $list.html('<em style="color:#999">예약된 공유가 없습니다.</em>');
                return;
            }
            var html = '';
            $.each(res.data, function (i, item) {
                var cancelBtn = item.status === 'pending'
                    ? '<button class="sns-q-cancel" data-item-id="' + item.id + '" title="예약 취소">✕</button>'
                    : '';
                html += '<div class="sns-queue-item">' +
                    '<span class="sns-q-platform">' + (platformNames[item.platform] || item.platform) + '</span>' +
                    '<span class="sns-q-time">' + item.scheduled_at + '</span>' +
                    '<span class="sns-q-status ' + item.status + '">' + statusLabel(item.status) + '</span>' +
                    cancelBtn + '</div>';
            });
            $list.html(html);
        }).fail(function () {
            $list.html('<em style="color:#c00">로드 실패</em>');
        });
    }

    function statusLabel(s) {
        return { pending: '⏳대기', sent: '✅완료', failed: '❌실패', cancelled: '🚫취소' }[s] || s;
    }

    if ( postId ) loadQueue();

})(jQuery);
