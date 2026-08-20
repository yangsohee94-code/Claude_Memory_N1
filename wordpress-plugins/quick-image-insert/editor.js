( function () {
    var el = window.wp.element.createElement;
    var Fragment = window.wp.element.Fragment;
    var addFilter = window.wp.hooks.addFilter;
    var createBlock = window.wp.blocks.createBlock;
    var BlockControls = window.wp.blockEditor.BlockControls;
    var ToolbarGroup = window.wp.components.ToolbarGroup;
    var ToolbarButton = window.wp.components.ToolbarButton;

    var allowedBlocks = [
        'core/paragraph',
        'core/heading',
        'core/list',
        'core/quote',
        'core/pullquote',
    ];

    // ── addFilter 방식: 각 블록 Edit 컴포넌트에 직접 툴바 버튼 추가 ──
    // registerPlugin 보다 안정적으로 툴바에 버튼을 붙임
    addFilter(
        'editor.BlockEdit',
        'quick-image-insert/toolbar',
        function ( BlockEdit ) {
            return function ( props ) {
                var baseEdit = el( BlockEdit, props );

                if ( allowedBlocks.indexOf( props.name ) === -1 ) {
                    return baseEdit;
                }

                function openMediaLibrary() {
                    var frame = wp.media( {
                        title: '미디어 / 파일 찾기 (여러 개 선택 가능)',
                        button: { text: '이미지 삽입' },
                        multiple: true,
                        frame: 'post',
                        state: 'insert',
                        library: { type: 'image' },
                    } );

                    frame.on( 'insert', function () {
                        var selection = frame.state().get( 'selection' );
                        var clientId = wp.data.select( 'core/block-editor' ).getSelectedBlockClientId();
                        var insertIndex = wp.data.select( 'core/block-editor' ).getBlockIndex( clientId ) + 1;

                        var imageBlocks = [];
                        selection.each( function ( attachment ) {
                            var data = attachment.toJSON();
                            imageBlocks.push( createBlock( 'core/image', {
                                url: data.url,
                                alt: data.alt || data.title || '',
                                caption: '',
                                id: data.id,
                            } ) );
                        } );

                        wp.data.dispatch( 'core/block-editor' ).insertBlocks( imageBlocks, insertIndex );
                    } );

                    frame.open();
                }

                return el(
                    Fragment,
                    null,
                    baseEdit,
                    el(
                        BlockControls,
                        null,
                        el(
                            ToolbarGroup,
                            null,
                            el( ToolbarButton, {
                                icon: 'format-image',
                                label: '미디어 / 파일 찾기',
                                onClick: openMediaLibrary,
                                showTooltip: true,
                            } )
                        )
                    )
                );
            };
        }
    );
} )();
