/* VAAK app script 16. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
   Request values are in VAAK_BOOT.s16, filled by an inline script right before it. */
const VIEW = VAAK_BOOT.s16[0];
let allGuestbookEntries = [];

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function fmtDate(v) {
  try {
    const d = typeof v === 'number' ? new Date(v > 1e12 ? v : v * 1000) : new Date(v);
    return isNaN(d) ? String(v) : d.toLocaleString();
  } catch { return String(v); }
}
async function fetchJson(url, opts = {}) {
  const res = await fetch(url, { credentials: 'same-origin', ...opts });
  if (!res.ok) throw new Error('HTTP ' + res.status);
  return res.json();
}

function displayGuestbook(entries) {
  const el = document.getElementById('gb-list');
  if (!entries.length) { el.innerHTML = '<div class="empty">No entries.</div>'; return; }
  el.innerHTML = entries.map(e => `
    <div class="gb-item" data-id="${esc(e.id)}">
      <div class="gb-hd">
        <div><strong>${esc(e.name || 'Anonymous')}</strong> <span class="meta">${esc(e.email || '')}</span></div>
        <div class="meta">${esc(fmtDate(e.timestamp || e.date))}</div>
      </div>
      <div class="body">${esc(e.message || '')}</div>
      <div class="tweet-actions">
        <button type="button" class="danger" onclick="deleteGuestbook(${Number(e.id)})">Delete</button>
      </div>
    </div>`).join('');
}
function filterGuestbook() {
  const q = (document.getElementById('gb-search').value || '').toLowerCase();
  if (!q) return displayGuestbook(allGuestbookEntries);
  displayGuestbook(allGuestbookEntries.filter(e =>
    (e.name||'').toLowerCase().includes(q) ||
    (e.email||'').toLowerCase().includes(q) ||
    (e.message||'').toLowerCase().includes(q)
  ));
}
async function loadGuestbook() {
  try {
    const data = await fetchJson('/vaak/?site_api=guestbook');
    allGuestbookEntries = data.entries || [];
    if (data.stats) {
      document.getElementById('gb-total').textContent = data.stats.total ?? '0';
      document.getElementById('gb-recent').textContent = data.stats.recent ?? '0';
      document.getElementById('gb-updated').textContent = data.stats.lastUpdated || '—';
    }
    displayGuestbook(allGuestbookEntries);
  } catch (err) {
    document.getElementById('gb-list').innerHTML = '<div class="empty danger">Failed to load guestbook.</div>';
  }
}
async function deleteGuestbook(id) {
  if (!confirm('Delete guestbook entry #' + id + '?')) return;
  try {
    const data = await fetchJson('/vaak/?site_api=guestbook&id=' + encodeURIComponent(id), { method: 'DELETE' });
    if (data.success) {
      allGuestbookEntries = allGuestbookEntries.filter(e => Number(e.id) !== Number(id));
      displayGuestbook(allGuestbookEntries);
    } else alert(data.error || 'Delete failed');
  } catch { alert('Delete failed'); }
}

async function loadSupport() {
  try {
    const data = await fetchJson('/vaak/?site_api=subscribers');
    if (data.error) throw new Error(data.error);
    document.getElementById('sup-month').textContent = (data.total_month ?? 0).toFixed(2);
    document.getElementById('sup-subs').textContent = data.active_subscriptions ?? '0';
    document.getElementById('sup-mrr').textContent = (data.monthly_recurring ?? 0).toFixed(2);
    document.getElementById('sup-donations').textContent = data.recent_donations ?? '0';
    const acts = data.recent_activity || [];
    document.getElementById('sup-activity').innerHTML = acts.length ? acts.map(a => `
      <div class="fin-row">
        <div><strong>${esc(a.type === 'subscription' ? 'Subscription' : 'Donation')}</strong><br><span class="meta">${esc(a.customer_email || '')}</span></div>
        <div>$${(a.amount || 0).toFixed(2)}</div>
        <div class="meta">${esc(fmtDate(a.date))}</div>
      </div>`).join('') : '<div class="empty">No recent activity.</div>';
    const subs = data.subscriptions || [];
    document.getElementById('sup-subs-list').innerHTML = subs.length ? subs.map(s => `
      <div class="fin-row">
        <div><strong>${esc(s.customer_email || 'Unknown')}</strong><br><span class="meta">${esc(s.plan_name || '')}</span></div>
        <div>$${(s.amount || 0).toFixed(2)}/mo</div>
        <div class="meta">${esc(s.status || '')}</div>
      </div>`).join('') : '<div class="empty">No subscriptions.</div>';
  } catch {
    document.getElementById('sup-activity').innerHTML = '<div class="empty danger">Failed to load Stripe stats (auth required).</div>';
  }
}

async function loadAnalytics() {
  try {
    const data = await fetchJson('/api/analytics.php');
    if (!data.success && data.error) throw new Error(data.error);
    document.getElementById('an-today').textContent = (data.visitors?.today ?? 0).toLocaleString();
    document.getElementById('an-week').textContent = (data.visitors?.week ?? 0).toLocaleString();
    document.getElementById('an-views-week').textContent = (data.page_views?.week ?? 0).toLocaleString();
    document.getElementById('an-views-month').textContent = (data.page_views?.month ?? 0).toLocaleString();
    document.getElementById('an-mobile').textContent = (data.devices?.mobile_percent ?? 0) + '%';
    document.getElementById('an-desktop').textContent = (data.devices?.desktop_percent ?? 0) + '%';
    const pages = data.popular_pages || [];
    document.getElementById('an-pages').innerHTML = pages.length ? pages.map(p => `
      <div class="fin-row"><div>${esc(p.title || p.path || p.page || '—')}</div><div>${esc(String(p.views ?? p.count ?? ''))}</div></div>
    `).join('') : '<div class="empty">No page data.</div>';
    const refs = data.referrers || [];
    document.getElementById('an-refs').innerHTML = refs.length ? refs.map(r => `
      <div class="fin-row"><div class="mono">${esc(r.source || r.referrer || r.url || '—')}</div><div>${esc(String(r.count ?? r.views ?? ''))}</div></div>
    `).join('') : '<div class="empty">No referrer data.</div>';
    const browsers = data.browsers || [];
    document.getElementById('an-browsers').innerHTML = browsers.length ? browsers.map(b => `
      <div class="fin-row"><div>${esc(b.name || b.browser || '—')}</div><div>${esc(String(b.count ?? b.percent ?? ''))}</div></div>
    `).join('') : '<div class="empty">No browser data.</div>';
  } catch {
    document.getElementById('an-pages').innerHTML = '<div class="empty danger">Failed to load analytics.</div>';
  }
}

if (VIEW === 'guestbook') loadGuestbook();
if (VIEW === 'support') loadSupport();
if (VIEW === 'analytics') loadAnalytics();
