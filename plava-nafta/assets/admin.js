// Admin editor. Talks to api.php; the server re-validates everything.
(function () {
  "use strict";
  var app = document.getElementById("app");
  var boot = JSON.parse(app.getAttribute("data-boot"));
  var state = { stations: [], q: "", editing: null };

  // ---- helpers
  function h(tag, attrs) {
    var el = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v == null || v === false) return;
      if (k === "class") el.className = v;
      else if (k === "text") el.textContent = v;
      else if (k.slice(0, 2) === "on") el.addEventListener(k.slice(2), v);
      else if (k === "value") el.value = v;
      else el.setAttribute(k, v === true ? "" : v);
    });
    for (var i = 2; i < arguments.length; i++) {
      var c = arguments[i];
      if (c == null || c === false) continue;
      (Array.isArray(c) ? c : [c]).forEach(function (n) { el.append(n instanceof Node ? n : document.createTextNode(String(n))); });
    }
    return el;
  }
  function norm(s) {
    return String(s || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/đ/g, "d");
  }
  function api(action, body, isForm) {
    var opts = { method: body ? "POST" : "GET", headers: { "X-CSRF-Token": boot.csrf }, credentials: "same-origin" };
    if (body && isForm) opts.body = body;
    else if (body) { opts.headers["Content-Type"] = "application/json"; opts.body = JSON.stringify(body); }
    return fetch(boot.api + "?action=" + encodeURIComponent(action), opts).then(function (r) {
      return r.json().catch(function () { return { error: "Neočekivan odgovor poslužitelja." }; }).then(function (data) {
        if (r.status === 401) { location.reload(); throw new Error(data.error); }
        if (!r.ok) throw new Error(data.error || "Greška " + r.status);
        if (data.csrf) boot.csrf = data.csrf;
        return data;
      });
    });
  }
  var toastEl = h("div", { class: "toast", role: "status", hidden: true });
  document.body.append(toastEl);
  var toastTimer;
  function toast(msg, isError) {
    toastEl.textContent = msg;
    toastEl.className = "toast" + (isError ? " toast-error" : "");
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.hidden = true; }, isError ? 7000 : 3500);
  }
  function fail(err) { toast(err.message, true); }
  function input(attrs) { return h("input", Object.assign({ class: "input" }, attrs)); }
  function field(label, control, cls) { return h("label", { class: "field " + (cls || "") }, h("span", { text: label }), control); }
  // Same rule as Stations::closureState() in PHP / site.js.
  var today = (function () {
    try { return new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Zagreb" }).format(new Date()); }
    catch (e) { return new Date().toISOString().slice(0, 10); }
  })();
  function closureState(s) {
    if (s.status !== "closed") return "open";
    if (s.closed_until && today > s.closed_until) return "expired";
    if (s.closed_from && today < s.closed_from) return "upcoming";
    return "closed";
  }
  function hrDate(ymd) { var p = (ymd || "").split("-"); return p.length === 3 ? (+p[2]) + ". " + (+p[1]) + ". " + p[0] + "." : ""; }
  function statusBadge(s) {
    var st = closureState(s), until = s.closed_until ? " do " + hrDate(s.closed_until) : "";
    if (st === "closed") return h("span", { class: "card-badge badge-closed", text: "Privremeno zatvoreno" + until + (s.status_source === "ina" ? " · automatski s ina.hr" : "") });
    if (st === "upcoming") return h("span", { class: "card-badge badge-upcoming", text: "Zatvaranje od " + hrDate(s.closed_from) + until });
    if (st === "expired") return h("span", { class: "card-badge badge-upcoming", text: "Zatvaranje isteklo " + hrDate(s.closed_until) + " – provjerite i označite kao otvorenu" });
    return null;
  }

  var qrReady;
  function loadQr() {
    qrReady = qrReady || boot.qrScripts.reduce(function (p, src) {
      return p.then(function () {
        return new Promise(function (resolve, reject) {
          document.head.append(h("script", { src: src, onload: resolve, onerror: function () { reject(new Error("QR skripta se nije učitala.")); } }));
        });
      });
    }, Promise.resolve());
    return qrReady;
  }
  function codesList(codes) { return h("ul", { class: "codes" }, codes.map(function (c) { return h("li", null, h("code", { text: c })); })); }

  // ---- layout
  var tabs = [["stations", "Postaje"], ["data", "Uvoz / izvoz"], ["audit", "Dnevnik"], ["security", "Sigurnost"]];
  var current = "stations";
  try { current = sessionStorage.getItem("pn-tab") || current; } catch (e) { /* ignore */ }
  var nav = h("nav", { class: "tabs", role: "tablist" });
  var view = h("section", { class: "tab-view" });
  app.replaceChildren(nav, view);

  function renderTabs() {
    nav.replaceChildren.apply(nav, tabs.map(function (t) {
      return h("button", { type: "button", role: "tab", "aria-selected": String(t[0] === current), class: "tab" + (t[0] === current ? " active" : ""),
        onclick: function () { current = t[0]; try { sessionStorage.setItem("pn-tab", current); } catch (e) { /* ignore */ } show(); } }, t[1]);
    }));
  }
  function show() {
    renderTabs();
    ({ stations: viewStations, data: viewData, audit: viewAudit, security: viewSecurity })[current]();
  }

  // ---- stations
  function load() {
    return api("list").then(function (d) { state.stations = d.stations; });
  }

  function viewStations() {
    var search = input({ type: "search", placeholder: "Traži postaju, nadimak, županiju…", value: state.q,
      oninput: function (e) { state.q = e.target.value; renderList(); } });
    var list = h("div", { class: "rows" });
    var formSlot = h("div");
    var count = h("p", { class: "muted small" });
    var which = h("select", { class: "input toolbar-filter", "aria-label": "Prikaz", onchange: function (e) { state.which = e.target.value; renderList(); } },
      h("option", { value: "" }, "Sve postaje"),
      h("option", { value: "closed", selected: state.which === "closed" }, "Privremeno zatvorene / najavljene"),
      h("option", { value: "notice", selected: state.which === "notice" }, "Napomena s INA.hr za provjeru"));
    view.replaceChildren(
      h("div", { class: "toolbar" }, search, which, h("button", { class: "btn-primary", type: "button", onclick: function () { state.editing = "new"; renderList(); } }, "+ Nova postaja")),
      count, formSlot, list
    );
    function renderList() {
      var n = norm(state.q.trim());
      var shown = state.stations.filter(function (s) {
        if (state.which === "closed" && s.status !== "closed") return false;
        if (state.which === "notice" && !s.ina_notice) return false;
        return !n || norm([s.name, s.aliases, s.county, s.city, s.address, s.phone, s.status_note].join(" ")).indexOf(n) !== -1;
      });
      var notices = state.stations.filter(function (s) { return s.ina_notice; }).length;
      count.textContent = "Ukupno: " + state.stations.length + " · prikazano: " + shown.length
        + (notices ? " · napomena s INA.hr za provjeru: " + notices : "");
      formSlot.replaceChildren(state.editing === "new" ? stationForm(null, done) : "");
      list.replaceChildren.apply(list, shown.map(function (s) {
        if (state.editing === s.id) return h("div", { class: "row editing" }, stationForm(s, done));
        return h("div", { class: "row" },
          h("div", { class: "row-main" },
            h("p", { class: "row-title" }, s.name, " ", h("span", { class: "muted small", text: "· " + (s.county || "bez županije") })),
            h("p", { class: "muted small truncate", text: s.aliases ? "Nadimci: " + s.aliases : "Bez nadimaka" }),
            statusBadge(s),
            s.ina_notice ? h("p", { class: "ina-notice", title: "Pronađeno na ina.hr pri automatskoj provjeri. Provjerite i po potrebi označite postaju kao privremeno zatvorenu." },
              "⚠ INA.hr: " + s.ina_notice) : null),
          h("button", { class: "btn-ghost", type: "button", onclick: function () { state.editing = s.id; renderList(); } }, "Uredi"),
          h("button", { class: "btn-danger", type: "button", onclick: function () { del(s); } }, "Obriši"));
      }));
      if (!shown.length) list.append(h("p", { class: "muted pad", text: "Nema rezultata." }));
    }
    function done(saved) {
      state.editing = null;
      if (saved) load().then(renderList, fail); else renderList();
    }
    function del(s) {
      if (!confirm('Obrisati "' + s.name + '"? Promjena se odmah objavljuje.')) return;
      api("delete", { id: s.id }).then(function () { toast("Obrisano i objavljeno."); return load(); }).then(renderList, fail);
    }
    renderList();
  }

  function stationForm(s, onDone) {
    var f = s ? JSON.parse(JSON.stringify(s)) : { name: "", address: "", city: "", postal_code: "", phone: "", county: "", aliases: "", ina_url: "", ina_region: "", sales_area: "", working_hours: [],
      status: "open", status_note: "", closed_from: "", closed_until: "" };
    f.status = f.status || "open";
    var aliases = f.aliases.split("/").map(function (a) { return a.trim(); }).filter(Boolean);
    function bind(key, attrs) {
      return input(Object.assign({ value: f[key], oninput: function (e) { f[key] = e.target.value; } }, attrs || {}));
    }
    var county = h("select", { class: "input", onchange: function (e) { f.county = e.target.value; } },
      h("option", { value: "" }, "— Županija —"),
      boot.counties.map(function (c) { return h("option", { value: c, selected: c === f.county }, c); }));

    var chips = h("div", { class: "chips" });
    var newAlias = input({ placeholder: "Novi nadimak…", onkeydown: function (e) { if (e.key === "Enter") { e.preventDefault(); addAlias(); } } });
    function addAlias() {
      var v = newAlias.value.trim().replace(/\//g, "-");
      if (v && aliases.indexOf(v) === -1) aliases.push(v);
      newAlias.value = "";
      renderChips();
      newAlias.focus();
    }
    function renderChips() {
      chips.replaceChildren.apply(chips, aliases.length ? aliases.map(function (a, i) {
        return h("span", { class: "chip" }, a, h("button", { type: "button", "aria-label": "Ukloni " + a, onclick: function () { aliases.splice(i, 1); renderChips(); } }, "×"));
      }) : [h("span", { class: "muted small", text: "Nema nadimaka" })]);
    }
    renderChips();

    var hoursBox = h("div", { class: "hours" });
    function renderHours() {
      hoursBox.replaceChildren.apply(hoursBox, f.working_hours.map(function (row, i) {
        function cell(k, ph) { return input({ value: row[k] || "", placeholder: ph, "aria-label": ph, oninput: function (e) { row[k] = e.target.value; } }); }
        return h("div", { class: "hours-row" }, cell("label", "Naziv (npr. Redovno)"), cell("week", "Pon–Pet"), cell("sat", "Subota"), cell("sun", "Nedjelja"), cell("hol", "Blagdani"),
          h("button", { type: "button", class: "btn-ghost", "aria-label": "Ukloni red", onclick: function () { f.working_hours.splice(i, 1); renderHours(); } }, "×"));
      }).concat([h("button", { type: "button", class: "btn-ghost", onclick: function () { f.working_hours.push({ label: "Redovno", week: "", sat: "", sun: "", hol: "" }); renderHours(); } }, "+ Red radnog vremena")]));
    }
    renderHours();

    // Temporary closure (works on the station, renovation…)
    var closedFields = h("div", { class: "status-fields" },
      field("Napomena (vidljiva na stranici)", bind("status_note", { maxlength: 255, placeholder: "npr. Radovi na postaji" })),
      field("Od (prazno = odmah)", bind("closed_from", { type: "date" })),
      field("Do (prazno = do daljnjeg)", bind("closed_until", { type: "date" })));
    function syncStatus() { closedFields.hidden = f.status !== "closed"; }
    var statusSel = h("select", { class: "input", onchange: function (e) { f.status = e.target.value; syncStatus(); } },
      h("option", { value: "open", selected: f.status !== "closed" }, "Otvorena"),
      h("option", { value: "closed", selected: f.status === "closed" }, "Privremeno zatvorena"));
    var statusBox = h("div", { class: "span2 status-box" },
      h("p", { class: "label", text: "Status postaje" }),
      s && s.ina_notice ? h("p", { class: "ina-notice", text: "⚠ Na ina.hr piše: " + s.ina_notice }) : null,
      s && s.status === "closed" && s.status_source === "ina" ? h("p", { class: "muted small", text: "Zatvoreno automatski prema tablici radnog vremena na ina.hr – ponovno će se otvoriti samo kad ina.hr opet prikaže radno vrijeme. Ako upišete datume, zatvaranje postaje vaše i sinkronizacija ga više ne mijenja." }) : null,
      statusSel, closedFields,
      h("p", { class: "muted small", text: "Zatvorena postaja ostaje na popisu s oznakom, a posjetitelji je mogu sakriti filtrom „Samo otvorene”. Nakon datuma „do” oznaka sama nestaje." }));
    syncStatus();

    var saveBtn = h("button", { class: "btn-primary", type: "submit" }, "Spremi i objavi");
    var form = h("form", { class: "station-form", onsubmit: function (e) {
      e.preventDefault();
      if (!f.name.trim()) return toast("Naziv je obavezan.", true);
      f.aliases = aliases.join(" / ");
      saveBtn.disabled = true;
      api("save", f).then(function () { toast("Spremljeno i objavljeno."); onDone(true); }, function (err) { saveBtn.disabled = false; fail(err); });
    } },
      field("Naziv *", bind("name", { required: true, maxlength: 190 })),
      field("Adresa", bind("address")),
      field("Grad / naselje", bind("city")),
      field("Poštanski broj", bind("postal_code", { inputmode: "numeric" })),
      field("Telefon", bind("phone", { type: "tel" })),
      field("Županija", county),
      field("INA.hr poveznica", bind("ina_url", { type: "url", placeholder: "https://www.ina.hr/station/…/" }), "span2"),
      statusBox,
      h("div", { class: "span2" }, h("p", { class: "label", text: "Kako znaju zvati postaju" }), chips,
        h("div", { class: "inline" }, newAlias, h("button", { type: "button", class: "btn-ghost", onclick: addAlias }, "Dodaj"))),
      h("div", { class: "span2" }, h("p", { class: "label", text: "Radno vrijeme" }),
        h("p", { class: "muted small", text: "Ako je postavljen bin/sync-ina-hours.php, radno vrijeme se automatski osvježava s ina.hr." }), hoursBox),
      h("div", { class: "span2 inline" }, saveBtn, h("button", { type: "button", class: "btn-ghost", onclick: function () { onDone(false); } }, "Odustani"))
    );
    // Focus the first field once the form is in the page, unless the user is already typing in it.
    setTimeout(function () { if (!form.contains(document.activeElement)) form.querySelector("input").focus(); });
    return form;
  }

  // ---- import / export
  function viewData() {
    var file = h("input", { type: "file", accept: ".json,.csv,application/json,text/csv", class: "input" });
    var result = h("div");
    var btn = h("button", { class: "btn-primary", type: "submit" }, "Uvezi");
    view.replaceChildren(
      h("div", { class: "panel" },
        h("h2", { text: "Izvoz" }),
        h("p", { class: "muted small", text: "Sigurnosna kopija cijelog popisa." }),
        h("div", { class: "inline" },
          h("a", { class: "btn-ghost", href: boot.api + "?action=export&format=json" }, "Preuzmi JSON"),
          h("a", { class: "btn-ghost", href: boot.api + "?action=export&format=csv" }, "Preuzmi CSV (Excel)"))),
      h("form", { class: "panel", onsubmit: function (e) {
        e.preventDefault();
        if (!file.files[0]) return toast("Odaberite datoteku.", true);
        if (!confirm("Uvoz ažurira postojeće postaje istog naziva i dodaje nove. Nastaviti?")) return;
        var fd = new FormData();
        fd.append("file", file.files[0]);
        btn.disabled = true;
        api("import", fd, true).then(function (d) {
          btn.disabled = false;
          result.replaceChildren(h("p", { class: "flash flash-info", text: "Novih: " + d.created + " · ažuriranih: " + d.updated + " · grešaka: " + d.errors.length }),
            d.errors.length ? h("ul", { class: "muted small" }, d.errors.map(function (x) { return h("li", { text: x }); })) : "");
          return load();
        }).catch(function (err) { btn.disabled = false; fail(err); });
      } },
        h("h2", { text: "Uvoz" }),
        h("p", { class: "muted small", text: "JSON (izvoz ovog sustava ili izvoz tablice stations iz Supabase) ili CSV sa zaglavljem: name;address;phone;aliases;county;city;postal_code;ina_region;sales_area;ina_url;working_hours. Postaje se spajaju po nazivu; polja kojih nema u datoteci ostaju nepromijenjena." }),
        file, h("div", { class: "inline" }, btn), result),
      h("div", { class: "panel" },
        h("h2", { text: "Objava" }),
        h("p", { class: "muted small", text: "Svaka promjena automatski ponovno generira javnu stranicu (index.html i data/stations.json). Ovdje to možete pokrenuti i ručno, npr. nakon izmjene predloška." }),
        h("button", { class: "btn-ghost", type: "button", onclick: function () { api("publish", {}).then(function () { toast("Javna stranica je ponovno generirana."); }, fail); } }, "Ponovno objavi"))
    );
  }

  // ---- audit log
  var ACTIONS = { create: "Dodano", update: "Izmijenjeno", "delete": "Obrisano", "import": "Uvoz", publish: "Objava", password: "Promjena lozinke",
    "recovery-codes": "Novi rezervni kodovi", "totp-rotate": "Nova 2FA aplikacija", "ina-sync": "INA radno vrijeme", "ina-notice": "INA.hr: napomena o zatvaranju", "ina-close": "INA.hr: automatski zatvoreno", "ina-reopen": "INA.hr: automatski ponovno otvoreno", "totp-reset": "2FA resetirana (CLI)" };
  function viewAudit() {
    view.replaceChildren(h("p", { class: "muted", text: "Učitavanje…" }));
    api("audit").then(function (d) {
      view.replaceChildren(h("div", { class: "panel table-wrap" }, h("table", { class: "table" },
        h("thead", null, h("tr", null, ["Vrijeme", "Radnja", "Postaja", "Detalji", "IP"].map(function (t) { return h("th", { text: t }); }))),
        h("tbody", null, d.entries.length ? d.entries.map(function (e) {
          return h("tr", null, h("td", { text: e.created_at }), h("td", { text: ACTIONS[e.action] || e.action }), h("td", { text: e.station_name }), h("td", { text: e.detail }), h("td", { text: e.ip }));
        }) : h("tr", null, h("td", { colspan: 5, class: "muted", text: "Još nema zapisa." }))))));
    }, fail);
  }

  // ---- security
  function viewSecurity() {
    var pw = { current: input({ type: "password", autocomplete: "current-password", required: true }), "new": input({ type: "password", autocomplete: "new-password", minlength: 12, required: true }),
      repeat: input({ type: "password", autocomplete: "new-password", minlength: 12, required: true }), code: input({ inputmode: "numeric", autocomplete: "one-time-code", maxlength: 6, required: true }) };
    var rc = { code: input({ inputmode: "numeric", autocomplete: "one-time-code", maxlength: 6, required: true }), out: h("div") };
    var rot = { current: input({ type: "password", autocomplete: "current-password", required: true }), code: input({ inputmode: "numeric", maxlength: 6, required: true }), out: h("div") };

    view.replaceChildren(
      h("div", { class: "panel" },
        h("h2", { text: "Dvostruka provjera (2FA)" }),
        h("p", { class: "muted small", text: "Obavezna i ne može se isključiti. Prijavljeni ste kao " + boot.email + ". Neiskorištenih rezervnih kodova: " + boot.recoveryLeft + "." })),
      h("form", { class: "panel", onsubmit: function (e) {
        e.preventDefault();
        api("change-password", { current: pw.current.value, "new": pw["new"].value, repeat: pw.repeat.value, code: pw.code.value })
          .then(function () { toast("Lozinka je promijenjena. Ostale sesije su odjavljene."); viewSecurity(); }, fail);
      } }, h("h2", { text: "Promjena lozinke" }),
        h("input", { type: "text", name: "username", autocomplete: "username", value: boot.email, hidden: true }),
        h("div", { class: "form-grid" }, field("Trenutna lozinka", pw.current), field("Kod iz aplikacije", pw.code), field("Nova lozinka (min. 12)", pw["new"]), field("Ponovite novu lozinku", pw.repeat)),
        h("button", { class: "btn-primary", type: "submit" }, "Promijeni lozinku")),
      h("form", { class: "panel", onsubmit: function (e) {
        e.preventDefault();
        api("recovery-codes", { code: rc.code.value }).then(function (d) {
          boot.recoveryLeft = d.codes.length;
          rc.out.replaceChildren(h("p", { class: "flash flash-info", text: "Novi rezervni kodovi (stari više ne vrijede). Spremite ih odmah:" }), codesList(d.codes));
        }, fail);
      } }, h("h2", { text: "Rezervni kodovi" }),
        h("p", { class: "muted small", text: "Jednokratni kodovi za prijavu ako izgubite mobitel. Generiranje novih poništava stare." }),
        h("div", { class: "inline" }, field("Kod iz aplikacije", rc.code), h("button", { class: "btn-ghost", type: "submit" }, "Generiraj nove")), rc.out),
      h("form", { class: "panel", onsubmit: function (e) {
        e.preventDefault();
        api("totp-start", { current: rot.current.value, code: rot.code.value }).then(startRotation, fail);
      } }, h("h2", { text: "Premjesti 2FA na novi uređaj" }),
        h("p", { class: "muted small", text: "Stara aplikacija vrijedi dok ne potvrdite novu." }),
        h("div", { class: "form-grid" }, field("Trenutna lozinka", rot.current), field("Kod iz trenutne aplikacije", rot.code)),
        h("button", { class: "btn-ghost", type: "submit" }, "Nastavi"), rot.out)
    );

    function startRotation(d) {
      var qr = h("div", { class: "qr", role: "img", "aria-label": "QR kod za novu aplikaciju" });
      var code = input({ inputmode: "numeric", autocomplete: "one-time-code", maxlength: 6, required: true });
      rot.out.replaceChildren(h("div", { class: "rotate" },
        h("p", { class: "small", text: "Skenirajte QR kod novom aplikacijom i unesite kod koji prikaže:" }), qr,
        h("details", { class: "small" }, h("summary", { text: "Ručni unos ključa" }), h("code", { class: "secret", text: d.secret })),
        h("div", { class: "inline" }, field("Kod iz nove aplikacije", code), h("button", { class: "btn-primary", type: "button", onclick: function () {
          api("totp-confirm", { code: code.value }).then(function (r) {
            boot.recoveryLeft = r.codes.length;
            rot.out.replaceChildren(h("p", { class: "flash flash-info", text: "Nova aplikacija je aktivna. Novi rezervni kodovi:" }), codesList(r.codes));
          }, fail);
        } }, "Potvrdi"))));
      loadQr().then(function () { window.renderQr(qr, d.uri); }, fail);
    }
  }

  load().then(show, function (err) { app.replaceChildren(h("p", { class: "flash flash-error", text: err.message })); });
})();
