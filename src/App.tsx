import React, { useCallback, useEffect, useState } from 'react';
import { LayoutDashboard, Inbox, MapPin, Store, FileText, FileJson, Image as ImageIcon, Megaphone, Settings, LogOut } from 'lucide-react';
import { AdminUser, CategoryItem, CategoryPackItem, CityItem, NeighborhoodItem,
  fetchAuthMe, loginAdmin, logoutAdmin, fetchCategoriesAndPacks, fetchCitiesAndNeighborhoods, runWebMigrations, updateSeoSchema,
  FINANCE_PENDING_STORAGE_KEY, GATEWAY_PENDING_STORAGE_KEY } from './services/adminApi';
import { CitiesAndCategoriesView } from './components/CitiesAndCategoriesView';
import { BusinessesManagerView } from './components/BusinessesManagerView';
import { ReviewQueueView } from './components/ReviewQueueView';
import { ArticlesManagerView } from './components/ArticlesManagerView';
import { MediaManagerView } from './components/MediaManagerView';
import { AiWorkerPanel } from './components/AiWorkerPanel';
import { AdminMailPanel } from './components/AdminMailPanel';
import { AdvertisingView } from './components/AdvertisingView';
import { S6AnalyticsPanel } from './components/S6AnalyticsPanel';
import { JsonImportView } from './components/JsonImportView';

type Route = 'dashboard'|'review'|'cities'|'businesses'|'import'|'articles'|'media'|'advertising'|'settings';
const nav: Array<{id:Route;label:string;icon:React.ElementType}> = [
  {id:'dashboard',label:'داشبورد',icon:LayoutDashboard},{id:'review',label:'بازبینی',icon:Inbox},
  {id:'cities',label:'جمع‌آوری و شهرها',icon:MapPin},{id:'businesses',label:'کسب‌وکارها',icon:Store},
  {id:'import',label:'جمع‌آوری و ورود',icon:FileJson},{id:'articles',label:'مقالات',icon:FileText},
  {id:'media',label:'رسانه',icon:ImageIcon},
  {id:'advertising',label:'تبلیغات و درخواست‌ها',icon:Megaphone},{id:'settings',label:'تنظیمات',icon:Settings},
];
function routeFromPath():Route {
  const part = window.location.pathname.split('/').filter(Boolean)[1] as Route | undefined;
  return part && nav.some(n=>n.id===part) ? part : 'review';
}
export default function App() {
  const [route,setRoute]=useState<Route>(routeFromPath);
  const [articleDirty,setArticleDirty]=useState(false);
  const go = useCallback((next:Route)=>{
    if(route==='articles' && articleDirty && next!=='articles' && !window.confirm('تغییرات مقاله ذخیره نشده‌اند. از این بخش خارج شوید؟')) return;
    if(next!=='articles') setArticleDirty(false);
    window.history.pushState(null,'',`/admin/${next}/`);setRoute(next);
  },[route,articleDirty]);
  useEffect(()=>{
    const onPop=()=>{
      if(route==='articles' && articleDirty && routeFromPath()!=='articles' && !window.confirm('تغییرات مقاله ذخیره نشده‌اند. از این بخش خارج شوید؟')) {
        window.history.pushState(null,'','/admin/articles/');return;
      }
      setArticleDirty(false);setRoute(routeFromPath());
    };
    window.addEventListener('popstate',onPop);return()=>window.removeEventListener('popstate',onPop);
  },[route,articleDirty]);
  const [user,setUser]=useState<AdminUser|null>(null); const [csrf,setCsrf]=useState('');
  const [loading,setLoading]=useState(true); const [error,setError]=useState('');
  const [username,setUsername]=useState(''); const [password,setPassword]=useState('');
  const [cities,setCities]=useState<CityItem[]>([]); const [neighborhoods,setNeighborhoods]=useState<NeighborhoodItem[]>([]);
  const [categories,setCategories]=useState<CategoryItem[]>([]); const [packs,setPacks]=useState<CategoryPackItem[]>([]);
  const [notice,setNotice]=useState('');
  const refresh=useCallback(async()=>{
    try {
      const [c,k]=await Promise.all([fetchCitiesAndNeighborhoods(),fetchCategoriesAndPacks()]);
      setCities(c.cities);setNeighborhoods(c.neighborhoods);setCategories(k.categories);setPacks(k.packs);setError('');
    } catch(e) { setError(e instanceof Error?e.message:'خطا در دریافت داده‌ها'); }
  },[]);
  useEffect(()=>{ (async()=>{try { const a=await fetchAuthMe(); setCsrf(a.csrf_token);setUser(a.user);if(a.authenticated) await refresh(); }
    catch(e){setError(e instanceof Error?e.message:'خطای ارتباط با سرور');}finally{setLoading(false);} })(); },[refresh]);
  const login=async(e:React.FormEvent)=>{e.preventDefault();setError('');const a=await loginAdmin({username,password,csrfToken:csrf});
    if(!a.ok){setError(a.error);return;}setUser(a.user);setCsrf(a.csrf_token);setPassword('');await refresh();};
  const logout=async()=>{
    if(route==='articles' && articleDirty && !window.confirm('تغییرات مقاله ذخیره نشده‌اند. خارج شوید؟')) return;
    let pendingFinance=false;
    try {pendingFinance=window.sessionStorage.getItem(FINANCE_PENDING_STORAGE_KEY)!==null||window.sessionStorage.getItem(GATEWAY_PENDING_STORAGE_KEY)!==null;} catch { /* storage may be disabled */ }
    if(pendingFinance&&!window.confirm('نتیجه یک درخواست مالی یا تنظیم درگاه نیاز به پیگیری دارد. با خروج، اطلاعات بازیابی این زبانه حذف می‌شود؛ پیش از درخواست دوباره وضعیت را مستقل بررسی کنید. خارج می‌شوید؟'))return;
    const r=await logoutAdmin(csrf);if(!r.ok){setError(r.error);return;}
    try {window.sessionStorage.removeItem(FINANCE_PENDING_STORAGE_KEY);window.sessionStorage.removeItem(GATEWAY_PENDING_STORAGE_KEY);} catch { /* no accessible browser storage */ }
    setArticleDirty(false);setUser(null);setCsrf(r.csrf_token);
  };
  return <div dir="rtl" className="min-h-screen bg-slate-50 text-slate-900">
    <header className="bg-[#050B1E] text-white px-6 py-4 flex justify-between items-center gap-4"><a href="/" className="font-bold">حامی‌هاب گیلان</a>{user&&<button onClick={logout} className="text-sm flex gap-2 items-center"><LogOut className="w-4 h-4"/> خروج ({user.username})</button>}</header>
    {loading?<div className="p-10 text-center">در حال ارتباط با سرور…</div>:!user?<main className="max-w-md mx-auto p-6 mt-10 bg-white rounded-xl border border-slate-200">
      <h1 className="text-lg font-bold mb-5">ورود به پنل مدیریت</h1>{error&&<p role="alert" className="bg-rose-50 text-rose-800 p-3 rounded mb-4 text-sm">{error}</p>}
      <form onSubmit={login} className="space-y-4"><label className="block text-sm">نام کاربری<input className="border rounded w-full p-2 mt-1" value={username} onChange={e=>setUsername(e.target.value)} autoComplete="username" required/></label><label className="block text-sm">رمز عبور<input className="border rounded w-full p-2 mt-1" type="password" value={password} onChange={e=>setPassword(e.target.value)} autoComplete="current-password" required/></label><button disabled={!csrf} className="bg-blue-700 disabled:opacity-50 text-white w-full rounded p-2">ورود</button></form>
      <p className="text-xs text-slate-500 mt-4">برای نصب اولیه از نصب‌کننده واقعی سرور استفاده کنید.</p></main>:
    <div className="flex flex-col md:flex-row"><aside className="md:w-60 bg-white border-l border-slate-200 p-4 shrink-0"><p className="text-xs text-slate-500 p-2">{user.display_name} · {user.role}</p><nav className="flex md:flex-col gap-1 overflow-x-auto">{nav.filter(n=>!['import','advertising'].includes(n.id)||user.role==='admin').map(n=><button key={n.id} onClick={()=>go(n.id)} className={`flex items-center gap-2 px-3 py-2 rounded text-sm whitespace-nowrap ${route===n.id?'bg-blue-50 text-blue-800 font-bold':'hover:bg-slate-100'}`}><n.icon className="w-4 h-4"/>{n.label}</button>)}</nav></aside>
    <main className="flex-1 p-4 md:p-8 min-w-0">{error&&<p role="alert" className="p-3 mb-4 bg-rose-50 text-rose-800 rounded text-sm">{error}</p>}{notice&&<p className="p-3 mb-4 bg-blue-50 rounded text-sm">{notice}</p>}
      {route==='dashboard'&&<div className="space-y-4">{user.role==='admin'&&<section className="max-w-6xl mx-auto flex flex-wrap items-center justify-between gap-3 rounded-xl border border-blue-200 bg-blue-50 p-4"><div><h2 className="font-bold text-blue-950">درخواست‌های تبلیغات</h2><p className="mt-1 text-sm text-blue-800">درخواست‌های ثبت‌شده را ببینید و پیگیری کنید.</p></div><button type="button" onClick={()=>go('advertising')} className="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">مشاهده درخواست‌ها</button></section>}<S6AnalyticsPanel csrfToken={csrf} onNavigateReview={()=>go('review')}/></div>}
      {route==='review'&&<ReviewQueueView csrfToken={csrf} onRefreshParent={refresh} isAdmin={user.role==='admin'}/>}
      {route==='cities'&&<CitiesAndCategoriesView cities={cities} categories={categories} packs={packs} csrfToken={csrf} onRefreshData={refresh}/>}
      {route==='businesses'&&<BusinessesManagerView cities={cities} neighborhoods={neighborhoods} categories={categories} csrfToken={csrf} onRefreshData={refresh} isAdmin={user.role==='admin'}/>}
      {route==='import'&&user.role==='admin'&&<JsonImportView cities={cities} categories={categories} csrfToken={csrf} onRefreshData={refresh} onGoReview={()=>go('review')}/>}
      {route==='articles'&&<ArticlesManagerView cities={cities} categories={categories} csrfToken={csrf} isAdmin={user.role==='admin'} onGoReview={()=>go('review')} onDirtyChange={setArticleDirty}/>}
      {route==='media'&&<MediaManagerView csrfToken={csrf} isAdmin={user.role==='admin'}/>}
      {route==='advertising'&&user.role==='admin'&&<AdvertisingView csrfToken={csrf} cities={cities} categories={categories}/>}
      {route==='settings'&&<div className="space-y-4"><section className="bg-white border rounded-xl p-6"><h1 className="font-bold">نگهداری پایگاه داده</h1><div className="flex flex-wrap gap-2 mt-3"><button className="bg-blue-700 text-white rounded p-2 text-sm" onClick={async()=>{const r=await runWebMigrations(csrf);setNotice(r.message||r.error);if(r.ok) await refresh();}}>اجرای مایگریشن‌ها</button>{user.role==='admin'&&<button className="border border-slate-300 bg-white hover:bg-slate-50 rounded p-2 text-sm font-semibold" onClick={async()=>{const r=await updateSeoSchema(csrf);setNotice(r.message||r.error);}}>آماده‌سازی جدول قوانین سئو (۴۱۰/۳۰۱)</button>}</div></section>{user.role==='admin'&&<AdminMailPanel csrfToken={csrf}/>}<AiWorkerPanel csrfToken={csrf} isAdmin={user.role==='admin'}/></div>}
    </main></div>}</div>;
}
