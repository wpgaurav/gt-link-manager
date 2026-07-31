import {
	registerFormatType,
	applyFormat,
	useAnchor,
} from '@wordpress/rich-text';
import { BlockControls, RichTextToolbarButton } from '@wordpress/block-editor';
import {
	Popover,
	TextControl,
	Button,
	Spinner,
	Notice,
	ToolbarButton,
} from '@wordpress/components';
import {
	createElement,
	Fragment,
	useState,
	useEffect,
	useRef,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { addFilter } from '@wordpress/hooks';

const h = createElement;
const config = window.gtLinkManagerEditor || {};
const FORMAT_NAME = 'gt-link-manager/link-inserter';
const FORMAT_TYPE_SETTINGS = {
	title: __( 'GT Link', 'gt-link-manager' ),
	tagName: 'span',
	className: 'gtlm-link',
};

function stopDefaultEvent( event ) {
	if ( ! event ) {
		return;
	}
	event.preventDefault();
}

function normalizeRel( rel ) {
	return [
		...new Set(
			String( rel || '' )
				.split( /[\s,]+/ )
				.map( ( token ) => token.trim() )
				.filter( Boolean )
		),
	].join( ' ' );
}

function LinkSearchPopover( { anchor, initialQuery = '', onClose, onSelect } ) {
	const [ query, setQuery ] = useState( initialQuery );
	const [ results, setResults ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const queryInputRef = useRef( null );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setError( '' );

		apiFetch( {
			path:
				config.restPath +
				'?search=' +
				encodeURIComponent( query ) +
				'&per_page=20',
		} )
			.then( ( items ) => {
				if ( cancelled ) {
					return;
				}
				setResults( Array.isArray( items ) ? items : [] );
			} )
			.catch( () => {
				if ( cancelled ) {
					return;
				}
				setError( __( 'Could not load links.', 'gt-link-manager' ) );
				setResults( [] );
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ query ] );

	useEffect( () => {
		const input = queryInputRef.current;
		if ( ! input || typeof input.focus !== 'function' ) {
			return;
		}

		try {
			input.focus( { preventScroll: true } );
		} catch {
			input.focus();
		}
	}, [] );

	return h(
		Popover,
		{
			anchor,
			onClose,
			placement: 'bottom-start',
			focusOnMount: false,
		},
		h(
			'div',
			{ style: { padding: '12px', minWidth: '300px' } },
			h( TextControl, {
				label: __( 'Search GT Links', 'gt-link-manager' ),
				value: query,
				onChange: setQuery,
				placeholder: __(
					'Type a link name or slug',
					'gt-link-manager'
				),
				ref: queryInputRef,
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			} ),
			loading && h( Spinner, null ),
			error &&
				h( Notice, { status: 'error', isDismissible: false }, error ),
			! loading &&
				! error &&
				results.map( ( item ) =>
					h(
						Button,
						{
							key: item.id,
							variant: 'secondary',
							onClick: () => onSelect( item ),
							style: {
								display: 'block',
								width: '100%',
								marginBottom: '6px',
								textAlign: 'left',
							},
						},
						item.name + ' (' + item.slug + ')'
					)
				),
			! loading &&
				! error &&
				! results.length &&
				h( 'p', null, __( 'No links found.', 'gt-link-manager' ) )
		)
	);
}

function LinkInserterEdit( {
	value,
	onChange,
	isActive,
	activeAttributes,
	contentRef,
} ) {
	const popoverAnchor = useAnchor( {
		editableContentElement: contentRef?.current,
		settings: FORMAT_TYPE_SETTINGS,
	} );
	const [ isOpen, setIsOpen ] = useState( false );
	const [ query, setQuery ] = useState( '' );

	function insertLink( item ) {
		const rel = normalizeRel( item.rel );

		onChange(
			applyFormat( value, {
				type: 'core/link',
				attributes: {
					url: item.url,
					rel,
				},
			} )
		);
		setIsOpen( false );
	}

	function getSelectedText() {
		if (
			! value ||
			typeof value.start !== 'number' ||
			typeof value.end !== 'number' ||
			! value.text
		) {
			return '';
		}
		if ( value.end <= value.start ) {
			return '';
		}
		return String( value.text ).slice( value.start, value.end ).trim();
	}

	function togglePopover( event ) {
		stopDefaultEvent( event );

		if ( isOpen ) {
			setIsOpen( false );
			return;
		}

		const sel = getSelectedText();
		setQuery( sel || '' );
		setIsOpen( true );
	}

	return h(
		Fragment,
		null,
		h( RichTextToolbarButton, {
			icon: 'admin-links',
			title: __( 'GT Link', 'gt-link-manager' ),
			onClick: togglePopover,
			onMouseDown: stopDefaultEvent,
			isActive:
				isActive || !! ( activeAttributes && activeAttributes.url ),
		} ),
		isOpen &&
			h( LinkSearchPopover, {
				anchor: popoverAnchor,
				initialQuery: query,
				onClose: () => setIsOpen( false ),
				onSelect: insertLink,
			} )
	);
}

function ButtonBlockLinkInserter( { attributes, setAttributes } ) {
	const [ isOpen, setIsOpen ] = useState( false );
	const popoverAnchorRef = useRef( null );

	function togglePopover( event ) {
		stopDefaultEvent( event );

		if ( isOpen ) {
			setIsOpen( false );
			return;
		}

		if ( event && event.currentTarget ) {
			popoverAnchorRef.current = event.currentTarget;
		}
		setIsOpen( true );
	}

	function insertLink( item ) {
		const newTabRel = '_blank' === attributes.linkTarget ? 'noopener' : '';
		const rel = normalizeRel( ( item.rel || '' ) + ' ' + newTabRel );

		setAttributes( {
			url: item.url,
			rel: rel || undefined,
		} );
		setIsOpen( false );
	}

	return h(
		Fragment,
		null,
		h(
			BlockControls,
			{ group: 'block' },
			h( ToolbarButton, {
				icon: 'admin-links',
				title: __( 'GT Link', 'gt-link-manager' ),
				onClick: togglePopover,
				onMouseDown: stopDefaultEvent,
				isActive: !! attributes.url,
			} )
		),
		isOpen &&
			h( LinkSearchPopover, {
				anchor: popoverAnchorRef.current,
				onClose: () => setIsOpen( false ),
				onSelect: insertLink,
			} )
	);
}

function withCoreButtonLinkInserter( BlockEdit ) {
	return function CoreButtonLinkInserter( props ) {
		if (
			'core/button' !== props.name ||
			'button' === props.attributes?.tagName
		) {
			return h( BlockEdit, props );
		}

		return h(
			Fragment,
			null,
			h( BlockEdit, props ),
			props.isSelected &&
				h( ButtonBlockLinkInserter, {
					attributes: props.attributes,
					setAttributes: props.setAttributes,
				} )
		);
	};
}

registerFormatType( FORMAT_NAME, {
	...FORMAT_TYPE_SETTINGS,
	edit: LinkInserterEdit,
} );

addFilter(
	'editor.BlockEdit',
	'gt-link-manager/core-button-link-inserter',
	withCoreButtonLinkInserter
);
