/* VAAK app script 12. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
*/
(() => {
  const rail = document.getElementById('admin-rail-left');
  const btn = document.getElementById('mobile-menu-btn');
  const backdrop = document.getElementById('mobile-nav-backdrop');
  if (!rail || !btn || !backdrop) return;

  // Keep in sync with the CSS mobile drawer media query.
  const mq = window.matchMedia('(max-width: 700px), (max-width: 950px) and (max-height: 520px)');
  function isMobile() { return mq.matches; }

  function openNav() {
    if (!isMobile()) return;
    rail.classList.add('mobile-open');
    backdrop.hidden = false;
    requestAnimationFrame(() => backdrop.classList.add('show'));
    document.body.classList.add('mobile-nav-open');
    btn.setAttribute('aria-expanded', 'true');
    btn.setAttribute('aria-label', 'Close menu');
    try { window.dispatchEvent(new CustomEvent('vaak:mobile-nav-open')); } catch (e) {}
  }
  function closeNav() {
    rail.classList.remove('mobile-open');
    backdrop.classList.remove('show');
    document.body.classList.remove('mobile-nav-open');
    btn.setAttribute('aria-expanded', 'false');
    btn.setAttribute('aria-label', 'Open menu');
    window.setTimeout(() => {
      if (!rail.classList.contains('mobile-open')) backdrop.hidden = true;
    }, 200);
  }
  // Soft-nav (Notifications) stops propagation in capture — expose close for those paths.
  window.vaakCloseMobileNav = closeNav;
  function toggleNav() {
    if (rail.classList.contains('mobile-open')) closeNav();
    else openNav();
  }

  // Safari (esp. iOS) can restore a half-broken layout after backgrounding:
  // stuck drawer transform, body.mobile-nav-open lock, or a transient width that
  // activates the tablet 72px icon rail. Reset chrome whenever we become visible.
  function resetMobileChrome() {
    closeNav();
    document.body.classList.remove('mobile-nav-open');
    // Nudge layout/compositor after bfcache / visualViewport changes.
    try {
      const y = window.scrollY || 0;
      window.scrollTo(0, y);
    } catch (e) {}
    if (rail) {
      // Force a style recalc so translate3d isn't stuck mid-frame after resume.
      void rail.offsetWidth;
    }
  }

  btn.addEventListener('click', toggleNav);
  backdrop.addEventListener('click', closeNav);
  // Capture on the rail so we close before any soft-nav stopImmediatePropagation
  // on a child link (Notifications). Full page navigations still benefit too.
  rail.addEventListener('click', (ev) => {
    if (!isMobile()) return;
    const link = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
    if (!link || !rail.contains(link)) return;
    closeNav();
  }, true);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && rail.classList.contains('mobile-open')) closeNav();
  });
  mq.addEventListener('change', () => {
    closeNav();
  });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') resetMobileChrome();
  });
  window.addEventListener('pageshow', (ev) => {
    // bfcache restores (persisted) are the usual Safari resume path.
    resetMobileChrome();
    if (ev && ev.persisted) {
      requestAnimationFrame(resetMobileChrome);
    }
  });
  if (window.visualViewport) {
    let vvTimer = 0;
    window.visualViewport.addEventListener('resize', () => {
      window.clearTimeout(vvTimer);
      vvTimer = window.setTimeout(() => {
        if (document.visibilityState === 'visible') resetMobileChrome();
      }, 120);
    });
  }
})();
