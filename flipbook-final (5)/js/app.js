/**
 * FlipBook App — SQLite-backed, preloaded first page, native scroll
 */
const App = (() => {
    const CFG = window.FLIPBOOK || {};
    const $container = $('#container');
    const $bookBlock = $('#bb-bookblock');
    const $navNext = $('#bb-nav-next');
    const $navPrev = $('#bb-nav-prev').hide();
    const $tblcontents = $('#tblcontents');

    let bbPlugin = null;
    let totalPages = CFG.totalPages || 0;
    let current = CFG.startPage || 0;

    // Flat page list (no content — just metadata)
    let flatPages = [];
    let slugToIndex = {};

    // Content cache: index → HTML string
    const contentCache = new Map();

    // ─── Build flat page list from TOC ───
    const buildFlatPages = () => {
        (CFG.toc || []).forEach(ch => {
            (ch.sections || []).forEach(sec => {
                const slug = ch.slug + '/' + sec.slug;
                slugToIndex[slug] = flatPages.length;
                flatPages.push({
                    id: sec.id,
                    slug: sec.slug,
                    title: sec.title,
                    type: sec.type,
                    chSlug: ch.slug,
                    chTitle: ch.title,
                    chIcon: ch.icon || '',
                    fullSlug: slug
                });
            });
        });
        totalPages = flatPages.length;
    };

    // ─── Fetch content from API ───
    const fetchContent = async (index) => {
        if (contentCache.has(index)) return contentCache.get(index);
        const page = flatPages[index];
        if (!page) return '';

        try {
            const url = CFG.apiBase + '?action=section&ch=' +
                encodeURIComponent(page.chSlug) + '&sec=' +
                encodeURIComponent(page.slug);
            const res = await fetch(url);
            if (!res.ok) throw new Error(res.status);
            const data = await res.json();
            const html = data.content || '';
            contentCache.set(index, html);
            return html;
        } catch (e) {
            console.error('Fetch error:', e);
            return '<p style="color:red">Σφάλμα φόρτωσης.</p>';
        }
    };

    // ─── Render page HTML into a bb-item div ───
    const renderPageHtml = (index, content) => {
        const page = flatPages[index];
        if (!page) return '';
        const types = { article: '📄 Άρθρο', video: '🎬 Βίντεο', app: '🛠️ Εφαρμογή' };
        return `<div class="content"><div class="scroller">
            <div class="chapter-label">${page.chIcon} ${page.chTitle}</div>
            <h2>${page.title}</h2>
            <span class="type-indicator ${page.type}">${types[page.type] || page.type}</span>
            ${content}
        </div></div>`;
    };

    // ─── Fill a page div and run scripts ───
    const fillPage = (index, content) => {
        const $el = $(`#page-${index}`);
        if (!$el.length) return;
        $el.html(renderPageHtml(index, content));
        // Execute inline <script> (for app widgets)
        $el.find('script').each(function () {
            const s = document.createElement('script');
            s.textContent = this.textContent;
            this.parentNode.replaceChild(s, this);
        });
    };

    // ─── INIT ───
    const init = async () => {
        try {
            buildFlatPages();
            if (totalPages === 0) {
                $bookBlock.html('<div class="bb-item"><div class="content"><div class="scroller"><h2>Κενό βιβλίο</h2><p>Προσθέστε περιεχόμενο μέσω Admin.</p></div></div></div>');
                return;
            }

            // Resolve start page
            current = resolveStartPage();

            // ── Step 1: Create ALL placeholders ──
            let placeholders = '';
            for (let i = 0; i < totalPages; i++) {
                placeholders += `<div class="bb-item" id="page-${i}"></div>`;
            }
            $bookBlock.html(placeholders);

            // ── Step 2: Fill CURRENT page immediately (from PHP preload) ──
            if (CFG.preloadedContent !== undefined && CFG.preloadedContent !== null) {
                contentCache.set(current, CFG.preloadedContent);
            }
            const currentContent = contentCache.get(current) || await fetchContent(current);
            fillPage(current, currentContent);

            // ── Step 3: Init BookBlock ──
            bbPlugin = $bookBlock.bookblock({
                speed: 800,
                perspective: 2000,
                shadowSides: 0.8,
                shadowFlip: 0.4,
                startPage: current + 1,
                onEndFlip: (oldIdx, newIdx, isLimit) => {
                    current = newIdx;
                    afterFlip(isLimit);
                }
            });

            // ── Step 4: Setup UI ──
            initEvents();
            updateTOC();
            updateNav();
            updateIndicator();
            buildSidebar();

            // ── Step 5: Background-load adjacent pages ──
            loadAdjacent(current);

        } catch (err) {
            console.error('Init error:', err);
        }
    };

    // ─── After each page flip ───
    const afterFlip = async (isLimit) => {
        updateTOC();
        updateNav();
        pushURL();
        updateIndicator();

        // Load content if not yet loaded
        const $el = $(`#page-${current}`);
        if ($el.is(':empty')) {
            const html = await fetchContent(current);
            fillPage(current, html);
        }

        // Cleanup far pages (keep ±3)
        for (let i = 0; i < totalPages; i++) {
            if (Math.abs(i - current) > 4 && !$(`#page-${i}`).is(':empty')) {
                $(`#page-${i}`).empty();
            }
        }

        // Pre-load adjacent
        loadAdjacent(current);
    };

    // ─── Load adjacent pages in background ───
    const loadAdjacent = (idx) => {
        [-1, 1, 2, -2].forEach(offset => {
            const i = idx + offset;
            if (i >= 0 && i < totalPages && $(`#page-${i}`).is(':empty')) {
                fetchContent(i).then(html => fillPage(i, html));
            }
        });
    };

    // ─── Resolve start page from URL ───
    const resolveStartPage = () => {
        // 1. Clean URL path
        const path = window.location.pathname;
        const bp = CFG.basePath || '';
        const route = path.replace(bp, '').replace(/^\/+|\/+$/g, '');
        if (route && slugToIndex[route] !== undefined) return slugToIndex[route];

        // 2. ?page=N
        const p = parseInt(new URLSearchParams(window.location.search).get('page'));
        if (p > 0 && p <= totalPages) return p - 1;

        // 3. #hash
        if (window.location.hash) {
            const h = window.location.hash.substring(1);
            if (slugToIndex[h] !== undefined) return slugToIndex[h];
        }

        // 4. PHP-provided start page
        return CFG.startPage || 0;
    };

    // ─── Build sidebar TOC ───
    const buildSidebar = () => {
        let html = '';
        (CFG.toc || []).forEach((ch, ci) => {
            html += `<div class="ch-group" data-chapter="${ch.slug}">`;
            html += `<div class="ch-header" data-ch="${ci}">`;
            html += `<span class="ch-icon">${ch.icon || '📄'}</span>`;
            html += `<span>${ch.title}</span>`;
            html += `<span class="ch-arrow">▶</span></div>`;
            html += `<div class="ch-sections"><ul class="menu-toc">`;
            (ch.sections || []).forEach(sec => {
                const slug = ch.slug + '/' + sec.slug;
                const idx = slugToIndex[slug];
                html += `<li data-idx="${idx}"><a href="${pageURL(idx)}">`;
                html += `<span class="sec-title">${sec.title}</span>`;
                html += `<span class="type-tag t-${sec.type}">${sec.type}</span>`;
                html += `</a></li>`;
            });
            html += `</ul></div></div>`;
        });
        $('.menu-chapters').html(html);

        // Accordion
        $('.ch-header').off('click').on('click', function () {
            const $s = $(this).next('.ch-sections');
            $s.toggleClass('open');
            $(this).toggleClass('expanded');
        });

        // TOC clicks
        $('.menu-toc li').off('click').on('click', function (e) {
            e.preventDefault();
            const idx = parseInt($(this).data('idx'));
            if (!isNaN(idx) && idx !== current) {
                closeTOC(() => bbPlugin.jump(idx + 1));
            } else {
                closeTOC();
            }
            return false;
        });
    };

    // ─── URL ───
    const pageURL = (i) => {
        const p = flatPages[i];
        return p ? (CFG.basePath || '') + '/' + p.fullSlug : '#';
    };

    const pushURL = () => {
        const p = flatPages[current];
        if (!p) return;
        const url = (CFG.basePath || '') + '/' + p.fullSlug;
        const title = p.title + ' — ' + (CFG.bookTitle || '');
        history.pushState({ index: current }, title, url);
        document.title = title;
    };

    // ─── UI updates ───
    const updateIndicator = () => {
        const pct = ((current + 1) / totalPages * 100).toFixed(0);
        $('#pageIndicator').text((current + 1) + ' / ' + totalPages);
        $('.progress-fill').css('width', pct + '%');
        $('#progressCount').text((current + 1) + ' / ' + totalPages);
    };

    const updateTOC = () => {
        $('.menu-toc li').removeClass('menu-toc-current');
        $('.ch-header').removeClass('active');
        const p = flatPages[current];
        if (!p) return;
        const $li = $(`.menu-toc li[data-idx="${current}"]`).addClass('menu-toc-current');
        const $g = $li.closest('.ch-group');
        if ($g.length) {
            $g.find('.ch-sections').addClass('open');
            $g.find('.ch-header').addClass('expanded active');
            // Scroll into view
            const c = $('.menu-chapters')[0], el = $li[0];
            if (c && el) {
                const off = el.offsetTop - c.offsetTop;
                if (off < c.scrollTop || off > c.scrollTop + c.clientHeight - 40)
                    c.scrollTo({ top: off - 60, behavior: 'smooth' });
            }
        }
    };

    const updateNav = () => {
        $navPrev.toggle(current > 0);
        $navNext.toggle(current < totalPages - 1);
    };

    // ─── TOC panel ───
    const openTOC = () => { $navNext.hide(); $navPrev.hide(); $container.addClass('slideRight').data('opened', true); };
    const closeTOC = (cb) => { updateNav(); $container.removeClass('slideRight').data('opened', false); if (cb) setTimeout(cb, 300); };
    const toggleTOC = () => { $container.data('opened') ? closeTOC() : openTOC(); };

    // ─── Events ───
    const initEvents = () => {
        $navNext.on('click', () => { bbPlugin.next(); return false; });
        $navPrev.on('click', () => { bbPlugin.prev(); return false; });
        $tblcontents.on('click', toggleTOC);

        // Swipe
        $bookBlock.on('swipeleft', '.bb-item', () => { if (!$container.data('opened')) bbPlugin.next(); return false; });
        $bookBlock.on('swiperight', '.bb-item', () => { if (!$container.data('opened')) bbPlugin.prev(); return false; });

        // Keyboard
        $(document).on('keydown', (e) => {
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;
            if (e.key === 'ArrowRight') bbPlugin.next();
            if (e.key === 'ArrowLeft') bbPlugin.prev();
        });

        // Browser back/forward
        window.addEventListener('popstate', (e) => {
            if (e.state && typeof e.state.index === 'number') {
                current = e.state.index;
                bbPlugin.jump(current + 1);
            }
        });

        // Search
        $('#menuSearch').on('input', function () {
            const q = $(this).val().toLowerCase().trim();
            $('.ch-group').each(function () {
                let match = false;
                $(this).find('.menu-toc li').each(function () {
                    const ok = !q || $(this).find('.sec-title').text().toLowerCase().includes(q);
                    $(this).toggle(ok);
                    if (ok) match = true;
                });
                $(this).toggle(match);
                if (q && match) {
                    $(this).find('.ch-sections').addClass('open');
                    $(this).find('.ch-header').addClass('expanded');
                }
            });
        });

        initTheme();
        initSharing();
    };

    // ─── Theme ───
    const initTheme = () => {
        const $btn = $('#themeBtn');
        const upd = () => { $btn.text((document.documentElement.getAttribute('data-theme') || 'light') === 'dark' ? '☀️' : '🌙'); };
        upd();
        $btn.on('click', () => {
            const now = document.documentElement.getAttribute('data-theme') || 'light';
            const next = now === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('flipbook-theme', next);
            upd();
        });
    };

    // ─── Sharing ───
    const initSharing = () => {
        const $dd = $('#shareDropdown');
        const title = () => { const p = flatPages[current]; return p ? p.title + ' — ' + (CFG.bookTitle || '') : document.title; };

        $('#shareBtn').on('click', function (e) {
            e.stopPropagation();
            if (navigator.share) { navigator.share({ title: title(), url: location.href }).catch(() => {}); return; }
            $dd.toggleClass('open');
        });
        $(document).on('click', () => $dd.removeClass('open'));
        $dd.on('click', e => e.stopPropagation());

        const w = (u) => window.open(u, '_blank', 'width=600,height=400');
        $('#shareFb').on('click', e => { e.preventDefault(); w('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(location.href)); });
        $('#shareX').on('click', e => { e.preventDefault(); w('https://twitter.com/intent/tweet?url=' + encodeURIComponent(location.href) + '&text=' + encodeURIComponent(title())); });
        $('#shareLi').on('click', e => { e.preventDefault(); w('https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(location.href)); });
        $('#shareWa').on('click', e => { e.preventDefault(); window.open('https://wa.me/?text=' + encodeURIComponent(title() + ' ' + location.href), '_blank'); });
        $('#shareCopy').on('click', e => { e.preventDefault(); navigator.clipboard.writeText(location.href).then(() => { showToast('✓ Αντιγράφηκε!'); $dd.removeClass('open'); }); });
    };

    const showToast = (msg) => { const $t = $('.toast-msg'); $t.text(msg).addClass('show'); setTimeout(() => $t.removeClass('show'), 2000); };

    return { init };
})();

$(document).ready(() => App.init());
