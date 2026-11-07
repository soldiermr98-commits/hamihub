import React, { useEffect, useState } from 'react';
import { AiProviderStatus, fetchAiProviders, updateFeatureSchema } from '../services/adminApi';

export const AiWorkerPanel: React.FC<{csrfToken:string;isAdmin:boolean}> = ({csrfToken,isAdmin}) => {
  const [providers,setProviders]=useState<AiProviderStatus[]>([]);const [ready,setReady]=useState(false);const [error,setError]=useState('');const [busy,setBusy]=useState(false);
  const load=async()=>{try{const d=await fetchAiProviders();setProviders(d.providers);setReady(d.schema_ready);setError('');}catch(e){setError(e instanceof Error?e.message:'خطا در دریافت وضعیت.');}};
  useEffect(()=>{void load();},[]);
  const install=async()=>{if(!window.confirm('از فایل‌ها و دیتابیس پشتیبان گرفته‌اید؟ فقط جدول‌های جدید اضافه می‌شوند و مهاجرت قدیمی ۰۰۳ اجرا نمی‌شود.'))return;
    setBusy(true);const result=await updateFeatureSchema(csrfToken);setBusy(false);if(!result.ok)setError(result.error);else await load();};
  return <section className="rounded-xl bg-white border border-slate-200 p-6 space-y-3">
    <h2 className="font-bold text-slate-900">سرویس‌های نویسنده هوش مصنوعی</h2>
    <p className="text-sm text-slate-600 leading-7">تولید فقط از داخل ویرایش مقاله و با تأیید نویسنده انجام می‌شود. نتیجه پیش‌نویس است و خودکار ذخیره یا منتشر نمی‌شود؛ سهمیه و احتمال هزینه را برای هر حساب بررسی کنید.</p>
    {error&&<p role="alert" className="text-sm text-rose-800">{error}</p>}
    {!ready&&<div className="text-sm rounded bg-amber-50 p-3 text-amber-900">جدول‌های بخش جدید آماده نیستند. {isAdmin&&<button type="button" disabled={busy} className="underline font-bold" onClick={()=>void install()}>آماده‌سازی پس از پشتیبان‌گیری</button>}</div>}
    <ul className="text-sm divide-y">{providers.map(p=><li key={p.id} className="py-2 flex justify-between gap-3"><span dir="ltr">{p.label} {p.model&&`· ${p.model}`}</span><span className={p.available?'text-emerald-700':'text-slate-500'}>{p.available?'آماده':'تنظیم‌نشده'}</span></li>)}</ul>
    <p className="text-xs text-slate-600 leading-6">مدیر کلیدها و گزینه تأیید سهمیه را فقط در فایل خصوصی <code dir="ltr">config/config.local.php</code> روی هاست تنظیم کند؛ آن‌ها را در پنل یا گفت‌وگو وارد نکنید. مدل OpenRouter به <code dir="ltr">openrouter/free</code> یا مدل‌های <code dir="ltr">:free</code> محدود است. فایل راهنمای <code dir="ltr">docs/AI_ADS_DATA_FA.md</code> را ببینید.</p>
  </section>;
};
