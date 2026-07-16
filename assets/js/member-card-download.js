( function () {
	'use strict';

	var config = window.adamMemberCardDownload || {};
	var messages = config.messages || {};
	var busyButtons = new WeakMap();
	var colorResolver = createColorResolver();

	function getMessage( key, fallback ) {
		return messages[ key ] || fallback;
	}

	function createColorResolver() {
		var resolver = document.createElement( 'span' );

		resolver.style.display = 'none';
		document.body.appendChild( resolver );

		return function resolveColor( value, fallback ) {
			if ( ! value || 'string' !== typeof value ) {
				return fallback || 'rgba(0, 0, 0, 0)';
			}

			resolver.style.color = '';
			resolver.style.color = value.trim();

			if ( ! resolver.style.color ) {
				return fallback || 'rgba(0, 0, 0, 0)';
			}

			return window.getComputedStyle( resolver ).color || fallback || 'rgba(0, 0, 0, 0)';
		};
	}

	function clamp( value, min, max ) {
		return Math.min( max, Math.max( min, value ) );
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

	function parseRgba( value ) {
		var normalized = colorResolver( value, 'rgba(0, 0, 0, 0)' );
		var match = normalized.match( /rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s/]+([\d.]+))?\s*\)/i );

		if ( ! match ) {
			return {
				r: 0,
				g: 0,
				b: 0,
				a: 0
			};
		}

		return {
			r: parseFloat( match[ 1 ] ),
			g: parseFloat( match[ 2 ] ),
			b: parseFloat( match[ 3 ] ),
			a: 'undefined' === typeof match[ 4 ] ? 1 : parseFloat( match[ 4 ] )
		};
	}

	function toRgbaString( color ) {
		return 'rgba(' + Math.round( color.r ) + ', ' + Math.round( color.g ) + ', ' + Math.round( color.b ) + ', ' + clamp( color.a, 0, 1 ) + ')';
	}

	function mixColors( colorA, colorB, weightA ) {
		var a = parseRgba( colorA );
		var b = parseRgba( colorB );
		var ratioA = clamp( weightA, 0, 1 );
		var ratioB = 1 - ratioA;

		return toRgbaString( {
			r: ( a.r * ratioA ) + ( b.r * ratioB ),
			g: ( a.g * ratioA ) + ( b.g * ratioB ),
			b: ( a.b * ratioA ) + ( b.b * ratioB ),
			a: ( a.a * ratioA ) + ( b.a * ratioB )
		} );
	}

	function getNumericCustomProperty( element, property, fallback ) {
		var raw = window.getComputedStyle( element ).getPropertyValue( property ) || '';
		var value = parseFloat( raw );

		return Number.isFinite( value ) ? value : fallback;
	}

	function getCustomProperty( element, property, fallback ) {
		var value = window.getComputedStyle( element ).getPropertyValue( property ).trim();

		return value || fallback;
	}

	function applyUnsupportedColorFallbacks( originalCard, clonedCard ) {
		var carbonOriginal = originalCard.querySelector( '.adam-digital-card__pattern--carbon' );
		var carbonClone = clonedCard.querySelector( '.adam-digital-card__pattern--carbon' );

		if ( carbonOriginal && carbonClone ) {
			var carbonColor = getCustomProperty( carbonOriginal, '--adam-card-pattern-color', 'rgba(255, 255, 255, 0.18)' );
			var carbonDensity = getNumericCustomProperty( carbonOriginal, '--adam-card-pattern-density', 1 );
			var carbonSpacing = getNumericCustomProperty( carbonOriginal, '--adam-card-pattern-spacing', 16 );

			carbonClone.style.backgroundImage =
				'repeating-linear-gradient(45deg, ' +
				mixColors( carbonColor, 'transparent', 0.9 ) + ' 0 ' + ( carbonDensity * 3 ) + 'px, ' +
				'rgba(0, 0, 0, 0.14) ' + ( carbonDensity * 3 ) + 'px ' + ( carbonSpacing / 1.8 ) + 'px)';
		}

		var diagonalOriginal = originalCard.querySelector( '.adam-digital-card__pattern--diagonal' );
		var diagonalClone = clonedCard.querySelector( '.adam-digital-card__pattern--diagonal' );

		if ( diagonalOriginal && diagonalClone ) {
			var diagonalColor = getCustomProperty( diagonalOriginal, '--adam-card-pattern-color', 'rgba(255, 255, 255, 0.18)' );
			var diagonalDensity = getNumericCustomProperty( diagonalOriginal, '--adam-card-pattern-density', 1 );
			var diagonalSpacing = getNumericCustomProperty( diagonalOriginal, '--adam-card-pattern-spacing', 16 );

			diagonalClone.style.backgroundImage =
				'repeating-linear-gradient(135deg, ' +
				mixColors( diagonalColor, 'transparent', 0.8 ) + ' 0 ' + ( diagonalDensity * 4 ) + 'px, ' +
				'transparent ' + ( diagonalDensity * 4 ) + 'px ' + diagonalSpacing + 'px)';
		}

		var titleMarkOriginal = originalCard.querySelector( '.adam-digital-card__title-mark' );
		var titleMarkClone = clonedCard.querySelector( '.adam-digital-card__title-mark' );

		if ( titleMarkOriginal && titleMarkClone ) {
			var iconHighlight = getCustomProperty( titleMarkOriginal, '--adam-title-badge-icon-highlight', '#ffffff' );
			var iconBase = getCustomProperty( titleMarkOriginal, '--adam-title-badge-icon', '#2f4b3b' );
			var iconGlow = getCustomProperty( titleMarkOriginal, '--adam-title-badge-icon-glow', '0px' );

			titleMarkClone.style.background =
				'radial-gradient(circle at 30% 30%, ' + colorResolver( iconHighlight, '#ffffff' ) + ', ' +
				mixColors( iconHighlight, iconBase, 0.18 ) + ' 34%, ' +
				colorResolver( iconBase, '#2f4b3b' ) + ')';

			titleMarkClone.style.boxShadow =
				'inset 0 1px 0 rgba(255, 255, 255, 0.42), 0 0 ' + iconGlow + ' ' +
				mixColors( iconHighlight, 'transparent', 0.55 ) + ', 0 6px 12px rgba(0, 0, 0, 0.22)';
		}
	}

	function logCloneDiagnostics( clonedCard ) {
		var diagnostics = {
			rootClass: clonedCard.className,
			childCount: clonedCard.querySelectorAll( '*' ).length,
			hasLogo: !! clonedCard.querySelector( '.adam-digital-card__logo' ),
			hasPhoto: !! clonedCard.querySelector( '.adam-digital-card__photo' ),
			hasMemberName: !! clonedCard.querySelector( '.adam-digital-card__identity strong' ),
			hasQr: !! clonedCard.querySelector( '.adam-digital-card__qr img' ),
			hasTitleBadge: !! clonedCard.querySelector( '[data-adam-card-title]' ),
			hasDetails: !! clonedCard.querySelector( '.adam-digital-card__details' ),
			hasFooter: !! clonedCard.querySelector( '.adam-digital-card__footer' ),
			hasFrame: !! clonedCard.querySelector( '.adam-digital-card__frame' )
		};

		window.console.log( 'ADAM card capture clone diagnostics:', diagnostics );

		if ( ! diagnostics.hasLogo || ! diagnostics.hasPhoto || ! diagnostics.hasMemberName || ! diagnostics.hasQr || ! diagnostics.hasTitleBadge || ! diagnostics.hasDetails || ! diagnostics.hasFooter || ! diagnostics.hasFrame ) {
			throw new Error( 'A cópia temporária do cartão não contém todos os elementos obrigatórios.' );
		}
	}

	function createCaptureClone( card ) {
		var rect = card.getBoundingClientRect();
		var host = document.createElement( 'div' );
		var clone = card.cloneNode( true );

		host.className = 'adam-card-capture-host';
		host.style.position = 'fixed';
		host.style.left = '-10000px';
		host.style.top = '0';
		host.style.margin = '0';
		host.style.padding = '0';
		host.style.width = rect.width + 'px';
		host.style.height = rect.height + 'px';
		host.style.overflow = 'visible';
		host.style.pointerEvents = 'none';
		host.style.visibility = 'visible';
		host.style.opacity = '1';
		host.style.background = 'transparent';
		host.style.zIndex = '-1';

		clone.style.width = rect.width + 'px';
		clone.style.minWidth = rect.width + 'px';
		clone.style.maxWidth = rect.width + 'px';
		clone.style.height = rect.height + 'px';

		host.appendChild( clone );
		document.body.appendChild( host );

		applyUnsupportedColorFallbacks( card, clone );
		logCloneDiagnostics( clone );

		return {
			host: host,
			clone: clone
		};
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
		var allButtons = section ? Array.prototype.slice.call( section.querySelectorAll( '[data-adam-card-download="png"]' ) ) : [ button ];
		var cloneState = null;

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

			window.console.log( 'ADAM card capture root:', card );
			window.console.log( 'ADAM card capture root bounds:', card.getBoundingClientRect() );

			if ( document.fonts && document.fonts.ready ) {
				await document.fonts.ready;
			}

			await Promise.all( getCardImages( card ).map( waitForImage ) );

			cloneState = createCaptureClone( card );
			await Promise.all( getCardImages( cloneState.clone ).map( waitForImage ) );

			var canvas = await window.html2canvas( cloneState.clone, {
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
			if ( cloneState && cloneState.host && cloneState.host.parentNode ) {
				cloneState.host.parentNode.removeChild( cloneState.host );
			}

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
