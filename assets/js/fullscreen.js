(function () {
    'use strict';

    var FS_INTENT_KEY = 'kidtube_fullscreen_intent';

    function supportsFullscreen() {
        var el = document.documentElement;
        return !!(el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen);
    }

    function isFullscreen() {
        return !!(document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement);
    }

    function enterFullscreen() {
        var el = document.documentElement;
        if (el.requestFullscreen) el.requestFullscreen().catch(function () {});
        else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
        else if (el.msRequestFullscreen) el.msRequestFullscreen();
    }

    function exitFullscreen() {
        if (document.exitFullscreen) document.exitFullscreen();
        else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
        else if (document.msExitFullscreen) document.msExitFullscreen();
    }

    function init() {
        // Some browsers (notably older iOS Safari) don't support fullscreen
        // for arbitrary elements at all - if so, don't show a button that
        // wouldn't do anything.
        if (!supportsFullscreen()) return;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'fullscreen-toggle-btn';
        btn.setAttribute('aria-label', 'Go fullscreen');

        var expandIcon = '<svg viewBox="0 0 24 24" class="icon-expand"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        var compressIcon = '<svg viewBox="0 0 24 24" class="icon-compress"><path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        btn.innerHTML = expandIcon + compressIcon;

        function refreshState() {
            if (isFullscreen()) {
                btn.classList.add('is-fullscreen');
                btn.setAttribute('aria-label', 'Exit fullscreen');
                sessionStorage.setItem(FS_INTENT_KEY, '1');
            } else {
                btn.classList.remove('is-fullscreen');
                btn.setAttribute('aria-label', 'Go fullscreen');
                sessionStorage.removeItem(FS_INTENT_KEY);
            }
        }

        btn.addEventListener('click', function () {
            if (isFullscreen()) exitFullscreen();
            else enterFullscreen();
        });

        ['fullscreenchange', 'webkitfullscreenchange', 'msfullscreenchange'].forEach(function (evt) {
            document.addEventListener(evt, refreshState);
        });

        document.body.appendChild(btn);

        // Browsers exit fullscreen automatically on every page navigation
        // (a spec-mandated security behavior, not something we can disable) -
        // so tapping a video card or swiping to the next item drops out of
        // fullscreen. This is a best-effort attempt to silently re-enter it
        // on the next page so the kid doesn't have to keep re-tapping the
        // button - but browsers may require a fresh click to allow it, in
        // which case this simply does nothing and the button is still right
        // there as a fallback.
        if (sessionStorage.getItem(FS_INTENT_KEY) === '1' && !isFullscreen()) {
            enterFullscreen();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
