( function () {
	'use strict';

	var config = window.adamMemberCardDownload || {};
	var messages = config.messages || {};
	var busyButtons = new WeakMap();

	function getMessage( key, fallback ) {
		return messages[ key ] || fallback;
	}

	function isVisible( element ) {
		if ( ! element ) {
			return false;
		}

		var style = window.getComputedStyle( element );
		var rect = element.getBoundingClientRect();

		return 'none' !== style.display && 'hidden' !== style.visibility && rect.width > 0 && rect.height > 0;
	}

	function getCardForButton( button ) {
		var section = button.closest( '.adam-digital-card-section' );

		if ( ! section ) {
			return null;
		}

		var card = section.querySelector( '.adam-digital-card' );

		if ( ! card ) {
			return null;
		}

		return isVisible( card ) ? card : null;
	}

	function getCardImages( card ) {
		return Array.prototype.slice.call( card.querySelectorAll( 'img' ) ).filter( function ( image ) {
			return !! ( image.currentSrc || image.getAttribute( 'src' ) );
		} );
	}

	function isSameOriginUrl( url ) {
		if ( ! url ) {
			return true;
		}

		if ( 0 === url.indexOf( 'data:' ) || 0 === url.indexOf( 'blob:' ) ) {
			return true;
		}

		try {
			return new URL( url, window.location.href ).origin === window.location.origin;
		} catch ( error ) {
			return false;
		}
	}

	function waitForImage( image ) {
		return new Promise( function ( resolve, reject ) {
			var src = image.currentSrc || image.src || image.getAttribute( 'src' ) || '';

			if ( ! isSameOriginUrl( src ) ) {
				reject( new Error( 'cross-origin-image:' + src ) );
				return;
			}

			if ( image.complete ) {
				if ( image.naturalWidth > 0 || 0 === src.indexOf( 'data:' ) ) {
					resolve();
					return;
				}

				reject( new Error( 'image-load-failed:' + src ) );
				return;
			}

			function cleanup() {
				image.removeEventListener( 'load', onLoad );
				image.removeEventListener( 'error', onError );
			}

			function onLoad() {
				cleanup();
				resolve();
			}

			function onError() {
				cleanup();
				reject( new Error( 'image-load-failed:' + src ) );
			}

			image.addEventListener( 'load', onLoad, { once: true } );
			image.addEventListener( 'error', onError, { once: true } );
		} );
	}

	function canvasToBlob( canvas ) {
		return new Promise( function ( resolve, reject ) {
			if ( ! canvas || 'function' !== typeof canvas.toBlob ) {
				reject( new Error( 'canvas-toBlob-unavailable' ) );
				return;
			}

			canvas.toBlob( function ( blob ) {
				if ( ! blob ) {
					reject( new Error( 'canvas-blob-empty' ) );
					return;
				}

				resolve( blob );
			}, 'image/png' );
		} );
	}

	function downloadBlob( blob, filename ) {
		var blobUrl = window.URL.createObjectURL( blob );
		var link = document.createElement( 'a' );

		link.href = blobUrl;
		link.download = filename;
		document.body.appendChild( link );
		link.click();
		link.remove();

		window.setTimeout( function () {
			window.URL.revokeObjectURL( blobUrl );
		}, 1000 );
	}

	function setBusyState( buttons, busy ) {
		buttons.forEach( function ( button ) {
			if ( busy ) {
				if ( ! busyButtons.has( button ) ) {
					busyButtons.set( button, button.textContent );
				}

				button.setAttribute( 'aria-disabled', 'true' );
				button.dataset.adamCardBusy = '1';
				button.style.pointerEvents = 'none';
				button.textContent = getMessage( 'preparing', 'A preparar PNG...' );
				return;
			}

			button.removeAttribute( 'aria-disabled' );
			button.dataset.adamCardBusy = '0';
			button.style.pointerEvents = '';
			button.textContent = busyButtons.get( button ) || getMessage( 'defaultLabel', 'Descarregar cartão PNG' );
			busyButtons.delete( button );
		} );
	}

	async function handleDownload( event ) {
		event.preventDefault();

		var button = event.currentTarget;
		var section = button.closest( '.adam-digital-card-section' );
		var allButtons = section ? Array.prototype.slice.call( section.querySelectorAll( '[data-adam-card-download=\"png\"]' ) ) : [ button ];

		if ( '1' === button.dataset.adamCardBusy ) {
			return;
		}

		setBusyState( allButtons, true );

		try {
			if ( 'function' !== typeof window.html2canvas ) {
				throw new Error( getMessage( 'errorNoLibrary', 'A biblioteca de captura não está disponível.' ) );
			}

			var card = getCardForButton( button );

			if ( ! card ) {
				throw new Error( getMessage( 'errorHiddenCard', 'O cartão digital tem de estar visível para descarregar o PNG.' ) );
			}

			if ( document.fonts && document.fonts.ready ) {
				await document.fonts.ready;
			}

			var images = getCardImages( card );
			await Promise.all( images.map( waitForImage ) );

			var canvas = await window.html2canvas( card, {
				scale: 3,
				backgroundColor: null,
				useCORS: true,
				allowTaint: false,
				logging: false,
				imageTimeout: 15000
			} );

			var blob = await canvasToBlob( canvas );
			var filename = button.getAttribute( 'data-adam-card-filename' ) || 'cartao-adam.png';

			downloadBlob( blob, filename );
		} catch ( error ) {
			window.console.error( 'ADAM card PNG download failed:', error );
			window.alert( error && error.message ? error.message : getMessage( 'errorCapture', 'Não foi possível gerar o PNG do cartão.' ) );
		} finally {
			setBusyState( allButtons, false );
		}
	}

	function init() {
		var buttons = document.querySelectorAll( '[data-adam-card-download="png"]' );

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', handleDownload );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
