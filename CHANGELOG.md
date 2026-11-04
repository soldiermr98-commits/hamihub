# تاریخچه تغییرات پروژه HamiHubX.ir (CHANGELOG)

تمام تغییرات مرحله‌به‌مرحله این پروژه در این فایل ثبت می‌شوند.

## [مرحله S2] — ۱۴۰۵/۰۸/۱۳ (2026-11-04)
### اضافه‌شده
- **کنترلر صف جمع‌آوری خودکار در سمت سرور (`app/Controllers/CollectorController.php`):**
  - `GET /api/v1/collector/jobs`: دریافت لیست Jobها، وضعیت پرووایدرها (`provider_state`) و توکن Bearer برای پنل مدیریت.
  - `POST /api/v1/collector/jobs`: ثبت Job جدید (مثلاً «رشت + بسته کافه و رستوران») با استخراج خودکار مختصات شهر و کوئری‌های پرووایدر.
  - `POST /api/v1/collector/providers` و `POST /api/v1/collector/token`: روشن/خاموش کردن پرووایدرها، رفع توقف ۲۴ ساعته و چرخش توکن Bearer از پنل.
  - `GET /api/v1/collector/jobs/next`: تحویل Job بعدی در صف به برنامه `collector-remote` با احراز هویت Bearer Token.
  - `POST /api/v1/collector/ingest`: دریافت دسته‌ای حداکثر ۲۰۰ آیتم و حداکثر ۱ مگابایت، نرمال‌سازی شماره‌ها به `+98` و پیش‌شماره `013`، تشخیص تکراری و ثبت منابع چندگانه (`business_sources`).
  - **توقف ۲۴ ساعته و Fallback خودکار روی خطا:** در صورت بروز خطای `403`، `429` یا کپچا، پرووایدر فعلی تا ۲۴ ساعت در `provider_state` متوقف (`paused_until`) شده و Job خودکار به پرووایدر فعال بعدی منتقل می‌شود.
  - `POST /api/v1/collector/photo`: دریافت تصویر بهینه‌شده از `collector-remote` (در حالت `photo_policy = store`)، بررسی MIME واقعی و تبدیل به WebP با GD (بدون هیچ دانلود مستقیمی توسط هاست).
- **برنامه مستقل `collector-remote/` برای اجرا روی کامپیوتر مدیر:**
  - `collector-remote/config.sample.php` و `config.php`: تنظیم تایم‌اوت‌های دقیق اصلاحیه ۲ (`CONNECTTIMEOUT=5`, `TIMEOUT=12` برای سرور هاست، `TIMEOUT=90` برای Overpass، مدل Async Polling هر ۵ ثانیه تا سقف ۱۸۰ ثانیه برای `gosom`).
  - `collector-remote/Providers/ProviderInterface.php`: رابط استاندارد با متدهای `search()` و `details()`.
  - `OsmProvider.php` (Overpass API واقعی)، `GosomProvider.php` (REST محلی مدل async با polling هر ۵ ثانیه)، `CsvProvider.php` (همراه با دیتاست نمونه کافه‌های رشت در `imports/sample_rasht_cafes.csv`)، `NeshanProvider.php` (API رسمی نشان با کلید)، و اسکلت‌های `WikidataProvider`، `GooglePlacesProvider` و `BaladProvider`.
  - `collector-remote/run.php`: اسکریپت CLI اجرای کامل چرخه `Pull -> Search -> Batch Ingest`.
- **رابط کاربری صف جمع‌آوری در پنل مدیریت React (`CitiesAndCategoriesView.tsx`):**
  - نمایش آمار لحظه‌ای (در صف / در حال اجرا / یافت‌شده / ثبت جدید / تکراری / خطا)، ثبت Job جدید، مدیریت توکن Bearer و دکمه تست فوری اجرای Job «رشت + کافه».

---

## [مرحله S1] — ۱۴۰۵/۰۸/۱۲ (2026-11-03)
### اضافه‌شده
- مهاجرت فونت وزیرمتن به حالت ۱۰۰٪ لوکال (`public_html/assets/fonts/`).
- مایگریشن افزایشی `002_s1_seed_gilan_packs.sql` و سرویس‌های `SlugService` و `MediaService` (تبدیل به WebP در ۳ سایز 480/800/1200).
- کنترلرها و رابط کاربری CRUD شهر، دسته، بسته دسته و کسب‌وکار با قوانین UX بخش ۷.
- اصلاح `app/Core/DB.php` برای اتصال از طریق Unix Socket (`localhost` بدون پورت 3306) جهت سازگاری کامل با فایروال DirectAdmin.

---

## [مرحله S0] — ۱۴۰۵/۰۸/۱۲ (2026-11-03)
### اضافه‌شده
- راه‌اندازی ساختار پوشه‌ها، هسته PHP 8.3، مایگریشن پایه ۲۴ جدول (`001_initial_schema.sql`)، `install.php`، `check.php` و پوسته پنل React.
