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

		var extraOptions = [];
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
				var all1 = box.querySelector( '.b2bcp-all-address' );
				var sel = row.querySelector( '.b2bcp-person-address' );
				if ( sel ) {
					extraOptions.forEach( function ( o ) {
						sel.appendChild( new Option( o.label, o.id ) );
					} );
				}
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

			var allAddress = box.querySelector( '.b2bcp-all-address' );
			if ( allAddress ) {
				allAddress.addEventListener( 'change', function () {
					all( tbody, '.b2bcp-person-address' ).forEach( function ( sel ) {
						sel.value = allAddress.value;
					} );
				} );
				// "Ship everyone to" vs. "Choose an address for each person": the per-person
				// selects are hidden in the first mode and keep following the shared one.
				var syncShipMode = function () {
					var each = box.querySelector( 'input[name=b2bcp_ship_mode][value=each]' );
					var one = ! ( each && each.checked );
					people.classList.toggle( 'b2bcp-ship-one', one );
					allAddress.disabled = ! one;
					if ( one && allAddress.value ) {
						allAddress.dispatchEvent( new Event( 'change' ) );
					}
				};
				all( box, 'input[name=b2bcp_ship_mode]' ).forEach( function ( radio ) {
					radio.addEventListener( 'change', syncShipMode );
				} );
				syncShipMode();
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

		// Parts: complete product vs. only some parts, and which fields apply.
		var partsBox = box.querySelector( '.b2bcp-parts-box' );
		function mode() {
			var checked = partsBox ? partsBox.querySelector( 'input[name=b2bcp_parts_mode]:checked' ) : null;
			return checked ? checked.value : 'complete';
		}
		function syncColumns() {
			if ( ! partsBox || ! people ) {
				return;
			}
			var keys = null;
			if ( mode() === 'parts' ) {
				keys = [];
				all( partsBox, '.b2bcp-part-list li.is-alone' ).forEach( function ( li ) {
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
		function syncParts() {
			if ( ! partsBox ) {
				return;
			}
			var single = mode() === 'parts';
			all( partsBox, '.b2bcp-part-list input[type=checkbox]' ).forEach( function ( cb ) {
				cb.disabled = ! single;
			} );
			partsBox.classList.toggle( 'is-parts', single );
			syncColumns();
		}
		if ( partsBox ) {
			partsBox.addEventListener( 'change', syncParts );
			syncParts();
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

		// New address, saved to the company's address book without leaving the page.
		var panel = box.querySelector( '.b2bcp-new-address' );
		if ( panel ) {
			var status = panel.querySelector( '.b2bcp-new-address-status' );
			var save = function () {
				var inputs = all( panel, 'input[data-address-key]' );
				var missing = inputs.some( function ( input ) {
					return input.getAttribute( 'data-required' ) && input.value.trim() === '';
				} );
				if ( missing ) {
					status.textContent = i18n.required;
					return;
				}
				var body = new FormData();
				body.append( 'action', 'b2bcp_add_address' );
				body.append( 'nonce', i18n.addressNonce );
				inputs.forEach( function ( input ) {
					body.append( 'address[' + input.getAttribute( 'data-address-key' ) + ']', input.value.trim() );
				} );
				status.textContent = i18n.saving;
				fetch( i18n.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function ( res ) {
						if ( ! res || ! res.success ) {
							status.textContent = ( res && res.data && res.data.message ) || i18n.failed;
							return;
						}
						var a = res.data;
						extraOptions.push( a );
						all( box, 'select.b2bcp-person-address, select.b2bcp-all-address' ).forEach( function ( sel ) {
							sel.appendChild( new Option( a.label, a.id ) );
							if ( sel.classList.contains( 'b2bcp-person-address' ) && ! sel.value ) {
								sel.value = String( a.id );
							}
						} );
						var splitBody = box.querySelector( '.b2bcp-split-table tbody' );
						if ( splitBody ) {
							var tr = document.createElement( 'tr' );
							var td1 = document.createElement( 'td' );
							td1.textContent = a.label;
							var td2 = document.createElement( 'td' );
							td2.className = 'b2bcp-muted';
							td2.textContent = a.line;
							var td3 = document.createElement( 'td' );
							var q = document.createElement( 'input' );
							q.type = 'number';
							q.min = '0';
							q.className = 'b2bcp-branch-qty';
							q.name = 'b2bcp_qty[' + a.id + ']';
							q.setAttribute( 'aria-label', a.label );
							td3.appendChild( q );
							tr.appendChild( td1 );
							tr.appendChild( td2 );
							tr.appendChild( td3 );
							splitBody.appendChild( tr );
							splitBody.dispatchEvent( new Event( 'input', { bubbles: true } ) );
							q.focus();
						}
						inputs.forEach( function ( input ) {
							if ( input.getAttribute( 'data-address-key' ) !== 'country' ) {
								input.value = '';
							}
						} );
						status.textContent = i18n.saved;
					} )
					.catch( function () {
						status.textContent = i18n.failed;
					} );
			};
			panel.querySelector( '.b2bcp-save-address' ).addEventListener( 'click', save );
			// Enter inside the panel saves the address instead of submitting the order form.
			panel.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' ) {
					e.preventDefault();
					save();
				}
			} );
		}

		// Live preview: the first person's values on the company's sample piece.
		var preview = box.querySelector( '.b2bcp-preview' );
		if ( preview && tbody ) {
			var paint = function () {
				var first = tbody.querySelector( '.b2bcp-person' );
				all( preview, '[data-preview-key]' ).forEach( function ( line ) {
					var input = first ? first.querySelector( '[data-key="' + line.getAttribute( 'data-preview-key' ) + '"] input, [data-key="' + line.getAttribute( 'data-preview-key' ) + '"] select' ) : null;
					var v = input ? input.value.replace( /\s+/g, ' ' ).trim() : '';
					var fmt = input && input.getAttribute( 'data-format' );
					if ( v && fmt ) {
						v = fmt === 'upper' ? v.toUpperCase() : titleCase( v );
					}
					line.textContent = v || line.getAttribute( 'data-placeholder' );
					line.classList.toggle( 'is-empty', ! v );
				} );
			};
			tbody.addEventListener( 'input', paint );
			tbody.addEventListener( 'change', paint );
			tbody.addEventListener( 'click', function () {
				window.setTimeout( paint, 0 );
			} );
			paint();
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
