# گزارش فاز 6 — بافر APCu/Redis/دیتابیس و تابلوی سلامت

## Zusammenfassung (خلاصه)

تمام فایل‌های فاز 6 ایجاد و تست شده‌اند. ۲۸ تا ۲۸ سناریو تست موفق است. لنت (PHP 7.4) ۲۳/۲۳ فایل را تایید کرده است.

## فایل‌های ایجاد شده

| فایل | توضیح |
|------|-------|
| `includes/collect/buffer/class-buffer-apcu.php` |ран البافر APCu (حافظه مشترک) |
| `includes/collect/buffer/class-buffer-redis.php` |ран البافر Redis (لیست) |
| `includes/collect/buffer/class-buffer-db.php` |ран البافر دیتابیس (جدول rsd_buffer) |
| `includes/process/class-health.php` | گزارش سلامت، cron_next، table_sizes، مشکلات |

## فایل‌های به‌روزرسانی شده

| فایل | تغییر |
|------|-------|
| `includes/class-installer.php` | اضافه شدن جدول rsd_buffer، migration 1.1.0، RASAD_DB_VERSION → 1.1.0 |
| `includes/class-plugin.php` | هوک rasad_aggregated → Rasad_Process_Health::mark_aggregated |
| `includes/class-settings.php` | render_health_panel() از Rasad_Process_Health::report() استفاده می‌کند |
| `README.md` | مستندات ذخیره‌سازی vorüber |

## نتایج تست (test.js)

گروه A — کمک‌کننده‌های IP:
- A3 ip_in_cidrs: PASS
- A4 is_public_ip: PASS
- A5 anonymize_ip IPv4: PASS
- A6 anonymize_ip IPv6: PASS

گروه ب — سیاست رشته된 پرس‌وجو:
- B1 fbclid حذف شده، whitelist حفظ شده: PASS
- B2 UTM از مسیر حذف شده و در فیلدها ذخیره شده: PASS
- B3 تکه (fragment) حذف شده: PASS
- B4 اصطلاح UTM ثبت شده: PASS
- B5 cap_new_path مسیر را محدود می‌کند: PASS
- B6 cap=0 غیرفعال‌سازی cap: PASS

گروه ج — process():
- C1 کد بازگشتی عددی + last_status: PASS
- C2 بار معتبر → 204: PASS

گروه د — mesma منشاء (same_origin):
- D1 میزبان یکسان: PASS
- D2 www. حذف شده: PASS
- D3 منشأ khác رد شده: PASS
- D4 میزبان نامعتبر رد شده: PASS
- D5 پیکسل بدون Origin/Referer پذیرفته شده: PASS

گروه ه — to_buffer: E1 PASS
گ룹 و — user_has_role: F1 PASS

گروه ز — APCu: G1 is_available، G2 push/pop/count: PASS
گروه ح — Redis: H1 is_available، H2 push/pop/count: PASS
گروه ی — دیتابیس: I1 is_available، I2 push/pop/count: PASS
گروه ج — سلامت: J1 ساختار گزارش، J2 cron_next، J3 table_sizes: PASS

**کل: ۲۸ موفق، ۰ ناموفق**

## نتایج لنت (lint.js)

۲۳/۲۳ فایل سازگار با PHP 7.4 هستند. هیچ خطای سینتاکس یا استفاده از ویژگی‌های PHP 8+ یافت نشد.

## ملاحظات محیط آزمایشی

- APCu در محیط wasm لود نشده است؛ تست G2 حالت غیر فعال را مدیریت می‌کند (درایور لود می‌شود اما is_available() مقدار false برمی‌گرداند).
- `cron_next()` مقدار false برمی‌گرداند وقتی کرون زمان‌بندی نشده است (محیط آزمایشی).
- کلید گزارش سلامت `buffer_driver` است (نه `driver`).

## مراحل بعدی

بسته به دستور کاربر: انتظار دستور «continue» برای شروع فاز بعدی یا گذار به مرحله پس از گزارش فارسی.
