// Public site behaviour: theme toggle, cookie banner, station search/filter.
// Station cards are pre-rendered into index.html by the CMS; this only shows/hides them.
(function () {
  "use strict";
  var THEME_KEY = "plava-nafta-theme";
  var CONSENT_KEY = "plava-nafta-cookie-consent";

  function store(key, value) {
    try { value === undefined ? localStorage.removeItem(key) : localStorage.setItem(key, value); } catch (e) { /* blocked */ }
  }
  function read(key) {
    try { return localStorage.getItem(key); } catch (e) { return null; }
  }

  // ---- theme
  var root = document.documentElement;
  function apply(theme) {
    root.classList.toggle("dark", theme === "dark");
    root.style.colorScheme = theme;
    document.querySelectorAll("[data-theme-toggle]").forEach(function (b) {
      var label = theme === "dark" ? "Prebaci na svijetli način" : "Prebaci na tamni način";
      b.setAttribute("aria-label", label);
      b.title = theme === "dark" ? "Svijetli način" : "Tamni način";
    });
  }
  apply(root.classList.contains("dark") ? "dark" : "light");
  document.querySelectorAll("[data-theme-toggle]").forEach(function (b) {
    b.addEventListener("click", function () {
      var next = root.classList.contains("dark") ? "light" : "dark";
      store(THEME_KEY, next);
      apply(next);
    });
  });
  if (!read(THEME_KEY) && window.matchMedia) {
    matchMedia("(prefers-color-scheme: dark)").addEventListener("change", function (e) {
      if (!read(THEME_KEY)) apply(e.matches ? "dark" : "light");
    });
  }

  // ---- cookie banner
  var banner = document.getElementById("cookie");
  if (banner) {
    if (!read(CONSENT_KEY)) banner.hidden = false;
    banner.querySelectorAll("[data-consent]").forEach(function (b) {
      b.addEventListener("click", function () {
        store(CONSENT_KEY, b.getAttribute("data-consent"));
        banner.hidden = true;
      });
    });
  }

  // ---- search
  var q = document.getElementById("q");
  var county = document.getElementById("county");
  var list = document.getElementById("stations");
  if (!q || !county || !list) return;

  function norm(s) {
    return s.toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/đ/g, "d");
  }
  var cards = Array.prototype.map.call(list.querySelectorAll(".card"), function (el) {
    return { el: el, county: el.getAttribute("data-county"), text: norm(el.getAttribute("data-search") || "") };
  });
  var shown = document.getElementById("shown");
  var empty = document.getElementById("empty");

  function filter() {
    var n = norm(q.value.trim());
    var c = county.value;
    var count = 0;
    cards.forEach(function (card) {
      var ok = (!c || card.county === c) && (!n || card.text.indexOf(n) !== -1);
      card.el.hidden = !ok;
      if (ok) count++;
    });
    shown.textContent = count;
    empty.hidden = count !== 0;
    var params = new URLSearchParams();
    if (q.value.trim()) params.set("q", q.value.trim());
    if (c) params.set("zupanija", c);
    var qs = params.toString();
    history.replaceState(null, "", qs ? "?" + qs : location.pathname);
  }

  // Restore a shared/bookmarked search (?q=…&zupanija=…).
  var initial = new URLSearchParams(location.search);
  if (initial.get("q")) q.value = initial.get("q");
  if (initial.get("zupanija")) county.value = initial.get("zupanija");
  q.addEventListener("input", filter);
  county.addEventListener("change", filter);
  if (q.value || county.value) filter();
})();
