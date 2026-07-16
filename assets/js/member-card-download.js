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

	function hasUnsupportedColorFunction( value ) {
		if ( ! value || 'string' !== typeof value ) {
			return false;
		}

		return /color\(|color-mix\(|oklch\(|lab\(|lch\(/i.test( value );
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

	var resolveColor = createColorResolver();

	function clamp( value, min, max ) {
		return Math.min( max, Math.max( min, value ) );
	}

	function parseRgba( value ) {
		var normalized = resolveColor( value, 'rgba(0, 0, 0, 0)' );
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

	function getNumericStyleValue( style, property, fallback ) {
		var raw = style.getPropertyValue( property ) || '';
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
			var carbonDensity = getNumericStyleValue( window.getComputedStyle( carbonOriginal ), '--adam-card-pattern-density', 1 );
			var carbonSpacing = getNumericStyleValue( window.getComputedStyle( carbonOriginal ), '--adam-card-pattern-spacing', 16 );

			carbonClone.style.backgroundImage =
				'repeating-linear-gradient(45deg, ' +
				mixColors( carbonColor, 'transparent', 0.9 ) + ' 0 ' + ( carbonDensity * 3 ) + 'px, ' +
				'rgba(0, 0, 0, 0.14) ' + ( carbonDensity * 3 ) + 'px ' + ( carbonSpacing / 1.8 ) + 'px)';
		}

		var diagonalOriginal = originalCard.querySelector( '.adam-digital-card__pattern--diagonal' );
		var diagonalClone = clonedCard.querySelector( '.adam-digital-card__pattern--diagonal' );

		if ( diagonalOriginal && diagonalClone ) {
			var diagonalColor = getCustomProperty( diagonalOriginal, '--adam-card-pattern-color', 'rgba(255, 255, 255, 0.18)' );
			var diagonalDensity = getNumericStyleValue( window.getComputedStyle( diagonalOriginal ), '--adam-card-pattern-density', 1 );
			var diagonalSpacing = getNumericStyleValue( window.getComputedStyle( diagonalOriginal ), '--adam-card-pattern-spacing', 16 );

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
				'radial-gradient(circle at 30% 30%, ' + resolveColor( iconHighlight, '#ffffff' ) + ', ' +
				mixColors( iconHighlight, iconBase, 0.18 ) + ' 34%, ' +
				resolveColor( iconBase, '#2f4b3b' ) + ')';

			titleMarkClone.style.boxShadow =
				'inset 0 1px 0 rgba(255, 255, 255, 0.42), 0 0 ' + iconGlow + ' ' +
				mixColors( iconHighlight, 'transparent', 0.55 ) + ', 0 6px 12px rgba(0, 0, 0, 0.22)';
		}
	}

	function copyComputedStyles( source, target, properties ) {
		var computed = window.getComputedStyle( source );

		properties.forEach( function ( property ) {
			var value = computed.getPropertyValue( property );

			if ( value ) {
				target.style.setProperty( property, value );
			}
		} );
	}

	function normalizeCardClone( originalCard, clonedCard, clonedDocument ) {
		var originalNodes = [ originalCard ].concat( Array.prototype.slice.call( originalCard.querySelectorAll( '*' ) ) );
		var clonedNodes = [ clonedCard ].concat( Array.prototype.slice.call( clonedCard.querySelectorAll( '*' ) ) );
		var properties = [
			'color',
			'background',
			'background-color',
			'background-image',
			'background-size',
			'background-position',
			'background-repeat',
			'box-shadow',
			'text-shadow',
			'border-top-color',
			'border-right-color',
			'border-bottom-color',
			'border-left-color',
			'outline-color',
			'fill',
			'stroke',
			'opacity'
		];

		originalNodes.forEach( function ( originalNode, index ) {
			var clonedNode = clonedNodes[ index ];

			if ( ! clonedNode ) {
				return;
			}

			copyComputedStyles( originalNode, clonedNode, properties );

			[ '::before', '::after' ].forEach( function ( pseudo ) {
				var pseudoStyle = window.getComputedStyle( originalNode, pseudo );
				var content = pseudoStyle.getPropertyValue( 'content' );

				if ( ! content || 'none' === content ) {
					return;
				}

				var pseudoProperties = [
					'background',
					'background-color',
					'background-image',
					'box-shadow',
					'border-top-color',
					'border-right-color',
					'border-bottom-color',
					'border-left-color',
					'color',
					'opacity'
				];
				var needsFallback = pseudoProperties.some( function ( property ) {
					return hasUnsupportedColorFunction( pseudoStyle.getPropertyValue( property ) );
				} );

				if ( ! needsFallback ) {
					return;
				}

				var fallback = clonedDocument.createElement( 'span' );
				fallback.setAttribute( 'aria-hidden', 'true' );
				fallback.className = 'adam-card-capture-pseudo-fallback';
				fallback.style.position = 'absolute';
				fallback.style.pointerEvents = 'none';
				fallback.style.inset = pseudoStyle.getPropertyValue( 'inset' ) || 'auto';
				fallback.style.top = pseudoStyle.getPropertyValue( 'top' );
				fallback.style.right = pseudoStyle.getPropertyValue( 'right' );
				fallback.style.bottom = pseudoStyle.getPropertyValue( 'bottom' );
				fallback.style.left = pseudoStyle.getPropertyValue( 'left' );
				fallback.style.width = pseudoStyle.getPropertyValue( 'width' );
				fallback.style.height = pseudoStyle.getPropertyValue( 'height' );
				fallback.style.borderRadius = pseudoStyle.getPropertyValue( 'border-radius' );
				fallback.style.transform = pseudoStyle.getPropertyValue( 'transform' );
				fallback.style.transformOrigin = pseudoStyle.getPropertyValue( 'transform-origin' );
				fallback.style.opacity = pseudoStyle.getPropertyValue( 'opacity' );
				fallback.style.background = pseudoStyle.getPropertyValue( 'background' );
				fallback.style.backgroundImage = pseudoStyle.getPropertyValue( 'background-image' );
				fallback.style.boxShadow = pseudoStyle.getPropertyValue( 'box-shadow' );
				fallback.style.borderTopColor = pseudoStyle.getPropertyValue( 'border-top-color' );
				fallback.style.borderRightColor = pseudoStyle.getPropertyValue( 'border-right-color' );
				fallback.style.borderBottomColor = pseudoStyle.getPropertyValue( 'border-bottom-color' );
				fallback.style.borderLeftColor = pseudoStyle.getPropertyValue( 'border-left-color' );
				fallback.style.borderTopWidth = pseudoStyle.getPropertyValue( 'border-top-width' );
				fallback.style.borderRightWidth = pseudoStyle.getPropertyValue( 'border-right-width' );
				fallback.style.borderBottomWidth = pseudoStyle.getPropertyValue( 'border-bottom-width' );
				fallback.style.borderLeftWidth = pseudoStyle.getPropertyValue( 'border-left-width' );
				fallback.style.borderTopStyle = pseudoStyle.getPropertyValue( 'border-top-style' );
				fallback.style.borderRightStyle = pseudoStyle.getPropertyValue( 'border-right-style' );
				fallback.style.borderBottomStyle = pseudoStyle.getPropertyValue( 'border-bottom-style' );
				fallback.style.borderLeftStyle = pseudoStyle.getPropertyValue( 'border-left-style' );
				fallback.style.zIndex = pseudoStyle.getPropertyValue( 'z-index' );

				if ( 'static' === clonedNode.style.position || ! clonedNode.style.position ) {
					clonedNode.style.position = 'relative';
				}

				if ( '::before' === pseudo ) {
					clonedNode.insertBefore( fallback, clonedNode.firstChild );
				} else {
					clonedNode.appendChild( fallback );
				}
			} );
		} );

		var exactSelectors = [
			'.adam-digital-card__pattern--carbon',
			'.adam-digital-card__pattern--diagonal',
			'.adam-digital-card__title-mark'
		];

		exactSelectors.forEach( function ( selector ) {
			var originalElement = originalCard.querySelector( selector );
			var clonedElement = clonedCard.querySelector( selector );

			if ( originalElement && clonedElement ) {
				copyComputedStyles( originalElement, clonedElement, properties );
			}
		} );

		applyUnsupportedColorFallbacks( originalCard, clonedCard );
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
				imageTimeout: 15000,
				onclone: function ( clonedDocument ) {
					var clonedCard = clonedDocument.querySelector( '.adam-digital-card' );

					if ( clonedCard ) {
						normalizeCardClone( card, clonedCard, clonedDocument );
					}
				}
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
