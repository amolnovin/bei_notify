/**
 * دکمه ایجکس «دریافت شناسه مشتری» در صفحه تسویه حساب ووکامرس.
 * (بدون وابستگی به jQuery — با fetch)
 */
( function () {
	'use strict';

	var cfg = window.beiCheckout || null;
	if ( ! cfg ) {
		return;
	}

	/**
	 * ارسال ایجکس (فرم urlencoded).
	 *
	 * @param {string} action نام اکشن.
	 * @param {Object} extra  پارامترهای اضافی.
	 * @return {Promise}
	 */
	function post( action, extra ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		Object.keys( extra || {} ).forEach( function ( key ) {
			body.set( key, extra[ key ] );
		} );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * نظرسنجی وضعیت تا ذخیره شدن شناسه.
	 *
	 * @param {Object}  res    پاسخ اولیه (token و channel).
	 * @param {Element} btn    دکمه.
	 * @param {Element} status عنصر نمایش وضعیت.
	 */
	function poll( res, btn, status ) {
		var tries = 0;
		var timer = setInterval( function () {
			tries += 1;
			if ( tries > 40 ) { // حدود ۲ دقیقه.
				clearInterval( timer );
				btn.dataset.busy = '0';
				return;
			}

			post( 'bei_sub_status', { token: res.token, channel: btn.dataset.channel } ).then( function ( st ) {
				if ( st && st.success && st.data && st.data.status === 'done' ) {
					clearInterval( timer );
					btn.dataset.busy = '0';
					if ( status ) {
						status.textContent = cfg.i18n.done;
					}
				}
			} );
		}, 3000 );
	}

	/**
	 * دکمه رد اعلان (✕) — دیگر نمایش داده نمی‌شود.
	 */
	document.addEventListener( 'click', function ( event ) {
		var close = event.target.closest( '[data-bei-dismiss="1"]' );
		if ( ! close ) {
			return;
		}

		var bar = close.closest( '.bei-checkout-bar' );
		if ( bar ) {
			bar.style.display = 'none';
		}

		if ( cfg.promptNonce ) {
			var body = new URLSearchParams();
			body.set( 'action', 'bei_dismiss_prompt' );
			body.set( 'nonce', cfg.promptNonce );
			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} ).catch( function () {} );
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '.bei-checkout-btn' );
		if ( ! btn || btn.dataset.busy === '1' ) {
			return;
		}

		var status = btn.parentElement ? btn.parentElement.querySelector( '.bei-checkout-status' ) : null;

		btn.dataset.busy = '1';
		if ( status ) {
			status.textContent = cfg.i18n.waiting;
		}

		post( 'bei_subscribe', { channel: btn.dataset.channel } )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					btn.dataset.busy = '0';
					if ( status ) {
						status.textContent = ( res && res.data && res.data.message ) || cfg.i18n.error;
					}
					return;
				}

				if ( res.data.link ) {
					window.open( res.data.link, '_blank' );
				}

				if ( res.data.status === 'done' ) {
					btn.dataset.busy = '0';
					if ( status ) {
						status.textContent = cfg.i18n.done;
					}
					return;
				}

				poll( res.data, btn, status );
			} )
			.catch( function () {
				btn.dataset.busy = '0';
				if ( status ) {
					status.textContent = cfg.i18n.error;
				}
			} );
	} );
}() );
