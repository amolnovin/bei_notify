/**
 * اسکریپت صفحه تنظیمات — دکمه کپی آدرس REST (بدون وابستگی به jQuery)
 */
( function () {
	'use strict';

	/**
	 * کپی متن در کلیپ‌بورد (با پشتیبان برای مرورگرهای قدیمی).
	 *
	 * @param {string}   text   متن موردنظر.
	 * @param {Element}  button دکمه برای نمایش وضعیت.
	 */
	function copyText( text, button ) {
		var oldLabel = button.textContent;

		function done() {
			button.textContent = 'کپی شد ✓';
			button.classList.add( 'is-copied' );
			window.setTimeout( function () {
				button.textContent = oldLabel;
				button.classList.remove( 'is-copied' );
			}, 1800 );
		}

		function fallback() {
			var area = document.createElement( 'textarea' );
			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();
			try {
				document.execCommand( 'copy' );
				done();
			} catch ( error ) {
				/* هیچ اقدامی لازم نیست */
			}
			document.body.removeChild( area );
		}

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done, fallback );
		} else {
			fallback();
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-bei-copy]' );
		if ( ! button ) {
			return;
		}
		var target = document.getElementById( button.getAttribute( 'data-bei-copy' ) );
		if ( target ) {
			copyText( target.textContent.trim(), button );
		}
	} );

	/**
	 * دکمه‌های «استفاده از این شناسه» — پر کردن خودکار فیلد chat_id.
	 */
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-bei-fill]' );
		if ( ! button ) {
			return;
		}

		var input = document.getElementById( button.getAttribute( 'data-bei-fill' ) );
		if ( ! input ) {
			return;
		}

		var value = button.getAttribute( 'data-value' );
		var existing = input.value.trim();

		if ( '' === existing ) {
			input.value = value;
		} else if ( existing.split( /[\r\n,]+/ ).indexOf( value ) === -1 ) {
			input.value = existing + '\n' + value; // چند شناسه — هر کدام در یک خط
		}

		input.focus();
		input.scrollIntoView( { behavior: 'smooth', block: 'center' } );

		var oldLabel = button.textContent;
		button.textContent = 'انجام شد ✓';
		button.disabled = true;
		window.setTimeout( function () {
			button.textContent = oldLabel;
			button.disabled = false;
		}, 1500 );
	} );

	/**
	 * تب‌های صفحه ووکامرس (پیام‌های مدیر / پیام‌های مشتریان).
	 */
	document.addEventListener( 'click', function ( event ) {
		var tab = event.target.closest( '.bei-tab-btn' );
		if ( ! tab ) {
			return;
		}
		var wrap = tab.closest( '.bei-tabs' );
		if ( ! wrap ) {
			return;
		}

		var target = tab.getAttribute( 'data-bei-tab' );

		wrap.querySelectorAll( '.bei-tab-btn' ).forEach( function ( b ) {
			b.classList.toggle( 'is-active', b === tab );
		} );
		wrap.querySelectorAll( '.bei-tab-panel' ).forEach( function ( p ) {
			p.classList.toggle( 'is-active', p.getAttribute( 'data-bei-panel' ) === target );
		} );
	} );
}() );
