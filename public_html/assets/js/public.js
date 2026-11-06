/**
 * HamiHubX.ir - اسکریپت پایه فوق‌سبک سایت عمومی (< 3.5KB)
 * ۱. بارگذاری تنبل (Lazy-Load) نقشه Leaflet سلف‌هاست فقط در صورت کلیک کاربر روی دکمه نمایش نقشه
 * ۲. ثبت کلیک‌های تماس، نقشه، اینستاگرام، واتساپ و وب‌سایت با navigator.sendBeacon در جدول events_daily
 * ۳. ارسال بدون رفرش فرم‌های ادعای مالکیت (Claim) و گزارش خطا (Report) در مرحله S6 به صندوق بازبینی
 */
(function () {
  'use strict';

  var leafletLoaded = false;
  var leafletLoading = false;
  var pendingCallbacks = [];

  function loadLeafletAssets(done) {
    if (leafletLoaded && window.HHXMap) {
      done();
      return;
    }
    pendingCallbacks.push(done);
    if (leafletLoading) return;
    leafletLoading = true;

    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = '/assets/vendor/leaflet/leaflet.css?v=s6';
    document.head.appendChild(link);

    var script = document.createElement('script');
    script.src = '/assets/vendor/leaflet/leaflet.js?v=s6';
    script.async = true;
    script.onload = function () {
      leafletLoaded = true;
      leafletLoading = false;
      while (pendingCallbacks.length > 0) {
        var cb = pendingCallbacks.shift();
        if (typeof cb === 'function') cb();
      }
    };
    document.body.appendChild(script);
  }

  function initMapPlaceholder(holder) {
    if (!holder || holder.getAttribute('data-initialized') === '1') return;
    holder.setAttribute('data-initialized', '1');

    var rawMarkers = holder.getAttribute('data-markers') || '[]';
    var tileUrl =
      holder.getAttribute('data-tile-url') ||
      'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
    var markers = [];
    try {
      markers = JSON.parse(rawMarkers);
    } catch (e) {
      markers = [];
    }

    var canvas = document.createElement('div');
    canvas.className = 'hx-map-canvas';
    holder.appendChild(canvas);

    loadLeafletAssets(function () {
      if (window.HHXMap && typeof window.HHXMap.render === 'function') {
        window.HHXMap.render(canvas, markers, tileUrl);
      }
    });
  }

  document.addEventListener('click', function (ev) {
    var target = ev.target;
    if (!target || !target.closest) return;

    var toggleBtn = target.closest('[data-hx-toggle-form]');
    if (toggleBtn) {
      ev.preventDefault();
      var formId = toggleBtn.getAttribute('data-hx-toggle-form');
      var formEl = formId ? document.getElementById(formId) : null;
      if (formEl) {
        formEl.style.display = formEl.style.display === 'none' ? 'block' : 'none';
      }
      return;
    }

    var mapBtn = target.closest('[data-hx-load-map], .hhx-map-trigger');
    if (mapBtn) {
      ev.preventDefault();
      var holder = mapBtn.closest('[data-hx-map], .hhx-map-box');
      initMapPlaceholder(holder);
      mapBtn.style.display = 'none';
      return;
    }

    var trackEl = target.closest('[data-hx-track], [data-track-type]');
    if (trackEl && navigator.sendBeacon) {
      var evType =
        trackEl.getAttribute('data-hx-track') ||
        trackEl.getAttribute('data-track-type') ||
        'view';
      var payload = JSON.stringify({
        event_type: evType,
        business_id: Number(trackEl.getAttribute('data-business-id') || 0),
        article_id: Number(trackEl.getAttribute('data-article-id') || 0)
      });
      try {
        navigator.sendBeacon('/api/v1/events/track', payload);
      } catch (e) {}
    }
  });

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form || !form.getAttribute) return;
    var formType = form.getAttribute('data-hx-public-form');
    if (!formType) return;

    ev.preventDefault();
    var msgEl = form.querySelector('[data-hx-form-msg]');
    var endpoint =
      formType === 'claim' ? '/api/v1/public/claim' : '/api/v1/public/report';
    var fd = new FormData(form);
    var body = {};
    fd.forEach(function (val, key) {
      body[key] = val;
    });

    if (msgEl) {
      msgEl.style.color = '#0369a1';
      msgEl.textContent = 'در حال ارسال به صندوق بازبینی...';
    }

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        if (msgEl) {
          msgEl.style.color = res.ok ? '#15803d' : '#b91c1c';
          msgEl.textContent = res.message || res.error || 'ثبت انجام شد.';
        }
        if (res.ok) {
          form.reset();
        }
      })
      .catch(function () {
        if (msgEl) {
          msgEl.style.color = '#b91c1c';
          msgEl.textContent = 'خطا در ارتباط با سرور.';
        }
      });
  });
})();
