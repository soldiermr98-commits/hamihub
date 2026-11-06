import React, { useMemo, useState } from 'react';
import { AlertTriangle, FileJson, Search } from 'lucide-react';
import { applyJsonImport, previewJsonImport, type CategoryItem, type CityItem, type ImportEntry, type ImportOptions, type ImportResult, type ImportRow } from '../services/adminApi';

interface Props { cities:CityItem[]; categories:CategoryItem[]; csrfToken:string; onRefreshData:()=>Promise<void> }
const labels: Record<ImportRow['status'],string> = {new:'جدید',update:'به‌روزرسانی',review:'نیازمند بررسی',filtered:'فیلتر شده',invalid:'نامعتبر',conflict:'تعارض',stale:'قدیمی یا تکراری'};
const control = 'w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg';

export const JsonImportView:React.FC<Props> = ({cities,categories,csrfToken,onRefreshData}) => {
  const [items,setItems] = useState<unknown[]>([]);
  const [filename,setFilename] = useState('');
  const [city,setCity] = useState(0); const [category,setCategory] = useState(0);
  const [sourceCategories,setSourceCategories] = useState<string[]>([]);
  const [minRating,setMinRating] = useState(0); const [minReviews,setMinReviews] = useState(0);
  const [rows,setRows] = useState<ImportRow[]|null>(null);
  const [decisions,setDecisions] = useState<Record<number,string>>({});
  const [results,setResults] = useState<ImportResult[]>([]);
  const [rowFilter,setRowFilter] = useState('all'); const [search,setSearch] = useState('');
  const [busy,setBusy] = useState(false); const [progress,setProgress] = useState(''); const [error,setError] = useState('');
  const categoriesInFile = useMemo(() => {
    const counts = new Map<string,number>();
    for (const item of items) {
      if (item && typeof item === 'object' && !Array.isArray(item) && typeof (item as Record<string,unknown>).category === 'string') {
        const key = (item as Record<string,string>).category; counts.set(key,(counts.get(key) || 0)+1);
      }
    }
    return Array.from(counts.entries());
  },[items]);
  const options:ImportOptions = {city_id:city, category_id:category, source_categories:sourceCategories, min_rating:minRating, min_reviews:minReviews};
  const invalidate = () => {setRows(null);setDecisions({});setResults([]);setError('');setProgress('');};
  const readFile = async (event:React.ChangeEvent<HTMLInputElement>) => {
    const file=event.target.files?.[0]; event.target.value='';
    invalidate(); setItems([]); setFilename(''); setSourceCategories([]);
    if (!file) return;
    if (file.size > 4 * 1024 * 1024) {setError('حجم فایل باید حداکثر ۴ مگابایت باشد.'); return;}
    try {
      const parsed:unknown = JSON.parse((await file.text()).replace(/^\uFEFF/,''));
      if (!Array.isArray(parsed) || parsed.length === 0 || parsed.length > 500) throw new Error('فایل باید آرایه‌ای شامل ۱ تا ۵۰۰ رکورد باشد.');
      setItems(parsed);setFilename(file.name);
      const cats = Array.from(new Set(parsed.flatMap((item:unknown) => item && typeof item==='object' && !Array.isArray(item) && typeof (item as Record<string,unknown>).category === 'string' ? [(item as Record<string,string>).category] : [])));
      if(cats.length===1) setSourceCategories(cats);
    } catch(e) {setError(e instanceof SyntaxError ? 'فایل JSON معتبر نیست.' : e instanceof Error ? e.message : 'خواندن فایل ناموفق بود.');}
  };
  const doPreview = async () => {
    if (!items.length || !city || !category || !sourceCategories.length) {setError('فایل، شهر، دسته مقصد و حداقل یک دسته از فایل را انتخاب کنید.');return;}
    setBusy(true);setError('');setResults([]);setProgress('در حال بررسی فایل…');setRows(null);
    const res=await previewJsonImport(items,options,csrfToken); setBusy(false);setProgress('');
    if(!res.ok || !res.rows){setError(res.error || 'پیش‌نمایش ناموفق بود.');return;}
    setRows(res.rows);
    setDecisions(Object.fromEntries(res.rows.map(r=>[r.index,r.status==='new'?'new':r.status==='update'?'update':'skip'])));
  };
  const visibleRows = (rows || []).filter(r => (rowFilter==='all' || r.status===rowFilter) && (!search.trim() || `${r.name} ${r.source_id} ${r.source_category}`.toLowerCase().includes(search.trim().toLowerCase())));
  const selected = (rows || []).filter(r => decisions[r.index] && decisions[r.index]!=='skip');
  const doApply = async () => {
    if(!rows || !selected.length) return;
    if(!window.confirm(`${selected.length} رکورد انتخاب شده است. موارد جدید منتشر نمی‌شوند و تغییر مشخصات موجود به صف بازبینی می‌رود. ادامه می‌دهید؟`)) return;
    setBusy(true);setError('');setResults([]);
    const entries:ImportEntry[] = selected.map(r=>({index:r.index,item:items[r.index],decision:decisions[r.index],candidate_ids:r.candidates.map(c=>c.id)}));
    let all:ImportResult[]=[];
    for(let i=0;i<entries.length;i+=25){
      setProgress(`ثبت ${Math.min(i+25,entries.length)} از ${entries.length}…`);
      const res=await applyJsonImport(entries.slice(i,i+25),options,csrfToken);
      if(!res.ok || !res.results){
        setError(`${res.error || 'ارتباط قطع شد.'} نتیجه نوبت آخر ممکن است نامشخص باشد؛ پیش از تلاش دوباره پیش‌نمایش بگیرید.`);
        setRows(null); break;
      }
      all=[...all,...res.results];setResults(all);
      setDecisions(prev=>{const next={...prev};for(const r of res.results || []) if(r.status==='created' || r.status==='updated') next[r.index]='skip';return next;});
    }
    setProgress('');setBusy(false);await onRefreshData();
  };
  return <div className="space-y-5">
    <section className="bg-white border border-slate-200 rounded-xl p-5 space-y-4">
      <div><h1 className="font-bold text-lg flex items-center gap-2"><FileJson className="w-5 h-5 text-blue-700"/> ورود و به‌روزرسانی JSON</h1>
        <p className="text-sm text-slate-600 mt-2">فایل را انتخاب کنید، شهر و دسته مقصد را مشخص کنید و پیش از ثبت نتیجه را ببینید. جمع‌آوری و ورود کاملاً دستی است.</p></div>
      <div className="p-3 rounded bg-amber-50 text-amber-900 text-xs leading-6 flex gap-2"><AlertTriangle className="w-4 h-4 shrink-0 mt-1"/><span>شناسه منبع یکسان به‌روزرسانی می‌شود؛ شباهت‌ها نیاز به تصمیم شما دارند. هنگام اتصال، دسته و مشخصات ویرایش‌شده قبلی حفظ می‌شوند. رکورد جدید در صف بازبینی می‌ماند. متن نظرها به‌صورت مستقل نمایش داده نمی‌شود. مجوز استفاده از داده‌های منبع را بررسی کنید.</span></div>
      <label className="block text-sm font-semibold">فایل JSON (تا ۵۰۰ رکورد، حداکثر ۴ مگابایت)
        <input disabled={busy} type="file" accept=".json,application/json" onChange={readFile} className="block mt-2 text-sm font-normal"/>
      </label>
      {filename && <p className="text-xs text-blue-700">{filename} · {items.length} رکورد خوانده شد</p>}
      <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <label className="text-xs font-semibold">شهر مقصد<select disabled={busy} className={`${control} mt-1`} value={city} onChange={e=>{invalidate();setCity(Number(e.target.value));}}><option value="0">انتخاب شهر</option>{cities.filter(c=>Number(c.is_active)===1).map(c=><option key={c.id} value={c.id}>{c.name_fa}</option>)}</select></label>
        <label className="text-xs font-semibold">دسته مقصد در حامی‌هاب<select disabled={busy} className={`${control} mt-1`} value={category} onChange={e=>{invalidate();setCategory(Number(e.target.value));}}><option value="0">انتخاب دسته</option>{categories.filter(c=>Number(c.is_active)===1).map(c=><option key={c.id} value={c.id}>{c.name_fa}</option>)}</select></label>
        <label className="text-xs font-semibold">حداقل امتیاز (اختیاری)<input disabled={busy} type="number" min="0" max="5" step="0.1" className={`${control} mt-1`} value={minRating} onChange={e=>{invalidate();setMinRating(Number(e.target.value));}}/></label>
        <label className="text-xs font-semibold">حداقل تعداد نظر (اختیاری)<input disabled={busy} type="number" min="0" step="1" className={`${control} mt-1`} value={minReviews} onChange={e=>{invalidate();setMinReviews(Number(e.target.value));}}/></label>
      </div>
      {categoriesInFile.length>0 && <fieldset disabled={busy} className="border border-slate-200 rounded p-3"><legend className="text-xs font-bold px-1">دسته‌های داخل فایل که به دسته مقصد وارد می‌شوند</legend>
        <div className="flex flex-wrap gap-3 mt-1">{categoriesInFile.map(([name,count])=><label key={name} className="text-xs flex items-center gap-1.5"><input type="checkbox" checked={sourceCategories.includes(name)} onChange={e=>{invalidate();setSourceCategories(prev=>e.target.checked?[...prev,name]:prev.filter(x=>x!==name));}}/>{name || 'دسته خالی'} ({count})</label>)}</div>
        <p className="text-xs text-slate-500 mt-2">فقط دسته‌های مناسب را تیک بزنید؛ مثلاً هتل را به دسته کافه وارد نکنید. هر دسته مقصد را جداگانه وارد کنید.</p></fieldset>}
      <button disabled={busy || !items.length} onClick={doPreview} className="px-4 py-2.5 rounded-lg bg-blue-700 text-white text-sm font-semibold disabled:opacity-50">پیش‌نمایش بدون ذخیره</button>
      {error && <p role="alert" className="text-sm text-rose-800 bg-rose-50 p-3 rounded">{error}</p>}
      {progress && <p className="text-sm text-blue-700" role="status">{progress}</p>}
    </section>
    {results.length>0 && <section className="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-sm" role="status">
      نتیجه: {results.filter(r=>r.status==='created').length} جدید، {results.filter(r=>r.status==='updated').length} به‌روزرسانی، {results.filter(r=>r.status==='skipped').length} ردشده، {results.filter(r=>r.status==='error').length} خطا.
      {results.some(r=>r.status==='skipped'||r.status==='error') && <ul className="mt-2 text-xs space-y-1">{results.filter(r=>r.status==='skipped'||r.status==='error').map((r,i)=><li key={`${r.index}-${i}`}>ردیف {r.index+1}: {r.message}</li>)}</ul>}
    </section>}
    {rows && <section className="bg-white border border-slate-200 rounded-xl p-5 space-y-4">
      <div className="flex flex-wrap justify-between items-center gap-3"><div><h2 className="font-bold">پیش‌نمایش و انتخاب</h2><p className="text-xs text-slate-600 mt-1">{(['new','update','review','filtered','invalid','conflict','stale'] as const).map(k=>`${labels[k]}: ${rows.filter(r=>r.status===k).length}`).join(' · ')}</p></div>
        <button disabled={busy || !selected.length} onClick={doApply} className="bg-emerald-700 text-white rounded-lg px-4 py-2 text-sm font-bold disabled:opacity-50">ثبت {selected.length} مورد انتخاب‌شده</button></div>
      <div className="grid sm:grid-cols-2 gap-3"><label className="flex items-center gap-2 text-xs"><Search className="w-4 h-4"/><input className={control} placeholder="جستجو در پیش‌نمایش" value={search} onChange={e=>setSearch(e.target.value)}/></label>
        <select className={control} value={rowFilter} onChange={e=>setRowFilter(e.target.value)}><option value="all">همه وضعیت‌ها</option>{(Object.keys(labels) as ImportRow['status'][]).map(k=><option key={k} value={k}>{labels[k]}</option>)}</select></div>
      <p className="text-xs text-slate-500">{visibleRows.length} مورد در فیلتر نمایش؛ انتخاب‌ها حتی اگر در فیلتر پنهان شوند حفظ می‌شوند.</p>
      <div className="overflow-x-auto max-h-[36rem] overflow-y-auto"><table className="w-full text-xs text-right"><thead className="bg-slate-50 sticky top-0"><tr><th className="p-2">ردیف و نام</th><th className="p-2">دسته / امتیاز</th><th className="p-2">نتیجه بررسی</th><th className="p-2">تصمیم</th></tr></thead><tbody>{visibleRows.map(r=><tr key={r.index} className="border-t border-slate-200 align-top"><td className="p-2"><b>{r.index+1}. {r.name || 'بدون نام'}</b><div dir="ltr" className="text-slate-500 break-all mt-1">{r.source} / {r.source_id}</div></td><td className="p-2">{r.source_category}<div className="mt-1">{r.rating===null?'بدون امتیاز':`${r.rating} / 5`} · {r.reviews_count===null?'تعداد نامشخص':`${r.reviews_count} نظر`}</div></td><td className="p-2"><strong>{labels[r.status]}</strong><div className="mt-1 text-slate-600">{r.message}</div></td><td className="p-2 min-w-48">
        {(r.status==='new'||r.status==='update') && <label className="flex gap-2"><input disabled={busy} type="checkbox" checked={decisions[r.index] !== 'skip'} onChange={e=>setDecisions(prev=>({...prev,[r.index]:e.target.checked?(r.status==='new'?'new':'update'):'skip'}))}/>ثبت این مورد</label>}
        {r.status==='review' && <select disabled={busy} className={control} value={decisions[r.index] || 'skip'} onChange={e=>setDecisions(prev=>({...prev,[r.index]:e.target.value}))}><option value="skip">فعلاً رد شود</option><option value="new">این مکان جدید و جداست</option>{r.candidates.map(c=><option key={c.id} value={`link:${c.id}`}>اتصال به #{c.id} {c.name} · {c.category_name || 'دسته نامشخص'}{c.same_phone?' · تلفن مشترک':''}{c.distance!==null?` · ${Math.round(c.distance)} متر`:''} {c.address?` · ${c.address}`:''}</option>)}</select>}
      </td></tr>)}</tbody></table></div>
    </section>}
  </div>;
};
