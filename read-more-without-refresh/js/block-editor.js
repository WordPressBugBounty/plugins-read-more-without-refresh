/**
 * Read More Without Refresh Pro - Block Editor (v5.0)
 *
 * Registers:
 *  - rmwr/read-more: the new InnerBlocks block. The hidden area is a full
 *    block canvas - hide images, galleries, columns, embeds, anything.
 *  - the legacy v4 RichText block stays registered (deprecated, hidden from
 *    the inserter) so existing posts keep working.
 *
 * @package ReadMoreWithoutRefreshPro
 * @version 5.0.0
 */

(function () {
    'use strict';

    var registerBlockType = wp.blocks.registerBlockType;
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var InnerBlocks = wp.blockEditor.InnerBlocks;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var RichText = wp.blockEditor.RichText;
    var PanelBody = wp.components.PanelBody;
    var TextControl = wp.components.TextControl;
    var SelectControl = wp.components.SelectControl;
    var __ = wp.i18n.__;

    var blockData = window.rmwrBlockData || {};

    var animationOptions = [
        { label: __('Global default', 'rmwr'), value: '' },
        { label: __('None', 'rmwr'), value: 'none' },
        { label: __('Fade', 'rmwr'), value: 'fade' },
        { label: __('Slide', 'rmwr'), value: 'slide' },
        { label: __('Flip', 'rmwr'), value: 'flip' },
        { label: __('Zoom', 'rmwr'), value: 'zoom' },
        { label: __('Bounce', 'rmwr'), value: 'bounce' },
        { label: __('Rotate', 'rmwr'), value: 'rotate' },
        { label: __('Scale', 'rmwr'), value: 'scale' },
        { label: __('Elastic', 'rmwr'), value: 'elastic' }
    ];

    function inspector(attributes, setAttributes) {
        return el(InspectorControls, {},
            el(PanelBody, { title: __('Read More Settings', 'rmwr'), initialOpen: true },
                el(TextControl, {
                    label: __('Read More text', 'rmwr'),
                    value: attributes.openText,
                    placeholder: blockData.defaultOpen || 'Read More',
                    onChange: function (value) { setAttributes({ openText: value }); }
                }),
                el(TextControl, {
                    label: __('Read Less text', 'rmwr'),
                    value: attributes.closeText,
                    placeholder: blockData.defaultClose || 'Read Less',
                    onChange: function (value) { setAttributes({ closeText: value }); }
                }),
                el(SelectControl, {
                    label: __('Mode', 'rmwr'),
                    value: attributes.mode,
                    options: [
                        { label: __('Normal toggle', 'rmwr'), value: 'normal' },
                        { label: __('Accordion', 'rmwr'), value: 'accordion' }
                    ],
                    onChange: function (value) { setAttributes({ mode: value }); }
                }),
                attributes.mode === 'accordion' && el(TextControl, {
                    label: __('Accordion group', 'rmwr'),
                    value: attributes.accordionId,
                    help: __('Items sharing a group close each other.', 'rmwr'),
                    onChange: function (value) { setAttributes({ accordionId: value }); }
                }),
                attributes.mode === 'accordion' && el(TextControl, {
                    label: __('FAQ question (schema)', 'rmwr'),
                    value: attributes.question,
                    help: __('Adds this Q&A to the page FAQPage schema.', 'rmwr'),
                    onChange: function (value) { setAttributes({ question: value }); }
                }),
                el(SelectControl, {
                    label: __('Animation', 'rmwr'),
                    value: attributes.animation,
                    options: animationOptions,
                    onChange: function (value) { setAttributes({ animation: value }); }
                }),
                el(TextControl, {
                    label: __('Animation duration (ms)', 'rmwr'),
                    type: 'number',
                    value: attributes.animationDuration || '',
                    onChange: function (value) { setAttributes({ animationDuration: parseInt(value, 10) || 0 }); }
                }),
                el(TextControl, {
                    label: __('Instance ID (analytics)', 'rmwr'),
                    value: attributes.instanceId,
                    help: __('Optional: identify this block in the Analytics dashboard.', 'rmwr'),
                    onChange: function (value) { setAttributes({ instanceId: value }); }
                }),
                el(TextControl, {
                    label: __('Custom CSS class', 'rmwr'),
                    value: attributes.customClass,
                    onChange: function (value) { setAttributes({ customClass: value }); }
                })
            )
        );
    }

    /* -------------------------------------------------------------------
     * v5 block: InnerBlocks
     * ---------------------------------------------------------------- */

    registerBlockType('rmwr/read-more', {
        title: __('Read More Without Refresh', 'rmwr'),
        icon: 'editor-expand',
        category: 'text',
        description: __('Hide any blocks behind an SEO-friendly Read More toggle.', 'rmwr'),
        keywords: [__('read more', 'rmwr'), __('toggle', 'rmwr'), __('accordion', 'rmwr')],
        attributes: {
            instanceId: { type: 'string', default: '' },
            openText: { type: 'string', default: '' },
            closeText: { type: 'string', default: '' },
            animation: { type: 'string', default: '' },
            animationDuration: { type: 'number', default: 0 },
            customClass: { type: 'string', default: '' },
            mode: { type: 'string', default: 'normal' },
            accordionId: { type: 'string', default: '' },
            question: { type: 'string', default: '' },
            template: { type: 'string', default: '' },
            icon: { type: 'string', default: '' }
        },

        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var blockProps = useBlockProps({ className: 'rmwr-block-editor' });

            return el(Fragment, {},
                inspector(attributes, setAttributes),
                el('div', blockProps,
                    el('div', { className: 'rmwr-block-editor-header' },
                        el('span', { className: 'dashicons dashicons-editor-expand' }),
                        el('strong', {}, ' ' + (attributes.openText || blockData.defaultOpen || 'Read More')),
                        el('span', { className: 'rmwr-block-editor-hint' },
                            ' — ' + __('hidden until the visitor clicks', 'rmwr'))
                    ),
                    el('div', { className: 'rmwr-block-editor-canvas' },
                        el(InnerBlocks, {
                            templateLock: false,
                            renderAppender: InnerBlocks.ButtonBlockAppender
                        })
                    )
                )
            );
        },

        save: function () {
            // Dynamic block: PHP wraps the inner content at render time.
            return el(InnerBlocks.Content);
        }
    });

    /* -------------------------------------------------------------------
     * Legacy v4 block (kept for existing posts; hidden from inserter)
     * ---------------------------------------------------------------- */

    registerBlockType('read-more-without-refresh-pro/read-more-block', {
        title: __('Read More (legacy)', 'rmwr'),
        icon: 'editor-expand',
        category: 'text',
        supports: { inserter: false },
        attributes: {
            instanceId: { type: 'string', default: '' },
            openText: { type: 'string', default: '' },
            closeText: { type: 'string', default: '' },
            content: { type: 'string', default: '' },
            animation: { type: 'string', default: '' },
            animationDuration: { type: 'number', default: 0 },
            customClass: { type: 'string', default: '' },
            mode: { type: 'string', default: 'normal' },
            accordionId: { type: 'string', default: '' },
            question: { type: 'string', default: '' },
            template: { type: 'string', default: '' },
            icon: { type: 'string', default: '' }
        },

        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var blockProps = useBlockProps({ className: 'rmwr-block-editor' });

            return el(Fragment, {},
                inspector(attributes, setAttributes),
                el('div', blockProps,
                    el('div', { className: 'rmwr-block-editor-header' },
                        el('strong', {}, __('Read More (legacy block)', 'rmwr'))
                    ),
                    el(RichText, {
                        tagName: 'div',
                        className: 'read-content',
                        value: attributes.content,
                        placeholder: __('Hidden content goes here...', 'rmwr'),
                        onChange: function (value) { setAttributes({ content: value }); }
                    })
                )
            );
        },

        save: function () {
            return null;
        }
    });
})();
