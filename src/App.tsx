import React, { useCallback, useEffect, useState } from 'react';
import {
  LogOut,
  Lock,
  CheckCircle2,
  AlertTriangle,
  RefreshCw,
  FolderTree,
  Database,
  Inbox,
  MapPin,
  Store,
  FileText,
  Settings,
  LayoutDashboard,
  Copy,
  Check,
  Play,
} from 'lucide-react';
import {
  AdminUser,
  BusinessItem,
  CategoryItem,
  CategoryPackItem,
  CityItem,
  NeighborhoodItem,
  RESERVED_SLUGS,
  fetchAuthMe,
  fetchBusinessesList,
  fetchCategoriesAndPacks,
  fetchCitiesAndNeighborhoods,
  getCheckPhpReport,
  isInstallLocked,
  loginAdmin,
  logoutAdmin,
  resetInstallerForDemo,
  runOneTimeInstaller,
  runWebMigrations,
} from './services/adminApi';
import { CitiesAndCategoriesView } from './components/CitiesAndCategoriesView';
import { BusinessesManagerView } from './components/BusinessesManagerView';
import { ReviewQueueView } from './components/ReviewQueueView';
import { ArticlesManagerView } from './components/ArticlesManagerView';
import { AiWorkerPanel } from './components/AiWorkerPanel';

type AdminRoute =
  | '/admin/dashboard'
  | '/admin/review'
  | '/admin/cities'
  | '/admin/businesses'
  | '/admin/articles'
  | '/admin/settings';

type ActiveSimulatorView = 'admin_spa' | 'install_php' | 'check_php' | 'deploy_guide';

const NAV_ITEMS: Array<{
  path: AdminRoute;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  stageNote: string;
}> = [
  { path: '/admin/dashboard', label: 'داشبورد', icon: LayoutDashboard, stageNote: 'فعال در S0/S1' },
  { path: '/admin/review', label: 'صندوق بازبینی', icon: Inbox, stageNote: 'فعال در مرحله S3' },
  { path: '/admin/cities', label: 'شهرها و جمع‌آوری', icon: MapPin, stageNote: 'فعال در مراحل S1/S2' },
  { path: '/admin/businesses', label: 'کسب‌وکارها', icon: Store, stageNote: 'فعال در مرحله S1' },
  { path: '/admin/articles', label: 'مقالات و هوش مصنوعی S5', icon: FileText, stageNote: 'فعال در مراحل S4/S5' },
  { path: '/admin/settings', label: 'تنظیمات و صف AI', icon: Settings, stageNote: 'فعال در S0-S5' },
];

export default function App() {
  const [activeTopView, setActiveTopView] = useState<ActiveSimulatorView>('admin_spa');
  const [adminRoute, setAdminRoute] = useState<AdminRoute>(() => {
    const hashRoute = window.location.hash.replace(/^#/, '');
    if (hashRoute.startsWith('/admin/') && NAV_ITEMS.some((item) => item.path === hashRoute)) {
      return hashRoute as AdminRoute;
    }
    const currentPath = window.location.pathname.replace(/\/+$/, '');
    if (
      currentPath.startsWith('/admin/') &&
      NAV_ITEMS.some((item) => item.path === currentPath)
    ) {
      return currentPath as AdminRoute;
    }
    return '/admin/review';
  });

  // وضعیت احراز هویت و CSRF
  const [currentUser, setCurrentUser] = useState<AdminUser | null>(null);
  const [csrfToken, setCsrfToken] = useState<string>('');
  const [authLoading, setAuthLoading] = useState<boolean>(true);

  // داده‌های مرحله S1
  const [cities, setCities] = useState<CityItem[]>([]);
  const [neighborhoods, setNeighborhoods] = useState<NeighborhoodItem[]>([]);
  const [categories, setCategories] = useState<CategoryItem[]>([]);
  const [packs, setPacks] = useState<CategoryPackItem[]>([]);
  const [businesses, setBusinesses] = useState<BusinessItem[]>([]);

  // فرم ورود به پنل
  const [loginUsername, setLoginUsername] = useState<string>('admin');
  const [loginPassword, setLoginPassword] = useState<string>('');
  const [loginError, setLoginError] = useState<string>('');
  const [loginSuccessMsg, setLoginSuccessMsg] = useState<string>('');

  // فرم نصب اولیه یک‌بارمصرف (install.php)
  const [installToken, setInstallToken] = useState<string>('hhx-install-2026-secret-token');
  const [installUsername, setInstallUsername] = useState<string>('admin');
  const [installDisplayName, setInstallDisplayName] = useState<string>('مدیر ارشد حامی‌هاب');
  const [installPassword, setInstallPassword] = useState<string>('Gilan@1405Strong');
  const [installFeedback, setInstallFeedback] = useState<{
    type: 'ok' | 'err' | null;
    text: string;
  }>({ type: null, text: '' });
  const [lockedState, setLockedState] = useState<boolean>(() => isInstallLocked());

  // وضعیت اجرای مایگریشن وب در تنظیمات
  const [migrationBanner, setMigrationBanner] = useState<string>('');
  const [copiedCmd, setCopiedCmd] = useState<string | null>(null);

  const checkReport = getCheckPhpReport();

  const loadS1Entities = useCallback(async () => {
    const [cityRes, catRes, bizRes] = await Promise.all([
      fetchCitiesAndNeighborhoods(),
      fetchCategoriesAndPacks(),
      fetchBusinessesList(),
    ]);
    setCities(Array.isArray(cityRes?.cities) ? cityRes.cities : []);
    setNeighborhoods(Array.isArray(cityRes?.neighborhoods) ? cityRes.neighborhoods : []);
    setCategories(Array.isArray(catRes?.categories) ? catRes.categories : []);
    setPacks(Array.isArray(catRes?.packs) ? catRes.packs : []);
    setBusinesses(Array.isArray(bizRes) ? bizRes : []);
  }, []);

  useEffect(() => {
    let mounted = true;
    Promise.all([fetchAuthMe(), loadS1Entities()])
      .then(([authRes]) => {
        if (!mounted) return;
        setCurrentUser(authRes?.user ?? null);
        setCsrfToken(authRes?.csrf_token ?? '');
        setLockedState(isInstallLocked());
        setAuthLoading(false);
      })
      .catch(() => {
        if (!mounted) return;
        setAuthLoading(false);
      });
    return () => {
      mounted = false;
    };
  }, [loadS1Entities]);

  const navigateAdmin = (route: AdminRoute) => {
    setAdminRoute(route);
    try {
      // استفاده از Hash در کنار مسیر پایه /admin/ تا رفرش صفحه در هاست اشتراکی حتی بدون .htaccess هرگز خطا ندهد
      if (window.location.pathname.startsWith('/admin')) {
        window.history.replaceState({}, '', `/admin/#${route}`);
      } else {
        window.location.hash = route;
      }
    } catch {
      // ignore iframe history restrictions
    }
  };

  const handleInstallSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const res = runOneTimeInstaller({
      token: installToken,
      username: installUsername,
      display_name: installDisplayName,
      password: installPassword,
    });

    if (!res.ok) {
      setInstallFeedback({ type: 'err', text: res.error || 'خطا در نصب اولیه' });
      setLockedState(isInstallLocked());
      return;
    }

    setLockedState(true);
    setInstallFeedback({
      type: 'ok',
      text: res.message || 'اولین مدیر ساخته شد و فایل قفل ایجاد گردید.',
    });
    setLoginUsername(installUsername);
    setLoginPassword(installPassword);
  };

  const handleLoginSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoginError('');
    setLoginSuccessMsg('');

    const res = await loginAdmin({
      username: loginUsername,
      password: loginPassword,
      csrfToken,
    });

    if (!res.ok || !res.user) {
      setLoginError(res.error || 'ورود ناموفق بود.');
      return;
    }

    setCurrentUser(res.user);
    if (res.csrf_token) {
      setCsrfToken(res.csrf_token);
    }
    setLoginPassword('');
    setLoginSuccessMsg('ورود با موفقیت انجام شد.');
    await loadS1Entities();
  };

  const handleLogout = async () => {
    const res = await logoutAdmin(csrfToken);
    setCurrentUser(null);
    setCsrfToken(res.csrf_token);
    setLoginSuccessMsg('');
    setLoginError('');
  };

  const handleTriggerWebMigrations = async () => {
    const res = await runWebMigrations(csrfToken);
    setMigrationBanner(res.message);
    await loadS1Entities();
  };

  const copyText = (id: string, text: string) => {
    navigator.clipboard.writeText(text);
    setCopiedCmd(id);
    setTimeout(() => setCopiedCmd(null), 1800);
  };

  const shamsiToday = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
    dateStyle: 'full',
    timeZone: 'Asia/Tehran',
  }).format(new Date());

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-50 via-blue-50/35 to-slate-100 text-slate-900 flex flex-col" dir="rtl">
      {/* نوار بالایی شیشه‌ای (Glassmorphism) قرارداد ۳ ناحیه‌ای با تم لاجوردی و لوگوی رسمی HamiHubX */}
      <header className="sticky top-0 z-40 flex items-center justify-between gap-8 px-6 py-3 bg-[#050B1E]/90 backdrop-blur-xl text-white border-b border-sky-400/25 shadow-lg">
        <a
          href="/admin/dashboard"
          onClick={(e) => {
            e.preventDefault();
            setActiveTopView('admin_spa');
            navigateAdmin('/admin/dashboard');
          }}
          className="inline-flex items-center gap-3 text-base font-bold tracking-tight text-white whitespace-nowrap shrink-0"
        >
          <img
            src="/assets/img/favicon.png"
            alt="HamiHubX"
            className="w-9 h-9 rounded-lg border border-sky-400/35 shadow-sm object-cover"
          />
          <span>
            حامی‌هاب گیلان{' '}
            <span className="font-mono font-extrabold text-sky-400" dir="ltr">
              HamiHubX
            </span>
          </span>
        </a>

        <nav className="flex items-center gap-6 text-sm font-medium text-slate-300 overflow-x-auto">
          <button
            type="button"
            onClick={() => setActiveTopView('admin_spa')}
            className={`py-1 transition-colors whitespace-nowrap shrink-0 border-b-2 ${
              activeTopView === 'admin_spa'
                ? 'border-sky-400 text-white font-semibold'
                : 'border-transparent hover:text-white'
            }`}
          >
            پنل مدیریت (/admin/)
          </button>
          <button
            type="button"
            onClick={() => setActiveTopView('install_php')}
            className={`py-1 transition-colors whitespace-nowrap shrink-0 border-b-2 ${
              activeTopView === 'install_php'
                ? 'border-sky-400 text-white font-semibold'
                : 'border-transparent hover:text-white'
            }`}
          >
            نصب‌کننده (install.php)
          </button>
          <button
            type="button"
            onClick={() => setActiveTopView('check_php')}
            className={`py-1 transition-colors whitespace-nowrap shrink-0 border-b-2 ${
              activeTopView === 'check_php'
                ? 'border-sky-400 text-white font-semibold'
                : 'border-transparent hover:text-white'
            }`}
          >
            گزارش سرور (check.php)
          </button>
          <button
            type="button"
            onClick={() => setActiveTopView('deploy_guide')}
            className={`py-1 transition-colors whitespace-nowrap shrink-0 border-b-2 ${
              activeTopView === 'deploy_guide'
                ? 'border-sky-400 text-white font-semibold'
                : 'border-transparent hover:text-white'
            }`}
          >
            راهنمای آپلود در DirectAdmin
          </button>
        </nav>

        <div className="flex items-center gap-3 shrink-0">
          {currentUser ? (
            <button
              type="button"
              onClick={handleLogout}
              className="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold text-slate-200 bg-white/10 rounded-lg hover:bg-rose-500/20 hover:text-rose-200 transition-colors whitespace-nowrap shrink-0"
            >
              <LogOut className="w-3.5 h-3.5" />
              خروج ({currentUser.username})
            </button>
          ) : (
            <button
              type="button"
              onClick={() => setActiveTopView('install_php')}
              className="px-4 py-2 text-xs font-semibold text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors whitespace-nowrap shrink-0"
            >
              ساخت اولین مدیر
            </button>
          )}
        </div>
      </header>

      <div className="flex-1 flex flex-col">
        {activeTopView === 'install_php' && (
          <main className="max-w-3xl w-full mx-auto px-6 py-10">
            <div className="bg-white border border-slate-200 rounded-xl p-8">
              <div className="flex items-center justify-between gap-4 pb-5 mb-6 border-b border-slate-200">
                <div>
                  <h1 className="text-xl font-bold text-slate-900">
                    نصب‌کننده یک‌بارمصرف تحت وب (public_html/install.php)
                  </h1>
                  <p className="text-sm text-slate-600 mt-1">
                    بدون نیاز به ترمینال/SSH، فایل‌های مایگریشن <code className="font-mono text-xs">001</code> و{' '}
                    <code className="font-mono text-xs">002</code> را اجرا کرده و اولین مدیر را می‌سازد.
                  </p>
                </div>
                {lockedState && (
                  <button
                    type="button"
                    onClick={() => {
                      resetInstallerForDemo();
                      setLockedState(false);
                      setCurrentUser(null);
                      setInstallFeedback({ type: null, text: '' });
                    }}
                    className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg whitespace-nowrap shrink-0"
                  >
                    <RefreshCw className="w-3.5 h-3.5" />
                    بازنشانی قفل برای تست مجدد
                  </button>
                )}
              </div>

              {installFeedback.type === 'err' && (
                <div className="mb-6 p-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-sm flex items-start gap-3">
                  <AlertTriangle className="w-5 h-5 shrink-0 mt-0.5 text-rose-600" />
                  <div>{installFeedback.text}</div>
                </div>
              )}

              {installFeedback.type === 'ok' && (
                <div className="mb-6 p-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-900 text-sm flex items-start justify-between gap-4">
                  <div className="flex items-start gap-3">
                    <CheckCircle2 className="w-5 h-5 shrink-0 mt-0.5 text-emerald-700" />
                    <div>{installFeedback.text}</div>
                  </div>
                  <button
                    type="button"
                    onClick={() => setActiveTopView('admin_spa')}
                    className="px-4 py-2 bg-emerald-700 text-white text-xs font-semibold rounded-lg hover:bg-emerald-800 whitespace-nowrap shrink-0"
                  >
                    رفتن به پنل مدیریت
                  </button>
                </div>
              )}

              {lockedState ? (
                <div className="p-6 bg-slate-50 border border-slate-200 rounded-lg text-center">
                  <Lock className="w-8 h-8 text-slate-600 mx-auto mb-3" />
                  <h2 className="text-base font-bold text-slate-900">
                    نصب اولیه انجام شده و فایل قفل فعال است (HTTP 403 Locked)
                  </h2>
                  <p className="text-sm text-slate-600 mt-2 max-w-xl mx-auto">
                    هر ۲۴ جدول و داده‌های اولیه استان گیلان ساخته شده‌اند.
                  </p>
                  <div className="mt-5">
                    <button
                      type="button"
                      onClick={() => setActiveTopView('admin_spa')}
                      className="px-4 py-2 text-xs font-semibold text-white bg-slate-900 rounded-lg hover:bg-slate-800"
                    >
                      ورود به پنل مدیریت (/admin/)
                    </button>
                  </div>
                </div>
              ) : (
                <form onSubmit={handleInstallSubmit} className="space-y-5">
                  <div>
                    <label className="block text-sm font-semibold text-slate-800">
                      توکن نصب (<code className="font-mono text-xs">app.install_token</code>)
                    </label>
                    <input
                      type="text"
                      dir="ltr"
                      value={installToken}
                      onChange={(e) => setInstallToken(e.target.value)}
                      className="mt-1.5 w-full px-3.5 py-2.5 text-sm font-mono bg-slate-50 border border-slate-300 rounded-lg"
                      required
                    />
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                      <label className="block text-sm font-semibold text-slate-800">نام کاربری مدیر (انگلیسی)</label>
                      <input
                        type="text"
                        dir="ltr"
                        value={installUsername}
                        onChange={(e) => setInstallUsername(e.target.value)}
                        className="mt-1.5 w-full px-3.5 py-2.5 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                        required
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-semibold text-slate-800">نام نمایشی (فارسی)</label>
                      <input
                        type="text"
                        value={installDisplayName}
                        onChange={(e) => setInstallDisplayName(e.target.value)}
                        className="mt-1.5 w-full px-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg"
                        required
                      />
                    </div>
                  </div>
                  <div>
                    <label className="block text-sm font-semibold text-slate-800">رمز عبور (حداقل ۸ کاراکتر)</label>
                    <input
                      type="text"
                      dir="ltr"
                      value={installPassword}
                      onChange={(e) => setInstallPassword(e.target.value)}
                      className="mt-1.5 w-full px-3.5 py-2.5 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                      required
                    />
                  </div>
                  <div className="flex justify-end">
                    <button
                      type="submit"
                      className="px-6 py-2.5 bg-emerald-700 hover:bg-emerald-800 text-white text-sm font-semibold rounded-lg"
                    >
                      ساخت جداول، ثبت مدیر ارشد و قفل کردن نصب‌کننده
                    </button>
                  </div>
                </form>
              )}
            </div>
          </main>
        )}

        {activeTopView === 'check_php' && (
          <main className="max-w-5xl w-full mx-auto px-6 py-10">
            <div className="bg-white border border-slate-200 rounded-xl p-8">
              <div className="flex flex-wrap items-center justify-between gap-4 pb-5 mb-6 border-b border-slate-200">
                <div>
                  <h1 className="text-xl font-bold text-slate-900">
                    گزارش سلامت سرور و اجرای مایگریشن بدون ترمینال (public_html/check.php)
                  </h1>
                  <p className="text-sm text-slate-600 mt-1">
                    زمان سرور (Asia/Tehran):{' '}
                    <span className="font-mono text-xs text-slate-800 tabular-nums" dir="ltr">
                      {checkReport.timestamp_tehran}
                    </span>
                  </p>
                </div>
                <button
                  type="button"
                  onClick={handleTriggerWebMigrations}
                  className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-semibold rounded-lg"
                >
                  <Play className="w-3.5 h-3.5" />
                  اجرای مایگریشن‌های دیتابیس (بدون ترمینال)
                </button>
              </div>

              {migrationBanner && (
                <div className="mb-6 p-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-900 text-xs font-semibold">
                  {migrationBanner}
                </div>
              )}

              <div className="overflow-x-auto">
                <table className="w-full text-sm border-collapse">
                  <thead>
                    <tr className="border-b border-slate-200 text-slate-500 text-xs">
                      <th className="py-3 px-4 text-right font-semibold">شاخص بررسی</th>
                      <th className="py-3 px-4 text-right font-semibold">مقدار</th>
                      <th className="py-3 px-4 text-right font-semibold">وضعیت</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-200">
                    <tr>
                      <td className="py-3 px-4 font-medium">نسخه PHP</td>
                      <td className="py-3 px-4 font-mono text-xs tabular-nums" dir="ltr">
                        {checkReport.php.version}
                      </td>
                      <td className="py-3 px-4 text-emerald-700 font-semibold">معتبر (PHP 8.3)</td>
                    </tr>
                    <tr>
                      <td className="py-3 px-4 font-medium">افزونه GD و تبدیل تصویر WebP</td>
                      <td className="py-3 px-4 font-mono text-xs" dir="ltr">
                        gd: true | gd_webp: true (Sizes: 480 / 800 / 1200)
                      </td>
                      <td className="py-3 px-4 text-emerald-700 font-semibold">فعال</td>
                    </tr>
                    <tr>
                      <td className="py-3 px-4 font-medium">فونت وزیرمتن لوکال (Self-Hosted)</td>
                      <td className="py-3 px-4 font-mono text-xs" dir="ltr">
                        /assets/fonts/Vazirmatn-Regular.woff2 &amp; Bold.woff2
                      </td>
                      <td className="py-3 px-4 text-emerald-700 font-semibold">لوکال (بدون CDN خارجی)</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div className="mt-6 p-4 bg-slate-50 border border-slate-200 rounded-lg">
                <div className="flex items-center justify-between gap-4 mb-2">
                  <span className="text-xs font-bold text-slate-800">
                    دستور آماده Cron Job برای پنل DirectAdmin:
                  </span>
                  <button
                    type="button"
                    onClick={() => copyText('cron', checkReport.directadmin_cron.recommended_cron_line)}
                    className="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700 hover:text-emerald-800"
                  >
                    {copiedCmd === 'cron' ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
                    {copiedCmd === 'cron' ? 'کپی شد' : 'کپی دستور Cron'}
                  </button>
                </div>
                <pre
                  dir="ltr"
                  className="text-xs font-mono text-slate-800 bg-white border border-slate-200 rounded p-3 overflow-x-auto"
                >
                  {checkReport.directadmin_cron.recommended_cron_line}
                </pre>
              </div>
            </div>
          </main>
        )}

        {activeTopView === 'deploy_guide' && (
          <main className="max-w-5xl w-full mx-auto px-6 py-10 space-y-6">
            <div className="bg-white border border-slate-200 rounded-xl p-8 space-y-5">
              <h1 className="text-xl font-bold text-slate-900">
                راهنمای گام‌به‌گام آپلود مرحله S0 + S1 روی هاست اشتراکی DirectAdmin (بدون ترمینال)
              </h1>
              <p className="text-sm text-slate-600">
                بیلد نهایی پنل React با مسیر پایه <code className="font-mono text-xs">/admin/</code> و فونت‌های لوکال وزیرمتن از قبل داخل پوشه <code className="font-mono text-xs">public_html/admin/</code> ساخته شده است.
              </p>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="p-5 bg-slate-50 border border-slate-200 rounded-lg">
                  <h2 className="text-sm font-bold text-slate-900 flex items-center gap-2 mb-3">
                    <FolderTree className="w-4 h-4 text-emerald-700" />
                    ۱. پوشه‌هایی که کنار public_html آپلود می‌کنید
                  </h2>
                  <p className="text-xs text-slate-600 mb-2">
                    در File Manager دایرکت‌ادمین به مسیر <code className="font-mono" dir="ltr">/domains/hamihubx.ir/</code> بروید و این ۶ پوشه را کنار <code className="font-mono">public_html</code> قرار دهید:
                  </p>
                  <ul className="space-y-1 text-xs font-mono text-slate-800" dir="ltr">
                    <li>app/ (Core, Controllers, Services)</li>
                    <li>config/ (config.php &amp; config.sample.php)</li>
                    <li>database/migrations/ (001 &amp; 002 SQL files)</li>
                    <li>cron/ (worker.php)</li>
                    <li>storage/logs/</li>
                    <li>bin/</li>
                  </ul>
                </div>

                <div className="p-5 bg-slate-50 border border-slate-200 rounded-lg">
                  <h2 className="text-sm font-bold text-slate-900 flex items-center gap-2 mb-3">
                    <Database className="w-4 h-4 text-emerald-700" />
                    ۲. فایل‌هایی که داخل public_html آپلود می‌کنید
                  </h2>
                  <ul className="space-y-1 text-xs font-mono text-slate-800" dir="ltr">
                    <li>public_html/index.php &amp; .htaccess</li>
                    <li>public_html/install.php &amp; check.php</li>
                    <li>public_html/api/index.php</li>
                    <li>public_html/admin/ (index.html, .htaccess, assets/)</li>
                    <li>public_html/assets/fonts/ (Vazirmatn woff2)</li>
                    <li>public_html/assets/css/public.css</li>
                    <li>public_html/cache/pages/ &amp; uploads/</li>
                  </ul>
                </div>
              </div>
            </div>
          </main>
        )}

        {activeTopView === 'admin_spa' && (
          <div className="flex-1 flex">
            {authLoading ? (
              <div className="flex-1 flex items-center justify-center p-12 text-sm text-slate-500">
                در حال بارگذاری پنل مدیریت...
              </div>
            ) : !currentUser ? (
              <main className="flex-1 flex items-center justify-center p-6">
                <div className="max-w-md w-full bg-white/85 backdrop-blur-xl border border-blue-200/80 shadow-xl rounded-2xl p-8">
                  <h1 className="text-xl font-bold text-slate-900 mb-1">ورود به پنل مدیریت حامی‌هاب گیلان</h1>
                  <p className="text-xs text-slate-500 mb-6">
                    نام کاربری پیش‌فرض دمو: <code className="font-mono">admin</code> | رمز:{' '}
                    <code className="font-mono">Gilan@1405Strong</code>
                  </p>

                  {loginError && (
                    <div className="mb-5 p-3.5 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-xs">
                      {loginError}
                    </div>
                  )}

                  <form onSubmit={handleLoginSubmit} className="space-y-4">
                    <div>
                      <label className="block text-xs font-semibold text-slate-700">نام کاربری</label>
                      <input
                        type="text"
                        dir="ltr"
                        value={loginUsername}
                        onChange={(e) => setLoginUsername(e.target.value)}
                        className="mt-1.5 w-full px-3.5 py-2.5 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                        required
                      />
                    </div>
                    <div>
                      <label className="block text-xs font-semibold text-slate-700">رمز عبور</label>
                      <input
                        type="password"
                        dir="ltr"
                        value={loginPassword}
                        onChange={(e) => setLoginPassword(e.target.value)}
                        className="mt-1.5 w-full px-3.5 py-2.5 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                        required
                      />
                    </div>
                    <button
                      type="submit"
                      className="w-full py-2.5 px-4 bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold rounded-lg"
                    >
                      ورود به پنل مدیریت
                    </button>
                  </form>
                </div>
              </main>
            ) : (
              <div className="flex-1 flex">
                {/* منوی کناری ۶ بخشی طبق بخش ۷ مشخصات */}
                <aside className="w-64 bg-white border-l border-slate-200 flex flex-col justify-between shrink-0">
                  <div className="p-4">
                    <div className="px-3 py-2.5 mb-4 bg-slate-50 border border-slate-200 rounded-lg">
                      <div className="text-xs font-bold text-slate-900">{currentUser.display_name}</div>
                      <div className="text-[11px] text-slate-500 mt-0.5">
                        نقش: {currentUser.role === 'admin' ? 'مدیر ارشد (admin)' : 'ویرایشگر (editor)'}
                      </div>
                    </div>

                    <nav className="space-y-1">
                      {NAV_ITEMS.map((item) => {
                        const Icon = item.icon;
                        const active = adminRoute === item.path;
                        return (
                          <button
                            key={item.path}
                            type="button"
                            onClick={() => navigateAdmin(item.path)}
                            className={`w-full flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-medium transition-colors ${
                              active
                                ? 'bg-blue-50 text-blue-950 font-semibold border border-blue-200/80'
                                : 'text-slate-700 hover:bg-slate-100'
                            }`}
                          >
                            <span className="flex items-center gap-2.5">
                              <Icon className={`w-4 h-4 ${active ? 'text-blue-600' : 'text-slate-500'}`} />
                              <span>{item.label}</span>
                            </span>
                          </button>
                        );
                      })}
                    </nav>
                  </div>

                  <div className="p-4 border-t border-slate-200 text-xs text-slate-500 space-y-1.5">
                    <div>{shamsiToday}</div>
                    <div className="font-mono text-[11px] text-slate-400" dir="ltr">
                      {adminRoute}
                    </div>
                  </div>
                </aside>

                {/* ناحیه اصلی پنل */}
                <main className="flex-1 p-8 overflow-y-auto">
                  {loginSuccessMsg && (
                    <div className="mb-6 p-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-900 text-sm flex items-center justify-between">
                      <span>{loginSuccessMsg}</span>
                      <button
                        type="button"
                        onClick={() => setLoginSuccessMsg('')}
                        className="text-xs text-emerald-700 underline"
                      >
                        بستن
                      </button>
                    </div>
                  )}

                  {adminRoute === '/admin/dashboard' && (
                    <div className="space-y-6">
                      <div className="flex items-center justify-between bg-white/80 backdrop-blur-md border border-blue-200/70 rounded-2xl p-5 shadow-sm">
                        <div>
                          <h1 className="text-xl font-bold text-slate-900">داشبورد مدیریت حامی‌هاب گیلان</h1>
                          <p className="text-xs text-slate-500 mt-1">
                            خلاصه وضعیت داده‌های شهرها، دسته‌ها، کسب‌وکارها و صف هوش مصنوعی بدون توهم (مراحل S0 تا S5)
                          </p>
                        </div>
                        <div className="flex items-center gap-3">
                          <button
                            type="button"
                            onClick={() => navigateAdmin('/admin/businesses')}
                            className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold rounded-lg shadow-sm"
                          >
                            + ثبت سریع کسب‌وکار
                          </button>
                        </div>
                      </div>

                      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div className="bg-white/85 backdrop-blur-md border border-blue-200/70 rounded-xl p-5 shadow-sm">
                          <div className="text-xs text-slate-500">شهرهای فعال گیلان</div>
                          <div className="text-2xl font-bold text-slate-900 mt-1 font-mono tabular-nums">
                            {cities.length}
                          </div>
                          <div className="text-xs text-blue-700 mt-1">افزودن فوری با یک نام</div>
                        </div>
                        <div className="bg-white/85 backdrop-blur-md border border-blue-200/70 rounded-xl p-5 shadow-sm">
                          <div className="text-xs text-slate-500">دسته‌ها و بسته‌های جستجو</div>
                          <div className="text-2xl font-bold text-slate-900 mt-1 font-mono tabular-nums">
                            {categories.length} دسته · {packs.length} بسته
                          </div>
                          <div className="text-xs text-slate-600 mt-1">آماده جمع‌آوری خودکار S2</div>
                        </div>
                        <div className="bg-white/85 backdrop-blur-md border border-blue-200/70 rounded-xl p-5 shadow-sm">
                          <div className="text-xs text-slate-500">کسب‌وکارهای ثبت‌شده</div>
                          <div className="text-2xl font-bold text-slate-900 mt-1 font-mono tabular-nums">
                            {businesses.length}
                          </div>
                          <div className="text-xs text-slate-600 mt-1">با امتیاز وزنی بیزین</div>
                        </div>
                        <div className="bg-white/85 backdrop-blur-md border border-blue-200/70 rounded-xl p-5 shadow-sm">
                          <div className="text-xs text-slate-500">موتور AI بدون توهم (S5)</div>
                          <div className="text-lg font-bold text-blue-700 mt-1">گاردریل + used_fields</div>
                          <div className="text-xs text-slate-600 mt-1">فونت لوکال + کش دیسک</div>
                        </div>
                      </div>

                      <AiWorkerPanel csrfToken={csrfToken} onRefreshParent={loadS1Entities} />
                    </div>
                  )}

                  {adminRoute === '/admin/cities' && (
                    <CitiesAndCategoriesView
                      cities={cities}
                      categories={categories}
                      packs={packs}
                      csrfToken={csrfToken}
                      onRefreshData={loadS1Entities}
                    />
                  )}

                  {adminRoute === '/admin/businesses' && (
                    <BusinessesManagerView
                      businesses={businesses}
                      cities={cities}
                      neighborhoods={neighborhoods}
                      categories={categories}
                      csrfToken={csrfToken}
                      onRefreshData={loadS1Entities}
                    />
                  )}

                  {adminRoute === '/admin/settings' && (
                    <div className="space-y-6">
                      <div className="bg-white/85 backdrop-blur-md border border-blue-200/80 rounded-2xl p-6 space-y-5 shadow-sm">
                        <h1 className="text-lg font-bold text-slate-900">تنظیمات سیستم و مایگریشن تحت وب حامی‌هاب گیلان</h1>
                        <p className="text-xs text-slate-600">
                          چون روی هاست اشتراکی DirectAdmin دسترسی ترمینال (SSH) ندارید، از دکمه زیر برای اجرای مایگریشن‌های جدید دیتابیس استفاده کنید:
                        </p>
                        {migrationBanner && (
                          <div className="p-3.5 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-900 text-xs font-semibold">
                            {migrationBanner}
                          </div>
                        )}
                        <div>
                          <button
                            type="button"
                            onClick={handleTriggerWebMigrations}
                            className="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold rounded-lg shadow-sm"
                          >
                            <Play className="w-4 h-4" />
                            بررسی و اجرای مایگریشن‌های جدید دیتابیس (/api/v1/system/migrate)
                          </button>
                        </div>
                      </div>

                      <AiWorkerPanel csrfToken={csrfToken} onRefreshParent={loadS1Entities} />
                    </div>
                  )}

                  {adminRoute === '/admin/review' && (
                    <ReviewQueueView
                      csrfToken={csrfToken}
                      onRefreshParent={loadS1Entities}
                    />
                  )}

                  {adminRoute === '/admin/articles' && (
                    <ArticlesManagerView
                      cities={cities}
                      categories={categories}
                      businesses={businesses}
                      csrfToken={csrfToken}
                    />
                  )}
                </main>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
