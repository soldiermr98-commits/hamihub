/**
 * HamiHubX.ir - اسکریپت پایه فوق‌سبک سایت عمومی (< 2.5KB)
 * ۱. بارگذاری تنبل (Lazy-Load) نقشه Leaflet سلف‌هاست فقط در صورت کلیک کاربر یا اسکرول
 * ۲. ثبت کلیک‌های تماس، نقشه، اینستاگرام، واتساپ و وب‌سایت با navigator.sendBeacon
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
    link.href = '/assets/vendor/leaflet/leaflet.css';
    document.head.appendChild(link);

    var script = document.createElement('script');
    script.src = '/assets/vendor/leaflet/leaflet.js';
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

  function initMapBox(box) {
    if (!box || box.getAttribute('data-initialized') === '1') return;
    var canvas = box.querySelector('.hhx-map-canvas');
    if (!canvas) return;
    canvas.classList.add('is-active');
    box.setAttribute('data-initialized', '1');

    var rawMarkers = box.getAttribute('data-markers') || '[]';
    var tileUrl = box.getAttribute('data-tile-url') || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
    var markers = [];
    try {
      markers = JSON.parse(rawMarkers);
    } catch (e) {
      markers = [];
    }

    loadLeafletAssets(function () {
      if (window.HHXMap && typeof window.HHXMap.render === 'function') {
        window.HHXMap.render(canvas, markers, tileUrl);
      }
    });
  }

  document.addEventListener('click', function (ev) {
    var target = ev.target;
    if (!target || !target.closest) return;

    var mapBtn = target.closest('.hhx-map-trigger');
    if (mapBtn) {
      ev.preventDefault();
      var box = mapBtn.closest('.hhx-map-box');
      initMapBox(box);
      mapBtn.style.display = 'none';
      return;
    }

    var trackEl = target.closest('[data-track-type]');
    if (trackEl && navigator.sendBeacon) {
      var payload = JSON.stringify({
        type: trackEl.getAttribute('data-track-type'),
        business_id: Number(trackEl.getAttribute('data-business-id') || 0),
        article_id: Number(trackEl.getAttribute('data-article-id') || 0)
      });
      try {
        navigator.sendBeacon('/api/v1/track', payload);
      } catch (e) {}
    }
  });
})();
