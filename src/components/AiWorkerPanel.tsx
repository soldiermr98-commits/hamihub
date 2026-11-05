import React, { useCallback, useEffect, useState } from 'react';
import {
  Cpu,
  Play,
  Plus,
  RefreshCw,
  CheckCircle2,
  AlertTriangle,
  Copy,
  Check,
  ShieldCheck,
} from 'lucide-react';
import {
  AiQueueStats,
  AiTaskRow,
  enqueueAiTasks,
  fetchAiTasksState,
  runAiTasksBatch,
} from '../services/adminApi';

interface AiWorkerPanelProps {
  csrfToken: string;
  onRefreshParent?: () => void;
}

export const AiWorkerPanel: React.FC<AiWorkerPanelProps> = ({ csrfToken, onRefreshParent }) => {
  const [tasks, setTasks] = useState<AiTaskRow[]>([]);
  const [stats, setStats] = useState<AiQueueStats>({
    queued: 0,
    claimed: 0,
    done: 0,
    failed: 0,
  });
  const [bearerToken, setBearerToken] = useState<string>('');
  const [loading, setLoading] = useState<boolean>(true);
  const [runningBatch, setRunningBatch] = useState<boolean>(false);
  const [enqueuing, setEnqueuing] = useState<boolean>(false);
  const [banner, setBanner] = useState<{ type: 'ok' | 'err'; text: string } | null>(null);
  const [copiedCmd, setCopiedCmd] = useState<boolean>(false);
  const [expandedTaskId, setExpandedTaskId] = useState<number | null>(null);

  const loadAiState = useCallback(async () => {
    setLoading(true);
    const res = await fetchAiTasksState();
    if (res.ok) {
      setTasks(Array.isArray(res.tasks) ? res.tasks : []);
      setStats(res.stats || { queued: 0, claimed: 0, done: 0, failed: 0 });
      setBearerToken(res.bearer_token || '');
    }
    setLoading(false);
  }, []);

  useEffect(() => {
    loadAiState();
  }, [loadAiState]);

  const handleEnqueueAll = async () => {
    setEnqueuing(true);
    setBanner(null);
    const res = await enqueueAiTasks({ mode: 'batch_all' }, csrfToken);
    setEnqueuing(false);
    setBanner({
      type: res.ok ? 'ok' : 'err',
      text: res.message || res.error || 'عملیات ثبت تسک انجام شد.',
    });
    await loadAiState();
  };

  const handleRunBatchNow = async () => {
    setRunningBatch(true);
    setBanner(null);
    const res = await runAiTasksBatch(csrfToken, 10);
    setRunningBatch(false);
    setBanner({
      type: res.ok ? 'ok' : 'err',
      text: res.message || res.error || 'پردازش دسته‌ای تسک‌ها انجام شد.',
    });
    await loadAiState();
    if (onRefreshParent) {
      onRefreshParent();
    }
  };

  const copyCliCmd = () => {
    navigator.clipboard.writeText('php ai-worker/run.php');
    setCopiedCmd(true);
    setTimeout(() => setCopiedCmd(false), 1800);
  };

  const parseJsonArray = (raw?: string | null): string[] => {
    if (!raw) return [];
    try {
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed.map(String) : [];
    } catch {
      return [];
    }
  };

  return (
    <section className="rounded-2xl bg-white/85 backdrop-blur-md border border-blue-200/80 shadow-sm p-6 space-y-5">
      {/* هدر گلس‌مورفیسم لاجوردی مرحله S5 */}
      <div className="rounded-xl bg-gradient-to-l from-[#040814] via-[#09132e] to-[#10214d] text-white p-5 border border-sky-400/25 shadow-md">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div className="space-y-1">
            <div className="inline-flex items-center gap-2 text-xs font-semibold text-sky-300">
              <ShieldCheck className="w-4 h-4 text-sky-400" />
              <span>مرحله S5 · موتور تولید محتوای مستند و بدون توهم (Anti-Hallucination AI Worker)</span>
            </div>
            <h2 className="text-lg font-bold text-white flex items-center gap-2">
              <Cpu className="w-5 h-5 text-sky-400" />
              صف پردازش هوش مصنوعی حامی‌هاب گیلان (ai_tasks)
            </h2>
            <p className="text-xs text-slate-300 max-w-3xl leading-relaxed">
              تولید خودکار معرفی کسب‌وکارها (<code className="font-mono text-sky-300">business_text</code>) و پیش‌نویس مقالات لیستی (<code className="font-mono text-sky-300">article_draft</code>) صرفاً بر پایه فیلدهای تاییدشده ورودی، همراه با ثبت فهرست فیلدهای مصرفی در <code className="font-mono text-sky-300">used_fields_json</code> و مسدودسازی هرگونه ادعا یا شماره تماس خارج از دیتابیس.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-2.5">
            <button
              type="button"
              onClick={handleEnqueueAll}
              disabled={enqueuing}
              className="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold text-white bg-white/10 hover:bg-white/20 border border-white/20 backdrop-blur-md rounded-lg transition-colors whitespace-nowrap shrink-0"
            >
              <Plus className="w-3.5 h-3.5" />
              {enqueuing ? 'در حال ثبت...' : 'افزودن خودکار کسب‌وکارها و مقالات به صف'}
            </button>
            <button
              type="button"
              onClick={handleRunBatchNow}
              disabled={runningBatch}
              className="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 border border-sky-400/40 rounded-lg shadow-sm transition-colors whitespace-nowrap shrink-0"
            >
              <Play className="w-3.5 h-3.5" />
              {runningBatch ? 'در حال تولید و بررسی گاردریل...' : 'اجرای فوری صف بدون ترمینال (Batch Run)'}
            </button>
          </div>
        </div>

        {/* نوار آمار شیشه‌ای */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4 pt-4 border-t border-white/10">
          <div className="rounded-lg bg-white/10 backdrop-blur-md border border-white/15 px-3.5 py-2.5">
            <div className="text-[11px] text-slate-300">در انتظار پردازش (queued)</div>
            <div className="text-lg font-bold text-white font-mono tabular-nums mt-0.5">
              {stats.queued}
            </div>
          </div>
          <div className="rounded-lg bg-white/10 backdrop-blur-md border border-white/15 px-3.5 py-2.5">
            <div className="text-[11px] text-slate-300">در حال اجرا (claimed)</div>
            <div className="text-lg font-bold text-sky-300 font-mono tabular-nums mt-0.5">
              {stats.claimed}
            </div>
          </div>
          <div className="rounded-lg bg-white/10 backdrop-blur-md border border-white/15 px-3.5 py-2.5">
            <div className="text-[11px] text-slate-300">تکمیل‌شده بدون توهم (done)</div>
            <div className="text-lg font-bold text-emerald-300 font-mono tabular-nums mt-0.5">
              {stats.done}
            </div>
          </div>
          <div className="rounded-lg bg-white/10 backdrop-blur-md border border-white/15 px-3.5 py-2.5">
            <div className="text-[11px] text-slate-300">متوقف توسط گاردریل (failed)</div>
            <div className="text-lg font-bold text-rose-300 font-mono tabular-nums mt-0.5">
              {stats.failed}
            </div>
          </div>
        </div>
      </div>

      {banner && (
        <div
          className={`p-3.5 rounded-xl border text-xs font-semibold flex items-center justify-between ${
            banner.type === 'ok'
              ? 'bg-emerald-50/90 border-emerald-200 text-emerald-900'
              : 'bg-rose-50/90 border-rose-200 text-rose-900'
          }`}
        >
          <span className="flex items-center gap-2">
            {banner.type === 'ok' ? (
              <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
            ) : (
              <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
            )}
            {banner.text}
          </span>
          <button
            type="button"
            onClick={() => setBanner(null)}
            className="text-xs underline opacity-75 hover:opacity-100"
          >
            بستن
          </button>
        </div>
      )}

      {/* راهنمای اجرای ورکر راه دور یا مستقیم */}
      <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 rounded-xl bg-slate-50/90 border border-slate-200 text-xs text-slate-600">
        <div>
          <span>اسکریپت CLI ورکر راه دور: </span>
          <code className="font-mono text-slate-900 bg-white px-2 py-0.5 rounded border border-slate-200" dir="ltr">
            php ai-worker/run.php
          </code>
          <span className="mr-3 text-slate-400">·</span>
          <span className="mr-3">
            توکن مشترک Bearer:{' '}
            <code className="font-mono text-slate-800" dir="ltr">
              {bearerToken ? `${bearerToken.slice(0, 18)}...` : 'فعال'}
            </code>
          </span>
        </div>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={copyCliCmd}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white border border-slate-200 hover:bg-slate-100 text-slate-700 font-medium"
          >
            {copiedCmd ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5" />}
            {copiedCmd ? 'کپی شد' : 'کپی دستور CLI'}
          </button>
          <button
            type="button"
            onClick={loadAiState}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white border border-slate-200 hover:bg-slate-100 text-slate-700 font-medium"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
            بروزرسانی لیست
          </button>
        </div>
      </div>

      {/* جدول تسک‌ها و ممیزی used_fields_json */}
      <div className="overflow-x-auto border border-slate-200 rounded-xl bg-white">
        <table className="w-full text-right text-xs">
          <thead>
            <tr className="bg-slate-900 text-slate-100 border-b border-slate-800">
              <th className="py-3 px-4 font-semibold">شناسه</th>
              <th className="py-3 px-4 font-semibold">نوع تسک</th>
              <th className="py-3 px-4 font-semibold">هدف (کسب‌وکار / مقاله)</th>
              <th className="py-3 px-4 font-semibold">وضعیت</th>
              <th className="py-3 px-4 font-semibold">فیلدهای مستند استفاده‌شده (used_fields)</th>
              <th className="py-3 px-4 font-semibold">جزئیات خروجی</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {tasks.length === 0 ? (
              <tr>
                <td colSpan={6} className="py-8 text-center text-slate-500">
                  هنوز تسکی در جدول <code className="font-mono">ai_tasks</code> ثبت نشده است. روی دکمه «اجرای فوری صف بدون ترمینال» کلیک کنید.
                </td>
              </tr>
            ) : (
              tasks.map((t) => {
                const usedFields = parseJsonArray(t.used_fields_json);
                const isExpanded = expandedTaskId === t.id;
                return (
                  <React.Fragment key={t.id}>
                    <tr className="hover:bg-blue-50/40 transition-colors">
                      <td className="py-3 px-4 font-mono tabular-nums font-bold text-slate-800">
                        #{t.id}
                      </td>
                      <td className="py-3 px-4">
                        <span className="font-mono text-[11px] text-blue-900">
                          {t.type === 'business_text' ? 'business_text (معرفی کسب‌وکار)' : 'article_draft (پیش‌نویس مقاله)'}
                        </span>
                      </td>
                      <td className="py-3 px-4 font-semibold text-slate-900">
                        {t.target_title || `آیتم #${t.target_id}`}
                      </td>
                      <td className="py-3 px-4">
                        {t.status === 'done' && (
                          <span className="text-emerald-700 font-semibold">تکمیل‌شده (done)</span>
                        )}
                        {t.status === 'queued' && (
                          <span className="text-amber-700 font-semibold">در صف (queued)</span>
                        )}
                        {t.status === 'claimed' && (
                          <span className="text-blue-700 font-semibold">در حال پردازش (claimed)</span>
                        )}
                        {t.status === 'failed' && (
                          <span className="text-rose-700 font-semibold">ردشده توسط گاردریل (failed)</span>
                        )}
                      </td>
                      <td className="py-3 px-4 text-slate-600 font-mono text-[11px]" dir="ltr">
                        {usedFields.length > 0 ? usedFields.join(' · ') : '—'}
                      </td>
                      <td className="py-3 px-4">
                        <button
                          type="button"
                          onClick={() => setExpandedTaskId(isExpanded ? null : t.id)}
                          className="px-2.5 py-1 rounded bg-slate-100 hover:bg-blue-50 text-blue-800 font-semibold transition-colors"
                        >
                          {isExpanded ? 'بستن جزئیات' : 'مشاهده ورودی/خروجی'}
                        </button>
                      </td>
                    </tr>
                    {isExpanded && (
                      <tr className="bg-slate-50/90">
                        <td colSpan={6} className="p-4 space-y-3">
                          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            <div>
                              <div className="text-[11px] font-bold text-slate-700 mb-1">
                                داده‌های ورودی مستند (input_json):
                              </div>
                              <pre
                                dir="ltr"
                                className="p-3 rounded-lg bg-slate-900 text-sky-200 font-mono text-[11px] overflow-x-auto max-h-48"
                              >
                                {t.input_json}
                              </pre>
                            </div>
                            <div>
                              <div className="text-[11px] font-bold text-slate-700 mb-1">
                                خروجی تاییدشده بدون توهم (output_json):
                              </div>
                              <pre
                                dir="ltr"
                                className="p-3 rounded-lg bg-slate-900 text-emerald-200 font-mono text-[11px] overflow-x-auto max-h-48"
                              >
                                {t.output_json || t.error || 'در انتظار اجرا...'}
                              </pre>
                            </div>
                          </div>
                        </td>
                      </tr>
                    )}
                  </React.Fragment>
                );
              })
            )}
          </tbody>
        </table>
      </div>
    </section>
  );
};
