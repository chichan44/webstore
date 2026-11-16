/* Ketentuan Garansi — drill-down produk -> varian -> S&K/garansi.
   Alur (mengikuti halaman referensi koalastore):
   1. daftar produk (cari + chip kategori + pagination)
   2. pilih varian (langsung lompat bila varian cuma satu)
   3. dua kartu: Syarat & Ketentuan + Ketentuan Garansi (list bernomor) */
(function () {
  'use strict';

  var data = { products: [], categories: [] };
  var i18n = {};
  try { data = JSON.parse(document.getElementById('wtData').textContent || '{}') || data; } catch (e) {}
  try { i18n = JSON.parse(document.getElementById('wtI18n').textContent || '{}') || {}; } catch (e) {}

  function t(key, fallback) { return i18n[key] || fallback || key; }

  var PER_PAGE = 12;
  var state = { search: '', categoryId: null, page: 1, product: null, variant: null };

  var elSearch = document.getElementById('wtSearch');
  var elChips = document.getElementById('wtChips');
  var elView = document.getElementById('wtView');
  var elPager = document.getElementById('wtPager');

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* Parser teks mini-markdown (sama seperti referensi):
     - satu baris = satu poin; nomor "1." / "1)" dihapus
     - baris diawali ">" digabung ke poin sebelumnya
     - **teks** menjadi tebal */
  function parseTerms(text) {
    if (!text) return [];
    var lines = String(text).split('\n').map(function (l) { return l.trim(); }).filter(function (l) { return l.length > 0; });
    var out = [];
    for (var i = 0; i < lines.length; i++) {
      var l = lines[i];
      if (l.charAt(0) === '>') {
        var cont = l.slice(1).trim();
        if (out.length > 0 && cont) out[out.length - 1] += ' ' + cont;
        else if (cont) out.push(cont);
      } else {
        var item = l.replace(/^\d+[.)\s]+\s*/, '');
        if (item) out.push(item);
      }
    }
    return out;
  }

  function termsHtml(item) {
    return esc(item).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  }

  function iconSvg(name) {
    var paths = {
      back: '<path d="m15 18-6-6 6-6"/>',
      shield: '<path d="M12 3 20 6v5c0 5-3.4 8.4-8 10-4.6-1.6-8-5-8-10V6z"/><path d="m9 12 2 2 4-4"/>',
      doc: '<path d="M6 2.8h8l4 4V21H6z"/><path d="M14 2.8V7h4"/><path d="M9 12h6M9 16h6"/>',
      chevronLeft: '<path d="m15 18-6-6 6-6"/>',
      chevronRight: '<path d="m9 18 6-6-6-6"/>'
    };
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (paths[name] || '') + '</svg>';
  }

  function filteredProducts() {
    var q = state.search.trim().toLowerCase();
    return (data.products || []).filter(function (p) {
      if (state.categoryId != null && String(p.categoryId) !== String(state.categoryId)) return false;
      if (q && String(p.name).toLowerCase().indexOf(q) === -1) return false;
      return true;
    });
  }

  function renderChips() {
    var cats = data.categories || [];
    var html = '<button type="button" class="wt-chip' + (state.categoryId == null ? ' active' : '') + '" data-wt-cat="">'
      + esc(t('all', 'Semua')) + '</button>';
    for (var i = 0; i < cats.length; i++) {
      var c = cats[i];
      html += '<button type="button" class="wt-chip' + (String(state.categoryId) === String(c.id) ? ' active' : '') + '" data-wt-cat="' + esc(c.id) + '">'
        + (c.icon ? esc(c.icon) + ' ' : '') + esc(c.name)
        + (c.count ? ' <span class="wt-chip-count">(' + esc(c.count) + ')</span>' : '')
        + '</button>';
    }
    elChips.innerHTML = html;
  }

  function renderProductList() {
    var items = filteredProducts();
    var lastPage = Math.max(1, Math.ceil(items.length / PER_PAGE));
    if (state.page > lastPage) state.page = lastPage;
    var start = (state.page - 1) * PER_PAGE;
    var slice = items.slice(start, start + PER_PAGE);

    if (!slice.length) {
      elView.innerHTML = '<div class="wt-empty"><p>' + esc(t('noMatch', 'Tidak ada produk cocok.')) + '</p>'
        + '<button type="button" class="wt-clear" data-wt-clear>' + esc(t('clearFilter', 'Bersihkan filter')) + '</button></div>';
      renderPager(1, 1);
      return;
    }

    var html = '<div class="wt-grid">';
    for (var i = 0; i < slice.length; i++) {
      var p = slice[i];
      var thumb = p.image
        ? '<img src="' + esc(p.image) + '" alt="' + esc(p.name) + '" loading="lazy">'
        : '<span class="wt-thumb-letter">' + esc(String(p.name || '?').charAt(0)) + '</span>';
      var countLabel = String(t('variantCount', '{n} varian')).replace('{n}', (p.variants || []).length);
      html += '<button type="button" class="wt-card" data-wt-product="' + esc(p.id) + '">'
        + '<div class="wt-thumb"' + (p.image ? '' : ' style="background:var(--indigo)"') + '>' + thumb + '</div>'
        + '<p class="wt-name">' + esc(p.name) + '</p>'
        + '<p class="wt-meta">' + esc(countLabel) + '</p>'
        + '</button>';
    }
    html += '</div>';
    elView.innerHTML = html;
    renderPager(state.page, lastPage);
  }

  function renderPager(page, lastPage) {
    if (lastPage <= 1) { elPager.hidden = true; elPager.innerHTML = ''; return; }
    elPager.hidden = false;
    var start = Math.max(1, page - 2);
    var end = Math.min(lastPage, start + 4);
    var html = '<button type="button" class="wt-page-btn" data-wt-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '')
      + ' aria-label="' + esc(t('prevPage', 'Halaman sebelumnya')) + '">' + iconSvg('chevronLeft') + '</button>';
    for (var i = start; i <= end; i++) {
      html += '<button type="button" class="wt-page-btn' + (i === page ? ' active' : '') + '" data-wt-page="' + i + '"'
        + (i === page ? ' aria-current="page"' : '') + '>' + i + '</button>';
    }
    html += '<button type="button" class="wt-page-btn" data-wt-page="' + (page + 1) + '"' + (page >= lastPage ? ' disabled' : '')
      + ' aria-label="' + esc(t('nextPage', 'Halaman berikutnya')) + '">' + iconSvg('chevronRight') + '</button>';
    elPager.innerHTML = html;
  }

  function renderVariantList() {
    var p = state.product;
    var html = '<button type="button" class="wt-back" data-wt-back>' + iconSvg('back') + esc(t('back', 'Kembali')) + '</button>'
      + '<h2 class="wt-h2">' + esc(p.name) + '</h2>'
      + '<div class="wt-variant-grid">';
    for (var i = 0; i < p.variants.length; i++) {
      var v = p.variants[i];
      var badge = v.warrantyDays > 0
        ? '<span class="wt-v-warranty">' + iconSvg('shield')
          + esc(String(t('warrantyBadge', 'Garansi {label}')).replace('{label}', v.warrantyLabel))
          + '</span>'
        : '';
      html += '<button type="button" class="wt-variant" data-wt-variant="' + esc(v.id) + '">'
        + '<p class="wt-v-name">' + esc(v.name) + '</p>' + badge + '</button>';
    }
    html += '</div>';
    elView.innerHTML = html;
  }

  function renderTerms() {
    var p = state.product, v = state.variant;
    var snc = parseTerms(v.termsAndConditions);
    var garansi = parseTerms(v.warrantyTerms);
    var html = '<button type="button" class="wt-back" data-wt-back>' + iconSvg('back') + esc(t('back', 'Kembali')) + '</button>'
      + '<div class="wt-h2-row">' + iconSvg('doc')
      + '<h2 class="wt-h2">' + esc(p.name) + ' <span class="wt-h2-sub">— ' + esc(v.name) + '</span></h2></div>';

    if (!snc.length && !garansi.length) {
      elView.innerHTML = html + '<p class="wt-note">' + esc(t('emptyTerms', 'Belum ada S&K/garansi khusus untuk varian ini.')) + '</p>';
      return;
    }

    html += '<div class="wt-terms-grid">';
    if (snc.length) {
      html += '<div class="wt-terms-card"><h3>' + esc(t('termsTitle', 'Syarat & Ketentuan')) + '</h3><ol class="wt-terms-list">';
      for (var i = 0; i < snc.length; i++) {
        html += '<li><span class="wt-num">' + (i + 1) + '</span><span>' + termsHtml(snc[i]) + '</span></li>';
      }
      html += '</ol></div>';
    }
    if (garansi.length) {
      html += '<div class="wt-terms-card is-warranty"><h3>' + esc(t('warrantyTitle', 'Ketentuan Garansi')) + '</h3><ol class="wt-terms-list">';
      for (var j = 0; j < garansi.length; j++) {
        html += '<li><span class="wt-num">' + (j + 1) + '</span><span>' + termsHtml(garansi[j]) + '</span></li>';
      }
      html += '</ol></div>';
    }
    html += '</div>';
    elView.innerHTML = html;
  }

  function render() {
    if (state.product && state.variant) renderTerms();
    else if (state.product) renderVariantList();
    else renderProductList();
    renderChips();
    elSearch.value = state.search;
    var panel = document.getElementById('wtPanel');
    if (panel && (state.product)) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  /* Kembali: dari terms -> daftar varian (bila >1 varian), selain itu -> daftar produk */
  function goBack() {
    if (state.variant && state.product && state.product.variants.length > 1) {
      state.variant = null;
    } else {
      state.variant = null;
      state.product = null;
    }
    render();
  }

  var searchTimer = null;
  elSearch.addEventListener('input', function () {
    if (searchTimer) clearTimeout(searchTimer);
    var val = elSearch.value;
    searchTimer = setTimeout(function () {
      state.search = val;
      state.page = 1;
      if (state.product) { state.product = null; state.variant = null; }
      render();
    }, 300);
  });

  document.addEventListener('click', function (ev) {
    var el = ev.target.closest ? ev.target.closest('[data-wt-cat],[data-wt-product],[data-wt-variant],[data-wt-back],[data-wt-clear],[data-wt-page]') : null;
    if (!el) return;

    if (el.hasAttribute('data-wt-cat')) {
      var cat = el.getAttribute('data-wt-cat');
      state.categoryId = cat === '' || cat == null ? null : cat;
      state.page = 1;
      render();
    } else if (el.hasAttribute('data-wt-product')) {
      var pid = el.getAttribute('data-wt-product');
      for (var i = 0; i < data.products.length; i++) {
        if (String(data.products[i].id) === String(pid)) {
          state.product = data.products[i];
          state.variant = data.products[i].variants.length === 1 ? data.products[i].variants[0] : null;
          break;
        }
      }
      render();
    } else if (el.hasAttribute('data-wt-variant')) {
      var vid = el.getAttribute('data-wt-variant');
      if (state.product) {
        for (var j = 0; j < state.product.variants.length; j++) {
          if (String(state.product.variants[j].id) === String(vid)) {
            state.variant = state.product.variants[j];
            break;
          }
        }
      }
      render();
    } else if (el.hasAttribute('data-wt-back')) {
      goBack();
    } else if (el.hasAttribute('data-wt-clear')) {
      state.search = '';
      state.categoryId = null;
      state.page = 1;
      render();
    } else if (el.hasAttribute('data-wt-page')) {
      var pg = parseInt(el.getAttribute('data-wt-page'), 10);
      if (!isNaN(pg) && pg >= 1) {
        state.page = pg;
        render();
      }
    }
  });

  render();
})();
