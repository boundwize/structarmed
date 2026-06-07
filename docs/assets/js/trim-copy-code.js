(function () {
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

  document.addEventListener("click", function (event) {
    var button = event.target.closest("button");

    if (!button || !button.closest("div.highlighter-rouge, figure.highlight")) {
      return;
    }

    trimNextCopy = true;

    window.setTimeout(function () {
      trimNextCopy = false;
    }, 1000);
  }, true);
}());
