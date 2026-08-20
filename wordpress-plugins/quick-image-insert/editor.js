( function () {
    var el                       = wp.element.createElement;
    var Fragment                 = wp.element.Fragment;
    var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
    var BlockControls            = wp.blockEditor.BlockControls;
    var ToolbarButton            = wp.components.ToolbarButton;
    var createBlock              = wp.blocks.createBlock;

    // H2, H3 제목 블록에서만 버튼 표시
    function isTargetBlock( props ) {
        return props.name === 'core/heading'
            && props.attributes
            && ( props.attributes.level === 2 || props.attributes.level === 3 );
    }

    // ── 이미지 삽입 핸들러 (클릭 시점의 clientId + baseIndex 고정) ─
    function handleImageInsert( clientId, baseIndex ) {
        var frame = wp.media( {
            title  : '미디어 / 파일 찾기 (여러 개 선택 가능)',
            button : { text: '이미지 삽입' },
            multiple: 'add',
            library: { type: 'image' },
        } );

        frame.on( 'select', function () {
            var selection = frame.state().get( 'selection' );

            // H2 개수 = 삽입 한도
            var h2Count = wp.data.select( 'core/block-editor' ).getBlocks()
                .filter( function ( b ) {
                    return b.name === 'core/heading' && b.attributes.level === 2;
                } ).length;

            var all = [];
            selection.each( function ( a ) { all.push( a.toJSON() ); } );
            if ( ! all.length ) return;

            var limit    = h2Count > 0 ? h2Count : all.length;
            var toInsert = all.slice( 0, limit );
            var toDelete = all.slice( limit );

            // 초과 이미지 → 미디어 영구 삭제
            toDelete.forEach( function ( d ) {
                wp.apiFetch( {
                    path  : '/wp/v2/media/' + d.id + '?force=true',
                    method: 'DELETE',
                } ).catch( function ( e ) {
                    console.warn( '[QII] 삭제 실패 ID=' + d.id, e );
                } );
            } );

            // 현재 블록 바로 아래 삽입
            var blocks = toInsert.map( function ( d ) {
                return createBlock( 'core/image', {
                    url: d.url, alt: d.alt || d.title || '', caption: '', id: d.id,
                } );
            } );
            wp.data.dispatch( 'core/block-editor' ).insertBlocks( blocks, baseIndex + 1 );

            // 첫 번째 이미지를 대표이미지로 설정
            wp.data.dispatch( 'core/editor' ).editPost( { featured_media: toInsert[ 0 ].id } );
        } );

        frame.open();
    }

    // ── addFilter + createHigherOrderComponent (공식 권장) ────────
    var withImageButton = createHigherOrderComponent(
        function ( OriginalComponent ) {
            return function QiiWrapper( props ) {
                return el(
                    Fragment,
                    null,
                    el( OriginalComponent, props ),
                    // 선택된 허용 블록에서만 툴바 버튼 표시
                    props.isSelected && isTargetBlock( props ) && el(
                        BlockControls,
                        null,
                        el( ToolbarButton, {
                            icon       : 'format-image',
                            label      : '미디어 / 파일 찾기',
                            showTooltip: true,
                            onClick    : function () {
                                // 클릭 시점에 index 확정
                                var idx = wp.data.select( 'core/block-editor' ).getBlockIndex( props.clientId );
                                handleImageInsert( props.clientId, idx );
                            },
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
                        var vis = Array.from( g.querySelectorAll( 'button' ) )
                            .filter( function ( b ) { return getComputedStyle( b ).display !== 'none'; } );
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
