/* ==========================================================================
   Report lightbox — auto-wires every content image to a click-to-enlarge modal.
   Vanilla JS. Works on hand-written pages and pandoc-built appendices.

   Opt out of an image with class="no-lightbox" (e.g. the header logo, which
   lives outside the content area and is skipped anyway).
   ========================================================================== */

(function () {
  function build() {
    // One shared overlay for the whole page.
    var overlay = document.createElement('div');
    overlay.className = 'lb-overlay';
    overlay.innerHTML =
      '<button class="lb-close" aria-label="Close">&times;</button>' +
      '<img alt="">' +
      '<div class="lb-caption"></div>';
    document.body.appendChild(overlay);

    var bigImg  = overlay.querySelector('img');
    var caption = overlay.querySelector('.lb-caption');

    function open(src, alt) {
      bigImg.src = src;
      bigImg.alt = alt || '';
      caption.textContent = alt || '';
      overlay.classList.add('is-open');
    }
    function close() {
      overlay.classList.remove('is-open');
      bigImg.src = '';
    }

    overlay.addEventListener('click', close);
    overlay.querySelector('.lb-close').addEventListener('click', close);
    // clicking the image itself shouldn't close (only the backdrop / button)
    bigImg.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') close();
    });

    // Wire every content image (skip the header logo + any opt-outs).
    var imgs = document.querySelectorAll('.page img, main img');
    imgs.forEach(function (img) {
      if (img.classList.contains('no-lightbox')) return;
      if (img.closest('.report-header')) return;
      img.style.cursor = 'zoom-in';
      img.addEventListener('click', function () {
        open(img.currentSrc || img.src, img.alt);
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', build);
  } else {
    build();
  }
})();

/* ==========================================================================
   Video play overlay — gives each <video> a big, prominent centre play button.
   Wraps the video in a .video-wrap and injects a .video-play button (styled in
   report.css). The button hides while playing and reappears when paused.
   ========================================================================== */

(function () {
  function enhance() {
    document.querySelectorAll('video').forEach(function (v) {
      if (v.closest('.video-wrap')) return;            // already done

      var wrap = document.createElement('div');
      wrap.className = 'video-wrap';
      v.parentNode.insertBefore(wrap, v);
      wrap.appendChild(v);

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'video-play';
      btn.setAttribute('aria-label', 'Play video');
      wrap.appendChild(btn);

      btn.addEventListener('click', function () { v.play(); });
      v.addEventListener('play',  function () { wrap.classList.add('playing'); });
      v.addEventListener('pause', function () { wrap.classList.remove('playing'); });
      v.addEventListener('ended', function () { wrap.classList.remove('playing'); });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhance);
  } else {
    enhance();
  }
})();
