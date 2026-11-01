/**
 * Reports short link clicks to analytics tools that only have a browser API.
 *
 * Loaded only when a site owner has enabled one of those tools. It never stops
 * or delays the click, and it only recognises links under the link prefix.
 */
( function () {
	var config = window.gtlmIntegrations;
	if ( ! config || ! config.tools || ! config.tools.length ) {
		return;
	}

	var senders = {
		ga4: function ( link ) {
			// MonsterInsights and ExactMetrics expose their tag as __gtagTracker.
			var tag = window.gtag || window.__gtagTracker;
			if ( 'function' === typeof tag ) {
				tag( 'event', 'link_click', {
					link_slug: link.slug,
					link_url: link.href,
					outbound: true,
					transport_type: 'beacon',
				} );
			}
		},
		gtm: function ( link ) {
			var layer = window[ config.dataLayer ];
			if ( layer && 'function' === typeof layer.push ) {
				layer.push( {
					event: 'gtlm_link_click',
					gtlm_link_slug: link.slug,
					gtlm_link_url: link.href,
				} );
			}
		},
		fathom: function ( link ) {
			if ( window.fathom && 'function' === typeof window.fathom.trackEvent ) {
				window.fathom.trackEvent( 'Link Click: ' + link.slug );
			}
		},
		clarity: function ( link ) {
			if ( 'function' === typeof window.clarity ) {
				window.clarity( 'set', 'gtlm_link', link.slug );
				window.clarity( 'event', 'link_click' );
			}
		},
	};

	function shortLink( anchor ) {
		var url;
		try {
			url = new URL( anchor.href, window.location.href );
		} catch ( error ) {
			return null;
		}

		if ( url.host !== window.location.host || 0 !== url.pathname.indexOf( config.base ) ) {
			return null;
		}

		var slug = url.pathname.slice( config.base.length ).replace( /\/$/, '' );
		if ( ! slug || -1 !== slug.indexOf( '/' ) ) {
			return null;
		}

		return { slug: slug, href: url.origin + url.pathname };
	}

	function onClick( event ) {
		// auxclick also fires for the right button, which only opens a menu.
		if ( 'auxclick' === event.type && 1 !== event.button ) {
			return;
		}

		var anchor = event.target && event.target.closest ? event.target.closest( 'a[href]' ) : null;
		var link = anchor ? shortLink( anchor ) : null;
		if ( ! link ) {
			return;
		}

		config.tools.forEach( function ( tool ) {
			if ( ! senders[ tool ] ) {
				return;
			}
			try {
				senders[ tool ]( link );
			} catch ( error ) {
				// One analytics tool failing must never affect the click.
			}
		} );
	}

	document.addEventListener( 'click', onClick, true );
	document.addEventListener( 'auxclick', onClick, true );
}() );
