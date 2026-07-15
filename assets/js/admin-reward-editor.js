( function ( $ ) {
	'use strict';

	function clamp( value, min, max ) {
		var parsed = parseInt( value, 10 );

		if ( isNaN( parsed ) ) {
			return min;
		}

		return Math.min( max, Math.max( min, parsed ) );
	}

	function previewStyleValue( key ) {
		var $fields = $( '[data-adam-style="' + key + '"]' );
		var $field;

		if ( ! $fields.length ) {
			return '';
		}

		$fields = $fields.filter( ':enabled' );

		if ( ! $fields.length ) {
			$fields = $( '[data-adam-style="' + key + '"]' );
		}

		if ( $fields.first().is( ':radio' ) || $fields.first().is( ':checkbox' ) ) {
			$field = $fields.filter( ':checked' ).first();

			return $field.length ? $field.val() : '';
		}

		return $fields.first().val();
	}

	function normalizedString( value ) {
		var stringValue = String( value || '' ).toLowerCase();

		if ( typeof stringValue.normalize === 'function' ) {
			return stringValue.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' );
		}

		return stringValue;
	}

	function isDigitalReward() {
		var category = normalizedString( $( '[data-adam-reward-category]' ).val() || '' );
		var type = $( '[data-adam-reward-type]' ).val() || '';

		return type === 'digital_cosmetic' || category.indexOf( 'cart' ) !== -1;
	}

	function isTitleReward() {
		var category = normalizedString( $( '[data-adam-reward-category]' ).val() || '' );
		var rewardValue = String( currentRewardValue() || '' ).toLowerCase();

		return category.indexOf( 'titulo' ) !== -1 || rewardValue.indexOf( 'title_' ) === 0;
	}

	function currentSubtype() {
		var subtype = previewStyleValue( 'card_subtype' ) || 'background';

		return subtype === 'frame' ? 'card_style' : subtype;
	}

	function currentBackgroundMode() {
		var mode = previewStyleValue( 'background_mode' ) || 'gradient';

		if ( [ 'solid', 'gradient', 'image' ].indexOf( mode ) === -1 ) {
			return 'gradient';
		}

		return mode;
	}

	function currentFramePreset() {
		var preset = previewStyleValue( 'frame_style' ) || 'simple';

		if ( [ 'simple', 'metallic', 'gradient' ].indexOf( preset ) !== -1 ) {
			return preset;
		}

		return 'none';
	}

	function setAccordionState( $section, open ) {
		$section.toggleClass( 'is-open', open );
		$section.find( '[data-adam-accordion-toggle]' ).attr( 'aria-expanded', open ? 'true' : 'false' );
	}

	function normalizeHexColor( value ) {
		var color = String( value || '' ).trim();

		if ( /^#[0-9a-fA-F]{6}$/.test( color ) ) {
			return color;
		}

		if ( /^#[0-9a-fA-F]{3}$/.test( color ) ) {
			return '#' + color.charAt( 1 ) + color.charAt( 1 ) + color.charAt( 2 ) + color.charAt( 2 ) + color.charAt( 3 ) + color.charAt( 3 );
		}

		return '#ffffff';
	}

	function rememberInitialState( $field ) {
		if ( $field.is( ':radio, :checkbox' ) ) {
			$field.data( 'adamInitialChecked', $field.prop( 'checked' ) );
			return;
		}

		$field.data( 'adamInitialValue', $field.val() );
	}

	function restoreInitialState( $field ) {
		if ( $field.is( ':file' ) ) {
			$field.val( '' );
			return;
		}

		if ( $field.is( ':radio, :checkbox' ) ) {
			$field.prop( 'checked', !! $field.data( 'adamInitialChecked' ) );
			return;
		}

		var value = $field.data( 'adamInitialValue' );

		if ( typeof value === 'undefined' ) {
			return;
		}

		$field.val( value );

		if ( $field.hasClass( 'adam-color-picker' ) && $field.wpColorPicker ) {
			$field.wpColorPicker( 'color', value );
		}
	}

	function resetSectionToInitialState( selector ) {
		$( selector ).find( 'input, select, textarea' ).each(
			function () {
				restoreInitialState( $( this ) );
			}
		);
	}

	function initColorControls( context ) {
		$( context ).find( '.adam-color-picker' ).each( function () {
			var $input = $( this );
			var $control;
			var $swatch;
			var swatchValue;

			if ( $input.data( 'adamColorEnhanced' ) ) {
				return;
			}

			$control = $( '<div class="adam-reward-editor__color-control"></div>' );
			$swatch = $( '<input type="color" class="adam-reward-editor__color-swatch" aria-label="Escolher cor">' );
			swatchValue = normalizeHexColor( $input.val() );

			$input.addClass( 'adam-reward-editor__color-value' );
			$input.wrap( $control );
			$input.before( $swatch );
			$swatch.val( swatchValue );
			$input.data( 'adamColorEnhanced', true );

			$swatch.on(
				'input change',
				function () {
					$input.val( $( this ).val() ).trigger( 'input' );
				}
			);

			$input.on(
				'input change',
				function () {
					var normalized = normalizeHexColor( $input.val() );

					if ( /^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/.test( String( $input.val() || '' ) ) ) {
						$swatch.val( normalized );
					}
				}
			);
		} );
	}

	function initRangeControls( context ) {
		$( context ).find( 'input[type="range"][data-adam-style]' ).each( function () {
			var $range = $( this );
			var $number;
			var $group;

			if ( $range.data( 'adamRangeEnhanced' ) ) {
				return;
			}

			$group = $( '<div class="adam-reward-editor__range-group"></div>' );
			$number = $( '<input type="number" class="adam-reward-editor__range-number">' );
			$number.attr( 'min', $range.attr( 'min' ) || 0 );
			$number.attr( 'max', $range.attr( 'max' ) || 100 );
			$number.attr( 'step', $range.attr( 'step' ) || 1 );
			$number.val( $range.val() );

			$range.wrap( $group );
			$range.after( $number );
			$range.data( 'adamRangeEnhanced', true );

			$range.on(
				'input change',
				function () {
					$number.val( $range.val() );
				}
			);

			$number.on(
				'input change',
				function () {
					$range.val( $number.val() ).trigger( 'input' );
				}
			);
		} );
	}

	function syncValueLabels() {
		$( '[data-adam-value-for]' ).each(
			function () {
				var key = $( this ).data( 'adamValueFor' );
				var value = previewStyleValue( key );
				var suffix = '';

				if ( key.indexOf( 'opacity' ) !== -1 ) {
					suffix = '%';
				}

				if ( key.indexOf( 'angle' ) !== -1 || key.indexOf( 'rotation' ) !== -1 ) {
					suffix = '°';
				}

				if (
					key.indexOf( 'width' ) !== -1 ||
					key.indexOf( 'thickness' ) !== -1 ||
					key.indexOf( 'size' ) !== -1 ||
					key.indexOf( 'spacing' ) !== -1 ||
					key.indexOf( 'shadow' ) !== -1 ||
					key.indexOf( 'glow' ) !== -1 ||
					key.indexOf( 'accent' ) !== -1 ||
					key === 'content_padding' ||
					key === 'content_gap'
				) {
					suffix = suffix || 'px';
				}

				if ( key === 'gradient_stop_secondary' || key === 'gradient_stop_tertiary' ) {
					suffix = '%';
				}

				$( this ).text( value + suffix );
			}
		);
	}

	function buildShapeObject( $row ) {
		return {
			type: $row.find( '[data-shape-prop="type"]' ).val() || 'circle',
			x: clamp( $row.find( '[data-shape-prop="x"]' ).val(), 0, 100 ),
			y: clamp( $row.find( '[data-shape-prop="y"]' ).val(), 0, 100 ),
			width: clamp( $row.find( '[data-shape-prop="width"]' ).val(), 2, 90 ),
			height: clamp( $row.find( '[data-shape-prop="height"]' ).val(), 2, 90 ),
			rotation: clamp( $row.find( '[data-shape-prop="rotation"]' ).val(), 0, 360 ),
			opacity: clamp( $row.find( '[data-shape-prop="opacity"]' ).val(), 0, 100 ),
			color: $row.find( '[data-shape-prop="color"]' ).val() || '#ffffff',
		};
	}

	function syncShapes() {
		var shapes = [];

		$shapeList.find( '[data-adam-shape-row]' ).each(
			function () {
				shapes.push( buildShapeObject( $( this ) ) );
			}
		);

		$shapeInput.val( JSON.stringify( shapes ) );

		return shapes;
	}

	function renderShapePreview( shape ) {
		var $shape = $( '<span class="adam-digital-card__shape"></span>' );

		$shape.addClass( 'adam-digital-card__shape--' + shape.type );
		$shape.css(
			{
				left: shape.x + '%',
				top: shape.y + '%',
				width: shape.width + '%',
				height: shape.height + '%',
				transform: 'rotate(' + shape.rotation + 'deg)',
				opacity: shape.opacity / 100,
				background: shape.color,
			}
		);

		return $shape;
	}

	function shapeRowTemplate( type, index ) {
		var defaults = {
			circle: { x: 74, y: 14, width: 14, height: 14, rotation: 0, opacity: 26 },
			square: { x: 10, y: 66, width: 18, height: 18, rotation: 12, opacity: 18 },
		}[ type ] || { x: 74, y: 14, width: 14, height: 14, rotation: 0, opacity: 26 };

		return $(
			'<div class="adam-reward-editor__shape-row" data-adam-shape-row>' +
				'<div class="adam-reward-editor__shape-row-head">' +
					'<strong>Forma ' + index + '</strong>' +
					'<button type="button" class="button-link-delete" data-adam-remove-shape>Remover</button>' +
				'</div>' +
				'<div class="adam-reward-editor__shape-grid">' +
					'<label><span>Tipo</span><select data-shape-prop="type"><option value="circle">Circulo</option><option value="square">Quadrado</option></select></label>' +
					'<label><span>X</span><input type="number" min="0" max="100" data-shape-prop="x" value="' + defaults.x + '"></label>' +
					'<label><span>Y</span><input type="number" min="0" max="100" data-shape-prop="y" value="' + defaults.y + '"></label>' +
					'<label><span>Largura</span><input type="number" min="2" max="90" data-shape-prop="width" value="' + defaults.width + '"></label>' +
					'<label><span>Altura</span><input type="number" min="2" max="90" data-shape-prop="height" value="' + defaults.height + '"></label>' +
					'<label><span>Rotacao</span><input type="number" min="0" max="360" data-shape-prop="rotation" value="' + defaults.rotation + '"></label>' +
					'<label><span>Opacidade</span><input type="number" min="0" max="100" data-shape-prop="opacity" value="' + defaults.opacity + '"></label>' +
					'<label><span>Cor</span><input type="text" class="adam-color-picker" data-shape-prop="color" value="#ffffff"></label>' +
				'</div>' +
			'</div>'
		);
	}

	function patternClass() {
		var pattern = previewStyleValue( 'pattern' ) || 'grid';

		return 'adam-digital-card__pattern adam-digital-card__pattern--' + pattern;
	}

	function artClass() {
		var imagePosition = previewStyleValue( 'card_image_position' ) || 'top-right';
		var imageLayer = previewStyleValue( 'card_image_layer' ) || 'overlay';

		return 'adam-digital-card__art adam-digital-card__art--' + imagePosition + ' adam-digital-card__art--layer-' + imageLayer;
	}

	function previewClasses() {
		var frameStyle = currentFramePreset();
		var rewardRarity = $( '[data-adam-preview-rarity]' ).val() || 'common';
		var classes = [];

		if ( frameStyle !== 'none' && clamp( previewStyleValue( 'frame_thickness' ), 0, 16 ) > 0 ) {
			classes.push( 'adam-digital-card--has-frame' );
			classes.push( 'adam-digital-card--frame-' + frameStyle );
		}

		if ( String( currentRewardValue() || '' ).toLowerCase().indexOf( 'card_theme_' ) === 0 ) {
			classes.push( 'adam-digital-card--theme-rarity-' + rewardRarity );
		}

		return classes;
	}

	function colorWithAlpha( color, alpha ) {
		if ( ! color ) {
			return '';
		}

		var hex = String( color ).trim().replace( '#', '' );

		if ( hex.length === 3 ) {
			hex = hex.replace( /(.)/g, '$1$1' );
		}

		if ( ! /^[0-9a-fA-F]{6}$/.test( hex ) ) {
			return color;
		}

		return 'rgba(' +
			parseInt( hex.substring( 0, 2 ), 16 ) + ',' +
			parseInt( hex.substring( 2, 4 ), 16 ) + ',' +
			parseInt( hex.substring( 4, 6 ), 16 ) + ',' +
			alpha + ')';
	}

	function backgroundValue() {
		var mode = currentBackgroundMode();
		var primary = previewStyleValue( 'background_color' ) || '#143826';
		var secondary = previewStyleValue( 'background_color_secondary' ) || primary;
		var tertiary = previewStyleValue( 'background_color_tertiary' ) || secondary;
		var angle = clamp( previewStyleValue( 'gradient_angle' ), 0, 360 );
		var stopSecondary = clamp( previewStyleValue( 'gradient_stop_secondary' ), 0, 100 );
		var stopTertiary = clamp( previewStyleValue( 'gradient_stop_tertiary' ), 0, 100 );
		var origin = ( previewStyleValue( 'gradient_origin' ) || 'center' ).replace( /-/g, ' ' );
		var opacity = clamp( previewStyleValue( 'gradient_opacity' ), 0, 100 ) / 100;

		if ( mode === 'solid' ) {
			return primary;
		}

		return 'radial-gradient(circle at ' + origin + ', ' + colorWithAlpha( primary, opacity * 0.48 ) + ' 0%, transparent 34%), linear-gradient(' + angle + 'deg, ' + primary + ' 0%, ' + secondary + ' ' + stopSecondary + '%, ' + tertiary + ' ' + stopTertiary + '%)';
	}

	function currentRewardValue() {
		return $( 'input[name="reward_value"]' ).val() || '';
	}

	function currentPreviewTitle() {
		var rewardValue = String( currentRewardValue() || '' ).toLowerCase();
		var rewardName = $( '[data-adam-preview-name]' ).val() || 'SOBREVIVENTE';

		if ( rewardValue.indexOf( 'title_' ) === 0 || isTitleReward() ) {
			return String( rewardName ).toUpperCase();
		}

		return 'SOBREVIVENTE';
	}

	function toggleDesignerSections() {
		var digital = isDigitalReward();
		var titleReward = isTitleReward();
		var subtype = currentSubtype();
		var mode = currentBackgroundMode();
		var editorVisible = digital || titleReward;
		var backgroundVisible = digital && ! titleReward && subtype === 'background';
		var styleVisible = digital && ! titleReward && subtype === 'card_style';
		var cardTypographyVisible = digital && ! titleReward && subtype === 'card_style';
		var titleBadgeVisible = titleReward;
		var patternVisible = backgroundVisible && ( previewStyleValue( 'pattern' ) || 'grid' ) !== 'none';

		$( '[data-adam-digital-workspace]' ).toggleClass( 'is-hidden', ! editorVisible );
		$( '[data-adam-card-subtype-field]' ).toggleClass( 'is-hidden', ! digital || titleReward );
		$( '[data-adam-card-subtype-field]' ).find( 'input, select, textarea, button' ).prop( 'disabled', ! digital || titleReward );
		$( '[data-adam-non-digital-notice]' ).toggleClass( 'is-hidden', editorVisible );
		$( '[data-adam-background-controls], [data-adam-image-controls]' ).toggleClass( 'is-hidden', ! backgroundVisible );
		$( '[data-adam-background-controls], [data-adam-image-controls]' ).find( 'input, select, textarea, button' ).prop( 'disabled', ! backgroundVisible );
		$( '[data-adam-style-controls]' ).toggleClass( 'is-hidden', ! styleVisible );
		$( '[data-adam-style-controls]' ).find( 'input, select, textarea, button' ).prop( 'disabled', ! styleVisible );
		$( '[data-adam-card-typography-controls]' ).toggleClass( 'is-hidden', ! cardTypographyVisible );
		$( '[data-adam-card-typography-controls]' ).find( 'input, select, textarea, button' ).prop( 'disabled', ! cardTypographyVisible );
		$( '[data-adam-title-badge-controls]' ).toggleClass( 'is-hidden', ! titleBadgeVisible );
		$( '[data-adam-title-badge-controls]' ).find( 'input, select, textarea, button' ).prop( 'disabled', ! titleBadgeVisible );
		$( '[data-adam-card-preview-panel]' ).toggleClass( 'is-hidden', titleReward );
		$( '[data-adam-title-preview-panel]' ).toggleClass( 'is-hidden', ! titleReward );
		$( '[data-adam-details-controls]' ).toggleClass( 'is-hidden', false );
		$( '[data-adam-frame-group]' ).each(
			function () {
				var presets = String( $( this ).data( 'adamFrameGroup' ) || '' ).split( /\s+/ );
				var visible = styleVisible && presets.indexOf( currentFramePreset() ) !== -1;

				$( this ).toggleClass( 'is-hidden', ! visible );
				$( this ).find( 'input, select, textarea, button' ).prop( 'disabled', ! visible );
			}
		);

		$( '[data-adam-background-mode-group]' ).each(
			function () {
				var modes = String( $( this ).data( 'adamBackgroundModeGroup' ) || '' ).split( /\s+/ );
				var visible = ! backgroundVisible ? false : modes.indexOf( mode ) !== -1;

				$( this ).toggleClass( 'is-hidden', ! visible );
				$( this ).find( 'input, select, textarea, button' ).prop( 'disabled', ! visible );
			}
		);

		$( '[data-adam-pattern-detail]' ).toggleClass( 'is-hidden', ! patternVisible );
		$( '[data-adam-pattern-detail]' ).find( 'input, select, textarea, button' ).prop( 'disabled', ! patternVisible );
	}

	var $editor = $( '.adam-reward-editor' );

	if ( ! $editor.length ) {
		return;
	}

	var $preview = $( '[data-adam-card-preview]' );
	var $shapeInput = $( '[data-adam-shapes-input]' );
	var $shapeList = $( '[data-adam-shapes-list]' );
	var shapeCount = $shapeList.find( '[data-adam-shape-row]' ).length;
	var lastSubtype = currentSubtype();

	function updatePreview() {
		var rarity = $( '[data-adam-preview-rarity]' ).val() || 'common';
		var titleReward = isTitleReward();
		var subtype = currentSubtype();
		var framePreset = currentFramePreset();
		var imageUrl = $( '[data-adam-preview-image]' ).val() || '';
		var backgroundImageUrl = previewStyleValue( 'background_image_url' ) || '';
		var frameColor = previewStyleValue( 'frame_color' ) || '#ffffff';
		var frameHighlight = previewStyleValue( 'frame_highlight_color' ) || '#ffffff';
		var gradientColor1 = previewStyleValue( 'frame_gradient_color_1' ) || frameColor;
		var gradientColor2 = previewStyleValue( 'frame_gradient_color_2' ) || '#ffd700';
		var gradientColor3 = previewStyleValue( 'frame_gradient_color_3' ) || '#146aff';
		var frameGradientAngle = clamp( previewStyleValue( 'frame_gradient_angle' ), 0, 360 );
		var backgroundMode = currentBackgroundMode();
		var baseClass = $preview.attr( 'data-adam-card-base-class' ) || 'adam-digital-card';
		var classNames = baseClass.split( /\s+/ ).concat( previewClasses() );
		var frameThickness = clamp( previewStyleValue( 'frame_thickness' ), 0, 16 );
		var hasFrame = framePreset !== 'none' && subtype === 'card_style' && frameThickness > 0;
		var $cardTitleBadge = $preview.find( '[data-adam-card-title]' );
		var $previewTitleBadge = $( '[data-adam-title-preview-panel] [data-adam-card-title]' );
		if ( ! $preview.length && ! $previewTitleBadge.length ) {
			return;
		}

		if ( $preview.length ) {
			$preview.attr( 'class', classNames.join( ' ' ).trim() );

			$preview.css(
				{
				'--adam-card-surface': backgroundValue(),
				'--adam-card-text-primary': previewStyleValue( 'text_color' ) || '#ffffff',
				'--adam-card-text-secondary': previewStyleValue( 'muted_text_color' ) || '#cccccc',
				'--adam-card-member-name-color': previewStyleValue( 'member_name_color' ) || ( previewStyleValue( 'text_color' ) || '#ffffff' ),
				'--adam-card-member-name-weight': clamp( previewStyleValue( 'member_name_weight' ), 700, 900 ),
				'--adam-card-radius': '28px',
				'--adam-card-shadow': 'none',
				'--adam-frame-width': ( hasFrame ? frameThickness : 0 ) + 'px',
				'--adam-frame-color': hasFrame ? frameColor : 'transparent',
				'--adam-frame-highlight-color': hasFrame ? frameHighlight : 'transparent',
				'--adam-frame-gradient-color-1': hasFrame ? gradientColor1 : 'transparent',
				'--adam-frame-gradient-color-2': hasFrame ? gradientColor2 : 'transparent',
				'--adam-frame-gradient-color-3': hasFrame ? gradientColor3 : 'transparent',
				'--adam-frame-angle': frameGradientAngle + 'deg',
				'--adam-card-frame-inset': '12px',
				'--adam-card-content-padding': '28px',
				'--adam-card-content-gap': '20px',
				'--adam-card-photo-border': 'rgba(255,255,255,0.82)',
				'--adam-card-pattern-color': previewStyleValue( 'pattern_color' ) || '#86efac',
				'--adam-card-pattern-base': previewStyleValue( 'pattern_background_color' ) || '#143826',
				'--adam-card-pattern-opacity': clamp( previewStyleValue( 'pattern_opacity' ), 0, 100 ) / 100,
				'--adam-card-pattern-size': clamp( previewStyleValue( 'pattern_scale' ), 6, 120 ) + 'px',
				'--adam-card-pattern-spacing': clamp( previewStyleValue( 'pattern_spacing' ), 6, 120 ) + 'px',
				'--adam-card-pattern-density': clamp( previewStyleValue( 'pattern_density' ), 1, 12 ),
				'--adam-card-pattern-rotation': clamp( previewStyleValue( 'pattern_rotation' ), 0, 360 ) + 'deg',
				'--adam-card-background-opacity': clamp( previewStyleValue( 'background_image_opacity' ), 0, 100 ) / 100,
				'--adam-card-background-size': clamp( previewStyleValue( 'background_image_size' ), 20, 200 ) + '%',
				'--adam-card-background-position': ( previewStyleValue( 'background_image_position' ) || 'center' ).replace( /-/g, ' ' ),
				'--adam-card-background-blend': previewStyleValue( 'background_image_blend_mode' ) || 'screen',
				'--adam-card-art-opacity': clamp( previewStyleValue( 'card_image_opacity' ), 0, 100 ) / 100,
				'--adam-card-art-size': clamp( previewStyleValue( 'card_image_size' ), 10, 80 ) + '%',
				}
			);

			$preview.find( '[data-adam-card-pattern]' ).attr( 'class', patternClass() );

			var $artWrap = $preview.find( '[data-adam-card-art-wrap]' );
			$artWrap.attr( 'class', artClass() ).prop( 'hidden', subtype !== 'card_style' || ( ! imageUrl && ! $preview.find( '[data-adam-card-art]' ).attr( 'src' ) ) );

			if ( subtype === 'card_style' && imageUrl ) {
				$preview.find( '[data-adam-card-art]' ).attr( 'src', imageUrl );
				$artWrap.prop( 'hidden', false );
			}

			if ( backgroundMode === 'image' && backgroundImageUrl ) {
				$preview.find( '[data-adam-card-backdrop]' ).css( 'background-image', 'url(' + backgroundImageUrl + ')' );
			} else {
				$preview.find( '[data-adam-card-backdrop]' ).css( 'background-image', '' );
			}
		}

		var titleText = currentPreviewTitle();
		$cardTitleBadge.find( '[data-adam-card-title-text]' ).text( titleReward ? 'SOBREVIVENTE' : titleText );
		$previewTitleBadge.find( '[data-adam-card-title-text]' ).text( titleText );

		if ( titleReward && $previewTitleBadge.length ) {
			$previewTitleBadge.attr( 'class', 'adam-digital-card__title adam-digital-card__title--' + rarity );
			$previewTitleBadge.attr(
				'style',
				[
					'--adam-title-badge-background:' + ( previewStyleValue( 'badge_background_color' ) || '#36523f' ),
					'--adam-title-badge-text:' + ( previewStyleValue( 'badge_text_color' ) || '#ffffff' ),
					'--adam-title-badge-border:' + ( previewStyleValue( 'badge_border_color' ) || '#86efac' ),
					'--adam-title-badge-border-width:' + clamp( previewStyleValue( 'badge_border_width' ), 1, 4 ) + 'px',
					'--adam-title-badge-icon:' + ( previewStyleValue( 'badge_icon_color' ) || '#2f4b3b' ),
					'--adam-title-badge-icon-highlight:' + ( previewStyleValue( 'badge_icon_highlight_color' ) || '#ffffff' ),
					'--adam-title-badge-icon-glow:' + clamp( previewStyleValue( 'badge_icon_glow' ), 0, 40 ) + 'px'
				].join( ';' )
			);
		}

		if ( $preview.length ) {
			var $previewShapes = $preview.find( '[data-adam-card-shapes]' );
			var shapes = subtype === 'card_style' && ! titleReward ? syncShapes() : [];

			$previewShapes.empty();

			shapes.forEach(
				function ( shape ) {
					$previewShapes.append( renderShapePreview( shape ) );
				}
			);
		}

		syncValueLabels();
		toggleDesignerSections();
	}

	function setPreviewImageFromFile( input ) {
		if ( ! input.files || ! input.files.length ) {
			return;
		}

		var reader = new FileReader();

		reader.onload = function ( event ) {
			$preview.find( '[data-adam-card-art]' ).attr( 'src', event.target.result );
			$preview.find( '[data-adam-card-art-wrap]' ).prop( 'hidden', false );
		};

		reader.readAsDataURL( input.files[ 0 ] );
	}

	$editor.on( 'input change', '[data-adam-style], [data-adam-preview-name], [data-adam-preview-rarity], [data-adam-reward-type], [data-adam-reward-category], input[name="reward_value"]', updatePreview );

	$editor.on(
		'change',
		'[data-adam-style="card_subtype"]',
		function () {
			var subtype = currentSubtype();

			if ( subtype === lastSubtype ) {
				updatePreview();
				return;
			}

			if ( subtype === 'background' ) {
				resetSectionToInitialState( '[data-adam-style-controls]' );
			} else {
				resetSectionToInitialState( '[data-adam-background-controls], [data-adam-image-controls]' );
			}

			lastSubtype = subtype;
			updatePreview();
		}
	);

	$editor.on(
		'click',
		'[data-adam-accordion-toggle]',
		function () {
			var $section = $( this ).closest( '.adam-reward-editor__section' );
			setAccordionState( $section, ! $section.hasClass( 'is-open' ) );
		}
	);

	$editor.on(
		'change',
		'[data-adam-preview-image-upload]',
		function () {
			setPreviewImageFromFile( this );
			updatePreview();
		}
	);

	$editor.on(
		'click',
		'[data-adam-media-target]',
		function ( event ) {
			var targetSelector;
			var $target;
			var frame;

			event.preventDefault();

			targetSelector = $( this ).data( 'adamMediaTarget' );
			$target = $( targetSelector );

			if ( ! $target.length || typeof wp === 'undefined' || ! wp.media ) {
				return;
			}

			frame = wp.media(
				{
					title: 'Selecionar imagem',
					button: { text: 'Usar imagem' },
					multiple: false,
				}
			);

			frame.on(
				'select',
				function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					$target.val( attachment.url ).trigger( 'change' );
				}
			);

			frame.open();
		}
	);

	$editor.on(
		'click',
		'[data-adam-add-shape]',
		function () {
			var type = $( this ).data( 'adamAddShape' );
			var $row;

			shapeCount += 1;
			$row = shapeRowTemplate( type, shapeCount );
			$shapeList.append( $row );
			initColorControls( $row );
			$row.find( '[data-shape-prop="type"]' ).val( type );
			updatePreview();
		}
	);

	$editor.on(
		'click',
		'[data-adam-remove-shape]',
		function () {
			$( this ).closest( '[data-adam-shape-row]' ).remove();
			updatePreview();
		}
	);

	$editor.on( 'input change', '[data-shape-prop]', updatePreview );

	initColorControls( document );
	initRangeControls( document );
	$editor.find( '[data-adam-style], [data-adam-preview-image], [data-adam-preview-image-upload]' ).each(
		function () {
			rememberInitialState( $( this ) );
		}
	);
	updatePreview();
}( jQuery ) );
