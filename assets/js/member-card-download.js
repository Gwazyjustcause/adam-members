( function () {
	'use strict';

	var config = window.adamMemberCardDownload || {};
	var messages = config.messages || {};
	var busyButtons = new WeakMap();

	function initCardScaling() {
		document.querySelectorAll( '.adam-digital-card-viewport' ).forEach( function ( viewport ) {
			var card = viewport.querySelector( '.adam-digital-card' );
			if ( ! card ) {
				return;
			}

			function fitCard() {
				var scale = Math.min( 1, viewport.clientWidth / card.offsetWidth );
				if ( scale <= 0 || ! Number.isFinite( scale ) ) {
					return;
				}
				card.style.transform = 'scale(' + scale + ')';
				viewport.style.height = ( card.offsetHeight * scale ) + 'px';
				viewport.classList.add( 'is-scaled' );
			}

			fitCard();
			if ( 'function' === typeof window.ResizeObserver ) {
				var observer = new ResizeObserver( fitCard );
				observer.observe( viewport );
				observer.observe( card );
			} else {
				window.addEventListener( 'resize', fitCard );
			}
			if ( document.fonts && document.fonts.ready ) {
				document.fonts.ready.then( fitCard );
			}
		} );
	}

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

	async function fittedImagesForCapture( card ) {
		// html2canvas stretches replaced images instead of applying object-fit.
		// Bake the browser's crop into a same-ratio bitmap for the clone only.
		return Promise.all( Array.prototype.map.call( card.querySelectorAll( 'img' ), async function ( image ) {
			var style = window.getComputedStyle( image );
			var width = parseFloat( style.width );
			var height = parseFloat( style.height );
			if ( ! image.naturalWidth || ! image.naturalHeight || ! ( width > 0 && height > 0 ) ||
				( 'cover' !== style.objectFit && 'contain' !== style.objectFit ) ) {
				return null;
			}
			if ( 'function' === typeof image.decode ) {
				await image.decode();
			}

			var ratio = ( 'cover' === style.objectFit ? Math.max : Math.min )(
				width / image.naturalWidth, height / image.naturalHeight
			);
			var position = style.objectPosition.split( /\s+/ );
			function offset( value, space ) {
				if ( 'left' === value || 'top' === value ) { return 0; }
				if ( 'right' === value || 'bottom' === value ) { return space; }
				if ( /%$/.test( value ) ) { return space * parseFloat( value ) / 100; }
				if ( /px$/.test( value ) ) { return parseFloat( value ); }
				return space / 2;
			}

			var canvas = document.createElement( 'canvas' );
			canvas.width = Math.ceil( width * 3 );
			canvas.height = Math.ceil( height * 3 );
			var context = canvas.getContext( '2d' );
			context.scale( canvas.width / width, canvas.height / height );
			context.drawImage( image,
				offset( position[0], width - image.naturalWidth * ratio ),
				offset( position[1] || '50%', height - image.naturalHeight * ratio ),
				image.naturalWidth * ratio, image.naturalHeight * ratio
			);
			return { src: canvas.toDataURL( 'image/png' ), width: width, height: height };
		} ) );
	}

	function parseRgbColor( value ) {
		var match = /rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s/]+([\d.]+))?\s*\)/i.exec( value || '' );

		if ( ! match ) {
			return null;
		}

		return {
			r: Math.max( 0, Math.min( 255, parseFloat( match[1] ) ) ),
			g: Math.max( 0, Math.min( 255, parseFloat( match[2] ) ) ),
			b: Math.max( 0, Math.min( 255, parseFloat( match[3] ) ) ),
			a: null === match[4] || 'undefined' === typeof match[4] ? 1 : Math.max( 0, Math.min( 1, parseFloat( match[4] ) ) )
		};
	}

	function mixRgbColor( color, target, amount ) {
		return {
			r: color.r + ( target.r - color.r ) * amount,
			g: color.g + ( target.g - color.g ) * amount,
			b: color.b + ( target.b - color.b ) * amount,
			a: color.a
		};
	}

	function formatRgbColor( color ) {
		return 'rgba(' +
			Math.round( color.r ) + ', ' +
			Math.round( color.g ) + ', ' +
			Math.round( color.b ) + ', ' +
			color.a +
		')';
	}

	function cloneTitleBadgesForCapture( sourceCard, clonedDocument ) {
		if ( ! sourceCard || ! clonedDocument ) {
			return;
		}

		var sourceBadges = sourceCard.querySelectorAll( '.adam-digital-card__title' );
		var clonedBadges = clonedDocument.querySelectorAll( '.adam-digital-card__title' );

		Array.prototype.forEach.call( clonedBadges, function ( clonedBadge, index ) {
			var sourceBadge = sourceBadges[ index ];

			if ( ! sourceBadge ) {
				return;
			}

			var sourceStyle = window.getComputedStyle( sourceBadge );
			var badgeBackgroundVar = sourceStyle.getPropertyValue( '--adam-title-badge-background' ) || '';
			var baseBackground = badgeBackgroundVar.trim() || sourceStyle.backgroundColor;
			var baseColor = parseRgbColor( baseBackground );
			var backgroundImage = 'none';

			if ( baseColor ) {
				var topColor = formatRgbColor( mixRgbColor( baseColor, { r: 255, g: 255, b: 255 }, 0.12 ) );
				var bottomColor = formatRgbColor( mixRgbColor( baseColor, { r: 0, g: 0, b: 0 }, 0.12 ) );

				backgroundImage = 'linear-gradient(180deg, ' + topColor + ' 0%, ' + baseBackground + ' 56%, ' + bottomColor + ' 100%)';
			}

			clonedBadge.style.setProperty( 'display', 'flex', 'important' );
			clonedBadge.style.setProperty( 'align-items', 'center', 'important' );
			clonedBadge.style.setProperty( 'gap', sourceStyle.gap || '10px', 'important' );
			clonedBadge.style.setProperty( 'width', 'max-content', 'important' );
			clonedBadge.style.setProperty( 'max-width', '100%', 'important' );
			clonedBadge.style.setProperty( 'background-color', baseBackground, 'important' );
			clonedBadge.style.setProperty( 'background-image', backgroundImage, 'important' );
			clonedBadge.style.setProperty( 'box-shadow', '0 2px 6px rgba(0, 0, 0, 0.18)', 'important' );
			clonedBadge.style.setProperty( 'backdrop-filter', 'none', 'important' );
			clonedBadge.style.setProperty( 'mix-blend-mode', 'normal', 'important' );
			clonedBadge.style.setProperty( 'isolation', 'auto', 'important' );
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

			await Promise.all( getCardImages( card ).map( waitForImage ) );
			var fittedImages = await fittedImagesForCapture( card );
			var cardIndex = Array.prototype.indexOf.call( document.querySelectorAll( '.adam-digital-card' ), card );

			var canvas = await window.html2canvas( card, {
				scale: 3,
				width: card.offsetWidth,
				height: card.offsetHeight,
				backgroundColor: null,
				useCORS: true,
				allowTaint: false,
				logging: false,
				imageTimeout: 15000,
				onclone: async function ( clonedDocument ) {
					// Capture the full canvas, independently of its phone display scale.
					var clonedCard = clonedDocument.querySelectorAll( '.adam-digital-card' )[ cardIndex ];
					if ( clonedCard && card.closest( '.adam-digital-card-viewport' ) ) {
						clonedCard.style.transform = 'none';
						clonedCard.parentElement.style.height = 'auto';
						clonedCard.parentElement.style.overflow = 'visible';
					}
					if ( clonedCard ) {
						await Promise.all( Array.prototype.map.call( clonedCard.querySelectorAll( 'img' ), async function ( image, index ) {
							var fitted = fittedImages[ index ];
							if ( ! fitted ) { return; }
							image.removeAttribute( 'srcset' );
							image.removeAttribute( 'sizes' );
							image.src = fitted.src;
							image.style.width = fitted.width + 'px';
							image.style.height = fitted.height + 'px';
							image.style.objectFit = 'fill';
							await waitForImage( image );
							if ( 'function' === typeof image.decode ) {
								await image.decode();
							}
						} ) );
					}
					cloneTitleBadgesForCapture( card, clonedDocument );
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

	window.adamMemberCardDownloadApi = {
		downloadFromButton: function ( button ) {
			return handleDownload( {
				preventDefault: function () {},
				currentTarget: button
			} );
		}
	};

	function init() {
		initCardScaling();
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
