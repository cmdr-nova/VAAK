'use strict';
// Test helper: api/ap-admin.php with the shell's CSS and scripts put back
// inline (they live in api/assets/app/ so browsers can cache them). Same
// rules as admin-shell-source.php.
const fs = require('fs');
const path = require('path');

function adminShellSource(adminPath) {
  const assets = path.join(path.dirname(adminPath), 'assets');
  const admin = fs.readFileSync(adminPath, 'utf8');
  const boot = {};
  for (const m of admin.matchAll(/VAAK_BOOT\.(s[0-9]+)=\[(.*)\];<\/script>/g)) {
    boot[m[1]] = m[2].match(/<\?=.*?\?>/g) || [];
  }
  const body = (rel) => fs.readFileSync(path.join(assets, rel), 'utf8')
    .replace(/^\/\*[\s\S]*?\*\/\n/, '')
    .replace(/VAAK_BOOT\.(s[0-9]+)\[([0-9]+)\]/g, (all, key, i) => (boot[key] && boot[key][+i]) || all);
  return admin
    .replace(/<script src="<\?= h\(admin_static_asset_url\('(app\/[a-z0-9-]+\.js)'\)\) \?>"><\/script>/g,
      (_, rel) => '<script>\n' + body(rel).replace(/\n+$/, '') + '\n</script>')
    .replace(/<link rel="stylesheet" href="<\?= h\(admin_static_asset_url\('(app\/[a-z0-9-]+\.css)'\)\) \?>">/g,
      (_, rel) => '<style>\n' + body(rel).replace(/\n+$/, '') + '\n</style>');
}

module.exports = { adminShellSource };
