/*
 * Ezra Tube - Reels mode.
 *
 * A vertical, full-screen feed: CSS scroll-snap does the swiping, an
 * IntersectionObserver notices which item is on screen, and that one plays.
 *
 * Videos use a small pool of <video> elements that move between slides
 * (the one on screen plus the ones just above and below, so the next
 * swipe starts instantly). Reusing the same few elements matters on iPad:
 * Safari only lets a video play with sound after a tap, and the "Tap to
 * watch" tap unlocks every element in the pool at once.
 *
 * The feed never ends: when he gets near the bottom, everything is added
 * again, like scrolling Reels.
 */
(function () {
    'use strict';

    var cfg = window.EZRA_REELS || { items: [], start: 0, category: '' };
    var items = cfg.items || [];
    if (!items.length) return;

    var feed = document.getElementById('reels');
    var muteBtn = document.getElementById('reels-mute');
    var startScreen = document.getElementById('reels-start');
    var startBtn = document.getElementById('reels-start-btn');
    var hint = document.getElementById('reels-hint');

    var POOL_SIZE = 3;       // on screen + one either side
    var HYDRATE_BEHIND = 2;  // load pictures this many slides back...
    var HYDRATE_AHEAD = 4;   // ...and this many ahead
    var APPEND_WHEN_LEFT = 3;

    var slides = [];         // { el, stage, bar, item, hydrated, video, iframe }
    var pool = [];           // { video, slide }
    var active = -1;
    var started = false;
    var muted = false;
    var userPaused = false;
    var swipedOnce = false;

    // ---- building slides ------------------------------------------------

    function cssUrl(src) {
        return 'url("' + String(src).replace(/["\\\n\r]/g, encodeURIComponent) + '")';
    }

    function el(tag, className) {
        var e = document.createElement(tag);
        if (className) e.className = className;
        return e;
    }

    function addSlide(item) {
        var section = el('section', 'reel reel-' + item.type);
        section.setAttribute('aria-label', item.title);

        var bg = el('div', 'reel-bg');
        var stage = el('div', 'reel-stage');

        var info = el('div', 'reel-info');
        var title = el('div', 'reel-title');
        title.textContent = item.title;
        info.appendChild(title);

        var progress = el('div', 'reel-progress');
        var bar = el('span');
        progress.appendChild(bar);

        var flash = el('div', 'reel-flash');
        flash.innerHTML =
            '<svg viewBox="0 0 64 64" class="flash-play"><path d="M24 16 L50 32 L24 48 Z" fill="currentColor"/></svg>' +
            '<svg viewBox="0 0 64 64" class="flash-pause"><rect x="18" y="16" width="10" height="32" rx="3" fill="currentColor"/><rect x="36" y="16" width="10" height="32" rx="3" fill="currentColor"/></svg>';

        section.appendChild(bg);
        section.appendChild(stage);
        section.appendChild(info);
        if (item.type === 'video') section.appendChild(progress);
        section.appendChild(flash);

        var slide = {
            index: slides.length,
            el: section, bg: bg, stage: stage, bar: bar, flash: flash,
            item: item, hydrated: false, iframe: null
        };
        section.dataset.index = slide.index;
        section.addEventListener('click', function (e) { onSlideTap(slide, e); });

        slides.push(slide);
        feed.appendChild(section);
        observer.observe(section);
        return slide;
    }

    function addRound() {
        for (var i = 0; i < items.length; i++) addSlide(items[i]);
    }

    // Only slides near the one on screen load their pictures, so a long
    // session doesn't download every thumbnail at once.
    function hydrate(slide) {
        if (slide.hydrated) return;
        slide.hydrated = true;
        var item = slide.item;
        if (item.poster) slide.bg.style.backgroundImage = cssUrl(item.poster);

        var img = el('img', 'reel-media');
        img.alt = '';
        img.draggable = false;
        img.decoding = 'async';
        img.src = item.type === 'image' ? item.src : (item.poster || '');
        if (img.src) slide.stage.appendChild(img);
    }

    // ---- the video pool -------------------------------------------------

    function makeVideo() {
        var v = document.createElement('video');
        v.className = 'reel-media reel-video';
        v.setAttribute('playsinline', '');
        v.setAttribute('webkit-playsinline', '');
        v.setAttribute('disablepictureinpicture', '');
        v.setAttribute('controlslist', 'nodownload noplaybackrate noremoteplayback');
        v.loop = true;
        v.preload = 'auto';
        v.muted = true; // until "Tap to watch"
        v.addEventListener('timeupdate', function () { updateProgress(v); });
        return v;
    }

    for (var p = 0; p < POOL_SIZE; p++) pool.push({ video: makeVideo(), slide: null });

    function poolEntryFor(slide) {
        for (var i = 0; i < pool.length; i++) if (pool[i].slide === slide) return pool[i];
        return null;
    }

    function attach(entry, slide) {
        var v = entry.video;
        if (entry.slide && entry.slide !== slide) entry.slide.video = null;
        entry.slide = slide;
        slide.video = v;
        v.pause();
        if (v.dataset.src !== slide.item.src) {
            v.poster = slide.item.poster || '';
            v.dataset.src = slide.item.src;
            v.src = slide.item.src;
            v.load();
        } else {
            try { v.currentTime = 0; } catch (e) { /* not loaded yet */ }
        }
        slide.bar.style.transform = 'scaleX(0)';
        slide.stage.appendChild(v);
    }

    // Give the pool's videos to the slide on screen and its neighbours.
    function assignPool(index) {
        var wanted = [index, index + 1, index - 1]
            .map(function (i) { return slides[i]; })
            .filter(function (s) { return s && s.item.type === 'video'; });

        var free = pool.filter(function (entry) { return wanted.indexOf(entry.slide) === -1; });
        wanted.forEach(function (slide) {
            if (poolEntryFor(slide)) return;
            var entry = free.shift();
            if (entry) attach(entry, slide);
        });

        pool.forEach(function (entry) {
            if (entry.slide && entry.slide.index !== index) {
                entry.video.pause();
                if (entry.video.readyState > 0 && entry.video.currentTime > 0) {
                    try { entry.video.currentTime = 0; } catch (e) { /* ignore */ }
                }
            }
        });
    }

    function updateProgress(v) {
        var entry = null;
        for (var i = 0; i < pool.length; i++) if (pool[i].video === v) entry = pool[i];
        if (!entry || !entry.slide || !v.duration || !isFinite(v.duration)) return;
        entry.slide.bar.style.transform = 'scaleX(' + Math.min(1, v.currentTime / v.duration) + ')';
    }

    function playVideo(v) {
        v.muted = muted;
        var attempt = v.play();
        if (attempt && attempt.catch) {
            attempt.catch(function () {
                // Sound not allowed yet (no tap on this device): play quietly
                // and wiggle the sound button so a grown-up can turn it on.
                if (!v.muted) {
                    v.muted = true;
                    setMuted(true);
                    muteBtn.classList.add('needs-tap');
                    v.play().catch(function () {});
                }
            });
        }
    }

    // ---- YouTube (only if the admin turned it on) -----------------------

    function showYouTube(slide) {
        if (slide.iframe || !slide.item.youtube) return;
        var f = document.createElement('iframe');
        f.className = 'reel-youtube';
        f.title = slide.item.title;
        f.allow = 'autoplay; encrypted-media; picture-in-picture';
        f.setAttribute('allowfullscreen', '');
        f.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(slide.item.youtube) +
            '?rel=0&modestbranding=1&playsinline=1&autoplay=1&loop=1&playlist=' +
            encodeURIComponent(slide.item.youtube) + (muted ? '&mute=1' : '');
        slide.iframe = f;
        slide.stage.appendChild(f);
    }

    function hideYouTube(slide) {
        if (!slide.iframe) return;
        slide.iframe.remove(); // removing it is the only reliable way to stop it
        slide.iframe = null;
    }

    // ---- which slide is on screen ---------------------------------------

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting && entry.intersectionRatio >= 0.6) {
                setActive(Number(entry.target.dataset.index));
            }
        });
    }, { root: feed, threshold: [0.6] });

    function setActive(index) {
        if (index === active || !slides[index]) return;
        var previous = slides[active];
        if (previous && active !== -1 && index !== active) {
            if (previous.item.type === 'youtube') hideYouTube(previous);
            previous.el.classList.remove('is-active', 'is-paused');
            if (started && !swipedOnce) {
                swipedOnce = true;
                hint.classList.remove('show');
            }
        }

        active = index;
        userPaused = false;
        var slide = slides[index];
        slide.el.classList.add('is-active');

        for (var i = index - HYDRATE_BEHIND; i <= index + HYDRATE_AHEAD; i++) {
            if (slides[i]) hydrate(slides[i]);
        }
        assignPool(index);

        if (slides.length - index <= APPEND_WHEN_LEFT && items.length > 1) addRound();

        if (started) playActive();
        rememberPosition(slide.item.id);
    }

    function playActive() {
        var slide = slides[active];
        if (!slide) return;
        if (slide.item.type === 'video' && slide.video) playVideo(slide.video);
        if (slide.item.type === 'youtube') showYouTube(slide);
    }

    // Keep the address up to date, so a reload (or Add to Home Screen)
    // comes back to the same video.
    function rememberPosition(id) {
        if (!window.history || !history.replaceState) return;
        var qs = '?id=' + id + (cfg.category ? '&category=' + encodeURIComponent(cfg.category) : '');
        try { history.replaceState(null, '', 'reels.php' + qs); } catch (e) { /* ignore */ }
    }

    // ---- taps, sound, keys ----------------------------------------------

    function onSlideTap(slide) {
        if (!started || slide.index !== active) return;
        if (slide.item.type !== 'video' || !slide.video) return;
        var v = slide.video;
        if (v.paused) {
            userPaused = false;
            playVideo(v);
            flashIcon(slide, 'play');
            slide.el.classList.remove('is-paused');
        } else {
            userPaused = true;
            v.pause();
            flashIcon(slide, 'pause');
            slide.el.classList.add('is-paused');
        }
    }

    function flashIcon(slide, which) {
        slide.flash.className = 'reel-flash show-' + which;
        void slide.flash.offsetWidth; // restart the animation
        slide.flash.classList.add('pop');
    }

    function setMuted(value) {
        muted = value;
        muteBtn.classList.toggle('is-muted', muted);
        muteBtn.setAttribute('aria-label', muted ? 'Sound on' : 'Sound off');
    }

    muteBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        muteBtn.classList.remove('needs-tap');
        setMuted(!muted);
        var slide = slides[active];
        if (slide && slide.video) {
            slide.video.muted = muted;
            if (slide.video.paused && !userPaused) playVideo(slide.video);
        }
        if (slide && slide.item.type === 'youtube') { hideYouTube(slide); showYouTube(slide); }
    });

    function go(step) {
        var target = slides[active + step];
        if (target) target.el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    document.addEventListener('keydown', function (e) {
        if (!started) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); start(); }
            return;
        }
        if (e.key === 'ArrowDown' || e.key === 'PageDown' || e.key === 'ArrowRight') { e.preventDefault(); go(1); }
        if (e.key === 'ArrowUp' || e.key === 'PageUp' || e.key === 'ArrowLeft') { e.preventDefault(); go(-1); }
        if (e.key === ' ' || e.key === 'k') { e.preventDefault(); if (slides[active]) onSlideTap(slides[active]); }
        if (e.key === 'm') muteBtn.click();
    });

    // Pause when the iPad is locked or he switches apps; carry on after.
    document.addEventListener('visibilitychange', function () {
        var slide = slides[active];
        if (!started || !slide || !slide.video) return;
        if (document.hidden) slide.video.pause();
        else if (!userPaused) playVideo(slide.video);
    });

    // Stop pinch-zoom and the long-press menu wandering him out of the app.
    ['gesturestart', 'gesturechange'].forEach(function (evt) {
        document.addEventListener(evt, function (e) { e.preventDefault(); }, { passive: false });
    });
    document.addEventListener('contextmenu', function (e) {
        if (e.target.closest && e.target.closest('.reels')) e.preventDefault();
    });

    // ---- "Tap to watch" -------------------------------------------------

    function start() {
        if (started) return;
        started = true;
        setMuted(false);

        // This tap is what lets Safari play these videos with sound later,
        // so give every pooled video a quick play while we still have it.
        pool.forEach(function (entry) {
            var v = entry.video;
            if (!v.src) return;
            v.muted = false;
            var attempt = v.play();
            if (attempt && attempt.then) {
                attempt.then(function () {
                    if (!entry.slide || entry.slide.index !== active) v.pause();
                }).catch(function () {});
            }
        });

        // Same as Ezra ABC: the start tap also asks for full screen. Reels
        // is one page, so it stays full screen while he swipes.
        var root = document.documentElement;
        var standalone = window.navigator.standalone || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
        if (!standalone && !document.fullscreenElement && !document.webkitFullscreenElement) {
            try {
                if (root.requestFullscreen) root.requestFullscreen().catch(function () {});
                else if (root.webkitRequestFullscreen) root.webkitRequestFullscreen();
            } catch (e) { /* not supported - fine */ }
        }

        startScreen.classList.add('gone');
        document.body.classList.add('is-started');
        playActive();

        if (slides.length > 1) {
            setTimeout(function () { if (!swipedOnce) hint.classList.add('show'); }, 1500);
            setTimeout(function () { hint.classList.remove('show'); }, 9000);
        }
    }

    startBtn.addEventListener('click', start);
    startScreen.addEventListener('click', start);

    // ---- go -------------------------------------------------------------

    addRound();
    var startIndex = Math.max(0, Math.min(items.length - 1, cfg.start | 0));
    slides[startIndex].el.scrollIntoView({ block: 'start' });
    setActive(startIndex);
})();
