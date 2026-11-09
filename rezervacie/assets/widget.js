/* Rezervácie – verejný rezervačný formulár (bez závislostí). */
(function () {
  'use strict';

  function h(tag, attrs, children) {
    var el = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        var v = attrs[k];
        if (v === null || v === undefined || v === false) return;
        if (k === 'class') el.className = v;
        else if (k === 'text') el.textContent = v;
        else if (k === 'html') el.innerHTML = v;
        else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
        else el.setAttribute(k, v === true ? '' : v);
      });
    }
    (children || []).forEach(function (c) {
      if (c === null || c === undefined || c === false) return;
      el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return el;
  }

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

  function Widget(root) {
    this.root = root;
    this.cfg = JSON.parse(root.getAttribute('data-config'));
    this.t = this.cfg.i18n;
    this.s = { category: 0, service: null, addons: [], worker: 0, date: '', time: '', month: null, days: [], times: [] };
    this.init();
  }

  Widget.prototype.api = function (path, opts) {
    opts = opts || {};
    var headers = { 'Accept': 'application/json' };
    if (opts.body) headers['Content-Type'] = 'application/json';
    if (this.cfg.nonce) headers['X-WP-Nonce'] = this.cfg.nonce;
    return fetch(this.cfg.api + path, {
      method: opts.method || 'GET',
      headers: headers,
      credentials: 'same-origin',
      body: opts.body ? JSON.stringify(opts.body) : undefined
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) throw new Error(j.message || 'HTTP ' + r.status);
        return j;
      });
    });
  };

  Widget.prototype.money = function (v) {
    return Number(v).toLocaleString('sk-SK', { minimumFractionDigits: v % 1 ? 2 : 0, maximumFractionDigits: 2 }) + ' ' + this.cat.settings.currency;
  };

  Widget.prototype.dur = function (m) {
    var hh = Math.floor(m / 60), mm = m % 60;
    return (hh ? hh + 'h' : '') + (hh && mm ? ' ' : '') + (mm ? mm + 'min.' : '');
  };

  Widget.prototype.emit = function (name, detail) {
    this.root.dispatchEvent(new CustomEvent('rzv:' + name, { detail: detail, bubbles: true }));
  };

  Widget.prototype.init = function () {
    var self = this;
    if (this.cfg.mode === 'vouchers') return this.shop();
    if (this.cfg.manage) return this.manage();
    this.api('/catalog').then(function (cat) {
      self.cat = cat;
      var p = self.cfg.preset || {};
      if (p.service) {
        var svc = cat.services.find(function (x) { return x.id === p.service; });
        if (svc) { self.s.category = svc.category_id; self.s.service = svc; }
      } else if (p.category) {
        self.s.category = p.category;
      }
      if (p.worker) self.s.worker = p.worker;
      self.render();
    }).catch(function (e) { self.fail(e); });
  };

  Widget.prototype.fail = function (e) {
    this.root.innerHTML = '';
    this.root.appendChild(h('div', { class: 'rzv-error', text: (e && e.message) || this.t.error }));
  };

  Widget.prototype.step = function (n, title, body, done) {
    return h('section', { class: 'rzv-step' + (done ? ' is-done' : '') }, [
      h('h3', { class: 'rzv-step__title' }, [h('span', { class: 'rzv-step__num', text: String(n) }), title]),
      body
    ]);
  };

  Widget.prototype.render = function () {
    var self = this, t = this.t, cat = this.cat, s = this.s, n = 1;
    var frag = document.createDocumentFragment();
    var preset = this.cfg.preset || {};

    frag.appendChild(h('div', { class: 'rzv-head', text: cat.business }));

    // 1. Kategória
    var cats = cat.categories.filter(function (c) {
      return cat.services.some(function (x) { return x.category_id === c.id; });
    });
    var uncategorized = cat.services.some(function (x) { return !x.category_id || !cats.some(function (c) { return c.id === x.category_id; }); });
    if (cats.length > 1 && !preset.service && !preset.category) {
      frag.appendChild(this.step(n++, t.category, h('div', { class: 'rzv-chips' }, cats.map(function (c) {
        return h('button', {
          type: 'button', class: 'rzv-chip' + (s.category === c.id ? ' is-active' : ''), text: c.name,
          onclick: function () { s.category = c.id; s.service = null; self.resetFrom('service'); self.render(); }
        });
      }).concat(uncategorized ? [h('button', {
        type: 'button', class: 'rzv-chip' + (s.category === -1 ? ' is-active' : ''), text: 'Ostatné',
        onclick: function () { s.category = -1; s.service = null; self.resetFrom('service'); self.render(); }
      })] : [])), !!s.category));
    } else if (!s.category) {
      s.category = cats.length === 1 && !uncategorized ? cats[0].id : -2; // -2 = všetko
    }
    if (!s.category) return this.paint(frag);

    // 2. Služba
    var services = cat.services.filter(function (x) {
      if (s.category === -2) return true;
      if (s.category === -1) return !x.category_id || !cats.some(function (c) { return c.id === x.category_id; });
      return x.category_id === s.category;
    });
    if (!preset.service) {
      frag.appendChild(this.step(n++, t.service, h('div', { class: 'rzv-list' }, services.map(function (x) {
        return h('button', {
          type: 'button', class: 'rzv-item' + (s.service && s.service.id === x.id ? ' is-active' : ''),
          onclick: function () { s.service = x; self.resetFrom('addons'); self.render(); }
        }, [
          h('span', { class: 'rzv-item__main' }, [
            h('strong', { text: x.name + (cat.settings.show_duration ? ' (' + self.dur(x.duration) + ')' : '') }),
            x.description ? h('small', { text: x.description }) : null
          ]),
          cat.settings.show_prices && x.price > 0 ? h('span', { class: 'rzv-item__price', text: self.money(x.price) }) : null
        ]);
      })), !!s.service));
    }
    if (!s.service) return this.paint(frag);

    // Doplnkové služby
    var addons = cat.addons.filter(function (a) { return s.service.addons.indexOf(a.id) >= 0; });
    if (addons.length) {
      frag.appendChild(this.step(n++, t.addons, h('div', { class: 'rzv-list' }, addons.map(function (a) {
        var on = s.addons.indexOf(a.id) >= 0;
        return h('label', { class: 'rzv-item rzv-item--check' + (on ? ' is-active' : '') }, [
          h('input', {
            type: 'checkbox', checked: on, onchange: function (e) {
              if (e.target.checked) s.addons.push(a.id); else s.addons = s.addons.filter(function (i) { return i !== a.id; });
              self.resetFrom('date'); self.render();
            }
          }),
          h('span', { class: 'rzv-item__main' }, [
            h('strong', { text: a.name + (a.duration ? ' (+' + self.dur(a.duration) + ')' : '') }),
            a.description ? h('small', { text: a.description }) : null
          ]),
          a.price > 0 ? h('span', { class: 'rzv-item__price', text: '+' + self.money(a.price) }) : null
        ]);
      })), true));
    }

    // Pracovník
    var workers = cat.workers.filter(function (w) { return s.service.workers.indexOf(w.id) >= 0; });
    if (cat.settings.worker_choice && workers.length > 1 && !preset.worker) {
      frag.appendChild(this.step(n++, t.worker, h('div', { class: 'rzv-chips' }, [
        h('button', { type: 'button', class: 'rzv-chip' + (!s.worker ? ' is-active' : ''), text: t.anyWorker, onclick: function () { s.worker = 0; self.resetFrom('date'); self.render(); } })
      ].concat(workers.map(function (w) {
        return h('button', {
          type: 'button', class: 'rzv-chip rzv-chip--worker' + (s.worker === w.id ? ' is-active' : ''),
          onclick: function () { s.worker = w.id; self.resetFrom('date'); self.render(); }
        }, [w.photo ? h('img', { src: w.photo, alt: '' }) : null, w.name]);
      }))), true));
    }

    // Dátum
    frag.appendChild(this.step(n++, t.date, this.calendar(), !!s.date));
    if (!s.date) return this.paint(frag);

    // Čas
    frag.appendChild(this.step(n++, t.time, this.timesEl(), !!s.time));
    if (!s.time) return this.paint(frag);

    // Údaje
    frag.appendChild(this.step(n++, t.details, this.detailsEl(), false));
    this.paint(frag);
  };

  Widget.prototype.paint = function (frag) {
    var y = window.scrollY;
    this.root.innerHTML = '';
    this.root.appendChild(frag);
    window.scrollTo(0, y);
  };

  Widget.prototype.resetFrom = function (what) {
    var s = this.s;
    var order = ['service', 'addons', 'date', 'time'];
    var i = order.indexOf(what);
    if (i <= 1) s.addons = [];
    if (i <= 2) { s.date = ''; s.days = []; s.daysKey = ''; }
    s.time = ''; s.times = null; s.timesLoading = '';
  };

  Widget.prototype.query = function (extra) {
    var s = this.s;
    var q = 'service=' + s.service.id + '&worker=' + (s.worker || 0) + '&addons=' + s.addons.join(',');
    return q + (extra ? '&' + extra : '');
  };

  Widget.prototype.calendar = function () {
    var self = this, s = this.s, t = this.t;
    var today = new Date(); today.setHours(0, 0, 0, 0);
    if (!s.month) s.month = new Date(today.getFullYear(), today.getMonth(), 1);
    var m = s.month;
    var monthKey = m.getFullYear() + '-' + pad(m.getMonth() + 1);
    var key = this.query('month=' + monthKey);
    var wrap = h('div', { class: 'rzv-cal' });

    var max = new Date(today); max.setDate(max.getDate() + this.cat.settings.days_ahead);
    var prevOk = m > new Date(today.getFullYear(), today.getMonth(), 1);
    var nextOk = new Date(m.getFullYear(), m.getMonth() + 1, 1) <= max;

    wrap.appendChild(h('div', { class: 'rzv-cal__head' }, [
      h('button', { type: 'button', class: 'rzv-cal__nav', disabled: !prevOk, 'aria-label': '‹', text: '‹', onclick: function () { s.month = new Date(m.getFullYear(), m.getMonth() - 1, 1); self.render(); } }),
      h('strong', { text: t.months[m.getMonth()] + ' ' + m.getFullYear() }),
      h('button', { type: 'button', class: 'rzv-cal__nav', disabled: !nextOk, 'aria-label': '›', text: '›', onclick: function () { s.month = new Date(m.getFullYear(), m.getMonth() + 1, 1); self.render(); } })
    ]));

    var grid = h('div', { class: 'rzv-cal__grid' });
    t.days.forEach(function (d) { grid.appendChild(h('span', { class: 'rzv-cal__dow', text: d })); });
    var offset = (m.getDay() + 6) % 7;
    for (var i = 0; i < offset; i++) grid.appendChild(h('span'));
    var last = new Date(m.getFullYear(), m.getMonth() + 1, 0).getDate();
    var loading = s.daysKey !== key;
    for (var d = 1; d <= last; d++) {
      (function (d) {
        var date = ymd(new Date(m.getFullYear(), m.getMonth(), d));
        var free = !loading && s.days.indexOf(date) >= 0;
        var past = new Date(m.getFullYear(), m.getMonth(), d) < today;
        var dow = (new Date(m.getFullYear(), m.getMonth(), d).getDay() + 6) % 7 + 1;
        var waitOk = self.cat.settings.waitlist && !loading && !free && !past && new Date(m.getFullYear(), m.getMonth(), d) <= max &&
          (self.cat.settings.open_days || []).indexOf(dow) >= 0;
        grid.appendChild(h('button', {
          type: 'button',
          class: 'rzv-cal__day' + (free ? ' is-free' : '') + (s.date === date ? ' is-active' : '') + (waitOk ? ' is-wait' : ''),
          disabled: !(free || waitOk),
          text: String(d),
          onclick: function () { s.date = date; s.time = ''; s.times = null; self.render(); }
        }));
      })(d);
    }
    wrap.appendChild(grid);
    if (loading) wrap.classList.add('is-loading');
    if (loading && s.daysLoading !== key) {
      s.daysLoading = key;
      this.api('/days?' + key).then(function (r) {
        s.days = r.days || []; s.daysKey = key;
        if (!s.date && s.days.length === 0 && nextOk && !s._autoNext) {
          s._autoNext = true; s.month = new Date(m.getFullYear(), m.getMonth() + 1, 1);
        }
        self.render();
      }).catch(function (e) { self.fail(e); });
    }
    return wrap;
  };

  Widget.prototype.loadTimes = function () {
    var self = this, s = this.s, date = s.date;
    var k = this.query('date=' + date);
    if (s.timesLoading === k) return;
    s.timesLoading = k;
    this.api('/times?' + this.query('date=' + date)).then(function (r) {
      s.timesLoading = '';
      if (s.date !== date) return;
      s.times = r.times || []; self.render();
    }).catch(function (e) { self.fail(e); });
  };

  Widget.prototype.timesEl = function () {
    var self = this, s = this.s, t = this.t;
    if (s.times === null) { this.loadTimes(); return h('div', { class: 'rzv-muted', text: '…' }); }
    if (!s.times || !s.times.length) {
      var box = h('div', { class: 'rzv-empty' }, [h('p', { text: t.noTimes })]);
      if (this.cat.settings.waitlist) box.appendChild(this.waitlistEl());
      return box;
    }
    var am = s.times.filter(function (x) { return x < '12:00'; });
    var pm = s.times.filter(function (x) { return x >= '12:00'; });
    function group(label, list) {
      if (!list.length) return null;
      return h('div', { class: 'rzv-times__group' }, [
        h('div', { class: 'rzv-times__label', text: label }),
        h('div', { class: 'rzv-times' }, list.map(function (tm) {
          return h('button', { type: 'button', class: 'rzv-time' + (s.time === tm ? ' is-active' : ''), text: tm, onclick: function () { s.time = tm; self.render(); } });
        }))
      ]);
    }
    return h('div', {}, [group(t.morning, am), group(t.afternoon, pm)]);
  };

  Widget.prototype.personFields = function (form) {
    var t = this.t, req = this.cat.settings.require_phone;
    return h('div', { class: 'rzv-grid' }, [
      this.input('first_name', t.firstName, 'text', true, 'given-name'),
      this.input('last_name', t.lastName, 'text', true, 'family-name'),
      this.input('email', t.email, 'email', true, 'email'),
      this.input('phone', t.phone, 'tel', req, 'tel')
    ]);
  };

  Widget.prototype.input = function (name, label, type, required, ac) {
    return h('label', { class: 'rzv-field' }, [
      h('span', { text: label + (required ? ' *' : '') }),
      h('input', { type: type, name: name, required: required, autocomplete: ac || null })
    ]);
  };

  Widget.prototype.waitlistEl = function () {
    var self = this, s = this.s, t = this.t;
    var form = h('form', { class: 'rzv-waitlist' });
    var open = false;
    var btn = h('button', { type: 'button', class: 'rzv-btn rzv-btn--ghost', text: t.waitlist, onclick: function () {
      if (open) return; open = true;
      form.appendChild(self.personFields());
      form.appendChild(h('input', { type: 'text', name: 'website', class: 'rzv-hp', tabindex: '-1', autocomplete: 'off' }));
      form.appendChild(h('button', { type: 'submit', class: 'rzv-btn', text: t.waitlist }));
    } });
    form.appendChild(btn);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var d = self.formData(form);
      d.service_id = s.service.id; d.worker_id = s.worker; d.date = s.date;
      self.api('/waitlist', { method: 'POST', body: d }).then(function () {
        form.innerHTML = ''; form.appendChild(h('div', { class: 'rzv-ok', text: t.waitlistOk }));
      }).catch(function (err) { self.msg(form, err.message, true); });
    });
    return form;
  };

  Widget.prototype.formData = function (form) {
    var d = { answers: {} };
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name || el.disabled) return;
      var m = el.name.match(/^answers\[(\d+)\](\[\])?$/);
      if (m) {
        if (el.type === 'checkbox') { if (el.checked) (d.answers[m[1]] = d.answers[m[1]] || []).push(el.value); }
        else if (el.type === 'radio') { if (el.checked) d.answers[m[1]] = el.value; }
        else d.answers[m[1]] = el.value;
        return;
      }
      d[el.name] = el.type === 'checkbox' ? el.checked : el.value;
    });
    return d;
  };

  Widget.prototype.msg = function (el, text, isErr) {
    var old = el.querySelector('.rzv-msg'); if (old) old.remove();
    el.appendChild(h('div', { class: 'rzv-msg' + (isErr ? ' is-error' : ''), text: text }));
  };

  Widget.prototype.fieldEl = function (f) {
    var name = 'answers[' + f.id + ']', t = this.t;
    var label = f.label + (f.required ? ' *' : '');
    if (f.type === 'textarea') return h('label', { class: 'rzv-field rzv-field--wide' }, [h('span', { text: label }), h('textarea', { name: name, required: f.required, rows: '3' })]);
    if (f.type === 'select') return h('label', { class: 'rzv-field rzv-field--wide' }, [h('span', { text: label }), h('select', { name: name, required: f.required }, [h('option', { value: '', text: t.choose })].concat(f.options.map(function (o) { return h('option', { value: o, text: o }); })))]);
    if (f.type === 'radio' || f.type === 'checkbox' || f.type === 'yesno') {
      var opts = f.type === 'yesno' ? [t.yes, t.no] : f.options;
      var type = f.type === 'checkbox' ? 'checkbox' : 'radio';
      return h('fieldset', { class: 'rzv-field rzv-field--wide rzv-options' }, [h('legend', { text: label })].concat(opts.map(function (o) {
        return h('label', {}, [h('input', { type: type, name: name + (type === 'checkbox' ? '[]' : ''), value: o, required: type === 'radio' && f.required }), ' ' + o]);
      })));
    }
    return h('label', { class: 'rzv-field rzv-field--wide' }, [h('span', { text: label }), h('input', { type: 'text', name: name, required: f.required })]);
  };

  Widget.prototype.detailsEl = function () {
    var self = this, s = this.s, t = this.t, cat = this.cat;
    var form = h('form', { class: 'rzv-form', novalidate: false });

    // zhrnutie
    var addons = cat.addons.filter(function (a) { return s.addons.indexOf(a.id) >= 0; });
    var total = s.service.price + addons.reduce(function (a, b) { return a + b.price; }, 0);
    var dur = s.service.duration + addons.reduce(function (a, b) { return a + b.duration; }, 0);
    var dt = new Date(s.date + 'T12:00:00');
    var worker = cat.workers.find(function (w) { return w.id === s.worker; });
    var summary = h('div', { class: 'rzv-summary' }, [
      h('div', {}, [h('strong', { text: s.service.name }), addons.length ? ' + ' + addons.map(function (a) { return a.name; }).join(', ') : '']),
      h('div', { text: t.days[(dt.getDay() + 6) % 7] + ' ' + dt.getDate() + '. ' + (dt.getMonth() + 1) + '. ' + dt.getFullYear() + ', ' + s.time + ' (' + self.dur(dur) + ')' + (worker ? ' · ' + worker.name : '') }),
      h('div', { class: 'rzv-summary__applied' }),
      cat.settings.show_prices && total > 0 ? h('div', { class: 'rzv-summary__total', 'data-total': total }, [t.total + ': ', h('strong', { class: 'rzv-total', text: self.money(total) })]) : null,
      h('div', { class: 'rzv-summary__pay' })
    ]);
    form.appendChild(summary);
    s.codes = s.codes || [];
    var codeMsg = h('div', { class: 'rzv-code__msg' });
    var refresh = function (codes, onErr) {
      return self.api('/quote', { method: 'POST', body: { service_id: s.service.id, addons: s.addons, codes: codes, email: (form.email && form.email.value) || '' } }).then(function (q) {
        s.codes = codes; s.quote = q;
        var ap = summary.querySelector('.rzv-summary__applied'); ap.innerHTML = '';
        q.applied.forEach(function (a) {
          ap.appendChild(h('div', { class: 'rzv-applied' }, [a.label + ' (' + a.code + '): ', h('strong', { text: a.amount_label }), ' ',
            h('a', { href: '#', text: t.codeRemove, onclick: function (e) { e.preventDefault(); refresh(s.codes.filter(function (c) { return c !== a.code; })); } })]));
        });
        var tot = summary.querySelector('.rzv-total'); if (tot) tot.textContent = q.total_label;
        var pay = summary.querySelector('.rzv-summary__pay'); pay.innerHTML = '';
        if (q.online_required && q.pay_online > 0) pay.appendChild(h('div', { class: 'rzv-paynote', text: t.payRequired + ': ' + q.pay_online_label }));
        codeMsg.textContent = ''; codeMsg.className = 'rzv-code__msg';
      }).catch(function (err) {
        codeMsg.textContent = err.message; codeMsg.className = 'rzv-code__msg is-error';
        if (onErr) onErr();
      });
    };
    if (cat.settings.online || s.codes.length) refresh(s.codes);
    if (cat.settings.loyalty) {
      setTimeout(function () {
        if (form.email) form.email.addEventListener('change', function () { if (/@/.test(form.email.value)) refresh(s.codes || []); });
      }, 0);
    }

    form.appendChild(this.personFields());
    var extra = h('div', { class: 'rzv-grid' }, (s.service.fields || []).map(function (f) { return self.fieldEl(f); }));
    extra.appendChild(h('label', { class: 'rzv-field rzv-field--wide' }, [h('span', { text: t.note }), h('textarea', { name: 'note', rows: '2' })]));
    form.appendChild(extra);

    if (cat.settings.codes) {
      var codeIn = h('input', { type: 'text', class: 'rzv-code__input', placeholder: t.codeLabel, autocomplete: 'off' });
      var apply = function () {
        var c = codeIn.value.trim().toUpperCase().replace(/\s+/g, '');
        if (!c || s.codes.indexOf(c) >= 0) return;
        refresh(s.codes.concat([c])).then(function () { if (s.codes.indexOf(c) >= 0) codeIn.value = ''; });
      };
      codeIn.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); apply(); } });
      form.appendChild(h('div', { class: 'rzv-code' }, [
        h('label', { class: 'rzv-field rzv-field--wide' }, [h('span', { text: t.codeLabel }),
          h('div', { class: 'rzv-code__row' }, [codeIn, h('button', { type: 'button', class: 'rzv-btn rzv-btn--small', text: t.codeApply, onclick: apply })])]),
        codeMsg
      ]));
    }

    var modules = h('div', { class: 'rzv-modules' });
    form.appendChild(modules);

    var termsLabel = cat.settings.terms_url ? h('a', { href: cat.settings.terms_url, target: '_blank', rel: 'noopener', text: t.terms }) : t.terms;
    form.appendChild(h('label', { class: 'rzv-check' }, [h('input', { type: 'checkbox', name: 'terms', required: true }), ' ', termsLabel, ' *']));
    form.appendChild(h('label', { class: 'rzv-check' }, [h('input', { type: 'checkbox', name: 'marketing' }), ' ' + t.marketing]));
    if (cat.settings.privacy_url) form.appendChild(h('p', { class: 'rzv-small' }, [h('a', { href: cat.settings.privacy_url, target: '_blank', rel: 'noopener', text: t.privacy })]));
    if (cat.settings.cancel_policy) form.appendChild(h('p', { class: 'rzv-small', text: cat.settings.cancel_policy }));
    form.appendChild(h('input', { type: 'text', name: 'website', class: 'rzv-hp', tabindex: '-1', autocomplete: 'off' }));
    var submit = h('button', { type: 'submit', class: 'rzv-btn rzv-btn--primary', text: t.book });
    form.appendChild(submit);

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!form.reportValidity()) return;
      var d = self.formData(form);
      d.service_id = s.service.id; d.worker_id = s.worker; d.date = s.date; d.time = s.time; d.addons = s.addons; d.codes = s.codes || [];
      self.emit('payload', { payload: d, state: s, form: form });
      submit.disabled = true; submit.classList.add('is-busy');
      self.api('/bookings', { method: 'POST', body: d }).then(function (r) {
        self.done(r);
      }).catch(function (err) {
        submit.disabled = false; submit.classList.remove('is-busy');
        self.msg(form, err.message, true);
        if (/voľný/.test(err.message)) { s.time = ''; s.times = null; setTimeout(function () { self.render(); }, 1500); }
      });
    });

    // rozšírenia (zľavový kód, poukážka, platba…) sa môžu napojiť sem
    setTimeout(function () { self.emit('details', { el: modules, form: form, state: s, catalog: cat, widget: self }); }, 0);
    return form;
  };

  Widget.prototype.done = function (r) {
    var self = this, t = this.t, b = r.summary;
    if (r.payment && r.payment.redirect) {
      this.root.innerHTML = '';
      this.root.appendChild(h('div', { class: 'rzv-done' }, [h('h3', { text: t.redirecting })]));
      window.location.href = r.payment.redirect;
      return;
    }
    var box = h('div', { class: 'rzv-done' }, [
      h('div', { class: 'rzv-done__icon', text: r.status === 'confirmed' ? '✓' : '…' }),
      h('h3', { text: r.status === 'confirmed' ? t.confirmed : t.pending }),
      this.bookingCard(b),
      r.payment ? this.paymentEl(r.payment) : null,
      h('p', { class: 'rzv-muted', text: t.emailSent }),
      h('div', { class: 'rzv-done__extra' }),
      h('button', { type: 'button', class: 'rzv-btn', text: t.newBooking, onclick: function () {
        self.s = { category: 0, service: null, addons: [], worker: 0, date: '', time: '', month: null, days: [], times: [] };
        self.render();
      } })
    ]);
    this.root.innerHTML = '';
    this.root.appendChild(box);
    this.root.scrollIntoView({ behavior: 'smooth', block: 'start' });
    this.emit('booked', { response: r, el: box.querySelector('.rzv-done__extra'), widget: this });
  };

  Widget.prototype.bookingCard = function (b) {
    return h('dl', { class: 'rzv-card' }, [
      h('dt', { text: 'Služba' }), h('dd', { text: b.service + (b.addons && b.addons.length ? ' + ' + b.addons.join(', ') : '') }),
      h('dt', { text: 'Termín' }), h('dd', { text: b.date + ', ' + b.time + ' – ' + b.end }),
      h('dt', { text: 'Pracovník' }), h('dd', { text: b.worker }),
      h('dt', { text: 'Cena' }), h('dd', {}, [b.price].concat((b.discounts || []).map(function (x) { return h('small', { class: 'rzv-block', text: x }); }))),
      b.paid ? h('dt', { text: 'Zaplatené' }) : null, b.paid ? h('dd', { text: b.paid + (b.due ? ' (zostáva ' + b.due + ')' : '') }) : null,
      h('dt', { text: 'Stav' }), h('dd', { text: b.status_label })
    ]);
  };

  // správa rezervácie cez odkaz z e-mailu
  Widget.prototype.manage = function () {
    var self = this, t = this.t, token = this.cfg.manage;
    this.api('/manage/' + encodeURIComponent(token)).then(function (r) {
      var box = h('div', { class: 'rzv-done' }, [
        self.cfg.paid === '1' ? h('div', { class: 'rzv-ok', text: t.paidOk }) : null,
        self.cfg.paid === '0' || self.cfg.paid === 'cancel' ? h('div', { class: 'rzv-error', text: t.paidFail }) : null,
        h('h3', { text: t.yourBooking }), self.bookingCard(r.booking),
        r.payment ? self.paymentEl(r.payment) : null,
        r.loyalty ? h('p', { class: 'rzv-loyalty', text: r.loyalty }) : null,
        r.rate_url ? h('a', { class: 'rzv-btn', href: r.rate_url, text: '★ Ohodnotiť návštevu' }) : null
      ]);
      var extra = h('div', { class: 'rzv-done__extra' });
      box.appendChild(extra);
      if (r.can_cancel) {
        box.appendChild(h('button', { type: 'button', class: 'rzv-btn rzv-btn--danger', text: t.cancel, onclick: function (e) {
          if (!window.confirm(t.cancelAsk)) return;
          e.target.disabled = true;
          self.api('/manage/' + encodeURIComponent(token) + '/cancel', { method: 'POST', body: {} }).then(function () {
            self.root.innerHTML = '';
            self.root.appendChild(h('div', { class: 'rzv-done' }, [h('h3', { text: t.cancelled })]));
          }).catch(function (err) { e.target.disabled = false; self.msg(box, err.message, true); });
        } }));
      } else if (r.reason && ['pending', 'confirmed'].indexOf(r.booking.status) >= 0) {
        box.appendChild(h('p', { class: 'rzv-muted', text: r.reason }));
      }
      self.root.innerHTML = '';
      self.root.appendChild(box);
      self.emit('manage', { response: r, el: extra, token: token, widget: self });
    }).catch(function (e) { self.fail(e); });
  };

  Widget.prototype.paymentEl = function (p) {
    var t = this.t;
    var box = h('div', { class: 'rzv-pay' }, [h('h4', { text: t.payTitle + ': ' + p.amount_label })]);
    if (p.required) box.appendChild(h('p', { class: 'rzv-paynote', text: t.payRequired + (p.expires ? '. ' + t.payUntil + ' ' + p.expires + '.' : '.') }));
    if (p.online_url) box.appendChild(h('a', { class: 'rzv-btn rzv-btn--primary', href: p.online_url, text: t.payOnline }));
    if (p.qr && window.qrcode) {
      var q = window.qrcode(0, 'M'); q.addData(p.qr); q.make();
      var qr = h('div', { class: 'rzv-qr', html: q.createSvgTag({ cellSize: 4, margin: 2, scalable: true }) });
      box.appendChild(h('div', { class: 'rzv-pay__qr' }, [
        qr,
        h('dl', { class: 'rzv-card rzv-card--small' }, [
          h('dt', { text: 'IBAN' }), h('dd', { text: p.iban }),
          h('dt', { text: 'Suma' }), h('dd', { text: p.amount_label }),
          h('dt', { text: 'VS' }), h('dd', { text: p.vs }),
          h('dt', { text: 'Príjemca' }), h('dd', { text: p.beneficiary || '' })
        ]),
        h('p', { class: 'rzv-small', text: t.payQrHint })
      ]));
    }
    return box;
  };

  /* ---------- predaj poukážok ---------- */
  Widget.prototype.shop = function () {
    var self = this;
    this.api('/voucher-types').then(function (d) { self.shopData = d; self.shopSel = null; self.renderShop(); }).catch(function (e) { self.fail(e); });
  };

  Widget.prototype.renderShop = function () {
    var self = this, t = this.t, d = this.shopData;
    this.cat = { settings: { currency: d.currency, require_phone: false } };
    var frag = document.createDocumentFragment();
    frag.appendChild(h('div', { class: 'rzv-head', text: t.vouchersTitle }));
    if (d.intro) frag.appendChild(h('p', { class: 'rzv-muted', text: d.intro }));
    if (!d.types.length) { frag.appendChild(h('p', { text: '—' })); return this.paint(frag); }
    frag.appendChild(h('div', { class: 'rzv-vtypes' }, d.types.map(function (vt) {
      return h('button', { type: 'button', class: 'rzv-vtype' + (self.shopSel && self.shopSel.id === vt.id ? ' is-active' : ''), style: vt.color ? '--rzv-vt:' + vt.color : null,
        onclick: function () { self.shopSel = vt; self.renderShop(); } }, [
        vt.image ? h('img', { src: vt.image, alt: '' }) : h('div', { class: 'rzv-vtype__band' }),
        h('strong', { text: vt.name }),
        h('span', { class: 'rzv-vtype__value', text: vt.value_label }),
        vt.description ? h('small', { text: vt.description }) : null,
        h('span', { class: 'rzv-vtype__price', text: vt.price_label }),
        h('small', { class: 'rzv-muted', text: t.validity + ': ' + vt.validity_days + ' ' + t.daysUnit })
      ]);
    })));
    if (this.shopSel) frag.appendChild(this.shopForm(this.shopSel));
    this.paint(frag);
  };

  Widget.prototype.shopForm = function (vt) {
    var self = this, t = this.t, d = this.shopData;
    var form = h('form', { class: 'rzv-form rzv-step' });
    form.appendChild(h('h3', { class: 'rzv-step__title', text: vt.name + ' – ' + vt.price_label }));
    form.appendChild(h('div', { class: 'rzv-grid' }, [
      h('label', { class: 'rzv-field rzv-field--wide' }, [h('span', { text: t.recipient }), h('input', { name: 'recipient', maxlength: '100' })]),
      h('label', { class: 'rzv-field rzv-field--wide' }, [h('span', { text: t.message }), h('textarea', { name: 'message', rows: '2', maxlength: '300' })])
    ]));
    form.appendChild(h('h4', { text: t.buyer }));
    form.appendChild(h('div', { class: 'rzv-grid' }, [
      h('label', { class: 'rzv-field' }, [h('span', { text: t.firstName + ' a ' + t.lastName.toLowerCase() + ' *' }), h('input', { name: 'buyer_name', required: true, autocomplete: 'name' })]),
      h('label', { class: 'rzv-field' }, [h('span', { text: t.email + ' *' }), h('input', { name: 'buyer_email', type: 'email', required: true, autocomplete: 'email' })]),
      h('label', { class: 'rzv-field' }, [h('span', { text: t.phone }), h('input', { name: 'buyer_phone', type: 'tel', autocomplete: 'tel' })]),
      d.coupons ? h('label', { class: 'rzv-field' }, [h('span', { text: 'Zľavový kód' }), h('input', { name: 'coupon', autocomplete: 'off' })]) : null
    ]));
    var termsLabel = d.terms_url ? h('a', { href: d.terms_url, target: '_blank', rel: 'noopener', text: t.terms }) : t.terms;
    form.appendChild(h('label', { class: 'rzv-check' }, [h('input', { type: 'checkbox', name: 'terms', required: true }), ' ', termsLabel, ' *']));
    if (d.terms) form.appendChild(h('p', { class: 'rzv-small', text: d.terms }));
    form.appendChild(h('input', { type: 'text', name: 'website', class: 'rzv-hp', tabindex: '-1', autocomplete: 'off' }));
    var btn = h('button', { type: 'submit', class: 'rzv-btn rzv-btn--primary', text: d.online ? t.payOnline : t.voucherBuy });
    form.appendChild(btn);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!form.reportValidity()) return;
      var body = self.formData(form); delete body.answers;
      body.type_id = vt.id; body.pay_online = d.online;
      btn.disabled = true;
      self.api('/vouchers/order', { method: 'POST', body: body }).then(function (r) {
        if (r.redirect) { self.root.innerHTML = ''; self.root.appendChild(h('div', { class: 'rzv-done' }, [h('h3', { text: t.redirecting })])); window.location.href = r.redirect; return; }
        var box = h('div', { class: 'rzv-done' }, [
          h('div', { class: 'rzv-done__icon', text: '✓' }),
          h('h3', { text: t.voucherOk }),
          h('p', { text: r.status === 'active' ? t.voucherPaid : t.voucherWait }),
          r.payment ? self.paymentEl(r.payment) : null,
          h('a', { class: 'rzv-btn', href: r.url, target: '_blank', rel: 'noopener', text: t.voucherOpen })
        ]);
        self.root.innerHTML = ''; self.root.appendChild(box);
        self.root.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }).catch(function (err) { btn.disabled = false; self.msg(form, err.message, true); });
    });
    return form;
  };

  function boot() {
    document.querySelectorAll('.rzv-widget[data-config]').forEach(function (el) {
      if (!el._rzv) el._rzv = new Widget(el);
    });
  }
  window.RzvWidget = Widget;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
