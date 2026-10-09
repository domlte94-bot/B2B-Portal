( function () {
	'use strict';
	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settingsApi = window.wc && window.wc.wcSettings;
	if ( ! registry || ! window.wp || ! window.wp.element ) {
		return;
	}
	var el = window.wp.element.createElement;
	var decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function ( s ) { return s; };
	var data = settingsApi && settingsApi.getSetting ? settingsApi.getSetting( 'b2bcp_invoice_data', {} ) : {};
	var title = decode( data.title || 'Invoice' );

	var Content = function () {
		return el( 'p', null, decode( data.description || '' ) );
	};

	registry.registerPaymentMethod( {
		name: 'b2bcp_invoice',
		label: el( 'span', null, title ),
		ariaLabel: title,
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: function () {
			return true;
		},
		supports: { features: data.supports || [ 'products' ] }
	} );
}() );
