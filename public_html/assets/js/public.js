/**
 * HamiHubX.ir - اسکریپت پایه فوق‌سبک سایت عمومی (< 2.5KB)
 * ۱. بارگذاری تنبل (Lazy-Load) نقشه Leaflet سلف‌هاست فقط در صورت کلیک کاربر روی دکمه نمایش نقشه
 * ۲. ثبت کلیک‌های تماس، نقشه، اینستاگرام، واتساپ و وب‌سایت با navigator.sendBeacon در جدول events_daily
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
    link.href = '/assets/vendor/leaflet/leaflet.css?v=s4';
    document.head.appendChild(link);

    var script = document.createElement('script');
    script.src = '/assets/vendor/leaflet/leaflet.js?v=s4';
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
})();
