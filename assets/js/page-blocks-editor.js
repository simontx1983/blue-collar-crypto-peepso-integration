/**
 * BCC Page Blocks – Editor Script
 *
 * Provides ServerSideRender previews for all dynamic BCC Page blocks.
 * Each block is server-rendered; this script simply registers the
 * editor-side wrapper so the block appears in the inserter and
 * renders its PHP output inside the editor.
 */
( function ( wp ) {
    var el                 = wp.element.createElement;
    var registerBlockType  = wp.blocks.registerBlockType;
    var ServerSideRender   = wp.serverSideRender;
    var InspectorControls  = wp.blockEditor.InspectorControls;
    var PanelBody          = wp.components.PanelBody;
    var TextControl        = wp.components.TextControl;
    var ToggleControl      = wp.components.ToggleControl;

    /* --------------------------------------------------------
       Shared helper – builds an edit() function that uses SSR
    -------------------------------------------------------- */
    function ssrEdit( blockName, extraControls ) {
        return function ( props ) {
            var children = [
                el( ServerSideRender, {
                    block: blockName,
                    attributes: props.attributes,
                } ),
            ];

            if ( extraControls ) {
                children.unshift(
                    el(
                        InspectorControls,
                        {},
                        el( PanelBody, { title: 'Settings', initialOpen: true },
                            extraControls( props )
                        )
                    )
                );
            }

            return el( 'div', { ...wp.blockEditor.useBlockProps() }, children );
        };
    }

    /* --------------------------------------------------------
       bcc/page-header
    -------------------------------------------------------- */
    registerBlockType( 'bcc/page-header', {
        edit: ssrEdit( 'bcc/page-header', function ( props ) {
            return [
                el( TextControl, {
                    label: 'Page ID',
                    type: 'number',
                    value: props.attributes.pageId,
                    onChange: function ( val ) {
                        props.setAttributes( { pageId: parseInt( val, 10 ) || 0 } );
                    },
                } ),
                el( ToggleControl, {
                    label: 'Show Trust Score',
                    checked: props.attributes.showTrustScore,
                    onChange: function ( val ) {
                        props.setAttributes( { showTrustScore: val } );
                    },
                } ),
                el( ToggleControl, {
                    label: 'Show Followers',
                    checked: props.attributes.showFollowers,
                    onChange: function ( val ) {
                        props.setAttributes( { showFollowers: val } );
                    },
                } ),
            ];
        } ),
        save: function () { return null; },
    } );

    /* --------------------------------------------------------
       bcc/page-tabs
    -------------------------------------------------------- */
    registerBlockType( 'bcc/page-tabs', {
        edit: ssrEdit( 'bcc/page-tabs' ),
        save: function () { return null; },
    } );

    /* --------------------------------------------------------
       bcc/tab-about
    -------------------------------------------------------- */
    registerBlockType( 'bcc/tab-about', {
        edit: ssrEdit( 'bcc/tab-about' ),
        save: function () { return null; },
    } );

    /* --------------------------------------------------------
       bcc/tab-builder
    -------------------------------------------------------- */
    registerBlockType( 'bcc/tab-builder', {
        edit: ssrEdit( 'bcc/tab-builder' ),
        save: function () { return null; },
    } );

    /* --------------------------------------------------------
       bcc/tab-validator
    -------------------------------------------------------- */
    registerBlockType( 'bcc/tab-validator', {
        edit: ssrEdit( 'bcc/tab-validator' ),
        save: function () { return null; },
    } );

    /* --------------------------------------------------------
       bcc/tab-nft
    -------------------------------------------------------- */
    registerBlockType( 'bcc/tab-nft', {
        edit: ssrEdit( 'bcc/tab-nft' ),
        save: function () { return null; },
    } );

    /* --------------------------------------------------------
       bcc/tab-dao
    -------------------------------------------------------- */
    registerBlockType( 'bcc/tab-dao', {
        edit: ssrEdit( 'bcc/tab-dao' ),
        save: function () { return null; },
    } );

} )( window.wp );
