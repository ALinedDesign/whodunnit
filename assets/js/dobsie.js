/**
 * Dobsie toast + admin handlers.
 *
 * Replaces inline onclick handlers so the plugin doesn't trip malware scanners
 * that pattern-match document.cookie writes from inline JS.
 */
(function () {
	'use strict';

	var COOKIE_NAME = 'dobsie_toast_hidden';

	function setCookie( name, value, days ) {
		var expires = '';
		if ( days ) {
			var d = new Date();
			d.setTime( d.getTime() + ( days * 86400000 ) );
			expires = '; expires=' + d.toUTCString();
		}
		document.cookie = name + '=' + value + expires + '; path=/; SameSite=Lax';
	}

	function clearCookie( name ) {
		document.cookie = name + '=; path=/; expires=Thu, 01 Jan 1970 00:00:01 GMT; SameSite=Lax';
	}

	function bindToast() {
		var toast = document.getElementById( 'dobsie-toast' );
		if ( ! toast ) {
			return;
		}

		var body    = document.getElementById( 'dobsie-toast-body' );
		var minBtn  = toast.querySelector( '[data-dobsie-action="minimize"]' );
		var closeBtn = toast.querySelector( '[data-dobsie-action="close"]' );

		if ( minBtn && body ) {
			minBtn.addEventListener( 'click', function () {
				body.style.display = ( body.style.display === 'none' ) ? 'block' : 'none';
			} );
		}

		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', function () {
				setCookie( COOKIE_NAME, '1', 30 );
				if ( toast.parentNode ) {
					toast.parentNode.removeChild( toast );
				}
			} );
		}
	}

	function bindShowButton() {
		var toggle = document.getElementById( 'dobsie-toggle' );
		if ( ! toggle ) {
			return;
		}
		toggle.addEventListener( 'click', function () {
			clearCookie( COOKIE_NAME );
			window.location.reload();
		} );
	}

	function bindPageTest() {
		var btn    = document.getElementById( 'dobsie-test-btn' );
		var input  = document.getElementById( 'dobsie-test-url' );
		var result = document.getElementById( 'dobsie-result' );
		if ( ! btn || ! input || ! result ) {
			return;
		}

		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var url = input.value;
			result.textContent = 'Testing...';
			var start = Date.now();
			fetch( url, { mode: 'no-cors' } )
				.then( function () {
					var time = Date.now() - start;
					var cls  = time > 3000 ? 'bad' : ( time > 1500 ? 'warn' : 'good' );
					result.textContent = '';
					var b = document.createElement( 'b' );
					b.className   = cls;
					b.textContent = time + 'ms';
					result.appendChild( b );
					result.appendChild( document.createTextNode( ' (client-side, includes network)' ) );
				} )
				.catch( function ( err ) {
					result.textContent = 'Error: ' + err.message;
				} );
		} );
	}

	function bindDebugFilter() {
		var filter = document.getElementById( 'dobsie-filter' );
		if ( ! filter ) {
			return;
		}
		filter.addEventListener( 'keyup', function () {
			var f       = filter.value.toLowerCase();
			var queries = document.querySelectorAll( '.dobsie-query' );
			for ( var i = 0; i < queries.length; i++ ) {
				var sql = queries[ i ].getAttribute( 'data-sql' ) || '';
				queries[ i ].style.display = ( sql.indexOf( f ) !== -1 ) ? 'block' : 'none';
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			bindToast();
			bindShowButton();
			bindPageTest();
			bindDebugFilter();
		} );
	} else {
		bindToast();
		bindShowButton();
		bindPageTest();
		bindDebugFilter();
	}
})();
