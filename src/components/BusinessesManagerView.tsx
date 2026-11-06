import React, { useEffect, useState } from 'react';
import {
  Store,
  Plus,
  Edit3,
  Upload,
  Sparkles,
  CheckCircle2,
  AlertTriangle,
  ArrowUpRight,
  Image as ImageIcon,
} from 'lucide-react';
import {
  BusinessItem,
  CategoryItem,
  CityItem,
  NeighborhoodItem,
  computeBusinessWarnings,
  fetchBusinessesPage,
  normalizeIranPhone,
  saveBusinessItem,
  setBusinessPromotion,
  suggestLatinSlug,
  uploadMediaWebp,
} from '../services/adminApi';

interface BusinessesManagerViewProps {
  cities: CityItem[];
  neighborhoods: NeighborhoodItem[];
  categories: CategoryItem[];
  csrfToken: string;
  onRefreshData: () => Promise<void>;
  isAdmin: boolean;
}

export const BusinessesManagerView: React.FC<BusinessesManagerViewProps> = ({
  cities,
  neighborhoods,
  categories,
  csrfToken,
  onRefreshData,
  isAdmin,
}) => {
  const [mode, setMode] = useState<'list' | 'form'>('list');
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [cityFilter, setCityFilter] = useState<number>(0);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [pageBusinesses, setPageBusinesses] = useState<BusinessItem[]>([]);
  const [listError, setListError] = useState('');
  const [refreshList, setRefreshList] = useState(0);
  const [promotionBusy, setPromotionBusy] = useState(0);
  useEffect(() => {
    if (mode !== 'list') return;
    let cancelled = false;
    const timer = window.setTimeout(async () => {
      try {
        const data = await fetchBusinessesPage(page, cityFilter, searchQuery);
        if (!cancelled) { setPageBusinesses(data.businesses); setTotal(data.total); setListError(''); }
      } catch (e) { if (!cancelled) { setPageBusinesses([]); setListError(e instanceof Error ? e.message : 'خطا در دریافت فهرست'); } }
    }, 200);
    return () => {cancelled=true;window.clearTimeout(timer);};
  }, [mode, page, cityFilter, searchQuery, refreshList]);

  const togglePromotion = async (biz:BusinessItem) => {
    setPromotionBusy(biz.id);setListError('');
    const result = await setBusinessPromotion(biz.id, !Number(biz.promoted), csrfToken);
    setPromotionBusy(0);
    if (!result.ok) {setListError(result.error || 'تغییر جایگاه ویژه ناموفق بود.');return;}
    setSaveSuccess(result.message);setRefreshList(v=>v+1);
  };

  // فیلدهای فرم یک‌صفحه‌ای کسب‌وکار (بخش ۷ قوانین UX)
  const [bizId, setBizId] = useState<number>(0);
  const [name, setName] = useState<string>('');
  const [slug, setSlug] = useState<string>('');
  const [cityId, setCityId] = useState<number>(cities[0]?.id || 1);
  const [neighborhoodId, setNeighborhoodId] = useState<number | null>(null);
  const [categoryId, setCategoryId] = useState<number>(categories[0]?.id || 1);
  const [address, setAddress] = useState<string>('');
  const [lat, setLat] = useState<string>('');
  const [lng, setLng] = useState<string>('');
  const [hours, setHours] = useState<string>('');
  const [shortDesc, setShortDesc] = useState<string>('');
  const [phone, setPhone] = useState<string>('');
  const [instagram, setInstagram] = useState<string>('');
  const [whatsapp, setWhatsapp] = useState<string>('');
  const [website, setWebsite] = useState<string>('');
  const [status, setStatus] = useState<BusinessItem['status']>('draft');
  const [tier, setTier] = useState<BusinessItem['tier']>('B');
  const [coverMediaId, setCoverMediaId] = useState<number | null>(null);
  const [coverPreviewUrl, setCoverPreviewUrl] = useState<string | null>(null);
  const [mediaFeedback, setMediaFeedback] = useState<string>('');
  const [uploadingMedia, setUploadingMedia] = useState<boolean>(false);

  // خطای کنار فیلد + لینک «برو به فیلد» (بخش ۷ قوانین UX)
  const [formError, setFormError] = useState<{ text: string; field?: string } | null>(null);
  const [saveSuccess, setSaveSuccess] = useState<string>('');

  const openNewBusinessForm = () => {
    setBizId(0);
    setName('');
    setSlug('');
    setCityId(cities[0]?.id || 1);
    setNeighborhoodId(null);
    setCategoryId(categories[0]?.id || 1);
    setAddress('');
    setLat('');
    setLng('');
    setHours('');
    setShortDesc('');
    setPhone('');
    setInstagram('');
    setWhatsapp('');
    setWebsite('');
    setStatus('draft');
    setTier('B');
    setCoverMediaId(null);
    setCoverPreviewUrl(null);
    setMediaFeedback('');
    setFormError(null);
    setMode('form');
  };

  const openEditBusinessForm = (biz: BusinessItem) => {
    setBizId(biz.id);
    setName(biz.name);
    setSlug(biz.slug);
    setCityId(biz.city_id);
    setNeighborhoodId(biz.neighborhood_id);
    setCategoryId(biz.category_id);
    setAddress(biz.address || '');
    setLat(biz.lat ? String(biz.lat) : '');
    setLng(biz.lng ? String(biz.lng) : '');
    setHours(biz.hours || '');
    setShortDesc(biz.short_desc || '');
    const primaryPhone = biz.contacts.find((c) => c.type === 'phone' || c.type === 'mobile')?.value || '';
    const ig = biz.contacts.find((c) => c.type === 'instagram')?.value || '';
    const wa = biz.contacts.find((c) => c.type === 'whatsapp')?.value || '';
    const web = biz.contacts.find((c) => c.type === 'website')?.value || '';
    setPhone(primaryPhone);
    setInstagram(ig);
    setWhatsapp(wa);
    setWebsite(web);
    setStatus(biz.status);
    setTier(biz.tier);
    setCoverMediaId(biz.cover_media_id);
    setCoverPreviewUrl(biz.cover_thumb_url || null);
    setMediaFeedback('');
    setFormError(null);
    setMode('form');
  };

  const scrollToField = (fieldName: string) => {
    const el = document.getElementById(`biz_field_${fieldName}`);
    if (el) {
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      el.focus();
    }
  };

  // محاسبه زنده چک‌لیست غیرمسدودکننده در فرم (هیچ قانونی ذخیره را قفل نمی‌کند)
  const liveWarnings = computeBusinessWarnings(
    {
      cover_media_id: coverMediaId,
      lat: lat !== '' ? Number(lat) : null,
      lng: lng !== '' ? Number(lng) : null,
      address,
      short_desc: shortDesc,
    },
    phone.trim() ? [{ type: 'phone', value: phone, is_primary: 1, source: 'manual' }] : []
  );

  const handleFileChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploadingMedia(true);
    setMediaFeedback('');
    setFormError(null);

    const res = await uploadMediaWebp(file, name || 'تصویر کسب‌وکار', csrfToken);
    setUploadingMedia(false);

    if (!res.ok || !res.media) {
      setFormError({ text: res.error || 'خطا در پردازش تصویر WebP', field: 'cover_media_id' });
      return;
    }

    setCoverMediaId(res.media.id);
    setCoverPreviewUrl(res.previewUrl || res.media.file_path_480);
    setMediaFeedback(
      res.message || 'تصویر با موفقیت به WebP در سایزهای 480 / 800 / 1200 تبدیل شد.'
    );
  };

  const handleSubmitBusiness = async (e: React.FormEvent) => {
    e.preventDefault();
    setFormError(null);
    setSaveSuccess('');

    const res = await saveBusinessItem(
      {
        id: bizId > 0 ? bizId : undefined,
        name,
        slug,
        city_id: cityId,
        neighborhood_id: neighborhoodId,
        category_id: categoryId,
        address,
        lat,
        lng,
        hours,
        short_desc: shortDesc,
        phone,
        instagram,
        whatsapp,
        website,
        status,
        tier,
        cover_media_id: coverMediaId,
        cover_thumb_url: coverPreviewUrl,
      },
      csrfToken
    );

    if (!res.ok) {
      setFormError({ text: res.error || 'خطا در ذخیره کسب‌وکار', field: res.field });
      if (res.field) {
        scrollToField(res.field);
      }
      return;
    }

    setSaveSuccess(res.message || 'کسب‌وکار با موفقیت ذخیره شد.');
    await onRefreshData();
    setMode('list');
  };

  const filteredBusinesses = pageBusinesses;

  const cityNeighborhoods = neighborhoods.filter((n) => n.city_id === Number(cityId));

  return (
    <div className="space-y-6">
      {saveSuccess && (
        <div className="p-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-900 text-sm flex items-center justify-between">
          <span className="flex items-center gap-2 font-medium">
            <CheckCircle2 className="w-4 h-4 text-emerald-700 shrink-0" />
            {saveSuccess}
          </span>
          <button
            type="button"
            onClick={() => setSaveSuccess('')}
            className="text-xs text-emerald-800 underline"
          >
            بستن
          </button>
        </div>
      )}

      {mode === 'list' ? (
        <section className="bg-white border border-slate-200 rounded-xl p-6">
          <div className="flex flex-wrap items-center justify-between gap-4 pb-5 mb-5 border-b border-slate-200">
            <div>
              <h2 className="text-base font-bold text-slate-900 flex items-center gap-2">
                <Store className="w-4 h-4 text-emerald-700" />
                فهرست کسب‌وکارها ({total} مورد)
              </h2>
              <p className="text-xs text-slate-500 mt-1">
                جستجو در همه رکوردها؛ جایگاه ویژه فقط برای موارد منتشرشده در سایت نمایش داده می‌شود.
              </p>
            </div>

            <button
              type="button"
              onClick={openNewBusinessForm}
              className="inline-flex items-center gap-2 px-4 py-2.5 bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-semibold rounded-lg transition-colors whitespace-nowrap shrink-0"
            >
              <Plus className="w-4 h-4" />
              افزودن کسب‌وکار جدید
            </button>
          </div>

          {/* نوار جستجو و فیلتر شهر */}
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
            <div className="sm:col-span-2">
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => {setPage(1);setSearchQuery(e.target.value);}}
                placeholder="جستجو بر اساس نام فارسی، اسلاگ یا آدرس..."
                className="w-full px-3.5 py-2 text-sm bg-slate-50 border border-slate-300 rounded-lg focus:bg-white focus:outline-none focus:border-emerald-700"
              />
            </div>
            <div>
              <select
                value={cityFilter}
                onChange={(e) => {setPage(1);setCityFilter(Number(e.target.value));}}
                className="w-full px-3.5 py-2 text-sm bg-slate-50 border border-slate-300 rounded-lg"
              >
                <option value={0}>همه شهرهای گیلان</option>
                {cities.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name_fa}
                  </option>
                ))}
              </select>
            </div>
          </div>

          {listError && <p role="alert" className="text-sm text-rose-800 bg-rose-50 p-3 mb-4 rounded">{listError}</p>}
          <div className="overflow-x-auto">
            <table className="w-full text-sm border-collapse">
              <thead>
                <tr className="border-b border-slate-200 text-slate-500 text-xs">
                  <th className="py-3 px-3 text-right font-semibold">نام و مسیر (/b/slug/)</th>
                  <th className="py-3 px-3 text-right font-semibold">شهر · محله · دسته</th>
                  <th className="py-3 px-3 text-right font-semibold">وضعیت و Tier</th>
                  <th className="py-3 px-3 text-right font-semibold">چک‌لیست غیرمسدودکننده</th>
                  <th className="py-3 px-3 text-left font-semibold">عملیات</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {filteredBusinesses.map((biz) => (
                  <tr key={biz.id} className="hover:bg-slate-50">
                    <td className="py-3.5 px-3">
                      <div className="font-semibold text-slate-900">{biz.name}</div>
                      <div className="font-mono text-xs text-slate-500 mt-0.5" dir="ltr">
                        /b/{biz.slug}/
                      </div>
                    </td>
                    <td className="py-3.5 px-3 text-xs text-slate-600">
                      <span>{biz.city_name}</span>
                      {biz.neighborhood_name && (
                        <>
                          <span className="mx-1.5">·</span>
                          <span>{biz.neighborhood_name}</span>
                        </>
                      )}
                      <span className="mx-1.5">·</span>
                      <span>{biz.category_name}</span>
                    </td>
                    <td className="py-3.5 px-3 text-xs">
                      <span
                        className={`font-semibold ${
                          biz.status === 'published'
                            ? 'text-emerald-700'
                            : biz.status === 'pending_review'
                            ? 'text-amber-700'
                            : 'text-slate-600'
                        }`}
                      >
                        {biz.status === 'published'
                          ? 'منتشرشده'
                          : biz.status === 'pending_review'
                          ? 'در انتظار بازبینی'
                          : 'پیش‌نویس'}
                      </span>
                      <span className="mx-1.5 text-slate-300">·</span>
                      <span className="font-mono text-slate-700">Tier {biz.tier}</span>
                    </td>
                    <td className="py-3.5 px-3 text-xs text-slate-600">
                      {biz.warnings && biz.warnings.length > 0 ? (
                        <span className="text-amber-800">{biz.warnings.join(' · ')}</span>
                      ) : (
                        <span className="text-emerald-700 font-medium">تکمیل (بدون نقص)</span>
                      )}
                    </td>
                    <td className="py-3.5 px-3 text-left">
                      <button
                        type="button"
                        onClick={() => openEditBusinessForm(biz)}
                        className="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700 hover:text-emerald-900"
                      >
                        <Edit3 className="w-3.5 h-3.5" />
                        ویرایش
                      </button>
                      {isAdmin && <button type="button" disabled={promotionBusy===biz.id} onClick={()=>togglePromotion(biz)} className="block mt-2 text-xs font-semibold text-blue-700 hover:underline disabled:opacity-50">
                        {Number(biz.promoted) ? 'حذف از جایگاه ویژه' : 'قرار دادن در جایگاه ویژه'}
                      </button>}
                      {!!Number(biz.promoted) && <span className="block mt-1 text-xs text-amber-700">جایگاه ویژه فعال است</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="flex gap-3 items-center mt-4 text-sm">
            <button type="button" disabled={page<=1} onClick={()=>setPage(p=>p-1)} className="px-3 py-1 rounded border disabled:opacity-40">قبلی</button>
            <span>صفحه {page} از {Math.max(1,Math.ceil(total/50))}</span>
            <button type="button" disabled={page*50>=total} onClick={()=>setPage(p=>p+1)} className="px-3 py-1 rounded border disabled:opacity-40">بعدی</button>
          </div>
        </section>
      ) : (
        /* فرم یک‌صفحه‌ای ثبت/ویرایش کسب‌وکار با بخش‌های ثابت و دکمه ذخیره Sticky (بخش ۷ قوانین UX) */
        <form onSubmit={handleSubmitBusiness} className="space-y-6 pb-20 relative">
          <div className="bg-white border border-slate-200 rounded-xl p-6 space-y-6">
            <div className="flex items-center justify-between pb-4 border-b border-slate-200">
              <div>
                <h2 className="text-base font-bold text-slate-900">
                  {bizId > 0 ? `ویرایش کسب‌وکار «${name}»` : 'ثبت سریع کسب‌وکار جدید (فرم یک‌صفحه‌ای)'}
                </h2>
                <p className="text-xs text-slate-500 mt-1">
                  هیچ فیلدی (به‌جز نام، شهر و دسته) ذخیره را قفل نمی‌کند؛ موارد ناقص در چک‌لیست غیرمسدودکننده پایین نمایش داده می‌شوند.
                </p>
              </div>
              <button
                type="button"
                onClick={() => setMode('list')}
                className="px-3.5 py-1.5 text-xs font-semibold text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200"
              >
                بازگشت به لیست
              </button>
            </div>

            {/* بنر خطا با لینک «برو به فیلد» */}
            {formError && (
              <div className="p-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-900 text-xs flex items-center justify-between gap-4">
                <div className="flex items-center gap-2.5">
                  <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
                  <span className="font-semibold">{formError.text}</span>
                </div>
                {formError.field && (
                  <button
                    type="button"
                    onClick={() => scrollToField(formError.field!)}
                    className="inline-flex items-center gap-1 underline font-bold text-rose-800 whitespace-nowrap shrink-0"
                  >
                    برو به فیلد مربوطه
                    <ArrowUpRight className="w-3.5 h-3.5" />
                  </button>
                )}
              </div>
            )}

            {/* چک‌لیست غیرمسدودکننده بالای فرم */}
            <div className="p-4 rounded-lg bg-slate-50 border border-slate-200">
              <div className="text-xs font-bold text-slate-800 mb-1.5">
                چک‌لیست غیرمسدودکننده کیفیت داده (مانع ذخیره نمی‌شود):
              </div>
              {liveWarnings.length === 0 ? (
                <div className="text-xs text-emerald-700 font-semibold">
                  تمام اطلاعات اصلی (عکس، شماره تماس، مختصات، آدرس و توضیح) کامل است.
                </div>
              ) : (
                <div className="text-xs text-amber-800 leading-relaxed">
                  {liveWarnings.join('  ·  ')}
                </div>
              )}
            </div>

            {/* بخش ثابت ۱: هویت، شهر و دسته‌بندی */}
            <div className="space-y-4">
              <h3 className="text-sm font-bold text-slate-900 border-b border-slate-100 pb-2">
                ۱. اطلاعات پایه، شهر و اسلاگ
              </h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label htmlFor="biz_field_name" className="block text-xs font-semibold text-slate-800">
                    نام کسب‌وکار (فارسی - الزامی)
                  </label>
                  <input
                    id="biz_field_name"
                    type="text"
                    value={name}
                    onChange={(e) => {
                      setName(e.target.value);
                      if (!bizId) {
                        setSlug(suggestLatinSlug(e.target.value, 'biz'));
                      }
                    }}
                    placeholder="مثلاً: کافه لمیز رشت"
                    className={`mt-1 w-full px-3.5 py-2.5 text-sm bg-white border rounded-lg focus:outline-none ${
                      formError?.field === 'name'
                        ? 'border-rose-500 bg-rose-50/30'
                        : 'border-slate-300 focus:border-emerald-700'
                    }`}
                  />
                  {formError?.field === 'name' && (
                    <p className="text-xs text-rose-600 mt-1">{formError.text}</p>
                  )}
                </div>

                <div>
                  <div className="flex items-center justify-between">
                    <label htmlFor="biz_field_slug" className="block text-xs font-semibold text-slate-800">
                      اسلاگ لاتین (مسیر: <code className="font-mono">/b/{slug || '...'}/</code>)
                    </label>
                    <button
                      type="button"
                      onClick={() => setSlug(suggestLatinSlug(name, 'biz'))}
                      className="text-[11px] font-medium text-emerald-700 hover:underline flex items-center gap-1"
                    >
                      <Sparkles className="w-3 h-3" />
                      پیشنهاد خودکار اسلاگ
                    </button>
                  </div>
                  <input
                    id="biz_field_slug"
                    type="text"
                    dir="ltr"
                    value={slug}
                    onChange={(e) => setSlug(e.target.value)}
                    placeholder="cafe-lamiz-rasht"
                    className={`mt-1 w-full px-3.5 py-2.5 text-sm font-mono bg-white border rounded-lg focus:outline-none ${
                      formError?.field === 'slug'
                        ? 'border-rose-500 bg-rose-50/30'
                        : 'border-slate-300 focus:border-emerald-700'
                    }`}
                  />
                  {formError?.field === 'slug' && (
                    <p className="text-xs text-rose-600 mt-1">{formError.text}</p>
                  )}
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <label htmlFor="biz_field_city_id" className="block text-xs font-semibold text-slate-800">
                    شهر
                  </label>
                  <select
                    id="biz_field_city_id"
                    value={cityId}
                    onChange={(e) => {
                      setCityId(Number(e.target.value));
                      setNeighborhoodId(null);
                    }}
                    className="mt-1 w-full px-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg"
                  >
                    {cities.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name_fa}
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label htmlFor="biz_field_neighborhood_id" className="block text-xs font-semibold text-slate-800">
                    محله (اختیاری)
                  </label>
                  <select
                    id="biz_field_neighborhood_id"
                    value={neighborhoodId || ''}
                    onChange={(e) =>
                      setNeighborhoodId(e.target.value ? Number(e.target.value) : null)
                    }
                    className="mt-1 w-full px-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg"
                  >
                    <option value="">— انتخاب محله —</option>
                    {cityNeighborhoods.map((n) => (
                      <option key={n.id} value={n.id}>
                        {n.name_fa}
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label htmlFor="biz_field_category_id" className="block text-xs font-semibold text-slate-800">
                    دسته‌بندی
                  </label>
                  <select
                    id="biz_field_category_id"
                    value={categoryId}
                    onChange={(e) => setCategoryId(Number(e.target.value))}
                    className="mt-1 w-full px-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg"
                  >
                    {categories.map((cat) => (
                      <option key={cat.id} value={cat.id}>
                        {cat.name_fa}
                      </option>
                    ))}
                  </select>
                </div>
              </div>
            </div>

            {/* بخش ثابت ۲: تماس، شبکه‌های اجتماعی و آدرس */}
            <div className="space-y-4">
              <h3 className="text-sm font-bold text-slate-900 border-b border-slate-100 pb-2">
                ۲. شماره تماس، آدرس و موقعیت نقشه (نرمال‌سازی خودکار به +98 و 013)
              </h3>
              <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-800">
                    تلفن ثابت یا موبایل اصلی
                  </label>
                  <input
                    type="text"
                    dir="ltr"
                    value={phone}
                    onChange={(e) => setPhone(e.target.value)}
                    onBlur={() => setPhone(normalizeIranPhone(phone))}
                    placeholder="01333112233 یا 0911..."
                    className="mt-1 w-full px-3 py-2 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-800">پیج اینستاگرام</label>
                  <input
                    type="text"
                    dir="ltr"
                    value={instagram}
                    onChange={(e) => setInstagram(e.target.value)}
                    placeholder="cafe.rasht"
                    className="mt-1 w-full px-3 py-2 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-800">شماره واتساپ</label>
                  <input
                    type="text"
                    dir="ltr"
                    value={whatsapp}
                    onChange={(e) => setWhatsapp(e.target.value)}
                    onBlur={() => setWhatsapp(normalizeIranPhone(whatsapp))}
                    placeholder="+98911..."
                    className="mt-1 w-full px-3 py-2 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-800">وب‌سایت</label>
                  <input
                    type="url"
                    dir="ltr"
                    value={website}
                    onChange={(e) => setWebsite(e.target.value)}
                    placeholder="https://..."
                    className="mt-1 w-full px-3 py-2 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div className="md:col-span-2">
                  <label className="block text-xs font-semibold text-slate-800">آدرس دقیق پستی</label>
                  <input
                    type="text"
                    value={address}
                    onChange={(e) => setAddress(e.target.value)}
                    placeholder="مثلاً: رشت، گلسار، بلوار دیلمان، نبش خیابان آذراندامی"
                    className="mt-1 w-full px-3.5 py-2 text-sm bg-white border border-slate-300 rounded-lg"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-800">ساعت کاری</label>
                  <input
                    type="text"
                    value={hours}
                    onChange={(e) => setHours(e.target.value)}
                    placeholder="۸ صبح تا ۱۱ شب"
                    className="mt-1 w-full px-3.5 py-2 text-sm bg-white border border-slate-300 rounded-lg"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-800">عرض جغرافیایی (Lat)</label>
                  <input
                    type="number"
                    step="any"
                    dir="ltr"
                    value={lat}
                    onChange={(e) => setLat(e.target.value)}
                    placeholder="37.2808"
                    className="mt-1 w-full px-3 py-2 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-800">طول جغرافیایی (Lng)</label>
                  <input
                    type="number"
                    step="any"
                    dir="ltr"
                    value={lng}
                    onChange={(e) => setLng(e.target.value)}
                    placeholder="49.5830"
                    className="mt-1 w-full px-3 py-2 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-800">وضعیت انتشار</label>
                  <select
                    value={status}
                    onChange={(e) => setStatus(e.target.value as BusinessItem['status'])}
                    className="mt-1 w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg"
                  >
                    <option value="published">منتشرشده (published)</option>
                    <option value="pending_review">در انتظار بازبینی (pending_review)</option>
                    <option value="draft">پیش‌نویس (draft)</option>
                    <option value="hidden">مخفی (hidden)</option>
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-800">رده کیفی (Tier)</label>
                  <select
                    value={tier}
                    onChange={(e) => setTier(e.target.value as BusinessItem['tier'])}
                    className="mt-1 w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg"
                  >
                    <option value="A">Tier A (۲۰٪ برتر)</option>
                    <option value="B">Tier B (استاندارد)</option>
                    <option value="C">Tier C (پایه)</option>
                  </select>
                </div>
              </div>
            </div>

            {/* بخش ثابت ۳: توضیح کوتاه و آپلود تصویر WebP با GD */}
            <div className="space-y-4">
              <h3 className="text-sm font-bold text-slate-900 border-b border-slate-100 pb-2">
                ۳. توضیح کوتاه و تصویر کاور (تبدیل خودکار به WebP در سایزهای ۴۸۰ / ۸۰۰ / ۱۲۰۰)
              </h3>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div className="md:col-span-2">
                  <label className="block text-xs font-semibold text-slate-800">
                    توضیح کوتاه معرفی کسب‌وکار (در مرحله S5 توسط AI هم قابل تولید است)
                  </label>
                  <textarea
                    rows={4}
                    value={shortDesc}
                    onChange={(e) => setShortDesc(e.target.value)}
                    placeholder="توضیح مستند بر اساس اطلاعات واقعی کسب‌وکار..."
                    className="mt-1 w-full px-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg"
                  />
                </div>

                <div id="biz_field_cover_media_id">
                  <label className="block text-xs font-semibold text-slate-800 mb-1">
                    تصویر کاور (JPG / PNG / WebP حداکثر ۵MB)
                  </label>
                  <div className="p-4 border border-dashed border-slate-300 rounded-lg bg-slate-50 text-center">
                    {coverPreviewUrl ? (
                      <div className="space-y-2">
                        <img
                          src={coverPreviewUrl}
                          alt={name || 'کاور'}
                          referrerPolicy="no-referrer"
                          className="w-full h-28 object-cover rounded border border-slate-200"
                        />
                        <div className="text-[11px] text-emerald-700 font-medium">
                          {mediaFeedback || `رسانه #${coverMediaId} آماده است`}
                        </div>
                      </div>
                    ) : (
                      <div className="py-3 text-xs text-slate-500 flex flex-col items-center gap-1.5">
                        <ImageIcon className="w-6 h-6 text-slate-400" />
                        <span>بدون عکس (جایگزین خودکار با آیکون دسته)</span>
                      </div>
                    )}

                    <label className="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-300 rounded-md text-xs font-semibold text-slate-700 hover:bg-slate-100 cursor-pointer">
                      <Upload className="w-3.5 h-3.5" />
                      <span>{uploadingMedia ? 'در حال تبدیل به WebP...' : 'انتخاب و تبدیل به WebP'}</span>
                      <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        onChange={handleFileChange}
                        className="hidden"
                      />
                    </label>
                  </div>
                </div>
              </div>
            </div>
          </div>

          {/* نوار دکمه ذخیره Sticky پایین صفحه (بخش ۷ قوانین UX) */}
          <div className="sticky bottom-4 z-20 bg-slate-900 text-white px-6 py-3.5 rounded-xl shadow-lg flex items-center justify-between gap-4">
            <div className="text-xs text-slate-300">
              {liveWarnings.length > 0 ? (
                <span>
                  یادآوری غیرمسدودکننده: {liveWarnings.length} مورد قابل تکمیل ({liveWarnings[0]})
                </span>
              ) : (
                <span className="text-emerald-400 font-medium">تمامی فیلدهای کلیدی تکمیل شده‌اند.</span>
              )}
            </div>
            <div className="flex items-center gap-3">
              <button
                type="button"
                onClick={() => setMode('list')}
                className="px-4 py-2 text-xs font-semibold text-slate-300 hover:text-white"
              >
                انصراف
              </button>
              <button
                type="submit"
                className="px-6 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold rounded-lg transition-colors whitespace-nowrap shrink-0"
              >
                ذخیره فوری کسب‌وکار
              </button>
            </div>
          </div>
        </form>
      )}
    </div>
  );
};
