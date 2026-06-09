/*
 * Story Image Gallery lightbox (v7)
 *
 * Reads the gallery items embedded as JSON (#gallery-data) and mounts a lightbox
 * (#gallery-lightbox) with prev/next (wrap-around), keyboard navigation
 * (left/right/Esc), and a filmstrip whose active thumbnail is highlighted and
 * scrolled into view.
 *
 * On the gallery page it also auto-wires the tile grid (#gallery-grid). Other
 * pages (e.g. the Story Editor scene thumbnails) reuse the same lightbox via the
 * exposed API: Gallery.open(index) and Gallery.indexOfSrc(src).
 *
 * Vanilla JS, no framework — matches the rest of the app.
 */
window.Gallery = (function () {
    var ctrl = null;

    // Build the lightbox controller for a given items array.
    function mount(items) {
        var box = document.getElementById('gallery-lightbox');
        if (!box) return null;

        var imgEl    = document.getElementById('gallery-lightbox-img');
        var titleEl  = document.getElementById('gallery-lightbox-title');
        var strip    = document.getElementById('gallery-filmstrip');
        var closeBtn = box.querySelector('.gallery-lightbox-close');
        var prevBtn  = box.querySelector('.gallery-nav-prev');
        var nextBtn  = box.querySelector('.gallery-nav-next');

        var current = 0;

        // Build the filmstrip once.
        var stripThumbs = items.map(function (it, i) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'gallery-filmstrip-thumb';
            btn.title = it.title;
            var img = document.createElement('img');
            img.src = it.src;
            img.alt = it.title;
            img.loading = 'lazy';
            btn.appendChild(img);
            btn.addEventListener('click', function () { show(i); });
            strip.appendChild(btn);
            return btn;
        });

        function show(i) {
            current = (i + items.length) % items.length;   // wrap-around
            var it = items[current];
            imgEl.src = it.src;
            imgEl.alt = it.title;
            titleEl.textContent = it.title;
            stripThumbs.forEach(function (t, idx) {
                t.classList.toggle('is-active', idx === current);
            });
            var active = stripThumbs[current];
            if (active) active.scrollIntoView({ inline: 'center', block: 'nearest' });
        }

        function openAt(i) {
            show(i);
            box.hidden = false;
            document.addEventListener('keydown', onKey);
        }
        function close() {
            box.hidden = true;
            imgEl.src = '';
            document.removeEventListener('keydown', onKey);
        }
        function next() { show(current + 1); }
        function prev() { show(current - 1); }

        function onKey(e) {
            if (e.key === 'Escape')          { close(); }
            else if (e.key === 'ArrowRight') { next(); }
            else if (e.key === 'ArrowLeft')  { prev(); }
        }

        closeBtn.addEventListener('click', close);
        nextBtn.addEventListener('click', next);
        prevBtn.addEventListener('click', prev);

        // Click on the dimmed backdrop (outside the image/controls) closes.
        box.addEventListener('click', function (e) {
            if (e.target === box || e.target.classList.contains('gallery-lightbox-stage')) close();
        });

        function indexOfSrc(src) {
            for (var i = 0; i < items.length; i++) {
                if (items[i].src === src) return i;
            }
            return -1;
        }

        return { open: openAt, indexOfSrc: indexOfSrc };
    }

    function init() {
        var dataEl = document.getElementById('gallery-data');
        if (!dataEl) return;
        var items;
        try { items = JSON.parse(dataEl.textContent || '[]'); }
        catch (e) { items = []; }
        if (!items.length) return;

        ctrl = mount(items);
        if (!ctrl) return;

        // Gallery page: clicking a tile opens the lightbox at that index.
        var grid = document.getElementById('gallery-grid');
        if (grid) {
            grid.querySelectorAll('.gallery-tile').forEach(function (tile) {
                tile.addEventListener('click', function () {
                    ctrl.open(parseInt(tile.dataset.index, 10) || 0);
                });
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    return {
        open:       function (i)   { if (ctrl) ctrl.open(i); },
        indexOfSrc: function (src) { return ctrl ? ctrl.indexOfSrc(src) : -1; },
        ready:      function ()    { return !!ctrl; }
    };
})();
