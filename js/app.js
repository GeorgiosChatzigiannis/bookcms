/**
 * FlipBook App — Production
 * Current page is server-rendered in the DOM. 
 * Other pages loaded via AJAX on flip.
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

    // Metadata per page (no content)
    const flatPages = [];
    const slugToIndex = {};

    // Content cache
    const contentCache = {};

    // ─── Build flat list from TOC ───
    const buildFlatPages = () => {
        (CFG.toc || []).forEach(ch => {
            (ch.sections || []).forEach(sec => {
                const slug = ch.slug + '/' + sec.slug;
                slugToIndex[slug] = flatPages.length;
                flatPages.push({
                    slug: sec.slug, title: sec.title, type: sec.type,
                    chSlug: ch.slug, chTitle: ch.title, chIcon: ch.icon || '',
                    fullSlug: slug
                });
            });
        });
        totalPages = flatPages.length;
    };

    // ─── Fetch content via AJAX ───
    const fetchContent = (index) => {
        if (contentCache[index]) return Promise.resolve(contentCache[index]);
        const p = flatPages[index];
        if (!p) return Promise.resolve('');
        return $.ajax({
            url: CFG.apiBase,
            data: { action: 'section', ch: p.chSlug, sec: p.slug },
            dataType: 'json',
            timeout: 8000
        }).then(data => {
            contentCache[index] = data.content || '';
            return contentCache[index];
        }).fail(() => '<p style="color:red">Σφάλμα φόρτωσης.</p>');
    };

    // ─── Fill a page div ───
    const fillPage = (index, html) => {
        const types = { article: '📄 Άρθρο', video: '🎬 Βίντεο', app: '🛠️ Εφαρμογή' };
        const p = flatPages[index];
        if (!p) return;
        const $el = $('#page-' + index);
        if (!$el.length) return;
        $el.html(
            '<div class="content"><div class="scroller">' +
            '<div class="chapter-label">' + p.chIcon + ' ' + p.chTitle + '</div>' +
            '<h2>' + p.title + '</h2>' +
            '<span class="type-indicator ' + p.type + '">' + (types[p.type] || p.type) + '</span>' +
            html +
            '</div></div>'
        );
        // Run inline scripts
        $el.find('script').each(function() {
            const s = document.createElement('script');
            s.textContent = this.textContent;
            this.parentNode.replaceChild(s, this);
        });
    };

    // ─── INIT ───
    const init = () => {
        buildFlatPages();
        if (!totalPages) return;

        current = CFG.startPage || 0;

        // Current page is ALREADY in the DOM from PHP.
        // Just init BookBlock with startPage.
        bbPlugin = $bookBlock.bookblock({
            speed: 800,
            perspective: 2000,
            shadowSides: 0.8,
            shadowFlip: 0.4,
            startPage: current + 1,
            onEndFlip: (oldIdx, newIdx, isLimit) => {
                current = newIdx;
                afterFlip();
            }
        });

        initUI();
        updateAll();

        // Pre-load adjacent pages in background
        loadAdjacent(current);
    };

    // ─── After flip ───
    const afterFlip = () => {
        // Fill page if empty
        const $el = $('#page-' + current);
        if ($el.is(':empty') || $el.children().length === 0) {
            fetchContent(current).then(html => fillPage(current, html));
        }
        updateAll();
        pushURL();
        loadAdjacent(current);
        cleanFarPages();
    };

    // ─── Load adjacent ───
    const loadAdjacent = (idx) => {
        [-1, 1, 2].forEach(off => {
            const i = idx + off;
            if (i >= 0 && i < totalPages) {
                const $el = $('#page-' + i);
                if ($el.is(':empty') || $el.children().length === 0) {
                    fetchContent(i).then(html => fillPage(i, html));
                }
            }
        });
    };

    // ─── Clean far pages ───
    const cleanFarPages = () => {
        for (let i = 0; i < totalPages; i++) {
            if (Math.abs(i - current) > 4) {
                const $el = $('#page-' + i);
                if ($el.children().length > 0) $el.empty();
            }
        }
    };

    // ─── URL ───
    const pushURL = () => {
        const p = flatPages[current];
        if (!p) return;
        const url = (CFG.basePath || '') + '/' + p.fullSlug;
        history.pushState({ index: current }, '', url);
        document.title = p.title + ' — ' + (CFG.bookTitle || '');
    };

    // ─── Update all UI ───
    const updateAll = () => {
        // Nav buttons
        $navPrev.toggle(current > 0);
        $navNext.toggle(current < totalPages - 1);

        // Indicators
        const txt = (current + 1) + ' / ' + totalPages;
        $('#pageIndicator').text(txt);
        $('#progressCount').text(txt);
        const pct = ((current + 1) / totalPages * 100);
        $('.progress-fill').css('width', pct + '%');

        // TOC highlight
        $('.menu-toc li').removeClass('menu-toc-current');
        $('.ch-header').removeClass('active');
        const $li = $('.menu-toc li[data-idx="' + current + '"]').addClass('menu-toc-current');
        const $g = $li.closest('.ch-group');
        if ($g.length) {
            $g.find('.ch-sections').addClass('open');
            $g.find('.ch-header').addClass('expanded active');
            const c = $('.menu-chapters')[0], el = $li[0];
            if (c && el) {
                const off = el.offsetTop - c.offsetTop;
                if (off < c.scrollTop || off > c.scrollTop + c.clientHeight - 40)
                    c.scrollTo({ top: off - 60, behavior: 'smooth' });
            }
        }
    };

    // ─── Sidebar ───
    const buildSidebar = () => {
        let html = '';
        (CFG.toc || []).forEach((ch, ci) => {
            html += '<div class="ch-group" data-chapter="' + ch.slug + '">' +
                '<div class="ch-header" data-ch="' + ci + '">' +
                '<span class="ch-icon">' + (ch.icon || '📄') + '</span>' +
                '<span>' + ch.title + '</span>' +
                '<span class="ch-arrow">▶</span></div>' +
                '<div class="ch-sections"><ul class="menu-toc">';
            (ch.sections || []).forEach(sec => {
                const slug = ch.slug + '/' + sec.slug;
                const idx = slugToIndex[slug];
                html += '<li data-idx="' + idx + '"><a href="' + (CFG.basePath||'') + '/' + slug + '">' +
                    '<span class="sec-title">' + sec.title + '</span>' +
                    '<span class="type-tag t-' + sec.type + '">' + sec.type + '</span>' +
                    '</a></li>';
            });
            html += '</ul></div></div>';
        });
        $('.menu-chapters').html(html);

        // Accordion
        $('.ch-header').on('click', function() {
            $(this).toggleClass('expanded').next('.ch-sections').toggleClass('open');
        });

        // TOC nav
        $('.menu-toc li').on('click', function(e) {
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

    // TOC open/close
    const openTOC = () => { $navNext.hide(); $navPrev.hide(); $container.addClass('slideRight').data('opened', true); };
    const closeTOC = (cb) => { updateAll(); $container.removeClass('slideRight').data('opened', false); if (cb) setTimeout(cb, 300); };
    const toggleTOC = () => { $container.data('opened') ? closeTOC() : openTOC(); };

    // ─── UI Init ───
    const initUI = () => {
        $navNext.on('click', () => { bbPlugin.next(); return false; });
        $navPrev.on('click', () => { bbPlugin.prev(); return false; });
        $tblcontents.on('click', toggleTOC);

        // Swipe
        $bookBlock.on('swipeleft', '.bb-item', () => { if (!$container.data('opened')) bbPlugin.next(); return false; });
        $bookBlock.on('swiperight', '.bb-item', () => { if (!$container.data('opened')) bbPlugin.prev(); return false; });

        // Keyboard
        $(document).on('keydown', (e) => {
            if (['INPUT','TEXTAREA','SELECT'].includes(e.target.tagName)) return;
            if (e.key === 'ArrowRight') bbPlugin.next();
            if (e.key === 'ArrowLeft') bbPlugin.prev();
        });

        // History
        window.addEventListener('popstate', (e) => {
            if (e.state && typeof e.state.index === 'number') {
                bbPlugin.jump(e.state.index + 1);
            }
        });

        // Initial history state
        const p = flatPages[current];
        if (p) history.replaceState({ index: current }, '', (CFG.basePath||'') + '/' + p.fullSlug);

        // Search
        $('#menuSearch').on('input', function() {
            const q = $(this).val().toLowerCase().trim();
            $('.ch-group').each(function() {
                let has = false;
                $(this).find('.menu-toc li').each(function() {
                    const ok = !q || $(this).find('.sec-title').text().toLowerCase().includes(q);
                    $(this).toggle(ok);
                    if (ok) has = true;
                });
                $(this).toggle(has);
                if (q && has) $(this).find('.ch-sections').addClass('open').prev().addClass('expanded');
            });
        });

        buildSidebar();

        // Theme
        const $tb = $('#themeBtn');
        const updTheme = () => { $tb.text((document.documentElement.getAttribute('data-theme') || 'light') === 'dark' ? '☀️' : '🌙'); };
        updTheme();
        $tb.on('click', () => {
            const now = document.documentElement.getAttribute('data-theme') || 'light';
            const next = now === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('flipbook-theme', next);
            updTheme();
        });

        // Share
        const $dd = $('#shareDropdown');
        const shareTitle = () => { const p = flatPages[current]; return p ? p.title + ' — ' + (CFG.bookTitle||'') : document.title; };
        $('#shareBtn').on('click', function(e) {
            e.stopPropagation();
            if (navigator.share) { navigator.share({title:shareTitle(),url:location.href}).catch(()=>{}); return; }
            $dd.toggleClass('open');
        });
        $(document).on('click', () => $dd.removeClass('open'));
        $dd.on('click', e => e.stopPropagation());
        const w = u => window.open(u,'_blank','width=600,height=400');
        $('#shareFb').on('click', e => { e.preventDefault(); w('https://www.facebook.com/sharer/sharer.php?u='+encodeURIComponent(location.href)); });
        $('#shareX').on('click', e => { e.preventDefault(); w('https://twitter.com/intent/tweet?url='+encodeURIComponent(location.href)+'&text='+encodeURIComponent(shareTitle())); });
        $('#shareLi').on('click', e => { e.preventDefault(); w('https://www.linkedin.com/sharing/share-offsite/?url='+encodeURIComponent(location.href)); });
        $('#shareWa').on('click', e => { e.preventDefault(); window.open('https://wa.me/?text='+encodeURIComponent(shareTitle()+' '+location.href),'_blank'); });
        $('#shareCopy').on('click', e => { e.preventDefault(); navigator.clipboard.writeText(location.href).then(()=>{ toast('✓ Αντιγράφηκε!'); $dd.removeClass('open'); }); });
    };

    const toast = (msg) => { const $t=$('.toast-msg'); $t.text(msg).addClass('show'); setTimeout(()=>$t.removeClass('show'),2000); };

    return { init, toggleTOC };
})();

$(document).ready(() => App.init());
