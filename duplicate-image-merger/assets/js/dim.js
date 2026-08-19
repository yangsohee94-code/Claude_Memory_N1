/* global jQuery, DIM */
(function ($) {
    'use strict';

    var allGroups      = [];   // 스캔된 전체 그룹
    var totalScanned   = 0;

    // ───────── 유틸 ─────────
    function formatBytes(bytes) {
        if (!bytes) return '0 B';
        var k = 1024, sizes = ['B','KB','MB','GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    function showNotice(msg, type) {
        var $n = $('#dim-notice');
        $n.removeClass('dim-notice-error dim-notice-success')
          .addClass(type === 'error' ? 'dim-notice-error' : 'dim-notice-success')
          .html(msg).show();
        $('html,body').animate({ scrollTop: $n.offset().top - 40 }, 300);
    }

    function setProgress(pct, text) {
        $('#dim-progress').show();
        $('#dim-progress-bar').css('width', pct + '%');
        $('#dim-progress-text').text(text);
    }

    // ───────── 렌더링 ─────────
    function renderGroups(groups) {
        var $results = $('#dim-results').empty();
        var groupTmpl = $('#dim-group-tmpl').html();
        var itemTmpl  = $('#dim-item-tmpl').html();

        groups.forEach(function (group, idx) {
            var itemsHtml = '';
            group.items.forEach(function (item) {
                var usedBadge = item.is_used
                    ? '<span class="dim-badge-used">사용 중</span>'
                    : '';
                var h = itemTmpl
                    .replace(/\{\{id\}\}/g,         item.id)
                    .replace(/\{\{url\}\}/g,         item.url || '')
                    .replace(/\{\{title\}\}/g,       escHtml(item.title || ''))
                    .replace(/\{\{date\}\}/g,        item.date || '')
                    .replace(/\{\{size\}\}/g,        formatBytes(item.file_size))
                    .replace(/\{\{group_idx\}\}/g,   idx)
                    .replace(/\{\{used_class\}\}/g,  item.is_used ? 'dim-item-used' : '')
                    .replace(/\{\{used_badge\}\}/g,  usedBadge);
                itemsHtml += h;
            });

            var hashShort = group.hash ? group.hash.substring(0, 12) + '…' : '';
            var card = groupTmpl
                .replace(/\{\{hash\}\}/g,       group.hash)
                .replace(/\{\{idx\}\}/g,         idx)
                .replace(/\{\{count\}\}/g,       group.count)
                .replace(/\{\{hash_short\}\}/g,  hashShort)
                .replace(/\{\{items\}\}/g,       itemsHtml);

            $results.append(card);
        });

        // 전체 선택 영역 표시
        if (groups.length) {
            $('#dim-select-all-wrap').show();
        }

        updateSelectedCount();
    }

    function escHtml(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ───────── 선택 카운터 ─────────
    function updateSelectedCount() {
        var n = $('.dim-item-checkbox:checked').length;
        $('#dim-selected-count').text(n + '개 선택됨');
        $('#dim-merge-selected-btn').prop('disabled', n === 0);
        $('#dim-auto-merge-btn').prop('disabled', allGroups.length === 0);
    }

    // ───────── 스캔 ─────────
    function startScan() {
        allGroups    = [];
        totalScanned = 0;
        $('#dim-results').empty();
        $('#dim-select-all-wrap').hide();
        $('#dim-summary').hide();
        $('#dim-notice').hide();
        $('#dim-auto-merge-btn, #dim-merge-selected-btn').prop('disabled', true);

        setProgress(5, DIM.i18n.scanning);
        scanBatch(0, 200);
    }

    function scanBatch(offset, batch) {
        $.post(DIM.ajax_url, {
            action : 'dim_scan',
            nonce  : DIM.nonce,
            offset : offset,
            batch  : batch
        }, function (res) {
            if (!res.success) {
                showNotice('스캔 오류: ' + (res.data && res.data.message), 'error');
                return;
            }
            var data = res.data;
            totalScanned += data.total_scanned;

            data.duplicates.forEach(function (g) { allGroups.push(g); });

            if (data.has_more) {
                var nextOffset = offset + batch;
                var pct = Math.min(90, Math.round((nextOffset / (nextOffset + batch)) * 90));
                setProgress(pct, '스캔 중... ' + totalScanned + '개 처리됨');
                scanBatch(nextOffset, batch);
            } else {
                setProgress(100, '스캔 완료');
                finishScan();
            }
        }).fail(function () {
            showNotice('서버 오류가 발생했습니다.', 'error');
        });
    }

    function finishScan() {
        setTimeout(function () { $('#dim-progress').hide(); }, 600);

        var totalDup  = allGroups.reduce(function (s, g) { return s + g.count; }, 0);
        var savedSize = allGroups.reduce(function (s, g) {
            var sizes = g.items.map(function (i) { return i.file_size || 0; });
            var maxSz = Math.max.apply(null, sizes);
            return s + (g.items.reduce(function (ss, i) { return ss + (i.file_size || 0); }, 0) - maxSz);
        }, 0);

        $('#dim-total-scanned').text(totalScanned);
        $('#dim-group-count').text(allGroups.length);
        $('#dim-dup-count').text(totalDup);
        $('#dim-saved-size').text(formatBytes(savedSize));
        $('#dim-summary').show();

        if (allGroups.length === 0) {
            $('#dim-results').html('<p style="padding:16px;">중복 이미지가 없습니다. ✅</p>');
            return;
        }
        renderGroups(allGroups);
    }

    // ───────── 수동 병합 ─────────
    function mergeSelected() {
        var $checked = $('.dim-item-checkbox:checked');
        if (!$checked.length) {
            alert(DIM.i18n.no_selection);
            return;
        }
        if (!confirm(DIM.i18n.confirm_merge)) return;

        // 각 그룹별로 keep_id 와 delete_ids 수집
        var tasks = [];
        var groups = {};
        $checked.each(function () {
            var id  = $(this).val();
            var gIdx = $(this).data('group-idx');
            if (!groups[gIdx]) groups[gIdx] = [];
            groups[gIdx].push(id);
        });

        $.each(groups, function (gIdx, checkedIds) {
            var keepId = $('input[name="dim-keep-' + gIdx + '"]:checked').val();
            if (!keepId) {
                alert('그룹 ' + (parseInt(gIdx)+1) + ': ' + DIM.i18n.select_keep);
                return false; // break
            }
            var deleteIds = checkedIds.filter(function (id) { return id !== keepId; });
            if (deleteIds.length) {
                tasks.push({ keep_id: keepId, delete_ids: deleteIds });
            }
        });

        if (!tasks.length) return;

        setProgress(10, DIM.i18n.merging);
        $('#dim-merge-selected-btn').prop('disabled', true);

        var chain = $.when();
        var totalMerged = 0;
        tasks.forEach(function (task) {
            chain = chain.then(function () {
                return $.post(DIM.ajax_url, {
                    action     : 'dim_merge',
                    nonce      : DIM.nonce,
                    keep_id    : task.keep_id,
                    delete_ids : task.delete_ids
                }).then(function (res) {
                    if (res.success) totalMerged += res.data.merged;
                });
            });
        });

        chain.always(function () {
            setProgress(100, '병합 완료');
            setTimeout(function () { $('#dim-progress').hide(); }, 600);
            showNotice(totalMerged + '개 중복 이미지가 병합되었습니다. 페이지를 새로고침해 다시 스캔하세요.', 'success');
            startScan();
        });
    }

    // ───────── 자동 병합 ─────────
    function autoMergeAll() {
        if (!allGroups.length) return;
        if (!confirm(DIM.i18n.confirm_auto)) return;

        setProgress(10, DIM.i18n.auto_merging);
        $('#dim-auto-merge-btn').prop('disabled', true);

        $.post(DIM.ajax_url, {
            action : 'dim_auto_merge',
            nonce  : DIM.nonce,
            groups : allGroups
        }, function (res) {
            setProgress(100, '완료');
            setTimeout(function () { $('#dim-progress').hide(); }, 600);
            if (res.success) {
                var msg = res.data.merged + '개 이미지가 자동 병합되었습니다.';
                if (res.data.errors && res.data.errors.length) {
                    msg += '<br>오류: ' + res.data.errors.join(', ');
                }
                showNotice(msg, 'success');
                startScan();
            } else {
                showNotice('자동 병합 실패: ' + (res.data && res.data.message), 'error');
            }
        }).fail(function () {
            showNotice('서버 오류가 발생했습니다.', 'error');
        });
    }

    // ───────── 자동 병합 스케줄 ─────────
    function toggleSchedule(enable) {
        $.post(DIM.ajax_url, {
            action          : 'dim_schedule_auto',
            nonce           : DIM.nonce,
            schedule_action : enable ? 'enable' : 'disable'
        }, function (res) {
            if (res.success) {
                showNotice(res.data.message, 'success');
            }
        });
    }

    // ───────── 이벤트 바인딩 ─────────
    $(function () {

        // 스캔 버튼
        $('#dim-scan-btn').on('click', startScan);

        // 자동 병합
        $('#dim-auto-merge-btn').on('click', autoMergeAll);

        // 선택 병합
        $('#dim-merge-selected-btn').on('click', mergeSelected);

        // 전체 선택 / 해제
        $('#dim-select-all').on('change', function () {
            var checked = this.checked;
            $('.dim-item-checkbox').prop('checked', checked);
            updateSelectedCount();
        });

        // 그룹별 전체 선택
        $(document).on('change', '.dim-group-select-all', function () {
            var gIdx    = $(this).data('group-idx');
            var checked = this.checked;
            $('.dim-item-checkbox[data-group-idx="' + gIdx + '"]').prop('checked', checked);
            updateSelectedCount();
        });

        // 개별 체크박스
        $(document).on('change', '.dim-item-checkbox', updateSelectedCount);

        // 그룹 병합 버튼
        $(document).on('click', '.dim-merge-group-btn', function () {
            var gIdx    = $(this).data('group-idx');
            var keepId  = $('input[name="dim-keep-' + gIdx + '"]:checked').val();
            if (!keepId) { alert(DIM.i18n.select_keep); return; }
            if (!confirm(DIM.i18n.confirm_merge)) return;

            var deleteIds = [];
            $('.dim-item-checkbox[data-group-idx="' + gIdx + '"]').each(function () {
                var id = $(this).val();
                if (id !== keepId) deleteIds.push(id);
            });

            if (!deleteIds.length) { alert('삭제할 이미지가 없습니다.'); return; }

            setProgress(10, DIM.i18n.merging);
            $.post(DIM.ajax_url, {
                action     : 'dim_merge',
                nonce      : DIM.nonce,
                keep_id    : keepId,
                delete_ids : deleteIds
            }, function (res) {
                setProgress(100, '완료');
                setTimeout(function () { $('#dim-progress').hide(); }, 400);
                if (res.success) {
                    showNotice(res.data.merged + '개 병합 완료.', 'success');
                    startScan();
                } else {
                    showNotice('오류: ' + (res.data && res.data.message), 'error');
                }
            });
        });

        // 매일 자동 병합 예약
        $('#dim-auto-schedule').on('change', function () {
            toggleSchedule(this.checked);
        });
    });

}(jQuery));
