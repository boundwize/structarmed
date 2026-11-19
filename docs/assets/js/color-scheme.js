(function () {
  var storageKey = "sa-color-scheme";
  var toggleSelector = "[data-sa-theme-toggle]";

  function storedTheme() {
    try {
      return window.localStorage.getItem(storageKey);
    } catch (error) {
      return null;
    }
  }

  function preferredTheme() {
    var stored = storedTheme();

    if (stored === "dark" || stored === "light") {
      return stored;
    }

    return window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
  }

  function currentTheme() {
    return document.documentElement.getAttribute("data-sa-theme") === "dark" ? "dark" : "light";
  }

  function updateToggle(button) {
    var next = currentTheme() === "dark" ? "light" : "dark";

    button.textContent = next === "dark" ? "Dark" : "Light";
    button.setAttribute("aria-label", "Switch to " + next + " color scheme");
    button.setAttribute("title", "Switch to " + next + " color scheme");
  }

  function applyTheme(theme) {
    if (theme !== currentTheme() && window.jtd && typeof window.jtd.setTheme === "function") {
      window.jtd.setTheme(theme);
    }

    document.documentElement.setAttribute("data-sa-theme", theme);
    document.querySelectorAll(toggleSelector).forEach(updateToggle);
  }

  // Runs in <head>, before the first paint, so a dark page never flashes light.
  applyTheme(preferredTheme());

  document.addEventListener("click", function (event) {
    var button = event.target.closest(toggleSelector);

    if (!button) {
      return;
    }

    var theme = currentTheme() === "dark" ? "light" : "dark";

    try {
      window.localStorage.setItem(storageKey, theme);
    } catch (error) {
      // The choice still applies to this page view.
    }

    applyTheme(theme);
  });

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(toggleSelector).forEach(updateToggle);
  });
}());
