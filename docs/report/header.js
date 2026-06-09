/* ==========================================================================
   CYOA Maker With AI — Report header (single source)

   Edit this ONE file to change the header on every page (main pages and
   pandoc-built appendices). No rebuild needed.

   How it works:
   - Every page has an empty <div id="report-header"></div> placeholder.
   - This script fills it on load.
   - The header markup is INLINE below (not fetched) so it works when the
     report is opened straight from disk (file://), where fetch() is blocked.
   - Paths to the logo / index are derived from THIS script's own location,
     so the header works no matter how deep the page is (e.g. /appendix/).
   ========================================================================== */

(function () {
  // Find where header.js lives, then resolve assets relative to docs/report/.
  var self = document.currentScript ||
             document.querySelector('script[src$="header.js"]');
  var base = self ? self.src.replace(/header\.js(\?.*)?$/, '') : '';

  var logo  = base + '../../images/app/logo_square.png';
  var index = base + 'index.html';

  var TITLE = 'CYOA.AI';
  var SUB1  = 'Choose Your Own Adventure - With AI';
  var SUB2  = 'Webtech 10/11, Term 3 Project';
  var SUB3  = '&copy;2026 Evan Praël';

  var html =
    '<header class="report-header">' +
      '<div class="report-header__inner">' +
        '<a class="report-header__home" href="' + index + '" aria-label="Report home">' +
          '<img class="report-header__logo" src="' + logo + '" alt="CYOA Maker logo">' +
        '</a>' +
        '<div class="report-header__text">' +
          '<a class="report-header__home" href="' + index + '">' +
            '<span class="report-header__title">' + TITLE + '</span>' +
          '</a>' +
          '<span class="report-header__sub--name">' + SUB1 + '</span>' +
          '<span class="report-header__sub report-header__sub">' + SUB2 + '</span>' +
          '<span class="report-header__sub report-header__sub">' + SUB3 + '</span>' +
        '</div>' +
      '</div>' +
    '</header>';

  function mount() {
    var slot = document.getElementById('report-header');
    if (slot) slot.innerHTML = html;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();
