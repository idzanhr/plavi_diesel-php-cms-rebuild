// Runs synchronously in <head> so the saved theme applies before first paint.
(function () {
  try {
    var t = localStorage.getItem("plava-nafta-theme");
    if (t !== "dark" && t !== "light") t = window.matchMedia && matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
    document.documentElement.classList.toggle("dark", t === "dark");
    document.documentElement.style.colorScheme = t;
  } catch (e) { /* storage blocked: fall back to light */ }
})();
