# Shell CSS and scripts

These files used to be inline `<style>`/`<script>` blocks in `api/ap-admin.php`.
Inline, every full page load resent ~730 KB (~165 KB gzipped) that the browser
could not cache or precompile. Now the shell links them with
`admin_static_asset_url('app/<file>')`, which adds a content-hash `?v=`, so a
changed file is fetched once and everything else comes from the browser cache.

- Edit the code here, not in `ap-admin.php`. Files load in the same order the
  inline blocks ran (`vaak-01.js` ... `vaak-23.js`), as classic scripts, so
  globals shared between them still work.
- A script that needs a per-request value reads `VAAK_BOOT.sNN[i]`. The inline
  boot line right before its `<script src>` in `ap-admin.php` fills it, e.g.
  `VAAK_BOOT.s16=[<?= json_encode($view) ?>];`. Add new values there.
- Source-level tests read `api/bin/admin-shell-source.php` / `.js`, which puts
  these files (and the boot values) back inline so string checks keep working.
- `api/bin/shell-static-assets-smoke.php` fails if a big inline block (12 KB+)
  comes back into the shell.
