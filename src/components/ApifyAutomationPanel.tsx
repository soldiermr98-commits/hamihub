import React, { useCallback, useEffect, useRef, useState } from 'react';
import { CloudDownload, RefreshCw } from 'lucide-react';
import { deleteApifyRun, fetchApifyRun, fetchApifyStatus, importApifyRun, refreshApifyRun, resolveApifyRun, saveApifySchedule, type ApifyRunData, type ApifyRunSummary, type ApifySchedule, type CategoryItem, type CityItem } from '../services/adminApi';

interface Props { cities:CityItem[]; categories:CategoryItem[]; csrfToken:string; onOpen:(run:ApifyRunData)=>void; onBusyChange:(busy:boolean)=>void; selectedSourceCategories:string[] }
const inputClass='w-full mt-1 border border-slate-300 rounded-lg bg-white px-3 py-2 text-sm';
const statusLabels:Record<string,string>={starting:'در حال آغاز',running:'در حال اجرا / دریافت',ready:'آماده بازبینی',failed:'ناموفق / بسته‌شده',too_large:'خروجی بیش از حد مجاز',start_unknown:'آغاز نامشخص؛ متوقف'};

export const ApifyAutomationPanel:React.FC<Props>=({cities,categories,csrfToken,onOpen,onBusyChange,selectedSourceCategories})=>{
  const [schedule,setSchedule]=useState<ApifySchedule|null>(null);
  const [runs,setRuns]=useState<ApifyRunSummary[]>([]); const [configured,setConfigured]=useState(false);
  const [task,setTask]=useState(''); const [city,setCity]=useState(0); const [category,setCategory]=useState(0); const [cap,setCap]=useState('1.00');
  const [sourceCategories,setSourceCategories]=useState(''); const [minRating,setMinRating]=useState('0'); const [minReviews,setMinReviews]=useState('0');
  const [runId,setRunId]=useState(''); const [busy,setBusy]=useState(false); const [notice,setNotice]=useState(''); const [error,setError]=useState('');
  const initialized=useRef(false);
  const setWorking=(value:boolean)=>{setBusy(value);onBusyChange(value);};
  const refresh=useCallback(async()=>{
    try {
      const r=await fetchApifyStatus();setConfigured(r.token_configured);setSchedule(r.schedule);setRuns(r.runs);
      if(!initialized.current && r.schedule){setTask(r.schedule.task_id || '');setCity(Number(r.schedule.city_id || 0));setCategory(Number(r.schedule.category_id || 0));if(r.schedule.max_charge_usd!==null)setCap(String(r.schedule.max_charge_usd));setSourceCategories((r.schedule.source_categories||[]).join('\n'));setMinRating(String(r.schedule.min_rating||0));setMinReviews(String(r.schedule.min_reviews||0));initialized.current=true;}
      setError('');
    } catch(e){setError(e instanceof Error?e.message:'دریافت وضعیت Apify ناموفق بود.');}
  },[]);
  useEffect(()=>{void refresh();},[refresh]);
  const open=async(id:number)=>{
    setWorking(true);setError('');
    try{const r=await fetchApifyRun(id);onOpen(r.run);setNotice('خروجی در بخش ورود و پیش‌نمایش پایین آماده شد؛ هنوز چیزی ثبت نشده است.');}
    catch(e){setError(e instanceof Error?e.message:'دریافت خروجی ناموفق بود.');}
    finally{setWorking(false);}
  };
  const receive=async()=>{
    if(!runId.trim()){setError('Run ID را وارد کنید؛ توکن را اینجا وارد نکنید.');return;}
    setWorking(true);setError('');setNotice('');
    const r=await importApifyRun(runId.trim(),csrfToken);
    if(!r.ok){setError(r.error || 'دریافت اجرا ناموفق بود.');setWorking(false);return;}
    await refresh();setNotice('اجرا بررسی شد. اگر هنوز در حال اجراست، پس از تکمیل و اجرای کرون در این فهرست آماده می‌شود.');
    if(r.id){try{const ready=await fetchApifyRun(r.id);onOpen(ready.run);setNotice('خروجی دریافت و برای پیش‌نمایش آماده شد؛ هنوز چیزی ثبت نشده است.');}catch{/* Still running or empty; status is shown above. */}}
    setWorking(false);
  };
  const enable=async()=>{
    if(!task.trim()||!city||!category||!Number.isFinite(Number(cap))||Number(cap)<=0){setError('Task ID، شهر، دسته و سقف هزینه را وارد کنید.');return;}
    if(!window.confirm(`زمان‌بندی فعال شود؟ اولین اجرای سررسیدشده ممکن است در کرون بعدی شروع شود و هر ۱۴ روز با سقف ${cap} دلار برای هر اجرا هزینه داشته باشد. داده‌ها خودکار منتشر نمی‌شوند.`)) return;
    setWorking(true);setError('');setNotice('');
    const r=await saveApifySchedule({enabled:true,task_id:task.trim(),city_id:city,category_id:category,max_charge_usd:Number(cap),source_categories:sourceCategories.split('\n').map(x=>x.trim()).filter(Boolean),min_rating:Number(minRating),min_reviews:Number(minReviews)},csrfToken);
    if(!r.ok) setError(r.error || 'ذخیره ناموفق بود.'); else {await refresh();setNotice('زمان‌بندی ذخیره شد. برای اجرا، کرون هاست و Task معتبر لازم‌اند.');}
    setWorking(false);
  };
  const disable=async()=>{
    if(!window.confirm('زمان‌بندی متوقف شود؟ اجرای شروع‌شده در Apify لغو نمی‌شود و دریافت نتیجه آن ادامه می‌یابد.'))return;
    setWorking(true);const r=await saveApifySchedule({enabled:false},csrfToken);if(!r.ok)setError(r.error||'توقف ناموفق بود.');else {await refresh();setNotice('زمان‌بندی متوقف شد.');}setWorking(false);
  };
  const checkRun=async(id:number)=>{
    setWorking(true);setError('');setNotice('');
    const r=await refreshApifyRun(id,csrfToken);if(!r.ok)setError(r.error||'بررسی اجرا ناموفق بود.');else {await refresh();setNotice('وضعیت اجرای موجود بررسی شد؛ اجرای جدیدی شروع نشد.');}setWorking(false);
  };
  const remove=async(id:number)=>{
    if(!window.confirm('فقط خروجی ذخیره‌شده این اجرا از صندوق Apify پاک می‌شود؛ کسب‌وکارهای قبلی حذف نمی‌شوند. ادامه؟'))return;
    setWorking(true);const r=await deleteApifyRun(id,csrfToken);if(!r.ok)setError(r.error||'حذف ناموفق بود.');else await refresh();setWorking(false);
  };
  const resolve=async(id:number)=>{
    if(!window.confirm('ابتدا در حساب Apify مطمئن شوید آیا اجرای هزینه‌دار آغاز شده است. اگر آغاز شده، Run ID آن را جداگانه دریافت کنید. این تأیید، توقف نامشخص را می‌بندد اما زمان‌بندی را دوباره فعال نمی‌کند. بررسی کردید؟'))return;
    setWorking(true);const r=await resolveApifyRun(id,csrfToken);if(!r.ok)setError(r.error||'رفع توقف ناموفق بود.');else await refresh();setWorking(false);
  };
  return <section className="bg-white border border-slate-200 rounded-xl p-5 space-y-4">
    <div className="flex justify-between gap-3 items-start"><div><h2 className="font-bold flex items-center gap-2"><CloudDownload className="w-5 h-5 text-blue-700"/> دریافت خودکار Apify</h2>
      <p className="text-sm text-slate-600 mt-2">اجرای ذخیره‌شده را بدون دانلود فایل دریافت کنید، یا یک Task را هر ۱۴ روز اجرا کنید. خروجی فقط برای بازبینی آماده می‌شود.</p></div>
      <button disabled={busy} onClick={()=>void refresh()} className="text-blue-700 text-xs flex items-center gap-1"><RefreshCw className="w-4 h-4"/> تازه‌سازی وضعیت</button></div>
    <div className="text-xs leading-6 bg-amber-50 text-amber-900 rounded-lg p-3">توکن باید فقط در فایل خصوصی <code dir="ltr">config/config.local.php</code> روی هاست قرار بگیرد؛ آن را در پنل یا گفتگو وارد نکنید. وضعیت توکن: <b>{configured?'تنظیم شده':'تنظیم نشده'}</b>. اتصال زنده و کرون باید روی هاست آزمایش شوند. برداشت و بازنشر داده‌های منبع نیازمند بررسی مجوز است.</div>
    <div className="grid sm:grid-cols-[1fr_auto] gap-2 items-end"><label className="text-xs font-semibold">دریافت یک اجرای موجود با Run ID (شروع جدید و هزینه Actor ندارد)<input dir="ltr" className={inputClass} value={runId} onChange={e=>setRunId(e.target.value)} placeholder="Run ID" disabled={busy}/></label><button disabled={busy||!configured} onClick={()=>void receive()} className="bg-slate-800 text-white rounded-lg px-4 py-2 text-sm disabled:opacity-50">دریافت اجرا</button></div>
    <details className="border border-slate-200 rounded-lg p-3"><summary className="font-semibold text-sm cursor-pointer">تنظیم اجرای خودکار هر ۱۴ روز — {Number(schedule?.enabled)===1?'فعال':'خاموش'}</summary>
      <p className="text-xs text-slate-600 leading-6 mt-2">یک Task سازگار در Apify بسازید و ابتدا با حجم کم تست کنید. ترجیحاً یک عبارت، یک شهر و یک زبان داشته باشد؛ سقف ۵۰۰ برای هر عبارت/شهر است و تضمین ۵۰۰ مکان یکتا نیست. زمان‌بندی اپایفای را برای همان Task هم‌زمان روشن نکنید. در اولین فعال‌سازی ممکن است نوبت بعدی کرون فوراً اجرا را شروع کند.</p>
      <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-3">
        <label className="text-xs font-semibold">Task ID در Apify<input dir="ltr" className={inputClass} value={task} onChange={e=>setTask(e.target.value)} disabled={busy} placeholder="Task ID"/></label>
        <label className="text-xs font-semibold">شهر مقصد<select className={inputClass} value={city} onChange={e=>setCity(Number(e.target.value))} disabled={busy}><option value={0}>انتخاب شهر</option>{cities.filter(c=>Number(c.is_active)===1).map(c=><option value={c.id} key={c.id}>{c.name_fa}</option>)}</select></label>
        <label className="text-xs font-semibold">دسته مقصد<select className={inputClass} value={category} onChange={e=>setCategory(Number(e.target.value))} disabled={busy}><option value={0}>انتخاب دسته</option>{categories.filter(c=>Number(c.is_active)===1).map(c=><option value={c.id} key={c.id}>{c.name_fa}</option>)}</select></label>
        <label className="text-xs font-semibold">سقف هزینه هر اجرا (دلار)<input dir="ltr" type="number" min="0.01" max="100" step="0.01" className={inputClass} value={cap} onChange={e=>setCap(e.target.value)} disabled={busy}/></label>
      </div>
      <div className="grid sm:grid-cols-3 gap-3 mt-3">
        <label className="text-xs font-semibold">دسته‌های مجاز منبع (اختیاری، هر خط یک مورد)<textarea dir="auto" rows={3} className={inputClass} value={sourceCategories} onChange={e=>setSourceCategories(e.target.value)} disabled={busy} placeholder="دندانپزشک"/><span className="font-normal text-slate-500">خالی باشد، دسته‌ها را در هر پیش‌نمایش بررسی می‌کنید.</span></label>
        <label className="text-xs font-semibold">حداقل امتیاز در پیش‌نمایش<input type="number" min="0" max="5" step="0.1" className={inputClass} value={minRating} onChange={e=>setMinRating(e.target.value)} disabled={busy}/></label>
        <label className="text-xs font-semibold">حداقل تعداد نظر در پیش‌نمایش<input type="number" min="0" step="1" className={inputClass} value={minReviews} onChange={e=>setMinReviews(e.target.value)} disabled={busy}/></label>
      </div>
      <button type="button" disabled={busy||!selectedSourceCategories.length} onClick={()=>setSourceCategories(selectedSourceCategories.join('\n'))} className="text-xs text-blue-700 disabled:opacity-50 mt-2">استفاده از دسته‌های انتخاب‌شده پایین صفحه</button>
      <p className="text-xs text-slate-500 mt-2">این فیلترها هنگام باز کردن هر خروجی انتخاب می‌شوند؛ پیش‌نمایش دوباره با وضعیت روز دیتابیس بررسی می‌شود. سقف درخواست به Apify ارسال می‌شود؛ هزینه‌های دیگر حساب/ذخیره ممکن است جدا باشند. زمان اجرای بعدی سرور (تهران): {schedule?.next_run_at || 'هنوز تعیین نشده'}.</p>
      {schedule?.last_error&&<p className="mt-2 text-xs text-rose-800">آخرین خطا: {schedule.last_error}</p>}
      <div className="flex flex-wrap gap-2 mt-3"><button disabled={busy||!configured} onClick={()=>void enable()} className="bg-blue-700 text-white rounded px-4 py-2 text-sm disabled:opacity-50">ذخیره و فعال‌سازی</button><button disabled={busy||Number(schedule?.enabled)!==1} onClick={()=>void disable()} className="border border-slate-300 rounded px-4 py-2 text-sm disabled:opacity-50">توقف زمان‌بندی</button></div>
    </details>
    {error&&<p role="alert" className="text-sm text-rose-800 bg-rose-50 p-3 rounded">{error}</p>}{notice&&<p role="status" className="text-sm text-blue-800 bg-blue-50 p-3 rounded">{notice}</p>}
    {runs.length>0&&<div className="overflow-x-auto"><table className="w-full text-xs text-right"><thead className="bg-slate-50"><tr><th className="p-2">اجرا</th><th className="p-2">وضعیت / تعداد</th><th className="p-2">عملیات</th></tr></thead><tbody>{runs.map(r=><tr key={r.id} className="border-t align-top"><td className="p-2"><b>#{r.id}</b><div dir="ltr" className="text-slate-500 break-all">{r.remote_run_id || 'Run ID نامشخص'}</div><div className="text-slate-500">{r.created_at}</div></td><td className="p-2">{statusLabels[r.status] || r.status}{r.item_count!==null?` · ${r.item_count} رکورد`:''}{r.error&&<div className="text-rose-700 mt-1">{r.error}</div>}</td><td className="p-2"><div className="flex flex-wrap gap-2">{r.status==='ready'&&<button disabled={busy||Number(r.item_count)===0} className="text-blue-700 font-bold disabled:opacity-50" onClick={()=>void open(Number(r.id))}>باز کردن برای پیش‌نمایش</button>}{r.status==='running'&&<button disabled={busy} className="text-blue-700" onClick={()=>void checkRun(Number(r.id))}>بررسی و دریافت</button>}{r.status==='start_unknown'&&<button disabled={busy} className="text-amber-800" onClick={()=>void resolve(Number(r.id))}>رفع توقف پس از بررسی</button>}{['ready','failed','too_large'].includes(r.status)&&<button disabled={busy} className="text-rose-700" onClick={()=>void remove(Number(r.id))}>حذف خروجی</button>}</div></td></tr>)}</tbody></table></div>}
  </section>;
};
