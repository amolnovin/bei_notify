/**
 * رله اختصاصی CallMeBot — Cloudflare Worker
 * ==========================================
 * برای سرورهای داخل ایران که به api.callmebot.com دسترسی ندارند
 * (خطای «cURL error 28: Connection timed out»).
 *
 * مراحل راه‌اندازی (دقیقاً مثل رله تلگرام شما):
 *  ۱) در داشبورد Cloudflare ← Workers & Pages ← Create Worker
 *  ۲) این کد را جایگزین کد پیش‌فرض کنید و Deploy بزنید
 *  ۳) در Settings ← Domains & Routes یک Route اضافه کنید:
 *      wa-relay.example.com/*   (دامنه خودتان، با Proxy روشن)
 *  ۴) در افزونه وردپرس:
 *      تنظیمات ← بله و ایتا ← کارت واتساپ ← «آدرس API (سفارشی/رله)»
 *      مقدار:  https://wa-relay.example.com
 *  ۵) دکمه «تست واتساپ» را بزنید.
 *
 * امنیت: فقط مسیر /whatsapp.php مجاز است؛ رله پراکسیِ باز نمی‌شود.
 *
 * ⏱️ مهلت upstream (UPSTREAM_TIMEOUT): اگر api.callmebot.com از سمت کلودفلر
 * پاسخ ندهد، به‌جای آویزان‌ماندن بی‌پایانِ کلاینت (خطاهای cURL error 28
 * با ۰ بایت و «Empty reply from server» در وردپرس) بعد از ۱۲ ثانیه پاسخ
 * سریع 504 برمی‌گردد تا افزونه بلافاصله مسیر مستقیم (Failover) را امتحان کند.
 */

const TARGET = 'https://api.callmebot.com'; // مقصد ثابت

// مهلت انتظار پاسخ مقصد (میلی‌ثانیه). ۱۲۰۰۰ = ۱۲ ثانیه.
const UPSTREAM_TIMEOUT = 12000;

export default {
	async fetch(request) {
		const url = new URL(request.url);

		// وضعیت سلامتی — برای تست از مرورگر و دکمهٔ «بررسی اتصال به رله» افزونه.
		if (url.pathname === '/' || url.pathname === '/health') {
			return new Response(JSON.stringify({ ok: true, service: 'bei-callmebot-relay', time: new Date().toISOString() }), {
				status: 200,
				headers: { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' },
			});
		}

		// فقط مسیرهای API متنی CallMeBot مجاز هستند
		if (url.pathname !== '/whatsapp.php') {
			return new Response('Not Found', { status: 404 });
		}

		// ساخت آدرس مقصد با حفظ کامل کوئری‌استرینگ (phone/text/apikey)
		const upstream = new URL(TARGET);
		upstream.pathname = url.pathname;
		upstream.search = url.search;

		const headers = new Headers(request.headers);
		headers.delete('host'); // Host باید مقصد باشد نه دامنه رله

		const init = {
			method: request.method,
			headers,
			redirect: 'manual',
		};

		// بدنه را کامل بخوان — جلوگیری از آویزان‌شدن استریم بدنه.
		if (request.method !== 'GET' && request.method !== 'HEAD') {
			init.body = await request.arrayBuffer();
		}

		// مهلت سخت‌گیرانه: اگر مقصد پاسخ نداد، 504 سریع برگردان تا کلاینت گیر نکند.
		const controller = new AbortController();
		const timer = setTimeout(() => controller.abort(), UPSTREAM_TIMEOUT);

		let response;
		try {
			response = await fetch(upstream.toString(), {
				...init,
				signal: controller.signal,
			});
		} catch (err) {
			return new Response('Gateway Timeout: upstream did not respond in ' + (UPSTREAM_TIMEOUT / 1000) + 's', {
				status: 504,
				headers: { 'content-type': 'text/plain; charset=utf-8' },
			});
		} finally {
			clearTimeout(timer);
		}

		const out = new Headers(response.headers);
		if (!out.has('cache-control')) {
			out.set('cache-control', 'no-store');
		}
		out.set('x-bei-relay', 'callmebot-relay');

		return new Response(response.body, {
			status: response.status,
			statusText: response.statusText,
			headers: out,
		});
	},
};
