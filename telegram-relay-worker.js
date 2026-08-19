/**
 * رله اختصاصی API تلگرام — Cloudflare Worker (چندسایتی + امن)
 * ==========================================================
 * برای سرورهای داخل ایران که به api.telegram.org دسترسی ندارند.
 *
 * ✅ پشتیبانی چندسایتی:
 *   چون توکن هر ربات داخل مسیر است (/bot<TOKEN>/sendMessage)، چندین سایت
 *   با ربات‌های مختلف می‌توانند همزمان از یک رله استفاده کنند — هر سایت فقط
 *   «آدرس رله» را در تنظیماتش وارد می‌کند و تمام.
 *
 * 🔒 دو دربان اختیاری (برای کنترل اینکه چه کسی از رله استفاده کند):
 *
 *   ۱) ALLOWED_BOTS — لیست سفید ربات‌ها:
 *      فقط ربات‌هایی که پیشوند توکنشان اینجا باشد پذیرفته می‌شوند.
 *      مثال: ['123456789:', '987654321:']
 *      خالی = همه ربات‌ها مجاز (حالت ساده چندسایتی).
 *
 *   ۲) RELAY_KEY — کلید مشترک:
 *      اگر مقدار بدهید، فقط درخواست‌هایی که ?key=... دارند پذیرفته می‌شوند.
 *      سپس همین کلید را در «کلید امنیتی رله» تنظیمات افزونه هر سایت وارد کنید
 *      تا افزونه خودکار آن را به درخواست‌ها اضافه کند.
 *
 * مراحل راه‌اندازی (همان رله قبلی):
 *  ۱) در داشبورد Cloudflare ← Workers & Pages ← Create Worker
 *  ۲) این کد را جایگزین کد پیش‌فرض کنید و Deploy بزنید
 *  ۳) در Settings ← Domains & Routes یک Route اضافه کنید:
 *      tg-relay.example.com/*   (دامنه خودتان، با Proxy روشن)
 *  ۴) در افزونه وردپرس هر سایت:
 *      تنظیمات ← کارت تلگرام ← «آدرس API (سفارشی/رله)» = https://tg-relay.example.com
 *      (و در صورت تنظیم RELAY_KEY، همان کلید را در «کلید امنیتی رله» بگذارید)
 *  ۵) دکمه «تست تلگرام» را بزنید.
 *
 * امنیت: فقط مسیرهای /bot... مجاز هستند؛ رله پراکسیِ باز نمی‌شود
 * و با دو دربان بالا فقط ربات‌ها/سایت‌های مجاز استفاده می‌کنند.
 */

const TARGET = 'https://api.telegram.org'; // مقصد ثابت — فقط API تلگرام

/* ---------- تنظیمات چندسایتی (اختیاری) ---------- */

// لیست سفید ربات‌ها: پیشوند توکن هر رباتی که مجاز است.
// خالی بماند = همه ربات‌ها مجاز (چندسایتی باز).
const ALLOWED_BOTS = [
	// '123456789:',
	// '987654321:',
];

// کلید مشترک امنیتی: اگر پر باشد، درخواست‌ها باید ?key=... داشته باشند.
// (در تنظیمات هر افزونه، فیلد «کلید امنیتی رله» همین مقدار را وارد کنید)
const RELAY_KEY = '';

/* ---------- کد (نیازی به تغییر نیست) ---------- */

export default {
	async fetch(request) {
		const url = new URL(request.url);

		// فقط مسیرهای Bot API مجاز هستند (جلوگیری از سوءاستفاده به‌عنوان پراکسی باز)
		if (!url.pathname.startsWith('/bot')) {
			return new Response('Not Found', { status: 404 });
		}

		// دربان ۱: کلید مشترک
		if (RELAY_KEY !== '') {
			if (url.searchParams.get('key') !== RELAY_KEY) {
				return new Response('Forbidden', { status: 403 });
			}
			url.searchParams.delete('key'); // کلید به api.telegram.org فوروارد نشود
		}

		// دربان ۲: لیست سفید ربات‌ها
		if (ALLOWED_BOTS.length > 0) {
			const tokenPart = url.pathname.slice('/bot'.length); // مثل 123456789:AAF.../sendMessage
			const allowed = ALLOWED_BOTS.some(function (prefix) {
				return tokenPart.startsWith(prefix);
			});
			if (!allowed) {
				return new Response('Forbidden', { status: 403 });
			}
		}

		// ساخت آدرس مقصد: هرچه بعد از دامنه رله می‌آید به api.telegram.org می‌رود
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

		// ارسال بدنه برای POST (sendMessage و...)
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
