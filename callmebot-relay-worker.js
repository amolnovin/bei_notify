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
 */

const TARGET = 'https://api.callmebot.com'; // مقصد ثابت

export default {
	async fetch(request) {
		const url = new URL(request.url);

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

		if (request.method !== 'GET' && request.method !== 'HEAD') {
			init.body = request.body;
			init.duplex = 'half'; // طبق استاندارد fetch برای بدنه استریمی
		}

		const response = await fetch(upstream.toString(), init);

		return new Response(response.body, {
			status: response.status,
			statusText: response.statusText,
			headers: response.headers,
		});
	},
};
