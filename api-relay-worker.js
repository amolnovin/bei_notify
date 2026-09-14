/**
 * رلهٔ چندمنظورهٔ API پیام‌رسان‌ها — Cloudflare Worker (نسخهٔ «سالم»)
 * =============================================================
 * یک ورکر برای هر سه مقصد — هر مسیر فقط به مقصدِ همان مسیر می‌رود،
 * پس رله هرگز «پراکسی باز» نمی‌شود:
 *
 *   GET  / یا /health          → وضعیت سلامتی (تست از مرورگر و از سرور)
 *   ANY  /bot<TOKEN>/...       → https://api.telegram.org      (تلگرام)
 *   GET  /whatsapp.php?...     → https://api.callmebot.com     (واتساپ رایگان CallMeBot)
 *   POST /<PHONE_ID>/messages  → https://graph.facebook.com    (اختیاری — WhatsApp Cloud API)
 *
 * چرا «سالم»؟ سه عیبی که در رله‌های قبلی باعث خطاهای
 * «cURL error 28: ... 0 bytes» و «Empty reply from server» می‌شد، اینجا رفع شده:
 *   ۱) مهلت سخت‌گیرانهٔ بالادست (UPSTREAM_TIMEOUT): اگر مقصد از سمت کلودفلر
 *      آویزان شود، به‌جای معلق‌ماندنِ کلاینت، 504 سریع برمی‌گردد تا افزونه
 *      بلافاصله مسیر جایگزین (Failover) را امتحان کند.
 *   ۲) بدنهٔ درخواست به‌صورت کامل بافر می‌شود (نه استریم آویزان).
 *   ۳) پاسخ‌ها با cache-control: no-store برمی‌گردند (بدون کش غلط).
 *
 * ⚠️ مهم‌ترین نکتهٔ ایران: آدرس پیش‌فرض ورکر (*.workers.dev) در شبکهٔ ملی
 * در لایهٔ SNI فیلتر می‌شود (TCP وصل می‌شود ولی TLS/پاسخ هرگز نمی‌رسد —
 * «مجازکردن» در پنل هاست اثری ندارد). حتماً برای ورکر یک «دامنهٔ اختصاصی»
 * بسازید:
 *   Cloudflare ← Workers & Pages ← ورکر ← Settings ← Domains & Routes
 *   ← Add ← Custom Domain ← مثلاً relay.example.com
 *
 * راه‌اندازی:
 *  ۱) این کد را در یک ورکر جدید Deploy کنید.
 *  ۲) Custom Domain بسازید (بالا).
 *  ۳) در افزونه:
 *     - واتساپ (CallMeBot): کارت واتساپ ← «آدرس API (سفارشی/رله)» = https://relay.example.com
 *     - تلگرام (اگر باز لازم شد): کارت تلگرام ← «آدرس API (سفارشی/رله)» = https://relay.example.com
 *     - اگر RELAY_KEY ست کردید: همان را در «کلید امنیتی رله» کارت تلگرام بگذارید
 *       (افزونه خودکار ?key= اضافه می‌کند؛ کلید فقط روی مسیر /bot اعمال می‌شود
 *       تا واتساپ/متا هم کار کنند)
 *  ۴) تست: https://relay.example.com/health در مرورگر باید JSON سبز برگرداند.
 *
 * امنیت (هر سه اختیاری):
 *  - RELAY_KEY: فقط مسیر تلگرام (/bot...) کلید می‌خواهد
 *  - ALLOWED_BOTS: فقط پیشوند توکن‌های مجاز به تلگرام می‌رسند
 *  - مسیرها کاملاً بسته‌اند: هر مسیر دیگر 404
 *
 * نکتهٔ Retry: این ورکر عمداً POST را خودکار تکرار نمی‌کند — اگر درخواست
 * واقعاً به تلگرام رسیده باشد ولی پاسخ گم شود، تکرار یعنی پیام تکراری.
 * مدیریت Retry/Failover کار افزونه است (نسخهٔ 3.1.8 به بعد).
 */

const TELEGRAM_TARGET = (typeof globalThis !== 'undefined' && globalThis.BEI_TG_TARGET) ? String(globalThis.BEI_TG_TARGET) : 'https://api.telegram.org';
const CALLMEBOT_TARGET = (typeof globalThis !== 'undefined' && globalThis.BEI_CMB_TARGET) ? String(globalThis.BEI_CMB_TARGET) : 'https://api.callmebot.com';
const META_TARGET      = (typeof globalThis !== 'undefined' && globalThis.BEI_META_TARGET) ? String(globalThis.BEI_META_TARGET) : 'https://graph.facebook.com';

// مهلت انتظار پاسخ بالادست (میلی‌ثانیه) — پیش‌فرض ۱۲ ثانیه.
const UPSTREAM_TIMEOUT = (typeof globalThis !== 'undefined' && globalThis.BEI_UPSTREAM_TIMEOUT) ? Number(globalThis.BEI_UPSTREAM_TIMEOUT) : 12000;

// کلید مشترک (اختیاری) — فقط مسیر تلگرام را قفل می‌کند.
const RELAY_KEY = (typeof globalThis !== 'undefined' && globalThis.BEI_RELAY_KEY !== undefined) ? String(globalThis.BEI_RELAY_KEY) : '';

// لیست سفید ربات‌ها (اختیاری): پیشوند توکن — مثل ['123456789:']
const ALLOWED_BOTS = (typeof globalThis !== 'undefined' && Array.isArray(globalThis.BEI_ALLOWED_BOTS)) ? globalThis.BEI_ALLOWED_BOTS : [];

/**
 * پاسخ JSON استاندارد.
 */
function jsonResponse(payload, status) {
	return new Response(JSON.stringify(payload), {
		status,
		headers: {
			'content-type': 'application/json; charset=utf-8',
			'cache-control': 'no-store',
		},
	});
}

/**
 * پاسخ سلامتی — بدون نیاز به کلید، برای تست از مرورگر/سرور/دکمهٔ افزونه.
 */
function healthResponse() {
	return jsonResponse({
		ok: true,
		service: 'bei-api-relay',
		time: new Date().toISOString(),
		upstreams: {
			telegram: TELEGRAM_TARGET,
			callmebot: CALLMEBOT_TARGET,
			meta: META_TARGET,
		},
	}, 200);
}

export default {
	async fetch(request) {
		const url = new URL(request.url);
		const path = url.pathname;

		// ۰) سلامتی — قبل از همهٔ دربان‌ها.
		if (path === '/' || path === '/health') {
			return healthResponse();
		}

		// ۱) مسیریابی — فقط مسیرهای شناخته‌شده.
		let target = null;

		if (path.startsWith('/bot')) {
			// دربان ۱: کلید مشترک (فقط تلگرام).
			if (RELAY_KEY !== '') {
				if (url.searchParams.get('key') !== RELAY_KEY) {
					return jsonResponse({ ok: false, error: 'forbidden: invalid relay key' }, 403);
				}
				url.searchParams.delete('key'); // کلید به api.telegram.org فوروارد نشود.
			}

			// دربان ۲: لیست سفید ربات‌ها.
			if (ALLOWED_BOTS.length > 0) {
				const tokenPart = path.slice('/bot'.length); // مثل 123456789:AAF.../sendMessage
				const allowed = ALLOWED_BOTS.some(function (prefix) {
					return tokenPart.startsWith(prefix);
				});
				if (!allowed) {
					return jsonResponse({ ok: false, error: 'forbidden: bot not allowed' }, 403);
				}
			}

			target = TELEGRAM_TARGET;
		} else if (path === '/whatsapp.php') {
			target = CALLMEBOT_TARGET;
		} else if (/^\/\d+\/messages$/.test(path)) {
			// WhatsApp Cloud API رسمی متا: /{phone_number_id}/messages
			target = META_TARGET;
		} else {
			return jsonResponse({ ok: false, error: 'not found' }, 404);
		}

		// ۲) ساخت درخواست بالادست (مسیر و کوئری عیناً حفظ می‌شوند).
		const upstream = new URL(target);
		upstream.pathname = path;
		upstream.search = url.search;

		const headers = new Headers(request.headers);
		headers.delete('host'); // Host باید مقصد باشد، نه دامنهٔ رله.

		const init = {
			method: request.method,
			headers,
			redirect: 'manual', // Redirect را دنبال نمی‌کنیم — افزونه خودش مدیریت می‌کند.
		};

		// بدنه را کامل بخوان (JSON کوچک) — جلوگیری از آویزان‌شدن استریم بدنه.
		if (request.method !== 'GET' && request.method !== 'HEAD') {
			init.body = await request.arrayBuffer();
		}

		// ۳) مهلت سخت‌گیرانه: اگر مقصد پاسخ نداد، 504 سریع برگردان تا کلاینت
		//    به‌جای معلق‌ماندن، بلافاصله Failover خودش را فعال کند.
		const controller = new AbortController();
		const timer = setTimeout(function () { controller.abort(); }, UPSTREAM_TIMEOUT);

		let response;
		try {
			response = await fetch(upstream.toString(), {
				method: init.method,
				headers: init.headers,
				redirect: init.redirect,
				body: init.body,
				signal: controller.signal,
			});
		} catch (err) {
			return jsonResponse({
				ok: false,
				error: 'upstream_timeout',
				ms: UPSTREAM_TIMEOUT,
				upstream: upstream.host,
				hint: 'gateway timeout — retry or failover',
			}, 504);
		} finally {
			clearTimeout(timer);
		}

		// ۴) پاسخ را همان‌طور برگردان + بدون کش + برچسب رله (برای دیباگ).
		const out = new Headers(response.headers);
		if (!out.has('cache-control')) {
			out.set('cache-control', 'no-store');
		}
		out.set('x-bei-relay', 'api-relay-worker');

		return new Response(response.body, {
			status: response.status,
			statusText: response.statusText,
			headers: out,
		});
	},
};
