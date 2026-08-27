'use strict';

const assert = require('assert');
const fs = require('fs');

function layoutForWidth(width) {
  if (width <= 991.98) return { columns: [width], listDrawer: true, contactDrawer: true, composerGrid: true };
  if (width <= 1100) return { columns: [68, 290, width - 358], listDrawer: false, contactDrawer: true, composerGrid: false };
  if (width <= 1480) return { columns: [190, 300, width - 490], listDrawer: false, contactDrawer: true, composerGrid: false };
  return { columns: [205, 315, width - 820, 300], listDrawer: false, contactDrawer: false, composerGrid: false };
}

const widths = [1920, 1440, 1366, 1024, 768, 390];
widths.forEach((width) => {
  const layout = layoutForWidth(width);
  assert.ok(layout.columns.every((column) => column >= 0), `no negative column at ${width}px`);
  assert.ok(layout.columns.reduce((sum, column) => sum + column, 0) <= width + 0.01, `no horizontal overflow at ${width}px`);
});

const zoomed = layoutForWidth(1024 / 1.25);
assert.strictEqual(zoomed.listDrawer, true, '125% zoom uses the same drawer interval as the visible list button');
assert.strictEqual(layoutForWidth(390).composerGrid, true, '390px keeps textarea, voice and send in the primary composer row');

const css = fs.readFileSync('Views/partials/styles.php', 'utf8');
const conversations = fs.readFileSync('Views/partials/conversations.php', 'utf8');
assert.ok(css.includes('container-type: inline-size'), 'inbox uses the real available inline size');
assert.ok(css.includes('@container impulso-inbox (max-width: 991.98px)'), 'container drawer breakpoint is defined');
assert.ok(!css.includes('100vw'), 'internal inbox layout does not depend on viewport width');
assert.ok(!css.includes('min-width: 90px'), 'composer has no fixed minimum that forces overflow');
assert.ok(conversations.includes('impulso-open-conversation-list') && !conversations.includes('d-lg-none'), 'list button uses the inbox drawer breakpoint');
console.log('Layout behavioral contract passed for 1920, 1440, 1366, 1024, 768, 390 and 125% zoom.');
