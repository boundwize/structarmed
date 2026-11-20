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

  var systemDark = window.matchMedia ? window.matchMedia("(prefers-color-scheme: dark)") : null;

  function systemTheme() {
    return systemDark && systemDark.matches ? "dark" : "light";
  }

  // The system appearance is the default; a stored choice only exists while
  // the visitor has picked the opposite of it.
  function preferredTheme() {
    var stored = storedTheme();

    if (stored === "dark" || stored === "light") {
      return stored;
    }

    return systemTheme();
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

  // The theme's own stylesheet is the light one. The dark one is added next to
  // it and the two are toggled through `sheet.disabled`, so the page is never
  // left without a loaded stylesheet (which would flash unstyled content).
  var lightLink = document.querySelector('link[rel="stylesheet"]');
  var darkHref = lightLink.getAttribute("href").replace(/just-the-docs-[a-z]+\.css/, "just-the-docs-dark.css");
  var darkLinkId = "sa-dark-stylesheet";

  function setDisabled(link, disabled) {
    if (link && link.sheet) {
      link.sheet.disabled = disabled;
    }
  }

  function showTheme(theme) {
    var darkLink = document.getElementById(darkLinkId);

    setDisabled(darkLink, theme !== "dark");
    // Light stays on until the dark stylesheet has really loaded.
    setDisabled(lightLink, theme === "dark" && Boolean(darkLink && darkLink.sheet));

    document.documentElement.setAttribute("data-sa-theme", theme);
    document.querySelectorAll(toggleSelector).forEach(updateToggle);
  }

  function applyTheme(theme) {
    if (theme !== "dark" || document.getElementById(darkLinkId)) {
      showTheme(theme);

      return;
    }

    var darkLink = document.createElement("link");

    darkLink.rel = "stylesheet";
    darkLink.id = darkLinkId;
    darkLink.href = darkHref;
    darkLink.onload = function () {
      showTheme(currentChoice);
    };

    lightLink.insertAdjacentElement("afterend", darkLink);
  }

  var currentChoice = preferredTheme();

  if (currentChoice === "dark" && document.readyState === "loading") {
    // Written by the parser while still in <head>, so it blocks the first
    // paint like any other stylesheet and a dark page never flashes light.
    document.write('<link rel="stylesheet" id="' + darkLinkId + '" href="' + darkHref + '">');
    document.getElementById(darkLinkId).onload = function () {
      showTheme(currentChoice);
    };
  }

  applyTheme(currentChoice);

  document.addEventListener("click", function (event) {
    var button = event.target.closest(toggleSelector);

    if (!button) {
      return;
    }

    var theme = currentChoice === "dark" ? "light" : "dark";

    currentChoice = theme;

    try {
      // Picking what the system already shows goes back to following it.
      if (theme === systemTheme()) {
        window.localStorage.removeItem(storageKey);
      } else {
        window.localStorage.setItem(storageKey, theme);
      }
    } catch (error) {
      // The choice still applies to this page view.
    }

    applyTheme(theme);
  });

  // Follow a system appearance change while the page is open, unless the
  // visitor picked a scheme themselves.
  if (systemDark && systemDark.addEventListener) {
    systemDark.addEventListener("change", function () {
      if (storedTheme() !== null) {
        return;
      }

      currentChoice = systemTheme();
      applyTheme(currentChoice);
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(toggleSelector).forEach(updateToggle);
  });
}());
