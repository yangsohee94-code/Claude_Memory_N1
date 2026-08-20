( function () {
    var el                         = wp.element.createElement;
    var Fragment                   = wp.element.Fragment;
    var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
    var BlockControls              = wp.blockEditor.BlockControls;
    var ToolbarButton              = wp.components.ToolbarButton;
    var createBlock                = wp.blocks.createBlock;

    // ── H2 블록 바로 아래에 이미지를 순서대로 배치하는 핵심 함수 ──
    function distributeImagesToH2( attachments ) {
        // 현재 글의 모든 블록 가져오기
        var allBlocks = wp.data.select( 'core/block-editor' ).getBlocks();

        // H2 블록만 순서대로 추출
        var h2Blocks = allBlocks.filter( function ( b ) {
            return b.name === 'core/heading' && b.attributes.level === 2;
        } );

        if ( h2Blocks.length === 0 ) {
            alert( 'H2 소제목이 없습니다. H2를 먼저 작성해 주세요.' );
            return;
        }

        var limit    = Math.min( attachments.length, h2Blocks.length );
        var toInsert = attachments.slice( 0, limit );
        var toDelete = attachments.slice( limit );

        // 초과 이미지 → 미디어 영구 삭제
        toDelete.forEach( function ( d ) {
            wp.apiFetch( {
                path  : '/wp/v2/media/' + d.id + '?force=true',
                method: 'DELETE',
            } ).catch( function ( e ) {
                console.warn( '[QII] 삭제 실패 ID=' + d.id, e );
            } );
        } );

        if ( ! toInsert.length ) return;

        // 첫 번째 이미지를 대표이미지로 설정
        wp.data.dispatch( 'core/editor' ).editPost( { featured_media: toInsert[ 0 ].id } );

        // ★ 뒤에서 앞으로 삽입 → 앞쪽 H2 인덱스가 밀리지 않음
        for ( var i = toInsert.length - 1; i >= 0; i-- ) {
            var h2ClientId = h2Blocks[ i ].clientId;
            // 매 반복마다 최신 인덱스 재조회 (이전 삽입으로 인해 바뀔 수 있음)
            var h2Index    = wp.data.select( 'core/block-editor' ).getBlockIndex( h2ClientId );

            var imgBlock = createBlock( 'core/image', {
                url    : toInsert[ i ].url,
                alt    : toInsert[ i ].alt || toInsert[ i ].title || '',
                caption: '',
                id     : toInsert[ i ].id,
            } );

            wp.data.dispatch( 'core/block-editor' ).insertBlocks( [ imgBlock ], h2Index + 1 );
        }
    }

    // ── 미디어 라이브러리 열기 ────────────────────────────────────
    function openMedia() {
        var frame = wp.media( {
            title  : '이미지 선택 — H2 소제목 순서대로 배치됩니다',
            button : { text: '이미지 삽입' },
            multiple: 'add',
            library: { type: 'image' },
        } );

        frame.on( 'select', function () {
            var attachments = [];
            frame.state().get( 'selection' ).each( function ( a ) {
                attachments.push( a.toJSON() );
            } );
            distributeImagesToH2( attachments );
        } );

        frame.open();
    }

    // ── HOC: H2, H3 블록 툴바에 버튼 추가 ───────────────────────
    var withImageButton = createHigherOrderComponent(
        function ( OriginalComponent ) {
            return function QiiWrapper( props ) {
                var isHeading = props.name === 'core/heading'
                    && props.attributes
                    && ( props.attributes.level === 2 || props.attributes.level === 3 );

                return el(
                    Fragment, null,
                    el( OriginalComponent, props ),
                    props.isSelected && isHeading && el(
                        BlockControls, null,
                        el( ToolbarButton, {
                            icon      : 'format-image',
                            label     : '이미지 삽입 (H2 순서대로)',
                            showTooltip: true,
                            onClick   : openMedia,
                        } )
                    )
                );
            };
        },
        'withImageButton'
    );

    wp.hooks.addFilter( 'editor.BlockEdit', 'quick-image-insert/button', withImageButton );

    // ── 불필요한 툴바 버튼 숨기기 (MutationObserver) ─────────────
    var HIDE_LABELS = [
        'AI 도우미', 'Rank Math AI', 'Content AI',
        '텍스트 정렬', '왼쪽 정렬', '가운데 정렬', '오른쪽 정렬',
        '이미지 링크', '자르기', '이미지 자르기',
        '텍스트 오버레이 추가', '텍스트 추가',
        '대체 텍스트', '대체 텍스트 없음', '대체 텍스트 추가',
    ];

    wp.domReady( function () {
        function hide() {
            HIDE_LABELS.forEach( function ( label ) {
                document.querySelectorAll(
                    'button[aria-label="' + label + '"], button[aria-label^="' + label + ' "]'
                ).forEach( function ( btn ) {
                    btn.style.cssText += ';display:none!important';
                    var g = btn.closest( '.components-toolbar-group' );
                    if ( g ) {
                        var vis = Array.from( g.querySelectorAll( 'button' ) ).filter(
                            function ( b ) { return getComputedStyle( b ).display !== 'none'; }
                        );
                        if ( ! vis.length ) g.style.cssText += ';display:none!important';
                    }
                } );
            } );
            document.querySelectorAll(
                '[class*="rank-math"][class*="ai"],[class*="rank-math"][class*="toolbar"]'
            ).forEach( function ( n ) { n.style.cssText += ';display:none!important'; } );
        }
        new MutationObserver( hide ).observe( document.body, { childList: true, subtree: true } );
        hide();
    } );
} )();
