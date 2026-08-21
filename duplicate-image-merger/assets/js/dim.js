/* global jQuery, DIM */
(function ($) {
    'use strict';

    var groups = [];

    // ── 유틸 ──
    function fmt(bytes) {
        if (!bytes) return '0 B';
        var k = 1024, u = ['B','KB','MB','GB'], i = Math.floor(Math.log(bytes)/Math.log(k));
        return (bytes/Math.pow(k,i)).toFixed(1)+' '+u[i];
    }
    function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    function progress(pct, text) {
        $('#dim-progress').show();
        $('#dim-progress-bar').css('width', pct+'%');
        $('#dim-progress-text').text(text);
    }
    function hideProgress(){ setTimeout(function(){ $('#dim-progress').hide(); }, 500); }

    function notice(msg, ok) {
        $('#dim-notice').removeClass('dim-ok dim-err')
            .addClass(ok ? 'dim-ok' : 'dim-err').html(msg).show();
    }

    function post(action, data, cb) {
        $.post(DIM.ajax_url, $.extend({ action: 'dim_'+action, nonce: DIM.nonce }, data))
            .done(function(r){ cb(r.success ? null : r.data, r.data); })
            .fail(function(){ cb({ message: '서버 오류' }); });
    }

    // ── 스케줄 초기화 ──
    function initSchedule() {
        var $tog = $('#dim-schedule-toggle').prop('checked', DIM.cron_on);
        $('#dim-schedule-label').text(DIM.cron_on ? '자동 최적화 예약 중 (매일)' : '자동 최적화 꺼짐');
        if (DIM.last_run) $('#dim-last-run').text('마지막 실행: '+DIM.last_run);

        $tog.on('change', function(){
            post('schedule', { enable: this.checked }, function(err, d){
                if (!err) notice(d.message, true);
            });
            $('#dim-schedule-label').text(this.checked ? '자동 최적화 예약 중 (매일)' : '자동 최적화 꺼짐');
        });
    }

    // ── 스캔 ──
    function startScan() {
        groups = [];
        $('#dim-results').empty();
        $('#dim-select-all-wrap, #dim-summary').hide();
        $('#dim-notice').hide();
        $('#dim-auto-merge-btn, #dim-merge-selected-btn').prop('disabled', true);
        scanBatch(0);
    }

    function scanBatch(offset) {
        progress(offset ? Math.min(85, Math.round(offset/(offset+200)*85)) : 5, '스캔 중... '+offset+'개 처리됨');
        post('scan', { offset: offset, batch: 200 }, function(err, data){
            if (err) return notice('스캔 실패: '+(err.message||''), false);
            data.duplicates.forEach(function(g){ groups.push(g); });
            if (data.has_more) { scanBatch(offset+200); return; }
            renderAll(data.total_scanned);
        });
    }

    function renderAll(scanned) {
        hideProgress();
        var dupCount = groups.reduce(function(s,g){ return s+g.count; },0);
        var saved    = groups.reduce(function(s,g){
            var sizes = g.items.map(function(i){ return i.file_size||0; });
            return s + sizes.reduce(function(a,b){ return a+b; },0) - Math.max.apply(null,sizes);
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
        var gtmpl = $('#dim-group-tmpl').html();
        var itmpl = $('#dim-item-tmpl').html();

        groups.forEach(function(g, idx){
            var items = g.items.map(function(item){
                return itmpl
                    .replace(/\{\{id\}\}/g,        item.id)
                    .replace(/\{\{url\}\}/g,        item.url||'')
                    .replace(/\{\{title\}\}/g,      esc(item.title||''))
                    .replace(/\{\{date\}\}/g,        item.date||'')
                    .replace(/\{\{size\}\}/g,        fmt(item.file_size))
                    .replace(/\{\{group_idx\}\}/g,   idx)
                    .replace(/\{\{used_class\}\}/g,  item.is_used ? 'dim-used' : '')
                    .replace(/\{\{used_badge\}\}/g,  item.is_used ? '<span class="dim-badge-used">사용 중</span>' : '');
            }).join('');

            $res.append( gtmpl
                .replace(/\{\{idx\}\}/g,        idx)
                .replace(/\{\{count\}\}/g,       g.count)
                .replace(/\{\{hash_short\}\}/g,  (g.hash||'').slice(0,10)+'…')
                .replace(/\{\{items\}\}/g,       items)
            );
        });

        $('#dim-select-all-wrap').show();
        $('#dim-auto-merge-btn, #dim-merge-selected-btn').prop('disabled', false);
        updateCount();
    }

    function updateCount() {
        var n = $('.dim-item-checkbox:checked').length;
        $('#dim-selected-count').text(n+'개 선택됨');
        $('#dim-merge-selected-btn').prop('disabled', n===0);
    }

    // ── 수동 병합 ──
    function mergeSelected() {
        var tasks = {};
        $('.dim-item-checkbox:checked').each(function(){
            var g = $(this).data('group-idx');
            (tasks[g] = tasks[g]||[]).push($(this).val());
        });
        if (!Object.keys(tasks).length) return alert('선택된 항목이 없습니다.');
        if (!confirm('선택 이미지를 병합합니다. 삭제된 이미지는 복구 불가합니다.')) return;

        var calls = [], merged = 0;
        $.each(tasks, function(g, ids){
            var keep = $('input[name="dim-keep-'+g+'"]:checked').val();
            if (!keep) { alert((parseInt(g)+1)+'번 그룹: 원본 유지 이미지를 선택하세요.'); return false; }
            var del = ids.filter(function(i){ return i!==keep; });
            if (del.length) calls.push({ keep_id: keep, delete_ids: del });
        });

        progress(10, '병합 중...');
        var chain = $.when();
        calls.forEach(function(c){
            chain = chain.then(function(){
                return $.post(DIM.ajax_url, $.extend({ action:'dim_merge', nonce:DIM.nonce }, c))
                    .done(function(r){ if(r.success) merged += r.data.merged; });
            });
        });
        chain.always(function(){
            hideProgress();
            notice(merged+'개 이미지가 병합되었습니다.', true);
            startScan();
        });
    }

    // ── 자동 병합 ──
    function autoMerge() {
        if (!groups.length || !confirm('사용 중인 이미지를 기준으로 전체 자동 병합합니다.')) return;
        progress(10, '자동 병합 중...');
        post('auto_merge', { groups: groups }, function(err, d){
            hideProgress();
            err ? notice('오류: '+err.message, false)
                : notice(d.merged+'개 병합 완료.'+(d.errors&&d.errors.length ? ' 오류: '+d.errors.join(', '):''), true);
            startScan();
        });
    }

    // ── WebP 변환 ──
    function convertWebP() {
        if (!DIM.can_webp) return notice('이 서버는 WebP 변환을 지원하지 않습니다 (GD 또는 Imagick 필요).', false);
        if (!confirm('전체 이미지를 WebP로 변환합니다. 원본 파일은 삭제됩니다.')) return;
        $('#dim-webp-btn').prop('disabled', true);
        webpBatch(0, 0);
    }

    function webpBatch(offset, total) {
        progress(offset ? Math.min(90, Math.round(offset/(offset+30)*90)) : 5, 'WebP 변환 중... '+total+'개 완료');
        post('convert_webp', { offset: offset, batch: 30 }, function(err, d){
            if (err) { $('#dim-webp-btn').prop('disabled',false); return notice(err.message, false); }
            total += d.converted;
            if (d.has_more) { webpBatch(offset+30, total); return; }
            hideProgress();
            $('#dim-webp-btn').prop('disabled', false);
            notice('WebP 변환 완료: '+total+'개 변환, '+d.skipped+'개 건너뜀.', true);
        });
    }

    // ── 대표이미지 정합성 ──
    function fixThumbnails() {
        if (!confirm('손상된 대표이미지를 자동 수정합니다.')) return;
        progress(50, '대표이미지 확인 중...');
        post('fix_thumbnails', {}, function(err, d){
            hideProgress();
            err ? notice(err.message, false)
                : notice('대표이미지 수정 완료 — 자동 연결: '+d.fixed+'개, 삭제: '+d.cleared+'개 (총 '+d.total_checked+'개 확인)', true);
        });
    }

    // ── 전체 최적화 즉시 실행 ──
    function runAll() {
        if (!confirm('중복 병합 → WebP 변환 → 대표이미지 정합성 수정을 지금 실행합니다.\n시간이 걸릴 수 있습니다.')) return;
        progress(10, '전체 최적화 실행 중...');
        // 순서대로 실행
        post('auto_merge', { groups: groups }, function(err, d){
            progress(40, 'WebP 변환 중...');
            if (DIM.can_webp) {
                webpRunSync(0, function(){
                    progress(90, '대표이미지 수정 중...');
                    post('fix_thumbnails', {}, function(){
                        hideProgress();
                        notice('전체 최적화 완료!', true);
                        startScan();
                    });
                });
            } else {
                post('fix_thumbnails', {}, function(){
                    hideProgress();
                    notice('최적화 완료 (WebP 미지원 서버)!', true);
                    startScan();
                });
            }
        });
    }

    function webpRunSync(offset, done) {
        post('convert_webp', { offset: offset, batch: 30 }, function(err, d){
            if (err || !d.has_more) { done(); return; }
            webpRunSync(offset+30, done);
        });
    }

    // ── 이벤트 ──
    $(function(){
        initSchedule();

        $('#dim-scan-btn').on('click', startScan);
        $('#dim-auto-merge-btn').on('click', autoMerge);
        $('#dim-merge-selected-btn').on('click', mergeSelected);
        $('#dim-webp-btn').on('click', convertWebP);
        $('#dim-thumb-btn').on('click', fixThumbnails);
        $('#dim-run-now-btn').on('click', runAll);

        $('#dim-select-all').on('change', function(){
            $('.dim-item-checkbox').prop('checked', this.checked);
            updateCount();
        });
        $(document).on('change', '.dim-group-select-all', function(){
            var g = $(this).data('group-idx');
            $('.dim-item-checkbox[data-group-idx="'+g+'"]').prop('checked', this.checked);
            updateCount();
        });
        $(document).on('change', '.dim-item-checkbox', updateCount);

        $(document).on('click', '.dim-merge-group-btn', function(){
            var g    = $(this).data('group-idx');
            var keep = $('input[name="dim-keep-'+g+'"]:checked').val();
            if (!keep) return alert('원본 유지 이미지를 선택하세요.');
            if (!confirm('이 그룹을 병합합니다.')) return;
            var del = [];
            $('.dim-item-checkbox[data-group-idx="'+g+'"]').each(function(){ if(this.value!==keep) del.push(this.value); });
            if (!del.length) return;
            progress(10, '병합 중...');
            post('merge', { keep_id: keep, delete_ids: del }, function(err, d){
                hideProgress();
                err ? notice(err.message, false) : notice(d.merged+'개 병합 완료.', true);
                startScan();
            });
        });
    });

}(jQuery));
