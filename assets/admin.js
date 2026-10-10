/* global jQuery, wp, b2bcpAdmin */
( function ( $ ) {
	'use strict';

	var counter = 1000;

	function templateFor( $table ) {
		var $tpl = $table.nextAll( '.b2bcp-row-template' ).first();
		if ( ! $tpl.length ) {
			$tpl = $table.parent().nextAll( '.b2bcp-row-template' ).first();
		}
		return $tpl;
	}

	// Repeater tables: add / remove rows.
	$( document ).on( 'click', '.b2bcp-add-row', function ( e ) {
		e.preventDefault();
		var $table = $( this ).closest( 'table.b2bcp-repeater' );
		var html = templateFor( $table ).html().replace( /__i__/g, 'n' + ( counter++ ) );
		var $row = $( $.trim( html ) );
		$table.children( 'tbody' ).append( $row );
		$( document.body ).trigger( 'wc-enhanced-select-init' );
		$row.find( 'input[type=text]:first' ).trigger( 'focus' );
	} );

	$( document ).on( 'click', '.b2bcp-remove-row', function ( e ) {
		e.preventDefault();
		$( this ).closest( 'tr' ).remove();
	} );

	// Field builder: only a dropdown has options; they take the place of "Max chars" and "Format".
	$( document ).on( 'change', '.b2bcp-field-type', function () {
		var isSelect = 'select' === this.value;
		var $row = $( this ).closest( 'tr' );
		$row.find( '.b2bcp-col-typed' ).prop( 'hidden', isSelect );
		var $options = $row.find( '.b2bcp-col-options' ).attr( 'colspan', isSelect ? 3 : 1 ).find( 'input' ).prop( 'hidden', ! isSelect );
		if ( isSelect ) {
			$options.trigger( 'focus' );
		}
	} );

	// Show / hide the settings under each checkbox on the product tab.
	function syncToggle( input ) {
		$( $( input ).data( 'b2bcp-toggle' ) ).toggle( input.checked );
	}
	$( document ).on( 'change', '[data-b2bcp-toggle]', function () {
		syncToggle( this );
	} );
	$( function () {
		$( '[data-b2bcp-toggle]' ).each( function () {
			syncToggle( this );
		} );

		if ( $.fn.wpColorPicker ) {
			$( '.b2bcp-color' ).wpColorPicker();
		}
	} );

	// Company logo picker.
	var frame;
	$( document ).on( 'click', '.b2bcp-logo-pick', function ( e ) {
		e.preventDefault();
		var $wrap = $( this ).closest( '.b2bcp-logo-field' );
		if ( ! frame ) {
			frame = wp.media( {
				title: b2bcpAdmin.chooseLogo,
				button: { text: b2bcpAdmin.useLogo },
				library: { type: 'image' },
				multiple: false
			} );
		}
		frame.off( 'select' ).on( 'select', function () {
			var file = frame.state().get( 'selection' ).first().toJSON();
			var url = file.sizes && file.sizes.medium ? file.sizes.medium.url : file.url;
			$wrap.find( 'input[type=hidden]' ).val( file.id );
			$wrap.find( '.b2bcp-logo-preview' ).html( $( '<img alt="">' ).attr( 'src', url ) );
		} );
		frame.open();
	} );

	$( document ).on( 'click', '.b2bcp-logo-clear', function ( e ) {
		e.preventDefault();
		var $wrap = $( this ).closest( '.b2bcp-logo-field' );
		$wrap.find( 'input[type=hidden]' ).val( '' );
		$wrap.find( '.b2bcp-logo-preview' ).empty();
	} );
}( jQuery ) );
