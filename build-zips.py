#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
ساخت بسته‌های ZIP افزونه «اعلان‌رسان بله، ایتا، تلگرام و واتساپ».

خروجی‌ها (در پوشهٔ ریشهٔ ریپو):
  1. wp-bale-eitaa-notifier.zip       → نسخهٔ کامل (با کیت لایسنس WPLM)
  2. wp-bale-eitaa-notifier-free.zip  → نسخهٔ آزاد (بدون کیت لایسنس، بدون قفل)

اجرا:  python3 build-zips.py
"""

import os
import shutil
import tempfile
import zipfile

ROOT = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(ROOT, 'wp-bale-eitaa-notifier')
MAIN_FILE = 'wp-bale-eitaa-notifier.php'
KIT_DIR = 'wplm-client-kit'
VERSION = '3.1.8'

# ---------------------------------------------------------------- توابع کمکی

def zip_dir(src_dir, out_zip):
    """زیپ‌کردن پوشه با ریشهٔ wp-bale-eitaa-notifier/"""
    base = os.path.basename(os.path.normpath(src_dir))
    with zipfile.ZipFile(out_zip, 'w', zipfile.ZIP_DEFLATED) as z:
        for dirpath, dirnames, filenames in os.walk(src_dir):
            dirnames.sort()
            for fn in sorted(filenames):
                full = os.path.join(dirpath, fn)
                rel = os.path.relpath(full, os.path.dirname(src_dir))
                z.write(full, rel)
    print('ساخته شد: %s (%d بایت)' % (out_zip, os.path.getsize(out_zip)))


def replace_in_file(path, old, new, label):
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()
    if old not in content:
        raise SystemExit('✘ خطا در %s: متن موردنظر پیدا نشد:\n%s' % (label, old[:80]))
    content = content.replace(old, new, 1)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print('  ✔ %s' % label)


def build_free(src_dir, out_zip):
    """ساخت نسخهٔ آزاد: حذف کیت + هدر/کامنت‌های مخصوص نسخهٔ آزاد + readme آزاد"""
    tmp = tempfile.mkdtemp(prefix='bei-free-')
    dst = os.path.join(tmp, os.path.basename(os.path.normpath(src_dir)))
    shutil.copytree(src_dir, dst)
    shutil.rmtree(os.path.join(dst, KIT_DIR), ignore_errors=True)

    main = os.path.join(dst, MAIN_FILE)

    # ۱) هدر: Plugin URI و Description
    replace_in_file(
        main,
        ' * Plugin URI:        https://example.com/bale-eitaa-notifier',
        ' * Plugin URI:        https://amolnovin.ir',
        'Plugin URI نسخهٔ آزاد',
    )
    replace_in_file(
        main,
        ' * Description:       ارسال خودکار پیام به تلگرام، بله، ایتا و واتساپ (با مسیرهای رایگان CallMeBot و شماره تست متا)؛ با پل ایمیل سراسری، اتصال آماده به فرم‌ها و ووکامرس، API خارجی، پشتیبانی پراکسی/رله برای دور زدن فیلترینگ و سیستم لایسنس و بروزرسانی خودکار. بر اساس مستندات رسمی core.telegram.org ، docs.bale.ai ، eitaayar.ir/api و callmebot.com',
        ' * Description:       ارسال خودکار پیام به تلگرام، بله، ایتا و واتساپ (با مسیرهای رایگان CallMeBot و شماره تست متا)؛ با پل ایمیل سراسری، اتصال آماده به فرم‌ها و ووکامرس، API خارجی و پشتیبانی پراکسی/رله برای دور زدن فیلترینگ. نسخهٔ آزاد — بدون نیاز به لایسنس. بر اساس مستندات رسمی core.telegram.org ، docs.bale.ai ، eitaayar.ir/api و callmebot.com',
        'Description نسخهٔ آزاد',
    )

    # ۲) کامنت لایسنس
    replace_in_file(
        main,
        """/*
 * لایسنس و بروزرسانی خودکار (WPLM Client Kit آمل نوین).
 * صفحه فعال‌سازی: «لایسنس منیجر آمل نوین» (افزونه جداگانه)
 */""",
        """/*
 * نسخهٔ آزاد: کیت لایسنس (WPLM Client Kit) همراه این بسته نیست —
 * افزونه بدون نیاز به لایسنس و بدون بروزرسانی خودکار اجرا می‌شود.
 * نسخهٔ کامل (لایسنس + بروزرسانی خودکار + پشتیبانی): https://amolnovin.ir
 */""",
        'کامنت لایسنس نسخهٔ آزاد',
    )

    # ۳) کامنت «قفل لایسنس»
    replace_in_file(
        main,
        """/*
 * قفل لایسنس: امکانات اصلی افزونه فقط با لایسنس فعال بارگذاری می‌شود.
 * (در سایت مالک می‌توان با define( 'BEI_LICENSE_BYPASS', true ) قفل را برداشت.)
 *
 * نکته مهم: علاوه بر require، «نمونه‌سازی» هم لازم است — همه هوک‌های افزونه
 * (منوی admin_menu، REST، پل ایمیل و...) در سازنده کلاس‌ها ثبت می‌شوند و
 * بدون نمونه‌سازی، حتی با لایسنس فعال هم منو و امکانات ظاهر نمی‌شوند.
 */""",
        """/*
 * نسخهٔ آزاد: کیت لایسنس همراه بسته نیست؛ is_active() همیشه true برمی‌گردد
 * و هستهٔ افزونه بدون قفل بارگذاری می‌شود (نمونه‌سازی Bei_Plugin لازم است).
 */""",
        'کامنت قفل نسخهٔ آزاد',
    )

    # ۴) readme.txt مخصوص نسخهٔ آزاد
    readme_free = """=== Telegram, Bale, Eitaa & WhatsApp Notifier | اعلان‌رسان چندکاناله (نسخهٔ آزاد) ===
Contributors: amolnovin
Tags: bale, eitaa, telegram, whatsapp, callmebot, messenger, notification, forms, woocommerce, proxy
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: VERSION
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

افزونهٔ اعلان‌رسان بله، ایتا، تلگرام و واتساپ — نسخهٔ آزاد.

این بسته بدون کیت لایسنس منتشر می‌شود: نیازی به کلید لایسنس نیست و همهٔ
امکانات افزونه بدون قفل در دسترس است. این نسخه بروزرسانی خودکار ندارد.

نسخهٔ کامل (لایسنس + بروزرسانی خودکار + پشتیبانی): https://amolnovin.ir

== Description ==

ارسال خودکار پیام به تلگرام، بله، ایتا و واتساپ (با مسیرهای رایگان CallMeBot و شماره تست متا)؛ با پل ایمیل سراسری، اتصال آماده به فرم‌ها و ووکامرس، API خارجی و پشتیبانی پراکسی/رله برای دور زدن فیلترینگ.

**امکانات:**

* ارسال پیام متنی، تصویر و فایل به تلگرام، بله و ایتا
* ارسال رایگان به واتساپ: CallMeBot (پیام به شماره خودتان) و «شماره تست» متا
* پشتیبانی از پراکسی (HTTP/SOCKS5) و آدرس API جایگزین/رله برای دور زدن فیلترینگ
* پل ایمیل سراسری — هر ایمیلی که سایت بفرستد همزمان به پیام‌رسان می‌رود
* اتصال آماده با یک سوییچ: Contact Form 7 ، WPForms ، Gravity Forms ، Ninja Forms ، Fluent Forms ، فرم المنتور و ووکامرس
* اطلاع‌رسانی انتشار نوشته جدید
* API خارجی (REST) و تابع عمومی `bei_notify()` برای هر افزونه یا قالب
* ابزار «پیدا کردن شناسه عددی گفتگو» و ربات پاسخگوی «شناسه شما»
* پنل تنظیمات مدرن + دکمه‌های تست اتصال

== Installation ==

1. از منوی «افزونه‌ها ← افزودن» روی «بارگذاری افزونه» بزنید و فایل ZIP را انتخاب کنید.
2. افزونه را فعال کنید.
3. از منوی «اعلان‌رسان» تنظیمات هر پیام‌رسان را وارد و با دکمهٔ تست بررسی کنید.

== Changelog ==

= VERSION =
* نخستین انتشار بستهٔ نسخهٔ آزاد (بدون کیت لایسنس) — بدون نیاز به لایسنس، بدون بروزرسانی خودکار
""".replace('VERSION', VERSION)
    with open(os.path.join(dst, 'readme.txt'), 'w', encoding='utf-8') as f:
        f.write(readme_free)
    print('  ✔ readme.txt نسخهٔ آزاد')

    zip_dir(dst, out_zip)
    shutil.rmtree(tmp, ignore_errors=True)


def main():
    full_zip = os.path.join(ROOT, 'wp-bale-eitaa-notifier.zip')
    free_zip = os.path.join(ROOT, 'wp-bale-eitaa-notifier-free.zip')

    print('۱) بستهٔ کامل (با کیت لایسنس):')
    zip_dir(SRC, full_zip)

    print('۲) بستهٔ آزاد (بدون کیت لایسنس):')
    build_free(SRC, free_zip)

    print('\nتمام شد ✔')


if __name__ == '__main__':
    main()
