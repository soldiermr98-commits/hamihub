import React, { useEffect, useState } from 'react';
import {
  FileText,
  Plus,
  Sparkles,
  ExternalLink,
  RefreshCw,
  Trash2,
  Award,
  HelpCircle,
  CheckCircle2,
  MapPin,
  Code2,
} from 'lucide-react';
import {
  ArticleBizRow,
  ArticleFaqRow,
  ArticleItem,
  BusinessItem,
  CategoryItem,
  CityItem,
  autoBuildCityCategoryArticle,
  fetchArticlesList,
  flushPublicHtmlCache,
  saveArticleItem,
} from '../services/adminApi';

interface ArticlesManagerViewProps {
  cities: CityItem[];
  categories: CategoryItem[];
  businesses: BusinessItem[];
  csrfToken: string;
}

export const ArticlesManagerView: React.FC<ArticlesManagerViewProps> = ({
  cities,
  categories,
  businesses,
  csrfToken,
}) => {
  const [articles, setArticles] = useState<ArticleItem[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [banner, setBanner] = useState<{ type: 'ok' | 'err'; text: string } | null>(null);

  // ساخت خودکار مقاله با ۱ کلیک
  const [autoCityId, setAutoCityId] = useState<number>(cities[0]?.id || 1);
  const [autoCatId, setAutoCatId] = useState<number>(categories[0]?.id || 1);
  const [autoBuilding, setAutoBuilding] = useState<boolean>(false);

  // فرم ویرایش / ساخت مقاله لیستی
  const [editingId, setEditingId] = useState<number>(0);
  const [cityId, setCityId] = useState<number>(cities[0]?.id || 1);
  const [categoryId, setCategoryId] = useState<number>(categories[0]?.id || 1);
  const [titleFa, setTitleFa] = useState<string>('');
  const [slug, setSlug] = useState<string>('');
  const [subtitleFa, setSubtitleFa] = useState<string>('');
  const [introHtml, setIntroHtml] = useState<string>('');
  const [methodologyHtml, setMethodologyHtml] = useState<string>('');
  const [status, setStatus] = useState<'draft' | 'review' | 'published'>('published');
  const [items, setItems] = useState<ArticleBizRow[]>([]);
  const [faqs, setFaqs] = useState<ArticleFaqRow[]>([]);
  const [saving, setSaving] = useState<boolean>(false);

  const loadArticles = async () => {
    setLoading(true);
    const list = await fetchArticlesList();
    setArticles(Array.isArray(list) ? list : []);
    setLoading(false);
  };

  useEffect(() => {
    loadArticles();
  }, []);

  useEffect(() => {
    if (cities.length > 0 && !autoCityId) setAutoCityId(cities[0].id);
    if (categories.length > 0 && !autoCatId) setAutoCatId(categories[0].id);
  }, [cities, categories, autoCityId, autoCatId]);

  const handleSelectArticleForEdit = (art: ArticleItem) => {
    setEditingId(art.id);
    setCityId(art.city_id);
    setCategoryId(art.category_id || categories[0]?.id || 1);
    setTitleFa(art.title_fa);
    setSlug(art.slug);
    setSubtitleFa(art.subtitle_fa || '');
    setIntroHtml(art.intro_html || '');
    setMethodologyHtml(art.methodology_html || '');
    setStatus(art.status || 'published');
    setItems(Array.isArray(art.items) ? art.items : []);
    setFaqs(Array.isArray(art.faqs) ? art.faqs : []);
    setBanner(null);
  };

  const handleResetForm = () => {
    setEditingId(0);
    setTitleFa('');
    setSlug('');
    setSubtitleFa('');
    setIntroHtml('');
    setMethodologyHtml('');
    setStatus('published');
    setItems([]);
    setFaqs([
      {
        question_fa: 'رتبه‌بندی این لیست بر چه اساسی انجام شده است؟',
        answer_fa:
          'بر اساس امتیاز وزنی بیزین (Bayesian Weighted Score با m=20) و تطابق چندمنبعی در همی‌هاب گیلان.',
        sort_order: 1,
      },
    ]);
  };

  const handleAutoBuild = async () => {
    setAutoBuilding(true);
    setBanner(null);
    const res = await autoBuildCityCategoryArticle(autoCityId, autoCatId, csrfToken);
    setAutoBuilding(false);
    if (res.ok) {
      setBanner({ type: 'ok', text: res.message });
      await loadArticles();
    } else {
      setBanner({ type: 'err', text: res.error || 'خطا در ساخت خودکار مقاله' });
    }
  };

  const handleFlushCache = async () => {
    const res = await flushPublicHtmlCache(csrfToken);
    setBanner({ type: res.ok ? 'ok' : 'err', text: res.message });
  };

  const handleAddBusinessToArticle = (bizId: number) => {
    if (!bizId) return;
    if (items.some((i) => Number(i.business_id) === Number(bizId))) return;
    const biz = businesses.find((b) => Number(b.id) === Number(bizId));
    if (!biz) return;

    setItems((prev) => [
      ...prev,
      {
        business_id: biz.id,
        business_name_fa: biz.name,
        business_slug: biz.slug,
        weighted_score: biz.internal_score,
        tier: biz.tier,
        rank_num: prev.length + 1,
        badge_fa: prev.length === 0 ? 'انتخاب اول تحریریه' : `منتخب Tier ${biz.tier}`,
        verdict_fa: `یکی از بهترین گزینه‌های ${biz.city_name || 'شهر'} با امتیاز بیزین بالا.`,
        custom_desc_html: biz.short_desc || `${biz.name} در ${biz.address || biz.city_name || ''}`,
        is_sponsored: 0,
        sponsor_label: 'پیشنهاد ویژه',
      },
    ]);
  };

  const handleSaveArticle = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setBanner(null);
    const res = await saveArticleItem(
      {
        id: editingId > 0 ? editingId : undefined,
        city_id: cityId,
        category_id: categoryId,
        title_fa: titleFa,
        slug,
        subtitle_fa: subtitleFa,
        intro_html: introHtml,
        methodology_html: methodologyHtml,
        status,
        items,
        faqs,
      },
      csrfToken
    );
    setSaving(false);
    if (res.ok) {
      setBanner({ type: 'ok', text: res.message });
      await loadArticles();
    } else {
      setBanner({ type: 'err', text: res.error || 'خطا در ذخیره مقاله لیستی' });
    }
  };

  return (
    <div className="space-y-6">
      {/* هدر بخش مدیریت مقالات لیستی و قالب‌های سئو S4 */}
      <div className="bg-white border border-slate-200 rounded-xl p-6">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 className="text-xl font-bold text-slate-900 flex items-center gap-2">
              <FileText className="w-5 h-5 text-emerald-700" />
              مدیریت مقالات لیستی سئو، اسکیماهای JSON-LD و کش صفحات عمومی (مرحله S4)
            </h1>
            <p className="text-xs text-slate-600 mt-1">
              ساخت مقالات «بهترین X در شهر Y» همراه با جدول مقایسه امتیاز بیزین، جایگاه ویژه اسپانسرشیپ (Sponsored)، نقشه تعاملی خودمیزبان Leaflet و کش کامل دیسک.
            </p>
          </div>
          <div className="flex items-center gap-2.5">
            <button
              type="button"
              onClick={handleFlushCache}
              className="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg"
            >
              <RefreshCw className="w-3.5 h-3.5" />
              پاک‌سازی کش صفحات عمومی (cache/pages)
            </button>
            <a
              href="/best-cafes-rasht/"
              target="_blank"
              rel="noopener"
              className="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold text-white bg-emerald-700 hover:bg-emerald-800 rounded-lg"
            >
              <ExternalLink className="w-3.5 h-3.5" />
              مشاهده صفحه عمومی «بهترین کافه‌های رشت»
            </a>
          </div>
        </div>

        {/* نوار لینک‌های سریع تست صفحات عمومی مرحله S4 */}
        <div className="mt-4 pt-4 border-t border-slate-200 flex flex-wrap items-center gap-2 text-xs">
          <span className="font-bold text-slate-700 ml-1">لینک‌های مستقیم تست سئو و قالب عمومی S4:</span>
          <a
            href="/"
            target="_blank"
            rel="noopener"
            className="px-2.5 py-1 bg-slate-100 hover:bg-emerald-50 text-slate-800 rounded font-mono"
            dir="ltr"
          >
            / (Home)
          </a>
          <a
            href="/best-cafes-rasht/"
            target="_blank"
            rel="noopener"
            className="px-2.5 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-900 font-semibold rounded font-mono"
            dir="ltr"
          >
            /best-cafes-rasht/ (Article + FAQ + ItemList)
          </a>
          <a
            href="/city/rasht/"
            target="_blank"
            rel="noopener"
            className="px-2.5 py-1 bg-slate-100 hover:bg-emerald-50 text-slate-800 rounded font-mono"
            dir="ltr"
          >
            /city/rasht/ (City Hub)
          </a>
          <a
            href="/sitemap.xml"
            target="_blank"
            rel="noopener"
            className="px-2.5 py-1 bg-slate-100 hover:bg-emerald-50 text-slate-800 rounded font-mono"
            dir="ltr"
          >
            /sitemap.xml
          </a>
          <a
            href="/robots.txt"
            target="_blank"
            rel="noopener"
            className="px-2.5 py-1 bg-slate-100 hover:bg-emerald-50 text-slate-800 rounded font-mono"
            dir="ltr"
          >
            /robots.txt
          </a>
        </div>
      </div>

      {banner && (
        <div
          className={`p-4 rounded-xl border text-xs font-semibold flex items-center justify-between ${
            banner.type === 'ok'
              ? 'bg-emerald-50 border-emerald-200 text-emerald-900'
              : 'bg-rose-50 border-rose-200 text-rose-800'
          }`}
        >
          <span className="flex items-center gap-2">
            <CheckCircle2 className="w-4 h-4 shrink-0" />
            {banner.text}
          </span>
          <button type="button" onClick={() => setBanner(null)} className="underline">
            بستن
          </button>
        </div>
      )}

      {/* باکس تولید خودکار مقاله لیستی از برترین‌های شهر و دسته */}
      <div className="bg-white border border-slate-200 rounded-xl p-6">
        <h2 className="text-sm font-bold text-slate-900 flex items-center gap-2 mb-3">
          <Sparkles className="w-4 h-4 text-emerald-700" />
          ساخت خودکار مقاله لیستی («بهترین [دسته] در [شهر]») بر اساس امتیاز وزنی بیزین
        </h2>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
          <div>
            <label className="block text-xs font-semibold text-slate-700 mb-1">انتخاب شهر هدف</label>
            <select
              value={autoCityId}
              onChange={(e) => setAutoCityId(Number(e.target.value))}
              className="w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg"
            >
              {cities.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name_fa} (/city/{c.slug}/)
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-xs font-semibold text-slate-700 mb-1">انتخاب دسته‌بندی</label>
            <select
              value={autoCatId}
              onChange={(e) => setAutoCatId(Number(e.target.value))}
              className="w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg"
            >
              {categories.map((cat) => (
                <option key={cat.id} value={cat.id}>
                  {cat.name_fa} ({cat.slug})
                </option>
              ))}
            </select>
          </div>
          <div>
            <button
              type="button"
              disabled={autoBuilding}
              onClick={handleAutoBuild}
              className="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold rounded-lg disabled:opacity-50"
            >
              <Sparkles className="w-4 h-4" />
              {autoBuilding ? 'در حال ساخت مقاله و اسکیما...' : 'تولید و انتشار خودکار مقاله لیستی'}
            </button>
          </div>
        </div>
      </div>

      {/* لیست مقالات موجود + ویرایشگر کامل */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <div className="lg:col-span-5 bg-white border border-slate-200 rounded-xl p-5 space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-bold text-slate-900">
              فهرست مقالات لیستی ({articles.length})
            </h2>
            <button
              type="button"
              onClick={handleResetForm}
              className="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 rounded-lg"
            >
              <Plus className="w-3.5 h-3.5" />
              مقاله جدید
            </button>
          </div>

          {loading ? (
            <div className="py-8 text-center text-xs text-slate-500">در حال بارگذاری مقالات...</div>
          ) : articles.length === 0 ? (
            <div className="py-8 text-center text-xs text-slate-500">هیچ مقاله‌ای ثبت نشده است.</div>
          ) : (
            <div className="space-y-3">
              {articles.map((art) => (
                <div
                  key={art.id}
                  className={`p-4 rounded-xl border transition-colors ${
                    editingId === art.id
                      ? 'border-emerald-600 bg-emerald-50/40'
                      : 'border-slate-200 hover:border-slate-300 bg-slate-50/50'
                  }`}
                >
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <span className="inline-block px-2 py-0.5 text-[11px] font-semibold rounded bg-emerald-100 text-emerald-900 mb-1">
                        {art.city_name_fa || 'گیلان'} · {art.items?.length || 0} کسب‌وکار
                      </span>
                      <h3 className="text-sm font-bold text-slate-900 leading-snug">
                        {art.title_fa}
                      </h3>
                      <div className="text-xs font-mono text-slate-500 mt-1" dir="ltr">
                        /{art.slug}/
                      </div>
                    </div>
                  </div>

                  <div className="mt-3 pt-3 border-t border-slate-200/80 flex items-center justify-between gap-2">
                    <button
                      type="button"
                      onClick={() => handleSelectArticleForEdit(art)}
                      className="px-3 py-1.5 text-xs font-semibold text-slate-800 bg-white border border-slate-300 hover:bg-slate-100 rounded-lg"
                    >
                      ویرایش آیتم‌ها و اسپانسرشیپ
                    </button>
                    <a
                      href={`/${art.slug}/`}
                      target="_blank"
                      rel="noopener"
                      className="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700 hover:text-emerald-800"
                    >
                      <ExternalLink className="w-3.5 h-3.5" />
                      مشاهده صفحه عمومی
                    </a>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* فرم ویرایشگر مقاله لیستی و جایگاه‌های ویژه */}
        <div className="lg:col-span-7 bg-white border border-slate-200 rounded-xl p-6">
          <h2 className="text-base font-bold text-slate-900 mb-4">
            {editingId > 0 ? `ویرایش مقاله لیستی #${editingId}` : 'ساخت مقاله لیستی جدید'}
          </h2>

          <form onSubmit={handleSaveArticle} className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">شهر</label>
                <select
                  value={cityId}
                  onChange={(e) => setCityId(Number(e.target.value))}
                  className="w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg"
                >
                  {cities.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name_fa}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">دسته‌بندی</label>
                <select
                  value={categoryId}
                  onChange={(e) => setCategoryId(Number(e.target.value))}
                  className="w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg"
                >
                  {categories.map((cat) => (
                    <option key={cat.id} value={cat.id}>
                      {cat.name_fa}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div className="md:col-span-2">
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  عنوان فارسی مقاله (H1 و اسکیمای Article)
                </label>
                <input
                  type="text"
                  value={titleFa}
                  onChange={(e) => setTitleFa(e.target.value)}
                  placeholder="مثلاً: بهترین کافه‌ها و رستوران‌های رشت در سال ۱۴۰۵"
                  className="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg"
                  required
                />
              </div>
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  اسلاگ لاتین (URL)
                </label>
                <input
                  type="text"
                  dir="ltr"
                  value={slug}
                  onChange={(e) => setSlug(e.target.value)}
                  placeholder="best-cafes-rasht"
                  className="w-full px-3 py-2 text-sm font-mono border border-slate-300 rounded-lg"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">
                توضیحات متا سئو (Meta Description)
              </label>
              <input
                type="text"
                value={subtitleFa}
                onChange={(e) => setSubtitleFa(e.target.value)}
                className="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">
                مقدمه مقاله (HTML مجاز)
              </label>
              <textarea
                rows={3}
                value={introHtml}
                onChange={(e) => setIntroHtml(e.target.value)}
                className="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg leading-relaxed"
              />
            </div>

            {/* مدیریت کسب‌وکارهای داخل لیست و اسپانسرشیپ */}
            <div className="pt-4 border-t border-slate-200 space-y-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-xs font-bold text-slate-900 flex items-center gap-1.5">
                  <Award className="w-4 h-4 text-amber-600" />
                  کسب‌وکارهای داخل این لیست (ItemList Schema + جایگاه Sponsored)
                </h3>
                <select
                  onChange={(e) => {
                    handleAddBusinessToArticle(Number(e.target.value));
                    e.target.value = '';
                  }}
                  defaultValue=""
                  className="px-3 py-1.5 text-xs bg-slate-50 border border-slate-300 rounded-lg"
                >
                  <option value="" disabled>
                    + افزودن کسب‌وکار به لیست...
                  </option>
                  {businesses.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name} ({b.city_name || 'گیلان'} - امتیاز {b.internal_score})
                    </option>
                  ))}
                </select>
              </div>

              {items.length === 0 ? (
                <div className="p-4 bg-slate-50 border border-slate-200 rounded-lg text-xs text-slate-500 text-center">
                  کسب‌وکاری به این مقاله اضافه نشده است. از منوی بالا یک کسب‌وکار انتخاب کنید.
                </div>
              ) : (
                <div className="space-y-2.5">
                  {items.map((it, idx) => (
                    <div
                      key={`${it.business_id}-${idx}`}
                      className={`p-3.5 rounded-lg border text-xs space-y-2 ${
                        it.is_sponsored
                          ? 'bg-amber-50/60 border-amber-300'
                          : 'bg-slate-50 border-slate-200'
                      }`}
                    >
                      <div className="flex items-center justify-between gap-2">
                        <div className="font-bold text-slate-900">
                          #{idx + 1} — {it.business_name_fa || `کسب‌وکار #${it.business_id}`}
                        </div>
                        <div className="flex items-center gap-3">
                          <label className="inline-flex items-center gap-1.5 cursor-pointer font-semibold text-amber-900">
                            <input
                              type="checkbox"
                              checked={Boolean(it.is_sponsored)}
                              onChange={(e) => {
                                const checked = e.target.checked ? 1 : 0;
                                setItems((prev) =>
                                  prev.map((row, rIdx) =>
                                    rIdx === idx ? { ...row, is_sponsored: checked } : row
                                  )
                                );
                              }}
                            />
                            جایگاه ویژه اسپانسر (پین در صدر لیست)
                          </label>
                          <button
                            type="button"
                            onClick={() =>
                              setItems((prev) => prev.filter((_, rIdx) => rIdx !== idx))
                            }
                            className="text-rose-600 hover:text-rose-800"
                          >
                            <Trash2 className="w-3.5 h-3.5" />
                          </button>
                        </div>
                      </div>

                      <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
                        <input
                          type="text"
                          value={it.badge_fa || ''}
                          onChange={(e) => {
                            const val = e.target.value;
                            setItems((prev) =>
                              prev.map((row, rIdx) =>
                                rIdx === idx ? { ...row, badge_fa: val } : row
                              )
                            );
                          }}
                          placeholder="برچسب رتبه (مثلاً: انتخاب اول تحریریه)"
                          className="px-2.5 py-1.5 bg-white border border-slate-300 rounded"
                        />
                        <input
                          type="text"
                          value={it.verdict_fa || ''}
                          onChange={(e) => {
                            const val = e.target.value;
                            setItems((prev) =>
                              prev.map((row, rIdx) =>
                                rIdx === idx ? { ...row, verdict_fa: val } : row
                              )
                            );
                          }}
                          placeholder="جمع‌بندی تحریریه"
                          className="px-2.5 py-1.5 bg-white border border-slate-300 rounded"
                        />
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {/* مدیریت سؤالات متداول FAQPage Schema */}
            <div className="pt-4 border-t border-slate-200 space-y-3">
              <div className="flex items-center justify-between">
                <h3 className="text-xs font-bold text-slate-900 flex items-center gap-1.5">
                  <HelpCircle className="w-4 h-4 text-emerald-700" />
                  سؤالات متداول مقاله (تولید خودکار اسکیمای FAQPage JSON-LD)
                </h3>
                <button
                  type="button"
                  onClick={() =>
                    setFaqs((prev) => [
                      ...prev,
                      { question_fa: '', answer_fa: '', sort_order: prev.length + 1 },
                    ])
                  }
                  className="text-xs font-semibold text-emerald-700 hover:underline"
                >
                  + افزودن سؤال متداول
                </button>
              </div>

              {faqs.map((f, idx) => (
                <div
                  key={idx}
                  className="p-3 bg-slate-50 border border-slate-200 rounded-lg space-y-2 text-xs"
                >
                  <div className="flex items-center justify-between gap-2">
                    <input
                      type="text"
                      value={f.question_fa}
                      onChange={(e) => {
                        const val = e.target.value;
                        setFaqs((prev) =>
                          prev.map((row, rIdx) =>
                            rIdx === idx ? { ...row, question_fa: val } : row
                          )
                        );
                      }}
                      placeholder="سؤال متداول..."
                      className="flex-1 px-2.5 py-1.5 bg-white border border-slate-300 rounded font-semibold"
                    />
                    <button
                      type="button"
                      onClick={() => setFaqs((prev) => prev.filter((_, rIdx) => rIdx !== idx))}
                      className="text-rose-600"
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                    </button>
                  </div>
                  <textarea
                    rows={2}
                    value={f.answer_fa}
                    onChange={(e) => {
                      const val = e.target.value;
                      setFaqs((prev) =>
                        prev.map((row, rIdx) => (rIdx === idx ? { ...row, answer_fa: val } : row))
                      );
                    }}
                    placeholder="پاسخ دقیق..."
                    className="w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded"
                  />
                </div>
              ))}
            </div>

            <div className="pt-4 border-t border-slate-200 flex items-center justify-between">
              <div className="flex items-center gap-3 text-[11px] text-slate-500">
                <span className="inline-flex items-center gap-1">
                  <Code2 className="w-3.5 h-3.5 text-emerald-700" />
                  Article + ItemList + FAQPage + BreadcrumbList
                </span>
                <span className="inline-flex items-center gap-1">
                  <MapPin className="w-3.5 h-3.5 text-emerald-700" />
                  نقشه Leaflet خودمیزبان
                </span>
              </div>
              <button
                type="submit"
                disabled={saving}
                className="px-6 py-2.5 bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-semibold rounded-lg disabled:opacity-50"
              >
                {saving ? 'در حال ذخیره و بازسازی کش...' : 'ذخیره مقاله و بازسازی کش صفحات عمومی'}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  );
};
