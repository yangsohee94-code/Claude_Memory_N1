( function () {
    var el = window.wp.element.createElement;
    var registerPlugin = window.wp.plugins.registerPlugin;
    var BlockControls = window.wp.blockEditor.BlockControls;
    var ToolbarGroup = window.wp.components.ToolbarGroup;
    var ToolbarButton = window.wp.components.ToolbarButton;
    var useSelect = window.wp.data.useSelect;
    var useDispatch = window.wp.data.useDispatch;
    var createBlock = window.wp.blocks.createBlock;

    // ─── 이미지 삽입 버튼 ────────────────────────────────────────
    function QuickImageInsertButton() {
        var selectedBlock = useSelect( function ( select ) {
            return select( 'core/block-editor' ).getSelectedBlock();
        } );
        var insertBlock = useDispatch( 'core/block-editor' ).insertBlocks;

        var allowedBlocks = [
            'core/paragraph',
            'core/heading',
            'core/list',
            'core/quote',
            'core/pullquote',
        ];

        if ( ! selectedBlock || allowedBlocks.indexOf( selectedBlock.name ) === -1 ) {
            return null;
        }

        function openMediaLibrary() {
            // frame: 'post' → 업로드 탭 + 미디어 라이브러리 탭 모두 포함 (아이패드 호환)
            var frame = wp.media( {
                title: '미디어 또는 파일 찾기 (여러 개 선택 가능)',
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

                // 선택 순서대로 현재 블록 바로 아래에 순서대로 삽입
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

                // 한 번에 삽입하면 선택 순서 유지됨
                insertBlock( imageBlocks, insertIndex );
            } );

            frame.open();
        }

        return el(
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
        );
    }

    registerPlugin( 'quick-image-insert', {
        render: QuickImageInsertButton,
    } );
} )();
