(function () {
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

  function codeText(code) {
    return code.textContent.replace(/\n+$/, "");
  }

  function addCopyButton(block) {
    if (block.querySelector(".sa-copy-code-button")) {
      return;
    }

    var code = block.querySelector("pre code");

    if (!code) {
      return;
    }

    block.classList.add("sa-code-block");

    var button = document.createElement("button");
    button.type = "button";
    button.className = "sa-copy-code-button";
    button.setAttribute("aria-label", "Copy code");
    button.setAttribute("title", "Copy code");

    button.addEventListener("click", function () {
      copyText(codeText(code)).then(function () {
        button.setAttribute("aria-label", "Copied");
        button.setAttribute("title", "Copied");
        button.classList.add("is-copied");

        window.setTimeout(function () {
          button.setAttribute("aria-label", "Copy code");
          button.setAttribute("title", "Copy code");
          button.classList.remove("is-copied");
        }, 1400);
      });
    });

    block.appendChild(button);
  }

  document.addEventListener("DOMContentLoaded", function () {
    document
      .querySelectorAll("div.highlighter-rouge, figure.highlight")
      .forEach(addCopyButton);
  });
}());
