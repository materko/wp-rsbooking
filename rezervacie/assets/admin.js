/* Rezervácie – administrácia: kalendár a editor rezervácie. */
(function () {
  'use strict';
  var R = window.RZV || {};

  function h(tag, attrs, children) {
    var el = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'class') el.className = v;
      else if (k === 'text') el.textContent = v;
      else if (k === 'style') el.setAttribute('style', v);
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
      else el.setAttribute(k, v === true ? '' : v);
    });
    (children || []).forEach(function (c) { if (c !== null && c !== undefined && c !== false) el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return el;
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parse(s) { var p = s.split(/[- :T]/); return new Date(+p[0], +p[1] - 1, +p[2], +(p[3] || 0), +(p[4] || 0)); }
  function mins(s) { var p = s.split(':'); return +p[0] * 60 + +p[1]; }
  function hm(m) { return pad(Math.floor(m / 60)) + ':' + pad(m % 60); }
  function money(v) { return Number(v).toFixed(2).replace('.', ',') + ' ' + R.currency; }
  var DAYS = ['Ne', 'Po', 'Ut', 'St', 'Št', 'Pi', 'So'];

  function api(path, opts) {
    opts = opts || {};
    return fetch(R.api + path, {
      method: opts.method || 'GET',
      headers: { 'X-WP-Nonce': R.nonce, 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: opts.body ? JSON.stringify(opts.body) : undefined
    }).then(function (r) {
      return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || 'Chyba'); return j; });
    });
  }

  function svc(id) { return R.services.find(function (s) { return s.id === +id; }); }
  function worker(id) { return R.workers.find(function (w) { return w.id === +id; }); }

  /* ================= Modal ================= */
  function modal(title, body, onClose) {
    var ov = h('div', { class: 'rzv-modal' });
    var close = function () { ov.remove(); document.removeEventListener('keydown', esc); if (onClose) onClose(); };
    var esc = function (e) { if (e.key === 'Escape') close(); };
    document.addEventListener('keydown', esc);
    ov.appendChild(h('div', { class: 'rzv-modal__box' }, [
      h('div', { class: 'rzv-modal__head' }, [h('h2', { text: title }), h('button', { type: 'button', class: 'rzv-modal__x', text: '×', onclick: close })]),
      body
    ]));
    ov.addEventListener('mousedown', function (e) { if (e.target === ov) close(); });
    document.body.appendChild(ov);
    return { el: ov, close: close };
  }

  /* ================= Editor rezervácie ================= */
  function editor(opts) {
    opts = opts || {};
    var b = opts.booking || null;
    var form = h('form', { class: 'rzv-editor' });
    var m = modal(b ? 'Rezervácia #' + b.id : 'Nová rezervácia', form);

    var st = {
      service: b ? b.service_id : (opts.service || (R.services.filter(function (s) { return s.active; })[0] || {}).id),
      worker: b ? b.worker_id : (opts.worker || 0),
      date: b ? b.start_at.slice(0, 10) : (opts.date || ymd(new Date())),
      time: b ? b.start_at.slice(11, 16) : (opts.time || ''),
      addons: b ? b.addons_list.map(function (a) { return a.id; }) : []
    };

    // zákazník
    var custBox = h('div', { class: 'rzv-ed-cust' });
    var customerId = b ? b.customer_id : 0;
    function renderCustomer() {
      custBox.innerHTML = '';
      if (b) {
        custBox.appendChild(h('div', {}, [
          h('strong', { text: b.customer_name }), ' · ', b.phone || '', ' · ', b.email || '', ' ',
          h('a', { href: 'admin.php?page=rzv-customers&edit=' + b.customer_id, target: '_blank', text: 'karta zákazníka' })
        ]));
        return;
      }
      var search = h('input', { type: 'search', placeholder: 'Hľadať existujúceho zákazníka (meno, telefón, e-mail)…', class: 'widefat' });
      var results = h('div', { class: 'rzv-ed-results' });
      var fields = h('div', { class: 'rzv-ed-grid' }, [
        h('label', {}, ['Meno *', h('input', { name: 'first_name', required: true })]),
        h('label', {}, ['Priezvisko', h('input', { name: 'last_name' })]),
        h('label', {}, ['Telefón', h('input', { name: 'phone', type: 'tel' })]),
        h('label', {}, ['E-mail', h('input', { name: 'email', type: 'email' })])
      ]);
      var t;
      search.addEventListener('input', function () {
        clearTimeout(t);
        var q = search.value.trim();
        if (q.length < 2) { results.innerHTML = ''; return; }
        t = setTimeout(function () {
          api('/admin/customers?q=' + encodeURIComponent(q)).then(function (list) {
            results.innerHTML = '';
            list.forEach(function (c) {
              results.appendChild(h('button', { type: 'button', class: 'button', text: c.first_name + ' ' + c.last_name + ' · ' + (c.phone || c.email), onclick: function () {
                customerId = +c.id;
                fields.querySelector('[name=first_name]').value = c.first_name;
                fields.querySelector('[name=last_name]').value = c.last_name;
                fields.querySelector('[name=phone]').value = c.phone;
                fields.querySelector('[name=email]').value = c.email;
                Array.prototype.forEach.call(fields.querySelectorAll('input'), function (i) { i.disabled = true; });
                results.innerHTML = '';
                results.appendChild(h('span', {}, ['Vybraný existujúci zákazník. ', h('a', { href: '#', text: 'zrušiť výber', onclick: function (e) {
                  e.preventDefault(); customerId = 0; results.innerHTML = '';
                  Array.prototype.forEach.call(fields.querySelectorAll('input'), function (i) { i.disabled = false; i.value = ''; });
                } })]));
              } }));
            });
          });
        }, 250);
      });
      custBox.appendChild(search);
      custBox.appendChild(results);
      custBox.appendChild(fields);
    }
    renderCustomer();

    var serviceSel = h('select', { name: 'service_id' }, R.services.filter(function (s) { return s.active || (b && s.id === b.service_id); }).map(function (s) {
      return h('option', { value: s.id, text: s.name + ' (' + s.duration + ' min, ' + money(s.price) + ')', selected: s.id === +st.service });
    }));
    var workerSel = h('select', { name: 'worker_id' });
    var addonsBox = h('div', { class: 'rzv-ed-addons' });
    var dateIn = h('input', { type: 'date', name: 'date', value: st.date, required: true });
    var timeIn = h('input', { type: 'time', name: 'time', value: st.time, required: true, step: 300, list: 'rzv-times-dl' });
    var dl = h('datalist', { id: 'rzv-times-dl' });
    var timesBox = h('div', { class: 'rzv-ed-times' });
    var priceIn = h('input', { type: 'number', step: '0.01', name: 'price', value: b ? b.price : '', placeholder: 'podľa cenníka' });
    var statusSel = h('select', { name: 'status' }, Object.keys(R.statuses).map(function (k) {
      return h('option', { value: k, text: R.statuses[k], selected: b ? b.status === k : k === 'confirmed' });
    }));
    var note = h('textarea', { name: 'note', rows: 2 }); note.value = b ? b.note || '' : '';
    var inote = h('textarea', { name: 'internal_note', rows: 2 }); inote.value = b ? b.internal_note || '' : '';
    var notify = h('input', { type: 'checkbox', name: 'notify', checked: !b });
    var force = h('input', { type: 'checkbox', name: 'force' });
    var msg = h('div', { class: 'rzv-ed-msg' });

    function renderWorkers() {
      var s = svc(serviceSel.value) || { workers: [] };
      var list = R.workers.filter(function (w) { return s.workers.indexOf(w.id) >= 0 || w.id === +st.worker; });
      workerSel.innerHTML = '';
      if (s.kind === 'rental') { workerSel.appendChild(h('option', { value: 0, text: '— prenájom bez pracovníka —' })); return; }
      if (!b) workerSel.appendChild(h('option', { value: 0, text: 'Ktokoľvek voľný' }));
      list.forEach(function (w) { workerSel.appendChild(h('option', { value: w.id, text: w.name, selected: w.id === +st.worker })); });
    }
    function renderAddons() {
      var s = svc(serviceSel.value) || { addons: [] };
      addonsBox.innerHTML = '';
      R.addons.filter(function (a) { return s.addons.indexOf(a.id) >= 0; }).forEach(function (a) {
        addonsBox.appendChild(h('label', {}, [h('input', { type: 'checkbox', value: a.id, checked: st.addons.indexOf(a.id) >= 0, onchange: function (e) {
          st.addons = e.target.checked ? st.addons.concat([a.id]) : st.addons.filter(function (x) { return x !== a.id; });
          loadTimes();
        } }), ' ' + a.name + ' (+' + a.duration + ' min, ' + money(a.price) + ')']));
      });
    }
    function loadTimes() {
      timesBox.textContent = 'Načítavam voľné časy…';
      api('/admin/times?service=' + serviceSel.value + '&worker=' + workerSel.value + '&date=' + dateIn.value + '&addons=' + st.addons.join(',') + '&exclude=' + (b ? b.id : 0)).then(function (r) {
        timesBox.innerHTML = ''; dl.innerHTML = '';
        var keys = Object.keys(r.times);
        if (!keys.length) { timesBox.textContent = 'V pracovnom čase nie je voľný termín (čas môžete zadať ručne).'; return; }
        keys.forEach(function (t) {
          dl.appendChild(h('option', { value: t }));
          timesBox.appendChild(h('button', { type: 'button', class: 'button button-small' + (timeIn.value === t ? ' button-primary' : ''), text: t, onclick: function () {
            timeIn.value = t; loadTimesMark();
          } }));
        });
      }).catch(function (e) { timesBox.textContent = e.message; });
    }
    function loadTimesMark() {
      Array.prototype.forEach.call(timesBox.querySelectorAll('button'), function (x) { x.classList.toggle('button-primary', x.textContent === timeIn.value); });
    }
    serviceSel.addEventListener('change', function () { st.addons = []; renderWorkers(); renderAddons(); loadTimes(); });
    workerSel.addEventListener('change', loadTimes);
    dateIn.addEventListener('change', loadTimes);
    timeIn.addEventListener('input', loadTimesMark);

    renderWorkers(); renderAddons(); loadTimes();

    var row = function (label, el) { return h('div', { class: 'rzv-ed-row' }, [h('label', { class: 'rzv-ed-label', text: label }), el]); };
    form.appendChild(row('Zákazník', custBox));
    form.appendChild(row('Služba', serviceSel));
    form.appendChild(row('Doplnky', addonsBox));
    form.appendChild(row('Pracovník', workerSel));
    form.appendChild(row('Dátum a čas', h('div', {}, [dateIn, ' ', timeIn, dl, timesBox])));
    form.appendChild(row('Cena', h('div', {}, [priceIn, ' ' + R.currency + (b ? ' (cenník, pred zľavou)' : '')])));
    var codeIn = h('input', { type: 'text', name: 'code', placeholder: 'zľavový kód alebo kód poukážky', class: 'regular-text' });
    if (!b) form.appendChild(row('Kód / poukážka', codeIn));
    if (b) form.appendChild(row('Platby', paymentsEl(b, function () { m.close(); openBooking(b.id, opts.saved); if (opts.saved) opts.saved(); })));
    form.appendChild(row('Stav', statusSel));
    form.appendChild(row('Poznámka zákazníka', note));
    form.appendChild(row('Interná poznámka', inote));
    if (b && b.answers_list && b.answers_list.length) {
      form.appendChild(row('Odpovede', h('div', {}, b.answers_list.map(function (a) { return h('div', {}, [h('strong', { text: a.label + ': ' }), a.value]); }))));
    }
    if (b) {
      form.appendChild(row('Info', h('div', { class: 'rzv-muted', text: 'Vytvorená ' + b.created_at + ' (' + (b.source === 'web' ? 'online' : 'admin') + ')' + (b.updated_at !== b.created_at ? ', upravená ' + b.updated_at : '') })));
    }
    form.appendChild(h('div', { class: 'rzv-ed-checks' }, [
      h('label', {}, [notify, ' Upozorniť zákazníka e-mailom']),
      h('label', {}, [force, ' Povoliť prekrytie s inou rezerváciou / blokáciou'])
    ]));
    if (opts.extra) opts.extra(form, b);
    form.appendChild(msg);
    var actions = h('div', { class: 'rzv-ed-actions' }, [h('button', { type: 'submit', class: 'button button-primary button-large', text: b ? 'Uložiť zmeny' : 'Vytvoriť rezerváciu' })]);
    if (b && ['pending', 'confirmed'].indexOf(b.status) >= 0) {
      actions.appendChild(h('button', { type: 'button', class: 'button button-link-delete', text: 'Zrušiť rezerváciu', onclick: function () {
        if (!confirm('Zrušiť rezerváciu? Zákazník dostane e-mail, ak je zaškrtnuté upozornenie.')) return;
        api('/admin/bookings/' + b.id, { method: 'POST', body: { status: 'cancelled', notify: notify.checked } }).then(function () { m.close(); if (opts.saved) opts.saved(); });
      } }));
    }
    form.appendChild(actions);

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var d = {
        service_id: +serviceSel.value, worker_id: +workerSel.value, date: dateIn.value, time: timeIn.value,
        addons: st.addons, price: priceIn.value, status: statusSel.value, note: note.value, internal_note: inote.value,
        notify: notify.checked, force: force.checked
      };
      if (!b && codeIn.value.trim()) d.codes = [codeIn.value.trim()];
      if (!b) {
        if (customerId) d.customer_id = customerId;
        ['first_name', 'last_name', 'phone', 'email'].forEach(function (k) { var i = custBox.querySelector('[name=' + k + ']'); if (i) d[k] = i.value; });
      }
      msg.textContent = 'Ukladám…'; msg.className = 'rzv-ed-msg';
      api(b ? '/admin/bookings/' + b.id : '/admin/bookings', { method: 'POST', body: d }).then(function () {
        m.close(); if (opts.saved) opts.saved();
      }).catch(function (err) { msg.textContent = err.message; msg.className = 'rzv-ed-msg is-error'; });
    });
  }

  function paymentsEl(b, reload) {
    var box = h('div', { class: 'rzv-ed-pay' });
    var lines = [['Cena', money(b.price)]];
    if (+b.discount > 0) lines.push(['Zľava ' + (b.coupon_code || ''), '−' + money(b.discount)]);
    if (+b.voucher_amount > 0) lines.push(['Poukážka ' + (b.voucher_code || ''), '−' + money(b.voucher_amount)]);
    lines.push(['Spolu', money(b.total)], ['Zaplatené', money(b.paid)], ['Zostáva', money(b.due)]);
    box.appendChild(h('table', { class: 'rzv-ed-paytable' }, lines.map(function (l, i) {
      return h('tr', { class: i === lines.length - 1 ? 'is-due' : '' }, [h('td', { text: l[0] }), h('td', { text: l[1] })]);
    })));
    (b.payments || []).forEach(function (p) {
      if (p.status === 'cancelled') return;
      var it = h('div', { class: 'rzv-ed-payrow' }, [
        h('span', { text: p.created_at.slice(0, 16) + ' · ' + money(p.amount) + ' · ' + p.method_label + ' · ' }),
        h('span', { class: 'rzv-badge rzv-badge--' + (p.status === 'paid' ? 'confirmed' : 'pending'), text: p.status_label }),
        p.reference ? h('small', { text: ' VS ' + p.reference }) : null
      ]);
      if (p.status === 'pending') {
        it.appendChild(h('button', { type: 'button', class: 'button button-small', text: 'Prijatá', onclick: function () {
          api('/admin/payments/' + p.id + '/paid', { method: 'POST', body: { method: 'transfer' } }).then(reload).catch(function (e) { alert(e.message); });
        } }));
      }
      box.appendChild(it);
    });
    if (+b.due > 0 && ['cancelled'].indexOf(b.status) < 0) {
      var amt = h('input', { type: 'number', step: '0.01', min: '0.01', value: (+b.due).toFixed(2), class: 'small-text' });
      var meth = h('select', {}, Object.keys(R.methods).map(function (k) { return h('option', { value: k, text: R.methods[k] }); }));
      box.appendChild(h('div', { class: 'rzv-ed-payadd' }, [amt, ' ' + R.currency + ' ', meth, ' ',
        h('button', { type: 'button', class: 'button', text: 'Zapísať platbu', onclick: function () {
          api('/admin/payments', { method: 'POST', body: { booking_id: b.id, amount: amt.value, method: meth.value } }).then(reload).catch(function (e) { alert(e.message); });
        } })]));
    }
    return box;
  }

  function openBooking(id, saved) {
    api('/admin/bookings/' + id).then(function (b) {
      b.service_id = +b.service_id; b.worker_id = +b.worker_id; b.customer_id = +b.customer_id; b.id = +b.id;
      editor({ booking: b, saved: saved });
    }).catch(function (e) { alert(e.message); });
  }

  /* ================= Kalendár ================= */
  function Calendar(root) {
    this.root = root;
    var saved = {};
    try { saved = JSON.parse(localStorage.getItem('rzvCal') || '{}'); } catch (e) { /* noop */ }
    this.view = saved.view || 'week';
    this.worker = saved.worker || 0;
    this.cancelled = false;
    this.date = new Date(); this.date.setHours(0, 0, 0, 0);
    var self = this;
    // rozsah hodín z pracovného času
    var minH = 24, maxH = 0;
    Object.keys(R.hours || {}).forEach(function (k) {
      var d = R.hours[k];
      if (!d.open) return;
      d.intervals.forEach(function (iv) { minH = Math.min(minH, Math.floor(mins(iv[0]) / 60)); maxH = Math.max(maxH, Math.ceil(mins(iv[1]) / 60)); });
    });
    this.startM = (minH < 24 ? Math.max(0, minH - 1) : 7) * 60;
    this.endM = (maxH > 0 ? Math.min(24, maxH + 1) : 20) * 60;
    this.ppm = 1.2; // px na minútu
    this.render();
    document.addEventListener('rzv:refresh', function () { self.load(); });
  }

  Calendar.prototype.persist = function () {
    try { localStorage.setItem('rzvCal', JSON.stringify({ view: this.view, worker: this.worker })); } catch (e) { /* noop */ }
  };

  Calendar.prototype.range = function () {
    var d = new Date(this.date);
    if (this.view === 'day') return [d, d];
    var start = new Date(d); start.setDate(d.getDate() - ((d.getDay() + 6) % 7));
    var end = new Date(start); end.setDate(start.getDate() + 6);
    return [start, end];
  };

  Calendar.prototype.columns = function () {
    var r = this.range(), cols = [], self = this;
    if (this.view === 'day') {
      var ws = R.workers.filter(function (w) { return w.active && (!self.worker || w.id === +self.worker); });
      if (!ws.length) ws = [{ id: 0, name: 'Všetci' }];
      ws.forEach(function (w) { cols.push({ date: ymd(r[0]), worker: w.id, label: w.name }); });
      if (!self.worker && R.services.some(function (s) { return s.kind === 'rental' && s.active; })) cols.push({ date: ymd(r[0]), worker: -1, label: 'Prenájmy' });
    } else {
      for (var i = 0; i < 7; i++) {
        var d = new Date(r[0]); d.setDate(r[0].getDate() + i);
        cols.push({ date: ymd(d), worker: +self.worker || 0, label: DAYS[d.getDay()] + ' ' + d.getDate() + '. ' + (d.getMonth() + 1) + '.', today: ymd(d) === ymd(new Date()) });
      }
    }
    return cols;
  };

  Calendar.prototype.render = function () {
    var self = this, r = this.range();
    this.root.innerHTML = '';
    var title = this.view === 'day'
      ? DAYS[r[0].getDay()] + ' ' + r[0].getDate() + '. ' + (r[0].getMonth() + 1) + '. ' + r[0].getFullYear()
      : r[0].getDate() + '. ' + (r[0].getMonth() + 1) + '. – ' + r[1].getDate() + '. ' + (r[1].getMonth() + 1) + '. ' + r[1].getFullYear();
    var shift = function (n) { var d = new Date(self.date); d.setDate(d.getDate() + n * (self.view === 'day' ? 1 : 7)); self.date = d; self.render(); };
    var workerSel = h('select', { onchange: function (e) { self.worker = +e.target.value; self.persist(); self.render(); } },
      [h('option', { value: 0, text: 'Všetci pracovníci' })].concat(R.workers.filter(function (w) { return w.active; }).map(function (w) { return h('option', { value: w.id, text: w.name, selected: w.id === +self.worker }); })));
    var dateIn = h('input', { type: 'date', value: ymd(this.date), onchange: function (e) { if (e.target.value) { self.date = parse(e.target.value); self.render(); } } });
    this.root.appendChild(h('div', { class: 'rzv-cal-toolbar' }, [
      h('button', { type: 'button', class: 'button', text: '‹', onclick: function () { shift(-1); } }),
      h('button', { type: 'button', class: 'button', text: 'Dnes', onclick: function () { self.date = new Date(); self.date.setHours(0, 0, 0, 0); self.render(); } }),
      h('button', { type: 'button', class: 'button', text: '›', onclick: function () { shift(1); } }),
      h('strong', { class: 'rzv-cal-title', text: title }),
      dateIn,
      h('span', { class: 'rzv-cal-spacer' }),
      workerSel,
      h('label', {}, [h('input', { type: 'checkbox', checked: this.cancelled, onchange: function (e) { self.cancelled = e.target.checked; self.load(); } }), ' zrušené']),
      h('span', { class: 'button-group' }, [
        h('button', { type: 'button', class: 'button' + (this.view === 'day' ? ' button-primary' : ''), text: 'Deň', onclick: function () { self.view = 'day'; self.persist(); self.render(); } }),
        h('button', { type: 'button', class: 'button' + (this.view === 'week' ? ' button-primary' : ''), text: 'Týždeň', onclick: function () { self.view = 'week'; self.persist(); self.render(); } })
      ])
    ]));

    var cols = this.columns();
    this.cols = cols;
    var height = (this.endM - this.startM) * this.ppm;
    var grid = h('div', { class: 'rzv-cal-grid', style: 'grid-template-columns: 56px repeat(' + cols.length + ', minmax(120px, 1fr))' });
    grid.appendChild(h('div', { class: 'rzv-cal-corner' }));
    cols.forEach(function (c) { grid.appendChild(h('div', { class: 'rzv-cal-colhead' + (c.today ? ' is-today' : ''), text: c.label })); });
    var axis = h('div', { class: 'rzv-cal-axis', style: 'height:' + height + 'px' });
    for (var m = this.startM; m < this.endM; m += 60) {
      axis.appendChild(h('div', { class: 'rzv-cal-hour', style: 'top:' + (m - this.startM) * this.ppm + 'px', text: hm(m) }));
    }
    grid.appendChild(axis);
    this.colEls = cols.map(function (c) {
      var col = h('div', { class: 'rzv-cal-col', style: 'height:' + height + 'px;background-size:100% ' + (60 * self.ppm) + 'px' });
      self.paintHours(col, c);
      col.addEventListener('click', function (e) {
        if (e.target !== col && !e.target.classList.contains('rzv-cal-open')) return;
        var rect = col.getBoundingClientRect();
        var minute = self.startM + Math.floor((e.clientY - rect.top) / self.ppm / (R.interval || 15)) * (R.interval || 15);
        self.emptyClick(c, minute, e);
      });
      grid.appendChild(col);
      return col;
    });
    var wrap = h('div', { class: 'rzv-cal-scroll' }, [grid]);
    this.root.appendChild(wrap);
    // čiara "teraz"
    this.load();
  };

  Calendar.prototype.paintHours = function (col, c) {
    var d = parse(c.date), n = (d.getDay() + 6) % 7 + 1;
    var day = R.hours[n] || R.hours[String(n)];
    if (!day || !day.open) return;
    var self = this;
    day.intervals.forEach(function (iv) {
      col.appendChild(h('div', { class: 'rzv-cal-open', style: 'top:' + (mins(iv[0]) - self.startM) * self.ppm + 'px;height:' + (mins(iv[1]) - mins(iv[0])) * self.ppm + 'px' }));
    });
  };

  Calendar.prototype.load = function () {
    var self = this, r = this.range();
    api('/admin/calendar?from=' + ymd(r[0]) + '&to=' + ymd(r[1]) + (this.cancelled ? '&cancelled=1' : '')).then(function (data) {
      self.data = data; self.paint();
    }).catch(function (e) { self.root.appendChild(h('div', { class: 'notice notice-error', text: e.message })); });
  };

  Calendar.prototype.paint = function () {
    var self = this;
    this.colEls.forEach(function (col) { Array.prototype.forEach.call(col.querySelectorAll('.rzv-ev, .rzv-now'), function (x) { x.remove(); }); });
    var items = [];
    this.data.blocked.forEach(function (b) { items.push({ kind: 'block', start: b.start, end: b.end, worker: b.worker_id, data: b }); });
    this.data.bookings.forEach(function (b) { items.push({ kind: 'booking', start: b.start, end: b.end, worker: b.worker_id, data: b }); });

    this.cols.forEach(function (c, i) {
      var col = self.colEls[i];
      var dayStart = parse(c.date + ' 00:00');
      var dayEnd = new Date(dayStart); dayEnd.setDate(dayEnd.getDate() + 1);
      var evs = items.filter(function (it) {
        var s = parse(it.start), e = parse(it.end);
        if (!(s < dayEnd && e > dayStart)) return false;
        if (it.kind === 'block' && !it.worker) return true;
        if (c.worker === -1) return it.kind === 'booking' && it.worker === 0;
        return !c.worker || it.worker === c.worker;
      }).map(function (it) {
        var s = parse(it.start), e = parse(it.end);
        var sm = Math.max(0, (s - dayStart) / 60000), em = Math.min(1440, (e - dayStart) / 60000);
        return { it: it, s: sm, e: em };
      }).sort(function (a, b) { return a.s - b.s || b.e - a.e; });

      // rozdelenie prekrývajúcich sa udalostí do stĺpcov – zvlášť pre každú skupinu,
      // ktorá sa navzájom prekrýva (inak by sa zúžili všetky udalosti dňa)
      var cluster = [], clusterEnd = -1, lanes = [];
      var closeCluster = function () { cluster.forEach(function (c) { c.n = lanes.length; }); cluster = []; lanes = []; };
      evs.forEach(function (ev) {
        if (cluster.length && ev.s >= clusterEnd) closeCluster();
        var placed = false;
        for (var l = 0; l < lanes.length; l++) { if (lanes[l] <= ev.s) { ev.lane = l; lanes[l] = ev.e; placed = true; break; } }
        if (!placed) { ev.lane = lanes.length; lanes.push(ev.e); }
        cluster.push(ev);
        clusterEnd = Math.max(clusterEnd, ev.e);
      });
      closeCluster();
      evs.forEach(function (ev) {
        var it = ev.it, d = it.data, n = Math.max(1, ev.n);
        var top = (Math.max(ev.s, self.startM) - self.startM) * self.ppm;
        var hgt = Math.max(18, (Math.min(ev.e, self.endM) - Math.max(ev.s, self.startM)) * self.ppm - 2);
        var style = 'top:' + top + 'px;height:' + hgt + 'px;left:calc(' + (ev.lane * 100 / n) + '% + 2px);width:calc(' + (100 / n) + '% - 4px);';
        var el;
        if (it.kind === 'block') {
          el = h('div', { class: 'rzv-ev rzv-ev--block', style: style, title: 'Blokované ' + d.start.slice(11, 16) + '–' + d.end.slice(11, 16) + (d.note ? ': ' + d.note : '') }, [
            h('span', { text: '⛔ ' + (d.note || 'Blokované') + (d.worker_id ? '' : ' (prevádzka)') })
          ]);
          el.addEventListener('click', function (e) {
            e.stopPropagation();
            if (d.repeat) { location.href = 'admin.php?page=rzv-blocked&edit=' + d.id; return; }
            if (confirm('Zrušiť blokáciu ' + d.start.slice(11, 16) + '–' + d.end.slice(11, 16) + '?')) api('/admin/blocked/' + d.id, { method: 'DELETE' }).then(function () { self.load(); });
          });
        } else {
          var w = worker(d.worker_id);
          var color = d.color || (w && w.color) || '#2271b1';
          el = h('div', { class: 'rzv-ev rzv-ev--' + d.status, style: style + '--ev:' + color, title: d.start.slice(11, 16) + '–' + d.end.slice(11, 16) + ' ' + d.title + ' · ' + d.service + (w ? ' · ' + w.name : '') + (d.note ? '\n' + d.note : '') }, [
            h('strong', { text: d.start.slice(11, 16) + ' ' + d.title }),
            h('span', { text: d.service + (!c.worker && w && self.view === 'week' ? ' · ' + w.name : '') })
          ]);
          el.addEventListener('click', function (e) { e.stopPropagation(); openBooking(d.id, function () { self.load(); }); });
        }
        col.appendChild(el);
      });

      if (c.today || c.date === ymd(new Date())) {
        var now = new Date(), nm = now.getHours() * 60 + now.getMinutes();
        if (nm >= self.startM && nm <= self.endM) col.appendChild(h('div', { class: 'rzv-now', style: 'top:' + (nm - self.startM) * self.ppm + 'px' }));
      }
    });
  };

  Calendar.prototype.emptyClick = function (c, minute, e) {
    var self = this;
    var old = document.querySelector('.rzv-pop'); if (old) old.remove();
    var pop = h('div', { class: 'rzv-pop', style: 'left:' + (e.pageX + 4) + 'px;top:' + (e.pageY + 4) + 'px' }, [
      h('div', { class: 'rzv-pop__title', text: c.date.split('-').reverse().join('.') + ' ' + hm(minute) }),
      h('button', { type: 'button', class: 'button button-primary', text: 'Nová rezervácia', onclick: function () {
        var rental = c.worker === -1 ? R.services.find(function (s) { return s.kind === 'rental' && s.active; }) : null;
        pop.remove(); editor({ date: c.date, time: hm(minute), worker: Math.max(0, c.worker), service: rental ? rental.id : null, saved: function () { self.load(); } });
      } }),
      h('button', { type: 'button', class: 'button', text: 'Blokovať čas', onclick: function () { pop.remove(); self.blockDialog(c, minute); } })
    ]);
    document.body.appendChild(pop);
    setTimeout(function () {
      document.addEventListener('mousedown', function off(ev) { if (!pop.contains(ev.target)) { pop.remove(); document.removeEventListener('mousedown', off); } });
    }, 0);
  };

  Calendar.prototype.blockDialog = function (c, minute) {
    var self = this;
    var form = h('form', { class: 'rzv-editor' });
    var m = modal('Blokovať čas', form);
    var wSel = h('select', {}, [h('option', { value: 0, text: 'Celá prevádzka' })].concat(R.workers.filter(function (w) { return w.active; }).map(function (w) { return h('option', { value: w.id, text: w.name, selected: w.id === c.worker }); })));
    var date = h('input', { type: 'date', value: c.date });
    var from = h('input', { type: 'time', value: hm(minute) });
    var to = h('input', { type: 'time', value: hm(Math.min(1439, minute + 60)) });
    var note = h('input', { type: 'text', placeholder: 'Dôvod (obed, dovolenka…)', class: 'regular-text' });
    var msg = h('div', { class: 'rzv-ed-msg' });
    [['Pre', wSel], ['Dátum', date], ['Od – do', h('span', {}, [from, ' – ', to])], ['Poznámka', note]].forEach(function (r) {
      form.appendChild(h('div', { class: 'rzv-ed-row' }, [h('label', { class: 'rzv-ed-label', text: r[0] }), r[1]]));
    });
    form.appendChild(h('p', { class: 'description' }, ['Dlhšie alebo opakované blokácie (dovolenka, každý obed) nastavíte v ', h('a', { href: 'admin.php?page=rzv-blocked&new=1', text: 'Blokovaných časoch' }), '.']));
    form.appendChild(msg);
    form.appendChild(h('div', { class: 'rzv-ed-actions' }, [h('button', { type: 'submit', class: 'button button-primary', text: 'Blokovať' })]));
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      api('/admin/blocked', { method: 'POST', body: { worker_id: +wSel.value, start: date.value + ' ' + from.value, end: date.value + ' ' + to.value, note: note.value } })
        .then(function () { m.close(); self.load(); })
        .catch(function (err) { msg.textContent = err.message; msg.className = 'rzv-ed-msg is-error'; });
    });
  };

  /* ================= Init ================= */
  document.addEventListener('click', function (e) {
    var n = e.target.closest('[data-rzv-new]');
    if (n) { e.preventDefault(); editor({ saved: function () { if (window.rzvCalendar) window.rzvCalendar.load(); else location.reload(); } }); return; }
    var ed = e.target.closest('[data-rzv-edit]');
    if (ed) { e.preventDefault(); openBooking(+ed.getAttribute('data-rzv-edit'), function () { location.reload(); }); return; }
    var mb = e.target.closest('.rzv-media-btn');
    if (mb && window.wp && wp.media) {
      e.preventDefault();
      var frame = wp.media({ title: 'Vybrať obrázok', multiple: false, library: { type: 'image' } });
      frame.on('select', function () { document.getElementById(mb.getAttribute('data-target')).value = frame.state().get('selection').first().toJSON().url; });
      frame.open();
    }
  });
  document.addEventListener('change', function (e) {
    if (e.target.classList.contains('rzv-hours-custom')) {
      e.target.closest('.rzv-hours').querySelector('.rzv-hours-table').classList.toggle('is-disabled', !e.target.checked);
    }
  });

  var root = document.getElementById('rzv-calendar');
  if (root) window.rzvCalendar = new Calendar(root);
  var editId = new URLSearchParams(location.search).get('edit');
  if (editId && /page=rzv-bookings/.test(location.search)) openBooking(+editId, function () { location.reload(); });

  window.RzvAdmin = { api: api, editor: editor, openBooking: openBooking, modal: modal, h: h };
})();
