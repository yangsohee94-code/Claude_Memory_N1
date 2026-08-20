/* global jQuery, DIM */
(function ($) {
    'use strict';

    var groups       = [];
    var totalImages  = 0;
    var totalNonWebp = 0;
    var nwOffset     = 0;   // 비WebP 목록 페이지
    var ntOffset     = 0;   // 대표이미지없는글 페이지

    // ── 유틸 ──
    function fmt(bytes) {
        if (!bytes) return '0 B';
        var k = 1024, u = ['B','KB','MB','GB'], i = Math.floor(Math.log(bytes)/Math.log(k));
        return (bytes/Math.pow(k,i)).toFixed(1)+' '+u[i];
    }
    function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    function prog($wrap, done, total, label) {
        if (!$wrap || !$wrap.length) return;
        var pct  = total > 0 ? Math.min(99, Math.round(done/total*100)) : 50;
        var text = total > 0
            ? label+' · '+done.toLocaleString()+' / '+total.toLocaleString()+'개 ('+pct+'%)'
            : label+'...';
        $wrap.show().find('.dim-progress-bar').css('width', pct+'%');
        $wrap.find('.dim-progress-text').text(text);
    }
    function hideProg($wrap){ if ($wrap && $wrap.length) setTimeout(function(){ $wrap.hide(); }, 700); }

    function progDone($wrap, msg, ok) {
        if (!$wrap || !$wrap.length) return;
        var icon = ok ? '✅' : '⚠️';
        $wrap.show()
            .find('.dim-progress-bar').css({ width: '100%', background: ok ? '#00a32a' : '#d63638' });
        $wrap.find('.dim-progress-text').text(icon + ' ' + msg);
        setTimeout(function(){ $wrap.hide(); $wrap.find('.dim-progress-bar').css({ background: '', width: '0%' }); }, 3000);
    }

    function notice(msg, ok) {
        var $n = $('#dim-notice').removeClass('dim-ok dim-err')
            .addClass(ok ? 'dim-ok' : 'dim-err').html(msg).show();
        var off = $n.offset();
        if (off) $('html,body').animate({ scrollTop: off.top - 40 }, 200);
    }

    // ── 자동 병합 중단 재개 지원 (localStorage) ──
    var DIM_PENDING_KEY = 'dim_pending_merge_' + (DIM.ajax_url || '').replace(/\W/g, '');
    function savePendingGroups(arr) {
        try { localStorage.setItem(DIM_PENDING_KEY, JSON.stringify(arr)); } catch(e) {}
    }
    function clearPendingGroups() {
        try { localStorage.removeItem(DIM_PENDING_KEY); } catch(e) {}
    }
    function getPendingGroups() {
        try { var v = localStorage.getItem(DIM_PENDING_KEY); return v ? JSON.parse(v) : null; } catch(e) { return null; }
    }

    function post(action, data, cb) {
        $.ajax({
            url: DIM.ajax_url, type: 'POST', timeout: 90000,
            data: $.extend({ action:'dim_'+action, nonce:DIM.nonce }, data)
        })
        .done(function(r){ cb(r.success ? null : r.data, r.data); })
        .fail(function(xhr, status){ cb({ message: status === 'timeout' ? '요청 시간 초과 (90초)' : '서버 오류' }); });
    }

    // ── 탭 ──
    $(document).on('click', '.dim-tab', function(){
        $('.dim-tab').removeClass('active');
        $('.dim-tab-content').removeClass('active');
        $(this).addClass('active');
        $('#dim-tab-'+$(this).data('tab')).addClass('active');
    });

    // ── 유틸: 항상 유효한 $progDup 반환 ──
    function getProgDup() {
        if (!$progDup || !$progDup.length) $progDup = $('#dim-progress-dup');
        return $progDup;
    }

    // ── 자동 예약 ──
    function initSchedule() {
        var $tog = $('#dim-schedule-toggle').prop('checked', DIM.cron_on);
        $('#dim-schedule-label').text(DIM.cron_on ? '자동 최적화 예약 중 (매일 새벽 3시)' : '자동 최적화 꺼짐');
        if (DIM.last_run) $('#dim-last-run').text('마지막 실행: '+DIM.last_run);
        $tog.on('change', function(){
            post('schedule', { enable: this.checked }, function(err, d){ if (!err) notice(d.message, true); });
            $('#dim-schedule-label').text(this.checked ? '자동 최적화 예약 중 (매일 새벽 3시)' : '자동 최적화 꺼짐');
        });
    }

    // ═══════════════════════════════════
    // ① 중복 이미지
    // ═══════════════════════════════════
    var $progDup = null;

    function startScan() {
        $progDup = $('#dim-progress-dup');
        groups   = [];
        $('#dim-results').empty();
        $('#dim-select-all-wrap, #dim-summary').hide();
        $('#dim-notice').hide();
        $('#dim-auto-merge-btn, #dim-merge-selected-btn').prop('disabled', true);

        prog($progDup, 0, 0, '이미지 수 확인 중');
        post('get_counts', {}, function(err, data){
            totalImages  = err ? 0 : (data.total_images  || 0);
            totalNonWebp = err ? 0 : (data.total_nonwebp || 0);
            scanBatch(0, 0);
        });
    }

    // 스캔 결과가 이미 enriched 형태로 옴 — 별도 enrich 단계 없음
    function scanBatch(offset, scanned) {
        var total = totalImages || Math.max(offset + 100, scanned + 100);
        prog($progDup, scanned, total, '스캔 중');
        post('scan', { offset: offset, batch: 100 }, function(err, data){
            if (err) return notice('스캔 실패: '+(err.message||''), false);
            // duplicates는 이미 {hash, items[], count} 형태
            data.duplicates.forEach(function(g){ groups.push(g); });
            var done = scanned + data.total_scanned;
            prog($progDup, done, totalImages || done + (data.has_more ? 100 : 0), '스캔 중');
            if (data.has_more) { scanBatch(offset + 100, done); return; }
            renderDuplicates(done, groups);
        });
    }

    function renderDuplicates(scanned, enriched) {
        groups = enriched;
        hideProg($progDup);
        var saved = groups.reduce(function(s,g){
            var sizes = g.items.map(function(i){ return i.file_size||0; });
            return s + sizes.reduce(function(a,b){return a+b;},0) - Math.max.apply(null,sizes);
        },0);
        $('#dim-total-scanned').text(scanned);
        $('#dim-group-count').text(groups.length);
        $('#dim-saved-size').text(fmt(saved));
        $('#dim-summary').show();

        if (!groups.length) {
            $('#dim-results').html('<p style="padding:16px">중복 이미지가 없습니다 ✅</p>');
            return;
        }

        var $res = $('#dim-results').empty();
        var gt = $('#dim-group-tmpl').html(), it = $('#dim-item-tmpl').html();

        groups.forEach(function(g, idx){
            var items = g.items.map(function(item){
                return it
                    .replace(/\{\{id\}\}/g,        item.id)
                    .replace(/\{\{url\}\}/g,        item.url||'')
                    .replace(/\{\{title\}\}/g,      esc(item.title))
                    .replace(/\{\{date\}\}/g,        item.date||'')
                    .replace(/\{\{size\}\}/g,        fmt(item.file_size))
                    .replace(/\{\{group_idx\}\}/g,   idx)
                    .replace(/\{\{used_class\}\}/g,  item.is_used ? 'dim-used' : '')
                    .replace(/\{\{used_badge\}\}/g,  item.is_used ? '<span class="dim-badge-used">사용 중</span>' : '');
            }).join('');

            $res.append(gt
                .replace(/\{\{idx\}\}/g,        idx)
                .replace(/\{\{count\}\}/g,       g.count)
                .replace(/\{\{hash_short\}\}/g,  (g.hash||'').slice(0,10)+'…')
                .replace(/\{\{items\}\}/g,       items)
            );
        });
        $('#dim-select-all-wrap').show();
        $('#dim-auto-merge-btn, #dim-merge-selected-btn').prop('disabled', false);
        updateDupCount();
    }

    function updateDupCount() {
        var n = $('.dim-item-checkbox:checked').length;
        $('#dim-selected-count').text(n+'개 선택됨');
        $('#dim-merge-selected-btn').prop('disabled', n===0);
    }

    function mergeSelected() {
        var tasks = {};
        $('.dim-item-checkbox:checked').each(function(){
            var g = $(this).data('group-idx');
            (tasks[g]=tasks[g]||[]).push($(this).val());
        });
        if (!Object.keys(tasks).length) return notice('선택된 항목이 없습니다.', false);

        var calls = [], merged = 0, mergeErrors = 0;
        $.each(tasks, function(g, ids){
            var keep = $('input[name="dim-keep-'+g+'"]:checked').val();
            if (!keep) { alert((parseInt(g)+1)+'번 그룹: 원본 유지 이미지를 선택하세요.'); return false; }
            var del = ids.filter(function(i){ return i!==keep; });
            if (del.length) calls.push({ keep_id:keep, delete_ids:del });
        });

        var total = calls.length, done = 0;
        prog(getProgDup(), done, total, '병합 중');
        var chain = $.when();
        calls.forEach(function(c){
            chain = chain.then(function(){
                var dfd = $.Deferred();
                $.ajax({
                    url: DIM.ajax_url, type: 'POST', timeout: 90000,
                    data: $.extend({ action:'dim_merge', nonce:DIM.nonce }, c)
                })
                .done(function(r){ if(r.success) merged += r.data.merged || 0; else mergeErrors++; })
                .fail(function(){ mergeErrors++; })
                .always(function(){ prog(getProgDup(), ++done, total, '병합 중'); dfd.resolve(); });
                return dfd.promise();
            });
        });
        chain.always(function(){
            var ok  = mergeErrors === 0;
            var msg = merged + '개 병합 완료';
            if (mergeErrors) msg += ' · 실패 ' + mergeErrors + '개';
            progDone(getProgDup(), msg, ok);
            notice(msg, ok);
            startScan();
        });
    }

    function autoMerge(pendingOverride) {
        var target = pendingOverride || groups;
        if (!target.length) return notice('중복 이미지가 없습니다. 먼저 스캔하세요.', false);

        var BATCH = 50;
        var batches = [];
        for (var i = 0; i < target.length; i += BATCH) batches.push(target.slice(i, i + BATCH));

        // 시작 전 전체 그룹 저장 — 중단 시 재개용
        savePendingGroups(target);

        var totalMerged = 0, totalErrors = [];
        var groupsDone = 0;
        prog(getProgDup(), groupsDone, target.length, '자동 병합 중');

        var chain = $.when();
        batches.forEach(function(batch, batchIdx) {
            chain = chain.then(function() {
                // 실패해도 항상 resolve — 실패 시 chain이 중단되어 always()가 즉시 호출되는 문제 방지
                var dfd = $.Deferred();
                $.ajax({
                    url: DIM.ajax_url, type: 'POST', timeout: 90000,
                    data: { action: 'dim_auto_merge', nonce: DIM.nonce, groups: batch }
                })
                .done(function(r) {
                    if (r.success) {
                        totalMerged += r.data.merged || 0;
                        totalErrors  = totalErrors.concat(r.data.errors || []);
                    }
                    groupsDone += batch.length;
                    savePendingGroups(target.slice((batchIdx + 1) * BATCH));
                    prog(getProgDup(), groupsDone, target.length, '자동 병합 중');
                    dfd.resolve();
                })
                .fail(function() {
                    groupsDone += batch.length;
                    prog(getProgDup(), groupsDone, target.length, '자동 병합 중');
                    dfd.resolve();  // 실패도 resolve로 처리해 다음 배치 계속 진행
                });
                return dfd.promise();
            });
        });
        chain.always(function() {
            clearPendingGroups();
            var ok  = totalErrors.length === 0;
            var msg = totalMerged + '개 병합 완료';
            if (totalErrors.length) msg += ' · 실패 ' + totalErrors.length + '개';
            progDone(getProgDup(), msg, ok);
            notice(msg, ok);
            startScan();
        });
    }

    function fixThumbnails() {
        prog(getProgDup(), 0, 0, '대표이미지 확인 중');
        post('fix_thumbnails', {}, function(err, d){
            if (err) {
                progDone(getProgDup(), err.message, false);
                notice(err.message, false);
            } else {
                var msg = '대표이미지 수정 완료 — 총 '+d.total_checked+'개 확인 · 연결: '+d.fixed+'개 · 삭제: '+d.cleared+'개';
                progDone(getProgDup(), msg, true);
                notice(msg, true);
            }
        });
    }

    function runAll() {

        var BATCH = 50;
        var batches = [];
        for (var i = 0; i < groups.length; i += BATCH) batches.push(groups.slice(i, i + BATCH));

        var done = 0, total = batches.length || 1;
        prog(getProgDup(), done, total, '자동 병합 중');

        var chain = $.when();
        batches.forEach(function(batch) {
            chain = chain.then(function() {
                var dfd = $.Deferred();
                $.ajax({
                    url: DIM.ajax_url, type: 'POST', timeout: 90000,
                    data: { action:'dim_auto_merge', nonce:DIM.nonce, groups:batch }
                })
                .always(function(){ prog(getProgDup(), ++done, total, '자동 병합 중'); dfd.resolve(); });
                return dfd.promise();
            });
        });
        chain.always(function() {
            if (DIM.can_webp) {
                prog(getProgDup(), 0, 0, 'WebP 변환 중');
                webpAllSync(function() {
                    post('fix_thumbnails', {}, function(){ hideProg(getProgDup()); notice('전체 최적화 완료!', true); startScan(); });
                });
            } else {
                post('fix_thumbnails', {}, function(){ hideProg(getProgDup()); notice('최적화 완료!', true); startScan(); });
            }
        });
    }

    // offset=0 고정: 변환 후 mime_type이 webp로 바뀌므로 다음 쿼리에서 자동 제외됨
    function webpAllSync(done) {
        post('convert_webp', { offset: 0, batch: 50 }, function(err, d) {
            if (err || !d) { done(); return; }
            var batchDone = (d.converted || 0) + (d.skipped || 0) + (d.errors || []).length;
            if (d.has_more && batchDone > 0) { webpAllSync(done); return; }
            done();
        });
    }

    // ═══════════════════════════════════
    // ② WebP 변환 탭
    // ═══════════════════════════════════
    var $progWebp = null;

    function loadNonWebp(append) {
        $progWebp = $('#dim-progress-webp');
        if (!append) { nwOffset = 0; $('#dim-nonwebp-list').empty(); }
        prog($progWebp, 0, 0, '불러오는 중');
        post('get_nonwebp', { limit:50, offset:nwOffset }, function(err, d){
            hideProg($progWebp);
            if (err) return notice(err.message, false);

            if (d.total) totalNonWebp = d.total;  // webpBatch 진행률에 사용
            var tmpl = $('#dim-nonwebp-item-tmpl').html();
            d.items.forEach(function(item){
                $('#dim-nonwebp-list').append(tmpl
                    .replace(/\{\{id\}\}/g,       item.id)
                    .replace(/\{\{url\}\}/g,       item.url||'')
                    .replace(/\{\{title\}\}/g,     esc(item.title))
                    .replace(/\{\{type\}\}/g,      item.type.toUpperCase())
                    .replace(/\{\{size\}\}/g,      fmt(item.file_size))
                    .replace(/\{\{date\}\}/g,      (item.date||'').slice(0,10))
                    .replace(/\{\{edit_url\}\}/g,  item.edit_url||'#')
                );
            });

            nwOffset += d.items.length;
            $('#dim-nonwebp-select-wrap').toggle(nwOffset > 0);
            $('#dim-nonwebp-more-wrap').toggle(d.has_more);
            updateNwCount();
        });
    }

    function updateNwCount() {
        var n = $('.dim-nw-checkbox:checked').length;
        $('#dim-nonwebp-selected-count').text(n+'개 선택됨');
        $('#dim-convert-selected-btn, #dim-delete-nonwebp-btn').prop('disabled', n===0);
    }

    function convertSelected() {
        var ids = $('.dim-nw-checkbox:checked').map(function(){ return this.value; }).get();
        if (!ids.length) return;

        var totals = { converted: 0, skipped: 0, errors: 0, unlink_failed: 0 };
        var done = 0;
        prog($progWebp, done, ids.length, 'WebP 변환 중');
        var chain = $.when();
        ids.forEach(function(id) {
            chain = chain.then(function() {
                var dfd = $.Deferred();
                $.ajax({
                    url: DIM.ajax_url, type: 'POST', timeout: 90000,
                    data: { action:'dim_convert_webp', nonce:DIM.nonce, single_id: id }
                })
                .done(function(r) {
                    if (r.success) {
                        totals.converted     += r.data.converted     || 0;
                        totals.skipped       += r.data.skipped       || 0;
                        totals.errors        += (r.data.errors       || []).length;
                        totals.unlink_failed += r.data.unlink_failed || 0;
                    } else {
                        totals.errors++;
                    }
                })
                .fail(function() { totals.errors++; })
                .always(function() { prog($progWebp, ++done, ids.length, 'WebP 변환 중'); dfd.resolve(); });
                return dfd.promise();
            });
        });
        chain.always(function() {
            var ok  = totals.errors === 0;
            var msg = '변환 완료: 성공 ' + totals.converted + '개 · 건너뜀 ' + totals.skipped + '개';
            if (totals.unlink_failed) msg += ' · 원본삭제 실패 ' + totals.unlink_failed + '개';
            if (totals.errors) msg += ' · 오류 ' + totals.errors + '개';
            progDone($progWebp, msg, ok);
            notice(msg, ok);
            loadNonWebp(false);
        });
    }

    function convertAll() {
        if (!DIM.can_webp) return notice('이 서버는 WebP 변환을 지원하지 않습니다. GD/Imagick 미설치 또는 업로드 디렉토리 쓰기 권한이 없을 수 있습니다.', false);
        $progWebp = $progWebp || $('#dim-progress-webp');
        var totals = { converted: 0, skipped: 0, errors: 0, unlink_failed: 0, firstError: '' };
        webpBatch(totals);
    }

    // offset=0 고정: 변환 후 mime_type이 webp로 바뀌어 다음 쿼리에서 자동 제외됨
    // batchDone===0이면 진행 불가(전체 오류)로 판단해 중단 — 무한루프 방지
    function webpBatch(totals) {
        var processed = totals.converted + totals.skipped + totals.errors;
        var total = totalNonWebp ? (totalNonWebp - totals.skipped) : (processed + 50);
        prog($progWebp, totals.converted, total,
            'WebP 변환 중 · 성공 ' + totals.converted + ' / 건너뜀 ' + totals.skipped);
        post('convert_webp', { offset: 0, batch: 50 }, function(err, d) {
            if (err) {
                hideProg($progWebp);
                return notice('변환 오류: ' + (err.message || ''), false);
            }
            var batchConverted = d.converted || 0;
            var batchSkipped   = d.skipped   || 0;
            var batchErrMsgs   = d.errors    || [];
            totals.converted     += batchConverted;
            totals.skipped       += batchSkipped;
            totals.errors        += batchErrMsgs.length;
            totals.unlink_failed += d.unlink_failed || 0;
            if (!totals.firstError && batchErrMsgs.length) totals.firstError = batchErrMsgs[0];
            // 실제로 변환된 이미지가 있을 때만 다음 배치 진행
            // skip(파일 없음)이나 오류만 있으면 무한루프 방지 — converted만 진행 기준으로 사용
            var madeProgress = batchConverted > 0;
            if (d.has_more && madeProgress) { webpBatch(totals); return; }
            var ok  = totals.errors === 0 && totals.converted > 0;
            var msg;
            if (totals.converted === 0 && totals.errors > 0) {
                msg = 'WebP 변환 실패 (오류 ' + totals.errors + '개)';
                if (totals.firstError) msg += ' — ' + totals.firstError;
            } else {
                msg = 'WebP 변환 완료: 성공 ' + totals.converted + '개 · 건너뜀 ' + totals.skipped + '개';
                if (totals.unlink_failed) msg += ' · 원본삭제 실패 ' + totals.unlink_failed + '개';
                if (totals.errors)        msg += ' · 오류 ' + totals.errors + '개';
            }
            progDone($progWebp, msg, ok);
            notice(msg, ok);
            loadNonWebp(false);
        });
    }

    function deleteAllNonWebp() {
        if (!confirm('WebP가 아닌 이미지(JPEG/PNG/GIF)를 모두 삭제합니다. 복구 불가합니다.\n계속하시겠습니까?')) return;
        $progWebp = $progWebp || $('#dim-progress-webp');
        var totalDeleted = 0, totalErrors = 0;

        function step() {
            prog($progWebp, totalDeleted, 0, '전체 삭제 중');
            // offset=0 고정: 삭제 후 다음 쿼리에서 자동 제외됨
            post('get_nonwebp', { limit: 100, offset: 0 }, function(err, d) {
                if (err || !d.items || !d.items.length) {
                    var msg = totalDeleted + '개 삭제 완료' + (totalErrors ? ' · 실패 ' + totalErrors + '개' : '');
                    progDone($progWebp, msg, totalErrors === 0);
                    notice(msg, totalErrors === 0);
                    loadNonWebp(false);
                    return;
                }
                var ids = d.items.map(function(item) { return item.id; });
                post('delete_images', { ids: ids }, function(err2, d2) {
                    if (!err2) {
                        totalDeleted += d2.deleted || 0;
                        totalErrors  += (d2.errors || []).length;
                    }
                    step();
                });
            });
        }
        step();
    }

    function deleteSelected() {
        var ids = $('.dim-nw-checkbox:checked').map(function(){ return this.value; }).get();
        if (!ids.length) return;
        if (!confirm('선택한 '+ids.length+'개 이미지를 완전히 삭제합니다. 복구 불가합니다.')) return;
        if (!$progWebp || !$progWebp.length) $progWebp = $('#dim-progress-webp');
        prog($progWebp, 0, 0, '삭제 중');
        post('delete_images', { ids:ids }, function(err, d){
            if (err) {
                progDone($progWebp, err.message, false);
                notice(err.message, false);
            } else {
                var msg = d.deleted + '개 삭제 완료';
                if (d.errors && d.errors.length) msg += ' · 실패 ' + d.errors.length + '개';
                progDone($progWebp, msg, !d.errors || !d.errors.length);
                notice(msg, !d.errors || !d.errors.length);
            }
            loadNonWebp(false);
        });
    }

    // ═══════════════════════════════════
    // ③ 대표이미지 없는 글
    // ═══════════════════════════════════
    var $progNt  = null;
    var ntFound  = 0;

    function loadNoThumb(append) {
        $progNt = $('#dim-progress-nothumb');
        if (!append) { ntOffset = 0; ntFound = 0; $('#dim-nothumb-list').empty(); }
        prog($progNt, 0, 0, '불러오는 중');
        post('get_no_thumb_posts', { limit:50, offset:ntOffset }, function(err, d){
            hideProg($progNt);
            if (err) return notice(err.message, false);

            $('#dim-nothumb-count').text(d.total);
            $('#dim-nothumb-summary').show();

            var tmpl     = $('#dim-nothumb-item-tmpl').html();
            var adminUrl = DIM.admin_url;
            var prevNt   = ntFound;
            ntFound     += d.items.length;
            d.items.forEach(function(item, i){
                var editUrl = adminUrl+'post.php?post='+item.ID+'&action=edit';
                $('#dim-nothumb-list').append(tmpl
                    .replace(/\{\{num\}\}/g,       prevNt + i + 1)
                    .replace(/\{\{title\}\}/g,     esc(item.post_title || '(제목 없음)'))
                    .replace(/\{\{type\}\}/g,       item.post_type === 'page' ? '페이지' : '글')
                    .replace(/\{\{date\}\}/g,       (item.post_date||'').slice(0,10))
                    .replace(/\{\{edit_url\}\}/g,   editUrl)
                );
            });

            ntOffset += d.items.length;
            $('#dim-nothumb-more-wrap').toggle(d.has_more);

            if (!d.total) {
                $('#dim-nothumb-list').html('<p style="padding:16px">대표이미지 없는 글이 없습니다 ✅</p>');
            }
        });
    }

    // ═══════════════════════════════════
    // ⑤ 미사용 이미지
    // ═══════════════════════════════════
    var $progUnused  = null;
    var unusedOffset = 0;
    var unusedTotalSize = 0;

    function loadUnused(append) {
        $progUnused = $('#dim-progress-unused');
        if (!append) { unusedOffset = 0; unusedTotalSize = 0; $('#dim-unused-list').empty(); }
        prog($progUnused, 0, 0, '스캔 중');
        post('scan_unused', { limit: 50, offset: unusedOffset }, function(err, d) {
            hideProg($progUnused);
            if (err) return notice(err.message, false);

            $('#dim-unused-total').text(d.total);
            unusedTotalSize += d.total_size || 0;
            $('#dim-unused-size').text(fmt(unusedTotalSize));
            $('#dim-unused-summary').show();

            if (!d.items.length && !unusedOffset) {
                $('#dim-unused-list').html('<p style="padding:16px">미사용 이미지가 없습니다 ✅</p>');
                return;
            }

            var tmpl = $('#dim-unused-item-tmpl').html();
            d.items.forEach(function(item) {
                $('#dim-unused-list').append(tmpl
                    .replace(/\{\{id\}\}/g,       item.id)
                    .replace(/\{\{url\}\}/g,       item.url || '')
                    .replace(/\{\{title\}\}/g,     esc(item.title))
                    .replace(/\{\{type\}\}/g,      (item.type || '').toUpperCase())
                    .replace(/\{\{size\}\}/g,      fmt(item.file_size))
                    .replace(/\{\{date\}\}/g,      (item.date || '').slice(0, 10))
                    .replace(/\{\{edit_url\}\}/g,  item.edit_url || '#')
                );
            });

            unusedOffset += d.items.length;
            $('#dim-unused-select-wrap').toggle(unusedOffset > 0);
            $('#dim-unused-more-wrap').toggle(!!d.has_more);
            updateUnusedCount();
        });
    }

    function updateUnusedCount() {
        var n = $('.dim-unused-checkbox:checked').length;
        $('#dim-unused-selected-count').text(n + '개 선택됨');
        $('#dim-delete-unused-btn').prop('disabled', n === 0);
    }

    function deleteUnused() {
        var ids = $('.dim-unused-checkbox:checked').map(function() { return this.value; }).get();
        if (!ids.length) return;
        if (!confirm('선택한 ' + ids.length + '개 이미지를 완전히 삭제합니다. 복구 불가합니다.')) return;
        if (!$progUnused || !$progUnused.length) $progUnused = $('#dim-progress-unused');
        prog($progUnused, 0, 0, '삭제 중');
        post('delete_images', { ids: ids }, function(err, d) {
            if (err) {
                progDone($progUnused, err.message, false);
                notice(err.message, false);
            } else {
                var msg = d.deleted + '개 삭제 완료';
                if (d.errors && d.errors.length) msg += ' · 실패 ' + d.errors.length + '개';
                progDone($progUnused, msg, !d.errors || !d.errors.length);
                notice(msg, !d.errors || !d.errors.length);
            }
            loadUnused(false);
        });
    }

    function deleteAllUnused() {
        if (!confirm('스캔된 미사용 이미지를 전부 삭제합니다. 복구 불가합니다.\n계속하시겠습니까?')) return;
        if (!$progUnused || !$progUnused.length) $progUnused = $('#dim-progress-unused');
        var totalDeleted = 0, totalErrors = 0;

        function step() {
            prog($progUnused, totalDeleted, 0, '전체 삭제 중');
            // offset=0 고정: 삭제 후 다음 쿼리에서 자동 제외됨
            post('scan_unused', { limit: 100, offset: 0 }, function(err, d) {
                if (err || !d.items || !d.items.length) {
                    var msg = totalDeleted + '개 삭제 완료' + (totalErrors ? ' · 실패 ' + totalErrors + '개' : '');
                    progDone($progUnused, msg, totalErrors === 0);
                    notice(msg, totalErrors === 0);
                    loadUnused(false);
                    return;
                }
                var ids = d.items.map(function(item) { return item.id; });
                post('delete_images', { ids: ids }, function(err2, d2) {
                    if (!err2) {
                        totalDeleted += d2.deleted || 0;
                        totalErrors  += (d2.errors || []).length;
                    }
                    step(); // 삭제 후 다시 스캔
                });
            });
        }
        step();
    }

    // ═══════════════════════════════════
    // ⑥ 이미지 오류 글
    // ═══════════════════════════════════
    var $progBroken     = null;
    var brokenOffset    = 0;
    var brokenScanned   = 0;
    var brokenFound     = 0;
    var brokenItems     = [];
    var brokenSortDesc  = false;

    function renderBrokenList() {
        var items = brokenItems.slice();
        if (brokenSortDesc) {
            items.sort(function(a, b) { return b.broken_ids.length - a.broken_ids.length; });
        }
        var $list    = $('#dim-brokenimg-list').empty();
        var tmpl     = $('#dim-brokenimg-item-tmpl').html();
        var adminUrl = DIM.admin_url;
        items.forEach(function(item, i) {
            var editUrl    = item.edit_url || (adminUrl + 'post.php?post=' + item.id + '&action=edit');
            var brokenCount = item.broken_ids.length;
            var brokenHtml  = item.broken_ids.map(function(bid) {
                return '<span class="dim-broken-id-badge">이미지 ID ' + bid + '</span>';
            }).join(' ');
            $list.append(tmpl
                .replace(/\{\{num\}\}/g,            i + 1)
                .replace(/\{\{title\}\}/g,           esc(item.title || '(제목 없음)'))
                .replace(/\{\{type\}\}/g,            item.type === 'page' ? '페이지' : '글')
                .replace(/\{\{date\}\}/g,            (item.date || '').slice(0, 10))
                .replace(/\{\{edit_url\}\}/g,         editUrl)
                .replace(/\{\{broken_count\}\}/g,    brokenCount)
                .replace(/\{\{broken_ids_html\}\}/g,  brokenHtml)
            );
        });
        if (!items.length) {
            $list.html('<p style="padding:16px">이미지 오류 글이 없습니다 ✅</p>');
        }
    }

    function loadBrokenImgPosts(append) {
        $progBroken = $('#dim-progress-brokenimg');
        if (!append) {
            brokenOffset = 0; brokenScanned = 0; brokenFound = 0; brokenItems = [];
            $('#dim-brokenimg-list').empty();
            $('#dim-brokenimg-summary').hide();
            $('#dim-brokenimg-sort-btn').hide();
        }
        prog($progBroken, brokenOffset, 0, '스캔 중');
        post('get_broken_img_posts', { limit: 50, offset: brokenOffset }, function(err, d) {
            if (err) { progDone($progBroken, err.message, false); return notice(err.message, false); }

            brokenScanned += d.total_scanned || 0;
            var realItems  = (d.items || []).filter(function(item) {
                return item.broken_ids && item.broken_ids.length > 0;
            });
            realItems.forEach(function(item) { brokenItems.push(item); });
            brokenFound = brokenItems.length;
            $('#dim-brokenimg-scanned').text(brokenScanned);
            $('#dim-brokenimg-count').text(brokenFound);
            $('#dim-brokenimg-summary').show();

            brokenOffset += 50;

            if (d.has_more) {
                prog($progBroken, brokenScanned, 0, '스캔 중');
                loadBrokenImgPosts(true);
            } else {
                $('#dim-brokenimg-more-wrap').hide();
                renderBrokenList();
                var msg = brokenFound
                    ? brokenScanned + '개 스캔 완료 — 오류 이미지 있는 글 ' + brokenFound + '개'
                    : brokenScanned + '개 스캔 완료 — 이미지 오류 글 없음 ✅';
                progDone($progBroken, msg, true);
                if (brokenFound > 1) $('#dim-brokenimg-sort-btn').show();
            }
        });
    }

    // ═══════════════════════════════════
    // ⑦ H2 아래 이미지 없는 글
    // ═══════════════════════════════════
    var $progH2     = null;
    var h2Offset    = 0;
    var h2Scanned   = 0;
    var h2Found     = 0;

    function loadH2NoImgPosts(append) {
        $progH2 = $('#dim-progress-h2noimg');
        if (!append) {
            h2Offset = 0; h2Scanned = 0; h2Found = 0;
            $('#dim-h2noimg-list').empty();
            $('#dim-h2noimg-summary').hide();
        }
        prog($progH2, h2Offset, 0, '스캔 중');
        post('get_h2_no_img_posts', { limit: 50, offset: h2Offset }, function(err, d) {
            if (err) { progDone($progH2, err.message, false); return notice(err.message, false); }

            h2Scanned += d.total_scanned || 0;
            var h2Prev = h2Found;
            h2Found   += d.items.length;
            $('#dim-h2noimg-scanned').text(h2Scanned);
            $('#dim-h2noimg-count').text(h2Found);
            $('#dim-h2noimg-summary').show();

            var tmpl     = $('#dim-nothumb-item-tmpl').html();
            var adminUrl = DIM.admin_url;
            d.items.forEach(function(item, i) {
                var editUrl = item.edit_url || (adminUrl + 'post.php?post=' + item.id + '&action=edit');
                $('#dim-h2noimg-list').append(tmpl
                    .replace(/\{\{num\}\}/g,      h2Prev + i + 1)
                    .replace(/\{\{title\}\}/g,     esc(item.title || '(제목 없음)'))
                    .replace(/\{\{type\}\}/g,      item.type === 'page' ? '페이지' : '글')
                    .replace(/\{\{date\}\}/g,      (item.date || '').slice(0, 10))
                    .replace(/\{\{edit_url\}\}/g,   editUrl)
                );
            });

            h2Offset += 50;

            if (d.has_more) {
                prog($progH2, h2Scanned, 0, '스캔 중');
                loadH2NoImgPosts(true);  // 자동으로 다음 배치 계속
            } else {
                $('#dim-h2noimg-more-wrap').hide();
                var msg = h2Found
                    ? h2Scanned + '개 스캔 완료 — H2 이미지 누락 ' + h2Found + '개'
                    : h2Scanned + '개 스캔 완료 — 모든 H2 아래 이미지 있음 ✅';
                progDone($progH2, msg, true);
                if (!h2Found) {
                    $('#dim-h2noimg-list').html('<p style="padding:16px">H2 이미지 누락 글이 없습니다 ✅</p>');
                }
            }
        });
    }

    // ═══════════════════════════════════
    // ✂ 이미지 자르기
    // ═══════════════════════════════════
    var cropId      = 0;
    var cropStartX  = 0, cropStartY = 0;
    var cropSelecting = false;
    var cropSel     = { x: 0, y: 0, w: 0, h: 0 };

    function openCropModal(id, url) {
        cropId = id;
        cropSel = { x: 0, y: 0, w: 0, h: 0 };
        cropSelecting = false;
        var $img = $('#dim-crop-img');
        $img.attr('src', url);
        $('#dim-crop-sel').hide().css({ left: 0, top: 0, width: 0, height: 0 });
        $('#dim-crop-info').text('드래그하여 자를 영역을 선택하세요');
        $('#dim-crop-apply').prop('disabled', true);
        $('#dim-crop-progress').hide();
        $('#dim-crop-modal').show();
    }

    function closeCropModal() {
        cropSelecting = false;
        $('#dim-crop-modal').hide();
    }

    // 드래그 선택
    $('#dim-crop-img-wrap').on('mousedown', function(e) {
        e.preventDefault();
        var offset = $(this).offset();
        var ww = $(this).width(), wh = $(this).height();
        cropStartX = Math.max(0, Math.min(e.pageX - offset.left, ww));
        cropStartY = Math.max(0, Math.min(e.pageY - offset.top,  wh));
        cropSelecting = true;
        cropSel = { x: 0, y: 0, w: 0, h: 0 };
        $('#dim-crop-sel').css({ left: cropStartX, top: cropStartY, width: 0, height: 0 }).show();
        $('#dim-crop-apply').prop('disabled', true);
        $('#dim-crop-info').text('드래그 중...');
    });

    $(document).on('mousemove.dimcrop', function(e) {
        if (!cropSelecting) return;
        var $wrap  = $('#dim-crop-img-wrap');
        var offset = $wrap.offset();
        var ww = $wrap.width(), wh = $wrap.height();
        var mx = Math.max(0, Math.min(e.pageX - offset.left, ww));
        var my = Math.max(0, Math.min(e.pageY - offset.top,  wh));

        var sx = Math.min(mx, cropStartX);
        var sy = Math.min(my, cropStartY);
        var sw = Math.abs(mx - cropStartX);
        var sh = Math.abs(my - cropStartY);

        $('#dim-crop-sel').css({ left: sx, top: sy, width: sw, height: sh });

        var img = $('#dim-crop-img')[0];
        if (img && img.naturalWidth && sw > 0 && sh > 0) {
            var scaleX = img.naturalWidth  / img.offsetWidth;
            var scaleY = img.naturalHeight / img.offsetHeight;
            cropSel = {
                x: Math.round(sx * scaleX), y: Math.round(sy * scaleY),
                w: Math.round(sw * scaleX), h: Math.round(sh * scaleY)
            };
            $('#dim-crop-info').text(cropSel.w + ' × ' + cropSel.h + ' px');
        }
    }).on('mouseup.dimcrop', function() {
        if (!cropSelecting) return;
        cropSelecting = false;
        if (cropSel.w > 5 && cropSel.h > 5) {
            $('#dim-crop-apply').prop('disabled', false);
        } else {
            $('#dim-crop-info').text('너무 작은 영역입니다. 다시 드래그하세요.');
        }
    });

    // 터치 지원 (모바일)
    $('#dim-crop-img-wrap').on('touchstart', function(e) {
        var t = e.originalEvent.touches[0];
        $(this).trigger($.Event('mousedown', { pageX: t.pageX, pageY: t.pageY }));
    }).on('touchmove', function(e) {
        e.preventDefault();
        var t = e.originalEvent.touches[0];
        $(document).trigger($.Event('mousemove.dimcrop', { pageX: t.pageX, pageY: t.pageY }));
    }).on('touchend', function() {
        $(document).trigger('mouseup.dimcrop');
    });

    $('#dim-crop-apply').on('click', function() {
        if (!cropId || !cropSel.w || !cropSel.h) return;
        $('#dim-crop-progress').show();
        $('#dim-crop-apply, #dim-crop-cancel').prop('disabled', true);
        post('crop_image', { id: cropId, x: cropSel.x, y: cropSel.y, w: cropSel.w, h: cropSel.h }, function(err, d) {
            $('#dim-crop-progress').hide();
            $('#dim-crop-apply, #dim-crop-cancel').prop('disabled', false);
            if (err) {
                $('#dim-crop-info').css('color', '#d63638').text('오류: ' + (err.message || ''));
            } else {
                // 목록에서 해당 이미지 썸네일 갱신
                $('img').filter(function() {
                    return this.src && this.src.indexOf('id=' + cropId) !== -1 ||
                           $(this).closest('[data-id="' + cropId + '"]').length;
                }).attr('src', d.url);
                notice('자르기 완료 — ' + d.w + '×' + d.h + ' px', true);
                closeCropModal();
            }
        });
    });

    $('#dim-crop-close, #dim-crop-cancel').on('click', closeCropModal);
    $('#dim-crop-modal').on('click', function(e) {
        if ($(e.target).is('#dim-crop-modal')) closeCropModal();
    });

    $(document).on('click', '.dim-open-crop', function() {
        openCropModal($(this).data('id'), $(this).data('url'));
    });

    // ═══════════════════════════════════
    // ④ 용량 현황
    // ═══════════════════════════════════
    function loadStats() {
        $('#dim-progress-stats').show();
        $('#dim-stats-result').hide();
        post('get_stats', {}, function(err, d){
            $('#dim-progress-stats').hide();
            if (err) return notice(err.message, false);

            var total = d.total_size, other = d.other_size, webp = d.webp_size;
            var estSave = Math.round(other * 0.35);
            var webpPct = total > 0 ? Math.round(webp/total*100) : 0;

            $('#stat-total-count').text(d.total_count.toLocaleString()+'개');
            $('#stat-total-size').text(fmt(total));
            $('#stat-webp-count').text(d.webp_count.toLocaleString()+'개');
            $('#stat-webp-size').text(fmt(webp));
            $('#stat-other-count').text(d.other_count.toLocaleString()+'개');
            $('#stat-other-size').text(fmt(other));
            $('#stat-save-pct').text('~'+fmt(estSave));

            $('#dim-webp-pct-bar').css('width', webpPct+'%');
            $('#dim-webp-pct-label').text(webpPct+'%');
            $('#dim-other-pct-label').text((100-webpPct)+'%');

            var $chart = $('#dim-type-chart').empty();
            var types = d.by_type || {};
            Object.keys(types).forEach(function(type){
                var info = types[type];
                var pct  = total > 0 ? Math.round(info.size/total*100) : 0;
                $chart.append(
                    '<div class="dim-chart-row">'+
                    '<span class="dim-chart-label">'+type.toUpperCase()+'</span>'+
                    '<div class="dim-chart-bar-wrap">'+
                    '<div class="dim-chart-bar dim-bar-'+type+'" style="width:'+pct+'%"></div>'+
                    '</div>'+
                    '<span class="dim-chart-val">'+info.count+'개 · '+fmt(info.size)+'</span>'+
                    '</div>'
                );
            });

            $('#dim-stats-result').show();
        });
    }

    // ═══════════════════════════════════
    // 이벤트 바인딩
    // ═══════════════════════════════════
    $(function(){
        initSchedule();

        // 이전 자동 병합 중단 감지 — 재개 안내
        (function() {
            var pending = getPendingGroups();
            if (!pending || !pending.length) return;
            notice(
                '⚠ 이전 자동 병합이 중단되었습니다 — <strong>' + pending.length + '개</strong> 그룹이 남아 있습니다. ' +
                '<button id="dim-resume-btn" class="button button-small" style="margin:0 4px">이어서 진행</button>' +
                '<button id="dim-resume-cancel-btn" class="button button-small">취소</button>',
                false
            );
            $('#dim-resume-btn').on('click', function() {
                $('#dim-notice').hide();
                groups = pending;
                // 스캔 요약 수치 복원
                $('#dim-group-count').text(groups.length);
                $('#dim-auto-merge-btn, #dim-merge-selected-btn').prop('disabled', false);
                autoMerge(pending);
            });
            $('#dim-resume-cancel-btn').on('click', function() {
                clearPendingGroups();
                $('#dim-notice').hide();
            });
        })();

        // 탭 ①
        $('#dim-scan-btn').on('click', startScan);
        $('#dim-auto-merge-btn').on('click', function() { autoMerge(); });
        $('#dim-merge-selected-btn').on('click', mergeSelected);
        $('#dim-thumb-btn').on('click', fixThumbnails);
        $('#dim-run-now-btn').on('click', runAll);
        $('#dim-select-all').on('change', function(){
            $('.dim-item-checkbox').prop('checked', this.checked); updateDupCount();
        });
        $(document).on('change', '.dim-group-select-all', function(){
            var g = $(this).data('group-idx');
            $('.dim-item-checkbox[data-group-idx="'+g+'"]').prop('checked', this.checked); updateDupCount();
        });
        $(document).on('change', '.dim-item-checkbox', updateDupCount);
        $(document).on('click', '.dim-merge-group-btn', function(){
            var g    = $(this).data('group-idx');
            var keep = $('input[name="dim-keep-'+g+'"]:checked').val();
            if (!keep) return notice('원본 유지 이미지를 선택하세요.', false);
            var del = [];
            $('.dim-item-checkbox[data-group-idx="'+g+'"]').each(function(){ if(this.value!==keep) del.push(this.value); });
            if (!del.length) return;
            prog($progDup||$('#dim-progress-dup'), 0, 0, '병합 중');
            post('merge', { keep_id:keep, delete_ids:del }, function(err, d){
                hideProg($progDup||$('#dim-progress-dup'));
                err ? notice(err.message,false) : notice(d.merged+'개 병합 완료.',true);
                startScan();
            });
        });

        // 탭 ②
        $('#dim-load-nonwebp-btn').on('click', function(){ loadNonWebp(false); });
        $('#dim-convert-selected-btn').on('click', convertSelected);
        $('#dim-convert-all-btn').on('click', convertAll);
        $('#dim-delete-nonwebp-btn').on('click', deleteSelected);
        $('#dim-delete-all-nonwebp-btn').on('click', deleteAllNonWebp);
        $('#dim-nonwebp-more-btn').on('click', function(){ loadNonWebp(true); });
        $('#dim-nonwebp-select-all').on('change', function(){
            $('.dim-nw-checkbox').prop('checked', this.checked); updateNwCount();
        });
        $(document).on('change', '.dim-nw-checkbox', updateNwCount);

        // 탭 ③
        $('#dim-load-nothumb-btn').on('click', function(){ loadNoThumb(false); });
        $('#dim-nothumb-more-btn').on('click', function(){ loadNoThumb(true); });

        // 탭 ④
        $('#dim-load-stats-btn').on('click', loadStats);

        // 탭 ⑤
        $('#dim-scan-unused-btn').on('click', function(){ loadUnused(false); });
        $('#dim-delete-unused-btn').on('click', deleteUnused);
        $('#dim-delete-all-unused-btn').on('click', deleteAllUnused);
        $('#dim-unused-more-btn').on('click', function(){ loadUnused(true); });
        $('#dim-unused-select-all').on('change', function(){
            $('.dim-unused-checkbox').prop('checked', this.checked); updateUnusedCount();
        });
        $(document).on('change', '.dim-unused-checkbox', updateUnusedCount);

        // 탭 ⑥ 이미지 오류 글
        $('#dim-scan-brokenimg-btn').on('click', function(){ loadBrokenImgPosts(false); });
        $('#dim-brokenimg-more-btn').on('click', function(){ loadBrokenImgPosts(true); });
        $('#dim-brokenimg-sort-btn').on('click', function(){
            brokenSortDesc = !brokenSortDesc;
            $(this).text(brokenSortDesc ? '기본 순 ↑' : '오류 많은 순 ↓');
            renderBrokenList();
        });

        // 탭 ⑦ H2 아래 이미지 없는 글
        $('#dim-scan-h2noimg-btn').on('click', function(){ loadH2NoImgPosts(false); });
        $('#dim-h2noimg-more-btn').on('click', function(){ loadH2NoImgPosts(true); });
    });

}(jQuery));
