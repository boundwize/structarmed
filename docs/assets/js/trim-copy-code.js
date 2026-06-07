(function () {
  var codeBlockSelector = "div.highlighter-rouge, figure.highlight";
  var trimNextCopy = false;
  var writeText = navigator.clipboard && navigator.clipboard.writeText;

  if (writeText) {
    navigator.clipboard.writeText = function (text) {
      if (trimNextCopy && typeof text === "string") {
        text = text.replace(/\n+$/, "");
      }

      trimNextCopy = false;

      return writeText.call(navigator.clipboard, text);
    };
  }

  function codeText(block) {
    var code = block && block.querySelector("pre code");

    return code ? code.textContent.replace(/\n+$/, "") : "";
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }

    var textarea = document.createElement("textarea");
    textarea.value = text;
    textarea.setAttribute("readonly", "");
    textarea.style.position = "fixed";
    textarea.style.top = "-9999px";
    document.body.appendChild(textarea);
    textarea.select();

    try {
      document.execCommand("copy");
      return Promise.resolve();
    } finally {
      document.body.removeChild(textarea);
    }
  }

  function addFallbackButton(block) {
    if (block.querySelector("button")) {
      return;
    }

    var code = block.querySelector("pre code");

    if (!code) {
      return;
    }

    var button = document.createElement("button");
    button.type = "button";
    button.className = "sa-copy-code-fallback";
    button.textContent = "Copy";
    button.setAttribute("aria-label", "Copy code");
    button.setAttribute("title", "Copy code");

    block.appendChild(button);
  }

  document.addEventListener("click", function (event) {
    var button = event.target.closest("button");
    var block = button && button.closest(codeBlockSelector);

    if (!block) {
      return;
    }

    if (button.classList.contains("sa-copy-code-fallback")) {
      event.preventDefault();

      copyText(codeText(block)).then(function () {
        button.textContent = "Copied";
        button.classList.add("is-copied");

        window.setTimeout(function () {
          button.textContent = "Copy";
          button.classList.remove("is-copied");
        }, 1400);
      });

      return;
    }

    trimNextCopy = true;

    window.setTimeout(function () {
      trimNextCopy = false;
    }, 1000);
  }, true);

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(codeBlockSelector).forEach(addFallbackButton);
  });
}());
