( function () {
    var el = window.wp.element.createElement;
    var registerPlugin = window.wp.plugins.registerPlugin;
    var PluginBlockSettingsMenuItem = window.wp.editPost.PluginBlockSettingsMenuItem;
    var BlockControls = window.wp.blockEditor.BlockControls;
    var ToolbarGroup = window.wp.components.ToolbarGroup;
    var ToolbarButton = window.wp.components.ToolbarButton;
    var useSelect = window.wp.data.useSelect;
    var useDispatch = window.wp.data.useDispatch;
    var Fragment = window.wp.element.Fragment;
    var createBlock = window.wp.blocks.createBlock;

    function QuickImageInsertButton() {
        var selectedBlock = useSelect( function ( select ) {
            return select( 'core/block-editor' ).getSelectedBlock();
        } );

        var insertBlock = useDispatch( 'core/block-editor' ).insertBlocks;
        var getSelectedBlockClientId = useSelect( function ( select ) {
            return select( 'core/block-editor' ).getSelectedBlockClientId;
        } );

        // 텍스트 계열 블록에서만 버튼 표시
        var allowedBlocks = [
            'core/paragraph',
            'core/heading',
            'core/list',
            'core/quote',
            'core/pullquote',
            'core/cover',
        ];

        if ( ! selectedBlock || allowedBlocks.indexOf( selectedBlock.name ) === -1 ) {
            return null;
        }

        function openMediaLibrary() {
            var frame = wp.media( {
                title: '이미지 선택',
                button: { text: '이미지 삽입' },
                multiple: false,
                library: { type: 'image' },
            } );

            frame.on( 'select', function () {
                var attachment = frame.state().get( 'selection' ).first().toJSON();
                var imageBlock = createBlock( 'core/image', {
                    url: attachment.url,
                    alt: attachment.alt || attachment.title || '',
                    caption: '',
                    id: attachment.id,
                } );

                var clientId = wp.data.select( 'core/block-editor' ).getSelectedBlockClientId();
                var index = wp.data.select( 'core/block-editor' ).getBlockIndex( clientId );
                insertBlock( imageBlock, index + 1 );
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
                    label: '이미지 삽입',
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
