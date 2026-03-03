import { registerFormatType, applyFormat } from '@wordpress/rich-text';
import { RichTextToolbarButton } from '@wordpress/block-editor';
import {
	Popover,
	TextControl,
	Button,
	Spinner,
	Notice,
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

const h = createElement;
const config = window.gtLinkManagerEditor || {};
const FORMAT_NAME = 'gt-link-manager/link-inserter';
const FORMAT_TYPE_SETTINGS = {
	title: __( 'GT Link', 'gt-link-manager' ),
	tagName: 'span',
	className: 'gt-link',
};

function LinkInserterEdit( { value, onChange, isActive, activeAttributes } ) {
	const [ isOpen, setIsOpen ] = useState( false );
	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const queryInputRef = useRef( null );
	const anchorRef = useRef( null );

	useEffect( () => {
		if ( ! isOpen ) {
			return;
		}

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
				if ( cancelled ) return;
				setResults( Array.isArray( items ) ? items : [] );
			} )
			.catch( () => {
				if ( cancelled ) return;
				setError(
					__( 'Could not load links.', 'gt-link-manager' )
				);
				setResults( [] );
			} )
			.finally( () => {
				if ( ! cancelled ) setLoading( false );
			} );

		return () => {
			cancelled = true;
		};
	}, [ query, isOpen ] );

	useEffect( () => {
		if ( ! isOpen ) {
			return;
		}

		const input = queryInputRef.current;
		if ( ! input || typeof input.focus !== 'function' ) {
			return;
		}

		try {
			input.focus( { preventScroll: true } );
		} catch {
			input.focus();
		}
	}, [ isOpen ] );

	function stopDefaultEvent( event ) {
		if ( ! event ) {
			return;
		}
		event.preventDefault();
	}

	function insertLink( item ) {
		const rel = ( item.rel || '' )
			.split( ',' )
			.map( ( t ) => t.trim() )
			.filter( Boolean )
			.join( ' ' );

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
		if ( value.end <= value.start ) return '';
		return String( value.text ).slice( value.start, value.end ).trim();
	}

	function togglePopover( event ) {
		stopDefaultEvent( event );

		if ( isOpen ) {
			setIsOpen( false );
			return;
		}

		if ( event && event.currentTarget ) {
			anchorRef.current = event.currentTarget;
		}

		const sel = getSelectedText();
		setQuery( sel || '' );
		setIsOpen( true );
	}

	const popoverContent = h(
		'div',
		{ style: { padding: '12px', minWidth: '300px' } },
		h( TextControl, {
			label: __( 'Search GT Links', 'gt-link-manager' ),
			value: query,
			onChange: setQuery,
			placeholder: __( 'Type a link name or slug', 'gt-link-manager' ),
			ref: queryInputRef,
			__nextHasNoMarginBottom: true,
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
						onClick: () => insertLink( item ),
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
	);

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
			h(
				Popover,
				{
					anchor: anchorRef.current,
					onClose: () => setIsOpen( false ),
					placement: 'bottom-start',
					focusOnMount: false,
				},
				popoverContent
			)
	);
}

registerFormatType( FORMAT_NAME, {
	...FORMAT_TYPE_SETTINGS,
	edit: LinkInserterEdit,
} );
