/*
 * tree-view.js — Story Tree renderer (Phase 31)
 *
 * TreeView.build(scenes, choices, storyID, opts) → a .tree-container <div> holding
 * an SVG tree of the story. Each node links to the scene editor when the story is
 * a draft (opts.isDraft), otherwise to the player (so following a node on a
 * published story doesn't flip it back to draft). Every edge is a directed
 * connector with an arrowhead; long-span edges route through side gutters. The
 * container supports drag-to-pan and wheel-zoom.
 *
 *   scenes:  [{id, title, thumbnail, is_start}]
 *   choices: [{from_scene_id, to_scene_id, label}]
 *   opts:    { isDraft: bool }  (default: treated as draft → editor links)
 */
var TreeView = (function () {
    var SVGNS = 'http://www.w3.org/2000/svg';

    // --- Tile geometry: tune these to scale the scene tiles. ---
    // Keep SP_X/SP_Y a bit larger than NODE_W/NODE_H so tiles don't touch.
    // NOTE: the tile label/number font sizes are NOT here — they live in
    // styles/tree-view.css (.tree-node-title / .tree-node-num). Scale those
    // alongside NODE_W/NODE_H or the text won't grow with the boxes. (v1)
    var NODE_W = 132, NODE_H = 112;        // tile (scene box) width / height
    var THUMB  = 72;                       // thumbnail / fallback circle size
    var SP_X   = 168, SP_Y   = 168;        // horizontal / vertical tile spacing
    var PAD    = 40;                        // outer padding around the tree

    var PALETTE = ['#4a6fa5', '#a5644a', '#4aa56f', '#8a4aa5', '#a59b4a', '#4aa5a0', '#a54a72'];

    function el(name, attrs) {
        var n = document.createElementNS(SVGNS, name);
        if (attrs) for (var k in attrs) n.setAttribute(k, attrs[k]);
        return n;
    }

    function truncate(s, n) {
        s = s || '(untitled)';
        return s.length > n ? s.slice(0, n - 1) + '…' : s;
    }

    // Unit vector from a toward b ([x,y] points).
    function unit(a, b) { var dx = b[0] - a[0], dy = b[1] - a[1], m = Math.hypot(dx, dy) || 1; return [dx / m, dy / m]; }

    // Build an SVG path through waypoints with rounded corners of radius r — used
    // for the orthogonal gutter routes so their elbows match the curved style.
    function roundedPath(pts, r) {
        var d = 'M' + pts[0][0] + ',' + pts[0][1];
        for (var i = 1; i < pts.length - 1; i++) {
            var p1 = pts[i], u1 = unit(p1, pts[i - 1]), u2 = unit(p1, pts[i + 1]);
            d += ' L' + (p1[0] + u1[0] * r) + ',' + (p1[1] + u1[1] * r);
            d += ' Q' + p1[0] + ',' + p1[1] + ' ' + (p1[0] + u2[0] * r) + ',' + (p1[1] + u2[1] * r);
        }
        var last = pts[pts.length - 1];
        d += ' L' + last[0] + ',' + last[1];
        return d;
    }

    // BFS from the start scene; returns {depthOf, order:[ids per depth], forward:[], cycle:[]}
    function layout(scenes, choices) {
        var byId = {};
        scenes.forEach(function (s) { byId[s.id] = s; });

        var adj = {};
        scenes.forEach(function (s) { adj[s.id] = []; });
        choices.forEach(function (c) {
            if (adj[c.from_scene_id] && byId[c.to_scene_id]) adj[c.from_scene_id].push(c.to_scene_id);
        });

        var root = scenes.find(function (s) { return s.is_start; });
        if (!root) root = scenes[0];

        var depthOf = {}, parentEdge = {}, forward = [], cycle = [];
        var queue = [];
        if (root) { depthOf[root.id] = 0; queue.push(root.id); }

        while (queue.length) {
            var id = queue.shift();
            adj[id].forEach(function (to) {
                if (depthOf[to] === undefined) {
                    depthOf[to] = depthOf[id] + 1;
                    forward.push([id, to]);
                    queue.push(to);
                } else {
                    // Already placed → loop/cross edge (dashed)
                    cycle.push([id, to]);
                }
            });
        }

        // Any scenes unreachable from the root still get shown on a trailing tier.
        var maxDepth = 0;
        for (var k in depthOf) maxDepth = Math.max(maxDepth, depthOf[k]);
        scenes.forEach(function (s) {
            if (depthOf[s.id] === undefined) depthOf[s.id] = maxDepth + 1;
        });

        // Bucket ids by depth, preserving scene order for stable layout.
        var tiers = [];
        scenes.forEach(function (s) {
            var d = depthOf[s.id];
            (tiers[d] = tiers[d] || []).push(s.id);
        });

        return { byId: byId, depthOf: depthOf, tiers: tiers, forward: forward, cycle: cycle };
    }

    function buildSVG(scenes, choices, storyID, opts) {
        var L = layout(scenes, choices);

        var maxTier = 0;
        L.tiers.forEach(function (ids) { if (ids) maxTier = Math.max(maxTier, ids.length); });
        var contentW = Math.max(maxTier, 1) * SP_X;

        // Column offset of each node from the tree's centre line — known before the
        // final centreX, so we can pick a gutter side for long edges up front.
        var offset = {};
        L.tiers.forEach(function (ids) {
            if (!ids) return;
            var k = ids.length;
            ids.forEach(function (id, i) { offset[id] = (i - (k - 1) / 2) * SP_X; });
        });

        // Classify non-tree edges: those spanning >= 2 tiers route through a side
        // "gutter" beside the columns; the rest keep the inline curved routing.
        // Each gutter edge gets a lane index on its side so parallel runs stagger.
        var GUTTER_STEP = 26, GUTTER_GAP = 30;
        var gutterList = [], shortCycle = [], leftLanes = 0, rightLanes = 0;
        L.cycle.forEach(function (p) {
            if (Math.abs(L.depthOf[p[0]] - L.depthOf[p[1]]) >= 2) {
                var side = ((offset[p[0]] + offset[p[1]]) / 2 <= 0) ? 'left' : 'right';
                var lane = (side === 'left') ? leftLanes++ : rightLanes++;
                gutterList.push({ from: p[0], to: p[1], side: side, lane: lane });
            } else {
                shortCycle.push(p);
            }
        });

        // Reserve horizontal room for the gutter lanes so they never clip.
        var leftRoom  = leftLanes  > 0 ? leftLanes  * GUTTER_STEP + GUTTER_GAP : 0;
        var rightRoom = rightLanes > 0 ? rightLanes * GUTTER_STEP + GUTTER_GAP : 0;
        var centerX = PAD + leftRoom + contentW / 2;

        // Pixel position of each node's top-left.
        var pos = {};
        L.tiers.forEach(function (ids, depth) {
            if (!ids) return;
            var k = ids.length;
            ids.forEach(function (id, i) {
                var cx = centerX + (i - (k - 1) / 2) * SP_X;
                pos[id] = { x: cx - NODE_W / 2, y: PAD + depth * SP_Y };
            });
        });

        var width  = contentW + PAD * 2 + leftRoom + rightRoom;
        var height = (L.tiers.length) * SP_Y + PAD * 2;

        // Gutter lane x positions sit just outside the node columns.
        var minNodeX = Infinity, maxNodeX = -Infinity;
        Object.keys(pos).forEach(function (id) {
            minNodeX = Math.min(minNodeX, pos[id].x);
            maxNodeX = Math.max(maxNodeX, pos[id].x + NODE_W);
        });
        var leftBase = minNodeX - GUTTER_GAP, rightBase = maxNodeX + GUTTER_GAP;

        var svg = el('svg', { width: width, height: height, viewBox: '0 0 ' + width + ' ' + height });
        svg.dataset.baseW = width;
        svg.dataset.baseH = height;

        // Arrowhead marker. Sized in user-space units (not stroke-widths) so it
        // stays a sensible size against the thin edge stroke, and scales with the
        // tree when zoomed. orient:auto aims it along each edge's travel.
        var defs = el('defs');
        var marker = el('marker', {
            id: 'tv-arrow', viewBox: '0 0 10 10', refX: 9, refY: 5,
            markerWidth: 11, markerHeight: 11, orient: 'auto', markerUnits: 'userSpaceOnUse'
        });
        marker.appendChild(el('path', { d: 'M0,0 L10,5 L0,10 z', class: 'tree-arrow' }));
        defs.appendChild(marker);
        svg.appendChild(defs);

        var g = el('g', { class: 'tree-root' });
        svg.appendChild(g);

        // --- edges first so nodes paint on top ---
        // Inline edge: attach to the side of source/destination that faces the
        // other so the arrowhead reads naturally (down for forward, up for back,
        // sideways for same-row). A short straight run (≈ arrowhead length) is
        // appended in the entry direction so the arrowhead sits on a straight
        // segment instead of the fast-curving tail.
        var STUB = 12;
        function shortEdge(from, to) {
            var a = pos[from], b = pos[to];
            if (!a || !b) return;
            var ax = a.x + NODE_W / 2, bx = b.x + NODE_W / 2;
            var x1, y1, x2, y2, c1x, c1y, c2x, c2y, sx, sy;
            if (b.y >= a.y + NODE_H) {                 // destination lower → bottom → top
                x1 = ax; y1 = a.y + NODE_H; x2 = bx; y2 = b.y;
                var mv = (y1 + y2) / 2;
                c1x = x1; c1y = mv; c2x = x2; c2y = mv; sx = x2; sy = y2 - STUB;
            } else if (b.y + NODE_H <= a.y) {          // destination higher → top → bottom
                x1 = ax; y1 = a.y; x2 = bx; y2 = b.y + NODE_H;
                var mu = (y1 + y2) / 2;
                c1x = x1; c1y = mu; c2x = x2; c2y = mu; sx = x2; sy = y2 + STUB;
            } else {                                   // same row → side → opposite side
                if (bx >= ax) { x1 = a.x + NODE_W; x2 = b.x; sx = x2 - STUB; }
                else          { x1 = a.x; x2 = b.x + NODE_W; sx = x2 + STUB; }
                y1 = a.y + NODE_H / 2; y2 = b.y + NODE_H / 2;
                var mh = (x1 + x2) / 2;
                c1x = mh; c1y = y1; c2x = mh; c2y = y2; sy = y2;
            }
            var d = 'M' + x1 + ',' + y1 +
                    ' C' + c1x + ',' + c1y + ' ' + c2x + ',' + c2y + ' ' + sx + ',' + sy +
                    ' L' + x2 + ',' + y2;
            g.appendChild(el('path', { d: d, class: 'tree-edge', 'marker-end': 'url(#tv-arrow)' }));
        }

        // Long edge (>= 2 tiers): route out to a side gutter so it doesn't cross
        // the node columns. Orthogonal path with rounded corners; the final
        // straight run into the node keeps the arrowhead aligned.
        function gutterEdge(gd) {
            var S = pos[gd.from], D = pos[gd.to];
            if (!S || !D) return;
            var sy = S.y + NODE_H / 2, dy = D.y + NODE_H / 2, gx, ex, nx;
            if (gd.side === 'left') { gx = leftBase - gd.lane * GUTTER_STEP; ex = S.x; nx = D.x; }
            else                    { gx = rightBase + gd.lane * GUTTER_STEP; ex = S.x + NODE_W; nx = D.x + NODE_W; }
            var d = roundedPath([[ex, sy], [gx, sy], [gx, dy], [nx, dy]], 10);
            g.appendChild(el('path', { d: d, class: 'tree-edge', 'marker-end': 'url(#tv-arrow)' }));
        }

        L.forward.forEach(function (p) { shortEdge(p[0], p[1]); });
        shortCycle.forEach(function (p) { shortEdge(p[0], p[1]); });
        gutterList.forEach(gutterEdge);

        // --- nodes ---
        var idx = 0;
        scenes.forEach(function (s) {
            var p = pos[s.id];
            if (!p) return;
            var isCurrent = opts && opts.currentId != null && s.id == opts.currentId;
            var a = el('a', { class: isCurrent ? 'tree-node tree-node-current' : 'tree-node' });
            // Draft stories open the scene editor; published (non-draft) stories
            // open the scene in the player instead. (Following the editor link on a
            // published story would needlessly flip it back to draft.)
            var href = (opts && opts.isDraft === false)
                ? 'play.php?storyID=' + encodeURIComponent(storyID) + '&id=' + encodeURIComponent(s.id)
                : 'editor.php?action=edit_scene&storyID=' + encodeURIComponent(storyID) + '&sceneID=' + encodeURIComponent(s.id);
            a.setAttributeNS('http://www.w3.org/1999/xlink', 'href', href);
            a.setAttribute('href', href);

            a.appendChild(el('rect', { x: p.x, y: p.y, width: NODE_W, height: NODE_H, rx: 10, ry: 10, class: 'tree-node-box' }));

            var thumbX = p.x + (NODE_W - THUMB) / 2, thumbY = p.y + 10;
            if (s.thumbnail) {
                var img = el('image', { x: thumbX, y: thumbY, width: THUMB, height: THUMB, preserveAspectRatio: 'xMidYMid slice' });
                img.setAttributeNS('http://www.w3.org/1999/xlink', 'href', s.thumbnail);
                img.setAttribute('href', s.thumbnail);
                a.appendChild(img);
            } else {
                a.appendChild(el('circle', {
                    cx: p.x + NODE_W / 2, cy: thumbY + THUMB / 2, r: THUMB / 2,
                    fill: PALETTE[idx % PALETTE.length], class: 'tree-node-fallback'
                }));
                var num = el('text', { x: p.x + NODE_W / 2, y: thumbY + THUMB / 2 + 6, class: 'tree-node-num' });
                num.textContent = (idx + 1);
                a.appendChild(num);
            }

            if (s.is_start) {
                a.appendChild(el('circle', { cx: p.x + NODE_W - 12, cy: p.y + 12, r: 7, class: 'tree-node-start' }));
            }

            var t = el('text', { x: p.x + NODE_W / 2, y: p.y + NODE_H - 14, class: 'tree-node-title' });
            t.textContent = truncate(s.title, 20);
            a.appendChild(t);

            g.appendChild(a);
            idx++;
        });

        return svg;
    }

    // Click-drag panning. The mouse wheel is left to scroll the container
    // natively (no zoom), so the modal keeps a fixed size.
    function wirePan(container, svg) {
        var down = false, moved = false, sx, sy, sl, st;
        container.addEventListener('mousedown', function (e) {
            down = true; moved = false;
            sx = e.clientX; sy = e.clientY; sl = container.scrollLeft; st = container.scrollTop;
        });
        window.addEventListener('mousemove', function (e) {
            if (!down) return;
            var dx = e.clientX - sx, dy = e.clientY - sy;
            if (Math.abs(dx) + Math.abs(dy) > 4) moved = true;
            container.scrollLeft = sl - dx;
            container.scrollTop = st - dy;
        });
        window.addEventListener('mouseup', function () { down = false; });
        // Suppress the click that follows a drag so a pan doesn't open a scene.
        container.addEventListener('click', function (e) {
            if (moved) { e.preventDefault(); e.stopPropagation(); }
        }, true);
    }

    function build(scenes, choices, storyID, opts) {
        var container = document.createElement('div');
        container.className = 'tree-container';
        if (!scenes || !scenes.length) {
            container.innerHTML = '<p style="padding:1rem;">This story has no scenes yet.</p>';
            return container;
        }
        var svg = buildSVG(scenes, choices, storyID, opts);
        container.appendChild(svg);
        wirePan(container, svg);
        return container;
    }

    // Scale the whole tree (tiles, spacing, and text) to `scale` (1 = default).
    // Resize only the svg's width/height and keep the viewBox at the base size —
    // the browser then scales the content to fit exactly, so the element's box
    // matches the rendered content and the scroll bounds stay accurate. (A group
    // transform here would double-scale and break overflow detection.)
    function setScale(container, scale) {
        var svg = container && container.querySelector('svg');
        if (!svg) return;
        var baseW = +svg.dataset.baseW, baseH = +svg.dataset.baseH;
        svg.setAttribute('width',  baseW * scale);
        svg.setAttribute('height', baseH * scale);
        var g = svg.querySelector('.tree-root');
        if (g) g.removeAttribute('transform');  // clear any stale transform
    }

    // Add a centered "tile size" slider to the modal footer (Close stays right).
    function addZoomSlider(container) {
        var actions = document.querySelector('.modal-overlay .modal-actions');
        if (!actions || actions.querySelector('.tree-zoom')) return;
        var wrap = document.createElement('div');
        wrap.className = 'tree-zoom';
        wrap.innerHTML = '<span>Tile size</span>';
        var slider = document.createElement('input');
        slider.type = 'range';
        slider.min = '0.5'; slider.max = '2'; slider.step = '0.05'; slider.value = '1';
        slider.setAttribute('aria-label', 'Tile size');
        wrap.appendChild(slider);
        actions.insertBefore(wrap, actions.firstChild);
        slider.addEventListener('input', function () { setScale(container, parseFloat(slider.value)); });
    }

    // Fetch the story tree and open it in the global Modal (with the tile-size
    // slider). Returns the fetch promise so callers can re-enable a trigger button.
    function openModal(storyID, opts) {
        opts = opts || {};
        return fetch('api_tree.php?storyID=' + encodeURIComponent(storyID))
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (res) {
                if (!res.ok) { Modal.alert(res.d && res.d.error ? res.d.error : 'Could not load the story tree.'); return; }
                var container = build(res.d.scenes, res.d.choices, storyID, { isDraft: !!res.d.is_draft, currentId: opts.currentId });
                Modal.open({
                    title: 'Story Tree',
                    body: container,
                    buttons: [{ label: 'Close', className: 'btn-secondary', action: Modal.close }]
                });
                addZoomSlider(container);
            })
            .catch(function () { Modal.alert('Could not load the story tree.'); });
    }

    return { build: build, setScale: setScale, openModal: openModal };
})();
