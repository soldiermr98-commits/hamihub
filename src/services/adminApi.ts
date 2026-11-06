/**
 * سرویس ارتباط پنل مدیریت React با API سمت سرور (/api/v1/*)
 * پشتیبانی کامل از عملیات مرحله S0 (احراز هویت، CSRF، مایگریشن بدون ترمینال)
 * و مرحله S1 (CRUD شهر، دسته، بسته دسته، کسب‌وکار، پیشنهاد اسلاگ و رسانه WebP)
 */

export interface AdminUser {
  id: number;
  username: string;
  display_name: string;
  role: 'admin' | 'editor';
  last_login_at?: string;
}

export interface CityItem {
  id: number;
  name_fa: string;
  slug: string;
  lat: number | null;
  lng: number | null;
  bbox_json: string | null;
  publish_mode: 'manual' | 'rules' | 'full_auto';
  is_active: number;
  businesses_count?: number;
  neighborhoods_count?: number;
}

export interface NeighborhoodItem {
  id: number;
  city_id: number;
  name_fa: string;
  slug: string;
  lat: number | null;
  lng: number | null;
}

export interface CategoryItem {
  id: number;
  parent_id: number | null;
  name_fa: string;
  slug: string;
  sort: number;
  is_active: number;
  businesses_count?: number;
}

export interface CategoryPackItem {
  id: number;
  name: string;
  queries_json: string;
  is_active: number;
}

export interface BusinessContactItem {
  id?: number;
  business_id?: number;
  type: 'phone' | 'mobile' | 'instagram' | 'whatsapp' | 'website';
  value: string;
  is_primary: number;
  source: string;
}

export interface BusinessItem {
  id: number;
  name: string;
  slug: string;
  category_id: number;
  city_id: number;
  neighborhood_id: number | null;
  address: string | null;
  lat: number | null;
  lng: number | null;
  hours: string | null;
  short_desc: string | null;
  cover_media_id: number | null;
  cover_thumb_url?: string | null;
  cover_medium_url?: string | null;
  status: 'draft' | 'pending_review' | 'published' | 'hidden' | 'rejected';
  tier: 'A' | 'B' | 'C';
  internal_score: number;
  promoted?: number;
  confidence: 'low' | 'medium' | 'high';
  city_name?: string;
  category_name?: string;
  neighborhood_name?: string;
  contacts: BusinessContactItem[];
  warnings: string[];
  updated_at: string;
}

export interface MediaAsset {
  id: number;
  file_path_480: string;
  file_path_800: string;
  file_path_1200: string;
  mime_type: string;
  width: number;
  height: number;
  size_bytes: number;
  alt_text: string;
}

export interface CollectorJobResult {
  found: number;
  inserted: number;
  duplicates: number;
  updated_sources?: number;
  pending_review?: number;
  source_conflict?: number;
  errors: number;
  last_error?: string | null;
  warning?: string;
  finished_at?: string;
}

export interface CollectorJobItem {
  id: number;
  city_id: number;
  pack_id: number | null;
  provider: string;
  params_json: string;
  status: 'queued' | 'claimed' | 'running' | 'done' | 'failed' | 'paused';
  claimed_at: string | null;
  attempts: number;
  result_json: string | null;
  error: string | null;
  city_name?: string;
  pack_name?: string;
  created_at: string;
  updated_at: string;
}

export interface ProviderStateItem {
  provider: string;
  enabled: number;
  priority: number;
  paused_until: string | null;
  last_error: string | null;
}

export interface ReviewQueueItem {
  id: number;
  kind: 'business' | 'change' | 'duplicate' | 'article' | 'claim' | 'report';
  target_id: number;
  reason_code: string;
  payload_json: string;
  status: 'pending' | 'approved' | 'rejected';
  decided_by: number | null;
  decided_at: string | null;
  created_at: string;
  business_name?: string | null;
  business_slug?: string | null;
  business_address?: string | null;
  business_score?: number | null;
  business_tier?: 'A' | 'B' | 'C' | null;
  business_confidence?: 'low' | 'medium' | 'high' | null;
  city_name?: string | null;
}

export interface ChangeLogItem {
  id: number;
  business_id: number;
  business_name?: string | null;
  business_slug?: string | null;
  field: string;
  old_value: string | null;
  new_value: string | null;
  source: string;
  status: 'pending' | 'approved' | 'rejected' | 'auto_applied';
  created_at: string;
}

export interface Benchmark100Summary {
  total_processed: number;
  published: number;
  pending_review: number;
  duplicate: number;
  source_conflict: number;
  breakdown_reasons: Record<string, number>;
}

export interface ArticleBizRow {
  id?: number;
  article_id?: number;
  business_id: number;
  business_name_fa?: string;
  business_slug?: string;
  weighted_score?: number;
  tier?: 'A' | 'B' | 'C';
  rank_num: number;
  badge_fa: string;
  verdict_fa: string;
  custom_desc_html: string;
  is_sponsored?: number;
  sponsor_label?: string;
  sponsor_start?: string | null;
  sponsor_end?: string | null;
}

export interface ArticleFaqRow {
  id?: number;
  article_id?: number;
  question_fa: string;
  answer_fa: string;
  sort_order: number;
}

export interface ArticleItem {
  id: number;
  city_id: number;
  category_id: number | null;
  city_name_fa?: string;
  city_slug?: string;
  category_name_fa?: string;
  category_slug?: string;
  slug: string;
  title_fa: string;
  subtitle_fa: string;
  intro_html: string;
  methodology_html: string;
  status: 'draft' | 'review' | 'published';
  meta_title: string;
  meta_description: string;
  published_at?: string | null;
  items: ArticleBizRow[];
  faqs: ArticleFaqRow[];
}

export interface CheckReport {
  ok: boolean;
  timestamp_tehran: string;
  php: {
    version: string;
    version_ok: boolean;
    sapi: string;
    memory_limit: string;
    max_execution_time: string;
    timezone: string;
    argon2id_supported: boolean;
    password_algo_active: string;
  };
  extensions: {
    gd: boolean;
    gd_webp: boolean;
    mbstring: boolean;
    curl: boolean;
    intl: boolean;
    pdo_mysql: boolean;
  };
  directories: Record<string, { path: string; exists: boolean; writable: boolean }>;
  database: {
    connected: boolean;
    version: string;
    time_zone: string;
    error: string | null;
  };
  directadmin_cron: {
    detected_php_cli: string;
    worker_script: string;
    recommended_cron_line: string;
  };
}

export const RESERVED_SLUGS = [
  'admin',
  'api',
  'assets',
  'uploads',
  'cache',
  'city',
  'b',
  'sitemap',
  'robots',
  'install',
  'check',
  'storage',
];

const SLUG_DICTIONARY: Record<string, string> = {
  'رشت': 'rasht',
  'بندر انزلی': 'bandar-anzali',
  'انزلی': 'anzali',
  'لاهیجان': 'lahijan',
  'لنگرود': 'langarud',
  'تالش': 'talesh',
  'آستارا': 'astara',
  'فومن': 'fuman',
  'ماسوله': 'masouleh',
  'ماسال': 'masal',
  'رودبار': 'rudbar',
  'صومعه‌سرا': 'someh-sara',
  'صومعه سرا': 'someh-sara',
  'آستانه اشرفیه': 'astaneh-ashrafiyeh',
  'رودسر': 'rudsar',
  'سیاهکل': 'siahkal',
  'دیلمان': 'deylaman',
  'شفت': 'shaft',
  'خمام': 'khomam',
  'رضوانشهر': 'rezvanshahr',
  'گلسار': 'golsar',
  'منظریه': 'manzarieh',
  'معلم': 'moallem',
  'سبزه میدان': 'sabzeh-meydan',
  'کافه': 'cafe',
  'رستوران': 'restaurant',
  'کبابی': 'kababi',
  'سالن زیبایی': 'beauty-salon',
  'کلینیک': 'clinic',
  'دندانپزشکی': 'dental',
  'ویلا': 'villa',
  'اقامتگاه': 'ecolodge',
  'بومگردی': 'ecolodge',
  'هتل': 'hotel',
  'جاذبه': 'attraction',
  'گردشگری': 'tourism',
};

const CHAR_MAP: Record<string, string> = {
  'آ': 'a', 'ا': 'a', 'أ': 'a', 'إ': 'e',
  'ب': 'b', 'پ': 'p', 'ت': 't', 'ث': 's',
  'ج': 'j', 'چ': 'ch', 'ح': 'h', 'خ': 'kh',
  'د': 'd', 'ذ': 'z', 'ر': 'r', 'ز': 'z',
  'ژ': 'zh', 'س': 's', 'ش': 'sh', 'ص': 's',
  'ض': 'z', 'ط': 't', 'ظ': 'z', 'ع': 'a',
  'غ': 'gh', 'ف': 'f', 'ق': 'gh', 'ک': 'k',
  'ك': 'k', 'گ': 'g', 'ل': 'l', 'م': 'm',
  'ن': 'n', 'و': 'v', 'ه': 'h', 'ة': 'h',
  'ی': 'y', 'ي': 'y', 'ئ': 'y',
  '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
  '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
  '‌': '-',
};

export function suggestLatinSlug(persianText: string, prefix = 'item'): string {
  let clean = persianText.trim().replace(/[ي]/g, 'ی').replace(/[ك]/g, 'ک');
  const sortedKeys = Object.keys(SLUG_DICTIONARY).sort((a, b) => b.length - a.length);
  for (const fa of sortedKeys) {
    clean = clean.split(fa).join(` ${SLUG_DICTIONARY[fa]} `);
  }
  let out = '';
  for (const ch of clean.toLowerCase()) {
    out += CHAR_MAP[ch] !== undefined ? CHAR_MAP[ch] : ch;
  }
  let slug = out
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .replace(/-+/g, '-');
  if (!slug) {
    slug = `${prefix}-${Math.floor(100 + Math.random() * 899)}`;
  }
  if (RESERVED_SLUGS.includes(slug)) {
    slug = `${slug}-gilan`;
  }
  return slug;
}

export function validateAsciiSlug(slug: string): { ok: boolean; error?: string } {
  const clean = slug.trim().toLowerCase();
  if (!clean) {
    return {
      ok: false,
      error: 'اسلاگ نمی‌تواند خالی باشد. روی دکمه «پیشنهاد خودکار» کلیک کنید یا یک عبارت انگلیسی بنویسید.',
    };
  }
  if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(clean)) {
    return {
      ok: false,
      error: 'اسلاگ فقط باید شامل حروف کوچک انگلیسی (a-z)، اعداد (0-9) و خط تیره (-) باشد.',
    };
  }
  if (RESERVED_SLUGS.includes(clean)) {
    return {
      ok: false,
      error: `اسلاگ «${clean}» جزو اسلاگ‌های رزروشده سیستم است. لطفاً آن را به «${clean}-gilan» تغییر دهید.`,
    };
  }
  return { ok: true };
}

export function normalizeIranPhone(raw: string): string {
  const mapDigits: Record<string, string> = {
    '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
    '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
  };
  let digits = raw
    .split('')
    .map((c) => mapDigits[c] ?? c)
    .join('')
    .replace(/[^0-9+]/g, '');

  if (digits.startsWith('0098')) digits = '+98' + digits.slice(4);
  else if (digits.startsWith('09') && digits.length === 11) digits = '+98' + digits.slice(1);
  else if (digits.startsWith('013') && digits.length === 11) digits = '+98' + digits.slice(1);
  else if (digits.length === 8 && !digits.startsWith('0')) digits = '+9813' + digits;
  return digits;
}

export function computeBusinessWarnings(
  biz: Partial<BusinessItem>,
  contacts: BusinessContactItem[]
): string[] {
  const warnings: string[] = [];
  if (!biz.cover_media_id) {
    warnings.push('بدون عکس (تصویر پیش‌فرض دسته نمایش داده می‌شود)');
  }
  const hasPhone = contacts.some(
    (c) => (c.type === 'phone' || c.type === 'mobile') && c.is_primary === 1 && c.value.trim() !== ''
  );
  if (!hasPhone) {
    warnings.push('شماره تماس ثبت یا تأیید نشده');
  }
  if (!biz.lat || !biz.lng) {
    warnings.push('مختصات نقشه (lat/lng) وارد نشده');
  }
  if (!biz.address || biz.address.trim() === '') {
    warnings.push('آدرس دقیق متنی خالی است');
  }
  if (!biz.short_desc || biz.short_desc.trim() === '') {
    warnings.push('توضیح کوتاه خالی است (آماده تکمیل با AI)');
  }
  return warnings;
}

export interface AiTaskRow {
  id: number;
  type: 'business_text' | 'article_draft';
  target_id: number;
  target_title?: string | null;
  input_json: string;
  status: 'queued' | 'claimed' | 'done' | 'failed';
  output_json?: string | null;
  used_fields_json?: string | null;
  model?: string | null;
  attempts: number;
  error?: string | null;
  created_at: string;
  updated_at?: string;
}

export interface AiQueueStats {
  queued: number;
  claimed: number;
  done: number;
  failed: number;
}

export interface TopEngagedBusiness {
  id: number;
  name: string;
  slug: string;
  tier: 'A' | 'B' | 'C';
  internal_score: number;
  city_name: string;
  views_cnt: number;
  calls_cnt: number;
  maps_cnt: number;
  total_interactions: number;
}

export interface SponsoredPlacementItem {
  id: number;
  article_id: number;
  business_id: number;
  position: number;
  sponsor_label: string;
  sponsor_start: string | null;
  sponsor_end: string | null;
  business_name: string;
  business_slug: string;
  article_title: string;
  article_slug: string;
  city_name: string;
}

export interface AnalyticsSummaryState {
  ok: boolean;
  totals_30d: {
    view: number;
    call: number;
    map: number;
    website: number;
    instagram: number;
    whatsapp: number;
  };
  top_businesses: TopEngagedBusiness[];
  sponsored_items: SponsoredPlacementItem[];
  pending_claims: number;
  pending_reports: number;
  sponsor_last_check: string;
}


// Never replace server failures with fabricated local data or local authentication.
async function request(path: string, options: RequestInit = {}): Promise<any> {
  let response: Response;
  try { response = await fetch(path, { credentials: 'same-origin', ...options }); }
  catch { throw new Error('ارتباط با سرور برقرار نشد.'); }
  if (!(response.headers.get('content-type') || '').includes('application/json')) {
    throw new Error(`پاسخ نامعتبر از سرور (HTTP ${response.status}).`);
  }
  const data = await response.json();
  if (!response.ok || !data.ok) throw new Error(data.error || `خطای سرور (HTTP ${response.status}).`);
  return data;
}
async function action(path: string, payload: unknown, csrf: string): Promise<any> {
  try {
    return await request(path, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(payload ?? {}) });
  } catch (e) {
    const message = e instanceof Error ? e.message : 'خطای نامشخص';
    return { ok: false, error: message, message };
  }
}
export async function fetchAuthMe(): Promise<{ok:boolean; authenticated:boolean; user:AdminUser|null; csrf_token:string}> { return request('/api/v1/auth/me'); }
export async function loginAdmin(params: {username:string;password:string;csrfToken:string}): Promise<any> {
  return action('/api/v1/auth/login', { username:params.username, password:params.password }, params.csrfToken);
}
export async function logoutAdmin(csrfToken:string): Promise<any> { return action('/api/v1/auth/logout', {}, csrfToken); }
export async function runWebMigrations(csrfToken:string): Promise<any> { return action('/api/v1/system/migrate', {}, csrfToken); }
export async function fetchCitiesAndNeighborhoods(): Promise<{cities:CityItem[];neighborhoods:NeighborhoodItem[]}> { return request('/api/v1/cities'); }
export async function fetchCategoriesAndPacks(): Promise<{categories:CategoryItem[];packs:CategoryPackItem[]}> { return request('/api/v1/categories'); }
export async function fetchBusinessesList(): Promise<BusinessItem[]> { return (await request('/api/v1/businesses')).businesses; }
export async function fetchBusinessesPage(page:number, cityId:number, query:string): Promise<{businesses:BusinessItem[];total:number;page:number;per_page:number}> {
  return request('/api/v1/businesses?' + new URLSearchParams({page:String(page),city_id:String(cityId),q:query}));
}
export async function setBusinessPromotion(businessId:number, active:boolean, csrf:string): Promise<any> {
  return action('/api/v1/businesses/promotion', {business_id:businessId,active}, csrf);
}
export interface ImportCandidate { id:number; name:string; address:string|null; status:string; distance:number|null; category_id:number; category_name:string|null; same_phone:boolean }
export interface ImportRow { index:number; name:string; source_category:string; source:string; source_id:string; rating:number|null; reviews_count:number|null; status:'new'|'update'|'review'|'filtered'|'invalid'|'conflict'|'stale'; message:string; candidates:ImportCandidate[]; business_id?:number }
export interface ImportOptions { city_id:number; category_id:number; source_categories:string[]; min_rating:number; min_reviews:number }
export interface ImportEntry { index:number; item:unknown; decision:string; candidate_ids:number[] }
export interface ImportResult { index:number; status:'created'|'updated'|'skipped'|'error'; business_id?:number; message:string }
export interface PreparedJson { ok:boolean; items?:unknown[]; stats?:{apify:number;already_standard:number;missing_time:number;missing_identity:number;missing_coordinates:number}; error?:string }
export async function prepareJsonImport(items:unknown[], runStartedAt:string, csrf:string):Promise<PreparedJson> {
  return action('/api/v1/import/json/prepare',{items,run_started_at:runStartedAt},csrf);
}
export interface ApifySchedule { enabled:number|string;mode:'direct'|'legacy';task_id:string;city_id:number|string|null;category_id:number|string|null;max_charge_usd:number|string|null;source_categories:string[];min_rating:number|string;min_reviews:number|string;next_run_at:string|null;last_error:string|null }
export interface ApifyRunSummary { id:number|string;task_id:string;remote_run_id:string|null;dataset_id:string|null;status:string;city_id:number|string|null;category_id:number|string|null;started_at_iso:string|null;finished_at_iso:string|null;item_count:number|string|null;error:string|null;created_at:string;updated_at:string }
export interface ApifyRunData { id:number|string;task_id:string;remote_run_id:string|null;status:string;city_id:number|string|null;category_id:number|string|null;source_categories:string[];min_rating:number|string;min_reviews:number|string;started_at_iso:string|null;finished_at_iso:string|null;item_count:number|string|null;items:unknown[] }
export async function fetchApifyStatus():Promise<{ok:boolean;token_configured:boolean;schedule:ApifySchedule;runs:ApifyRunSummary[];search_terms:Record<string,string>}> { return request('/api/v1/import/apify/status',{cache:'no-store'}); }
export async function fetchApifyRun(id:number):Promise<{ok:boolean;run:ApifyRunData}> { return request(`/api/v1/import/apify/runs/${id}`,{cache:'no-store'}); }
export async function saveApifySchedule(payload:{enabled:boolean;direct?:boolean;confirmed?:boolean;expected_search_term?:string;task_id?:string;city_id?:number;category_id?:number;max_charge_usd?:number;source_categories?:string[];min_rating?:number;min_reviews?:number},csrf:string):Promise<{ok:boolean;error?:string}> { return action('/api/v1/import/apify/schedule',payload,csrf); }
export async function startApifyCollection(payload:{city_id:number;category_id:number;max_places:number;max_charge_usd:number;confirmed:boolean;expected_search_term:string},csrf:string):Promise<{ok:boolean;status?:string;run_id?:number;error?:string}> { return action('/api/v1/import/apify/start',payload,csrf); }
export async function importLatestApify(csrf:string):Promise<{ok:boolean;id?:number;error?:string}> { return action('/api/v1/import/apify/latest',{},csrf); }
export async function importApifyRun(run_id:string,csrf:string):Promise<{ok:boolean;id?:number;error?:string}> { return action('/api/v1/import/apify/run',{run_id},csrf); }
export async function refreshApifyRun(id:number,csrf:string):Promise<{ok:boolean;error?:string}> { return action('/api/v1/import/apify/refresh',{id},csrf); }
export async function deleteApifyRun(id:number,csrf:string):Promise<{ok:boolean;error?:string}> { return action('/api/v1/import/apify/delete',{id},csrf); }
export async function resolveApifyRun(id:number,csrf:string):Promise<{ok:boolean;error?:string}> { return action('/api/v1/import/apify/resolve',{id,confirmed:true},csrf); }
export async function previewJsonImport(items:unknown[], options:ImportOptions, csrf:string): Promise<{ok:boolean;rows?:ImportRow[];error?:string}> {
  return action('/api/v1/import/json/preview',{items,options},csrf);
}
export async function applyJsonImport(entries:ImportEntry[], options:ImportOptions, csrf:string): Promise<{ok:boolean;results?:ImportResult[];error?:string}> {
  return action('/api/v1/import/json/apply',{entries,options},csrf);
}
export async function saveCityItem(payload:Partial<CityItem>, csrf:string): Promise<any> { return action('/api/v1/cities',payload,csrf); }
export async function saveCategoryItem(payload:Partial<CategoryItem>, csrf:string): Promise<any> { return action('/api/v1/categories',payload,csrf); }
export async function saveCategoryPackItem(payload:Partial<CategoryPackItem>, csrf:string): Promise<any> { return action('/api/v1/category-packs',payload,csrf); }
export async function saveBusinessItem(payload:any, csrf:string): Promise<any> { return action('/api/v1/businesses',payload,csrf); }
export async function uploadMediaWebp(file:File, altText:string, csrf:string): Promise<any> {
  try {
    const body = new FormData(); body.append('image',file); body.append('alt_text',altText);
    const data = await request('/api/v1/media/upload',{method:'POST',headers:{'X-CSRF-Token':csrf},body});
    return {...data,previewUrl:data.media?.file_path_480};
  } catch(e) { return {ok:false,error:e instanceof Error ? e.message : 'خطای آپلود'}; }
}
export async function fetchCollectorQueueState(): Promise<{jobs:CollectorJobItem[];providers:ProviderStateItem[];collector_bearer_token:string;photo_policy:string}> { return request('/api/v1/collector/jobs'); }
export async function createCollectorJob(payload:{city_id:number;pack_id:number;provider:string;custom_query?:string},csrf:string): Promise<any> { return action('/api/v1/collector/jobs',payload,csrf); }
export async function updateCollectorProvider(payload:{provider:string;enabled:number;priority:number;clear_pause?:boolean},csrf:string): Promise<any> { return action('/api/v1/collector/providers',payload,csrf); }
export async function triggerCollectorJobRun(csrf:string): Promise<{ok:boolean;logLines:string[]}> {
  const result=await action('/api/v1/collector/run-next',{},csrf);
  return {ok:result.ok,logLines:[result.message || result.error || 'پاسخ نامعتبر', ...(result.summary ? [JSON.stringify(result.summary)] : [])]};
}
export async function fetchReviewQueueState(status='pending',kind='all'): Promise<{items:ReviewQueueItem[];change_logs:ChangeLogItem[];pending_counts:Record<string,number>}> {
  return request('/api/v1/review-queue?' + new URLSearchParams({status,kind}));
}
export async function decideReviewQueueItems(ids:number[],decision:'approved'|'rejected',csrf:string): Promise<any> { return action('/api/v1/review-queue/decide',{ids,decision},csrf); }
export async function fetchArticlesList(): Promise<ArticleItem[]> { return (await request('/api/v1/articles')).articles; }
export async function saveArticleItem(payload:Partial<ArticleItem>,csrf:string): Promise<any> { return action('/api/v1/articles',payload,csrf); }
export async function autoBuildCityCategoryArticle(cityId:number,categoryId:number,csrf:string): Promise<any> { return action('/api/v1/articles/auto-build',{city_id:cityId,category_id:categoryId},csrf); }
export async function flushPublicHtmlCache(csrf:string): Promise<any> { return action('/api/v1/cache/flush',{},csrf); }
export async function fetchAnalyticsSummary(): Promise<AnalyticsSummaryState> { return request('/api/v1/analytics/summary'); }
export async function runCronMaintenanceS6(csrf:string): Promise<any> { return action('/api/v1/system/cron-run',{},csrf); }
