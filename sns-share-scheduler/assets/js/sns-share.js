(function ($) {
    'use strict';

    var postId = snsShare.postId;
    var nonce  = snsShare.nonce;
    var ajax   = snsShare.ajaxUrl;

    var platformNames = {
        twitter:   '𝕏 X',
        threads:   '⊕ Threads',
        pinterest: '𝑷 Pinterest',
        facebook:  'f Facebook'
    };

    // Share button click
    $(document).on('click', '.sns-btn', function () {
        var $btn      = $(this);
        var platform  = $btn.data('platform');
        if ($btn.hasClass('disabled') || $btn.is(':disabled') || $btn.hasClass('loading')) return;

        if (!confirm(platformNames[platform] + '에 예약 공유하시겠습니까?\n\n4시간 간격으로 자동 게시됩니다.')) return;

        $btn.addClass('loading').text('⏳ 예약 중...');

        $.post(ajax, {
            action:   'sns_schedule_share',
            nonce:    nonce,
            post_id:  postId,
            platform: platform
        }).done(function (res) {
            if (res.success) {
                showMsg('success', '✅ ' + res.data.message);
                showPreview(res.data.preview);
                loadQueue();
            } else {
                showMsg('error', '❌ ' + res.data);
            }
        }).fail(function () {
            showMsg('error', '❌ 서버 오류가 발생했습니다.');
        }).always(function () {
            $btn.removeClass('loading');
            // restore original content
            var icon  = $btn.find('.sns-icon').length ? '' : '';
            $btn.html(
                '<span class="sns-icon">' + $btn.data('icon') + '</span>' +
                '<span class="sns-label">' + platformNames[platform] + '</span>'
            );
            restoreButtonLabels();
        });
    });

    function restoreButtonLabels() {
        $('.sns-btn').each(function () {
            var $b = $(this);
            var p  = $b.data('platform');
            if (!$b.find('.sns-icon').length) return;
        });
    }

    // Refresh queue button
    $(document).on('click', '#sns-refresh-queue', function (e) {
        e.preventDefault();
        loadQueue();
    });

    // Cancel queue item
    $(document).on('click', '.sns-q-cancel', function () {
        var itemId = $(this).data('item-id');
        if (!confirm('이 예약을 취소하시겠습니까?')) return;
        $.post(ajax, {
            action:  'sns_cancel_queue_item',
            nonce:   nonce,
            item_id: itemId
        }).done(function (res) {
            if (res.success) loadQueue();
        });
    });

    function showMsg(type, text) {
        var $el = $('#sns-result-msg');
        $el.removeClass('success error').addClass(type).html(text).show();
        setTimeout(function () { $el.fadeOut(); }, 6000);
    }

    function showPreview(content) {
        if (!content) return;
        $('#sns-preview-content').text(content);
        $('#sns-preview-box').show();
    }

    function loadQueue() {
        var $list = $('#sns-queue-list');
        $list.html('<em>로딩 중...</em>');

        $.post(ajax, {
            action:  'sns_get_queue',
            nonce:   nonce,
            post_id: postId
        }).done(function (res) {
            if (!res.success || !res.data.length) {
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
                    cancelBtn +
                    '</div>';
            });
            $list.html(html);
        }).fail(function () {
            $list.html('<em style="color:#c00">로드 실패</em>');
        });
    }

    function statusLabel(s) {
        var map = { pending: '⏳대기', sent: '✅완료', failed: '❌실패', cancelled: '🚫취소' };
        return map[s] || s;
    }

    // Load queue on page load
    if (postId) {
        loadQueue();
    }

})(jQuery);
