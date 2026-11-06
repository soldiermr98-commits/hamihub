import React from 'react';

/** AI is deliberately unavailable until a real service and review workflow are configured. */
export const AiWorkerPanel: React.FC<{csrfToken:string; onRefreshParent?:()=>Promise<void>}> = () => (
  <section className="rounded-xl bg-white border border-slate-200 p-6">
    <h2 className="font-bold text-slate-900">هوش مصنوعی</h2>
    <p className="text-sm text-slate-600 mt-2 leading-7">در این فاز غیرفعال است. اتصال یک سرویس واقعی و بازبینی انسانی پیش از انتشار، در مرحله بعد انجام می‌شود؛ متن نمایشی تولید نمی‌شود.</p>
  </section>
);
