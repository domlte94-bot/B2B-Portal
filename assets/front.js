( function () {
	'use strict';

	var i18n = {
		person: 'person',
		people: 'people',
		unit: 'unit',
		units: 'units'
	};

	function all( root, sel ) {
		return Array.prototype.slice.call( root.querySelectorAll( sel ) );
	}

	function titleCase( s ) {
		return s.toLowerCase().replace( /(^|[\s\-'’])(\S)/g, function ( m, sep, ch ) {
			return sep + ch.toUpperCase();
		} );
	}

	function setup( box ) {
		var form = box.closest( 'form' );
		var counter = 0;

		// The plugin controls the quantity: hide WooCommerce's box.
		if ( box.getAttribute( 'data-controls-qty' ) === '1' && form ) {
			all( form, '.quantity' ).forEach( function ( q ) {
				q.style.display = 'none';
				var input = q.querySelector( 'input' );
				if ( input ) {
					input.value = 1;
				}
			} );
		}

		var people = box.querySelector( '.b2bcp-people' );
		var tbody = people ? people.querySelector( '.b2bcp-people-list' ) : null;
		var tpl = people ? people.querySelector( '.b2bcp-person-template' ) : null;

		function rowFilled( tr ) {
			return all( tr, 'input[type=text], select:not(.b2bcp-person-address), textarea' ).some( function ( el ) {
				return el.value.trim() !== '';
			} );
		}

		function renumber() {
			if ( ! tbody ) {
				return;
			}
			var rows = all( tbody, '.b2bcp-person' );
			rows.forEach( function ( tr, i ) {
				var num = tr.querySelector( '.b2bcp-num' );
				if ( num ) {
					num.textContent = ( i18n.personLabel || 'Person' ) + ' ' + ( i + 1 );
				}
			} );
			var n = rows.filter( rowFilled ).length;
			var out = people.querySelector( '.b2bcp-count' );
			if ( out ) {
				out.textContent = n + ' ' + ( n === 1 ? i18n.person : i18n.people ) + ' = ' + n + ' ' + ( n === 1 ? i18n.unit : i18n.units );
			}
		}

		if ( people && tpl ) {
			people.querySelector( '.b2bcp-add-person' ).addEventListener( 'click', function () {
				var html = tpl.innerHTML.replace( /__i__/g, 'n' + ( counter++ ) );
				var holder = document.createElement( 'div' );
				holder.innerHTML = html.trim();
				var row = holder.firstElementChild;
				var all1 = people.querySelector( '.b2bcp-all-address' );
				var sel = row.querySelector( '.b2bcp-person-address' );
				if ( all1 && sel && all1.value ) {
					sel.value = all1.value;
				}
				tbody.appendChild( row );
				syncColumns();
				renumber();
				var first = row.querySelector( 'input, select' );
				if ( first ) {
					first.focus();
				}
			} );

			people.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.b2bcp-remove-person' );
				if ( ! btn ) {
					return;
				}
				var rows = all( tbody, '.b2bcp-person' );
				var tr = btn.closest( '.b2bcp-person' );
				if ( rows.length > 1 ) {
					tr.parentNode.removeChild( tr );
				} else {
					all( tr, 'input, textarea' ).forEach( function ( el ) {
						el.value = '';
					} );
				}
				renumber();
			} );

			people.addEventListener( 'input', renumber );

			// Paste a list copied from Excel (one person per line, columns separated by tabs) into a box.
			people.addEventListener( 'paste', function ( e ) {
				var target = e.target;
				if ( ! target.matches || ! target.matches( '.b2bcp-person input[type=text]' ) ) {
					return;
				}
				var text = ( e.clipboardData || window.clipboardData ).getData( 'text' );
				if ( text.indexOf( '\n' ) === -1 && text.indexOf( '\t' ) === -1 ) {
					return;
				}
				e.preventDefault();
				var lines = text.replace( /\r/g, '' ).split( '\n' ).filter( function ( l ) {
					return l.trim() !== '';
				} );
				var card = target.closest( '.b2bcp-person' );
				var inputsOf = function ( c ) {
					return all( c, '.b2bcp-field:not([style*="none"]) input[type=text], .b2bcp-field:not([style*="none"]) select:not(.b2bcp-person-address), .b2bcp-field:not([style*="none"]) textarea' );
				};
				var start = inputsOf( card ).indexOf( target );
				lines.forEach( function ( line, li ) {
					if ( li > 0 ) {
						card = card.nextElementSibling;
						if ( ! card ) {
							people.querySelector( '.b2bcp-add-person' ).click();
							card = tbody.lastElementChild;
						}
					}
					var inputs = inputsOf( card );
					line.split( '\t' ).forEach( function ( value, ci ) {
						var input = inputs[ start + ci ];
						if ( input ) {
							input.value = value.trim();
						}
					} );
				} );
				renumber();
			} );

			var allAddress = people.querySelector( '.b2bcp-all-address' );
			if ( allAddress ) {
				allAddress.addEventListener( 'change', function () {
					all( tbody, '.b2bcp-person-address' ).forEach( function ( sel ) {
						sel.value = allAddress.value;
					} );
				} );
			}
		}

		// Text format preview (Title Case / UPPERCASE) when leaving a field.
		box.addEventListener( 'focusout', function ( e ) {
			var el = e.target;
			var fmt = el.getAttribute && el.getAttribute( 'data-format' );
			if ( ! fmt || ! el.value ) {
				return;
			}
			var v = el.value.replace( /\s+/g, ' ' ).trim();
			el.value = fmt === 'upper' ? v.toUpperCase() : titleCase( v );
		} );

		// Kit: full kit vs. loose pieces, and which columns apply.
		var kit = box.querySelector( '.b2bcp-kit' );
		function mode() {
			var checked = kit ? kit.querySelector( 'input[name=b2bcp_kit_mode]:checked' ) : null;
			return checked ? checked.value : 'full';
		}
		function syncColumns() {
			if ( ! kit || ! people ) {
				return;
			}
			var keys = null;
			if ( mode() === 'pieces' ) {
				keys = [];
				all( kit, '.b2bcp-pieces li' ).forEach( function ( li ) {
					var cb = li.querySelector( 'input[type=checkbox]' );
					if ( cb && cb.checked ) {
						keys = keys.concat( ( li.getAttribute( 'data-keys' ) || '' ).split( ' ' ).filter( Boolean ) );
					}
				} );
			}
			all( people, '[data-key]' ).forEach( function ( cell ) {
				var show = keys === null || keys.indexOf( cell.getAttribute( 'data-key' ) ) !== -1;
				cell.style.display = show ? '' : 'none';
			} );
		}
		function syncKit() {
			if ( ! kit ) {
				return;
			}
			var pieces = mode() === 'pieces';
			all( kit, '.b2bcp-pieces input[type=checkbox]' ).forEach( function ( cb ) {
				cb.disabled = ! pieces;
			} );
			kit.classList.toggle( 'is-pieces', pieces );
			syncColumns();
		}
		if ( kit ) {
			kit.addEventListener( 'change', syncKit );
			syncKit();
		}

		// Quantity per branch.
		var split = box.querySelector( '.b2bcp-split' );
		if ( split ) {
			var total = function () {
				var sum = 0;
				var branches = 0;
				all( split, '.b2bcp-branch-qty' ).forEach( function ( input ) {
					var n = parseInt( input.value, 10 ) || 0;
					sum += n;
					branches += n > 0 ? 1 : 0;
				} );
				split.querySelector( '.b2bcp-count' ).textContent = sum + ' ' + ( sum === 1 ? i18n.unit : i18n.units ) + ' · ' + branches + ' / ' + all( split, '.b2bcp-branch-qty' ).length;
			};
			split.addEventListener( 'input', total );
			split.querySelector( '.b2bcp-apply-qty' ).addEventListener( 'click', function () {
				var v = split.querySelector( '.b2bcp-all-qty' ).value;
				all( split, '.b2bcp-branch-qty' ).forEach( function ( input ) {
					input.value = v;
				} );
				total();
			} );
			total();
		}

		renumber();
	}

	function boot() {
		if ( window.b2bcpFront ) {
			Object.assign( i18n, window.b2bcpFront );
		}
		all( document, '.b2bcp-order' ).forEach( setup );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
