'use strict';

const assert = require('assert');
const Safe = require('../../Assets/js/inbox/message_safe_content.js');
const Renderers = require('../../Assets/js/inbox/message_renderers.js');
const Actions = require('../../Assets/js/inbox/message_actions.js');
// PHP capability documents are exercised by the PHP test. The JS suite keeps
// its fixtures provider-neutral so renderer behavior cannot grow provider
// branches accidentally.
const location = { origin: 'https://app.test' };
let passed = 0;
function test(name, callback) { callback(); passed += 1; console.log(`[OK] ${name}`); }

const base = {
  id: 10, conversation_id: 4, direction: 'incoming', status: 'sent',
  external_message_id: 'wamid.target', provider: 'meta_cloud', sender_name: 'Contato',
  sent_at: '2026-08-17T12:00:00Z', content: { text: 'Olá' }, type: 'text', reactions: [],
};

test('safe content escapes XSS and links only HTTP(S)', () => {
  const html = Safe.autoLink('<script>alert(1)</script> https://example.test/a?x=1 javascript:alert(2)');
  assert.ok(!html.includes('<script>'));
  assert.ok(html.includes('href="https://example.test/a?x=1"'));
  assert.ok(!html.includes('href="javascript'));
  assert.strictEqual(Safe.safeMediaUrl('https://provider.test/file.jpg', location), '');
  assert.strictEqual(Safe.safeMediaUrl('https://app.test/rise/chatwoot_plugin/api/media/10', location), 'https://app.test/rise/chatwoot_plugin/api/media/10');
});

test('registry deliberately covers every V2 type and falls back safely', () => {
  ['text', 'image', 'gallery', 'audio', 'voice', 'video', 'document', 'sticker', 'location', 'contact', 'template', 'interactive', 'internal_note', 'activity', 'unsupported', 'reaction'].forEach((type) => assert.strictEqual(typeof Renderers.registry[type], 'function', type));
  assert.strictEqual(Renderers.renderMessage({ ...base, type: 'reaction', content: { reaction: { emoji: '👍' } } }, { location }), '');
  const unsupported = Renderers.renderMessage({ ...base, type: 'future_provider_type', content: { text: '<b>preview</b>' } }, { location });
  assert.ok(unsupported.includes('Mensagem não suportada'));
  assert.ok(!unsupported.includes('<b>preview</b>'));
});

test('structured renderers keep provider metadata out of HTML', () => {
  const context = { location };
  const fixtures = [
    { type: 'image', content: { caption: 'legenda', attachments: [{ url: 'https://app.test/rise/chatwoot_plugin/api/media/1', kind: 'image' }] } },
    { type: 'voice', metadata: { is_voice_note: true }, content: { attachments: [{ url: 'https://app.test/rise/chatwoot_plugin/api/media/2', kind: 'audio', is_voice_note: true }] } },
    { type: 'video', content: { attachments: [{ url: 'https://app.test/rise/chatwoot_plugin/api/media/3', kind: 'video' }] } },
    { type: 'document', file_name: 'invoice.pdf', content: { attachments: [{ url: 'https://app.test/rise/chatwoot_plugin/api/media/4', kind: 'document', file_name: 'invoice.pdf' }] } },
    { type: 'sticker', content: { attachments: [{ url: 'https://app.test/rise/chatwoot_plugin/api/media/5', kind: 'sticker' }] } },
    { type: 'location', content: { location: { latitude: 1, longitude: 2, name: 'Local' } } },
    { type: 'contact', content: { contact: { display_name: 'Ana', phones: ['+5511'] } } },
    { type: 'template', content: { template: { name: 'welcome', body: 'Oi' } } },
    { type: 'interactive', content: { interactive: { label: 'Sim', description: 'Escolha' } } },
  ];
  fixtures.forEach((fixture) => {
    const html = Renderers.renderMessage({ ...base, ...fixture }, context);
    assert.ok(html.length > 0, fixture.type);
    assert.ok(!html.includes('provider.test'));
  });
});

test('gallery, reply quote, aggregates and statuses are rendered as accessible DTO output', () => {
  const html = Renderers.renderMessage({ ...base, direction: 'outgoing', status: 'read', type: 'gallery', content: { attachments: [{ url: '/rise/chatwoot_plugin/api/media/1' }, { url: '/rise/chatwoot_plugin/api/media/2' }], text: '' }, reply_to: { local_message_id: 8, author: 'Contato', preview: 'Original' }, reactions: [{ emoji: '👍', count: 2, reacted_by_me: true }] }, { location, time: () => '12:00' });
  assert.ok(html.includes('impulso-message-gallery'));
  assert.ok(html.includes('data-message-jump-id="8"'));
  assert.ok(html.includes('aria-label="👍, 2 reação(ões), você reagiu"'));
  assert.ok(html.includes('status-read'));
});

test('video captions are safe and native controls do not open the media viewer', () => {
  const html = Renderers.renderMessage({ ...base, type: 'video', caption: 'Veja https://example.test/video', content: { caption: 'Veja https://example.test/video', attachments: [{ url: '/rise/chatwoot_plugin/api/media/3', kind: 'video' }] } }, { location });
  assert.ok(html.includes('impulso-video-message'));
  assert.ok(html.includes('Veja <a'));
  assert.ok(!/<button[^>]*>\s*<video/i.test(html));
  assert.ok(html.includes('data-media-kind="video"'));
});

test('message statuses and timestamp tooltips are distinct and never fabricated', () => {
  const sent = Renderers.renderMessage({ ...base, direction: 'outgoing', status: 'sent', timestamps: { sent_at: '2026-08-17T12:00:00Z' } }, { location, time: (value) => value });
  const delivered = Renderers.renderMessage({ ...base, direction: 'outgoing', status: 'delivered', timestamps: { sent_at: '2026-08-17T12:00:00Z', delivered_at: '2026-08-17T12:01:00Z' } }, { location, time: (value) => value });
  const incoming = Renderers.renderMessage({ ...base, direction: 'incoming', status: 'received', timestamps: {} }, { location, time: (value) => value });
  assert.ok(sent.includes('status-sent') && !sent.includes('status-delivered'));
  assert.ok((delivered.match(/data-feather="check"/g) || []).length >= 2);
  assert.ok(delivered.includes('Entregue: 2026-08-17T12:01:00Z'));
  assert.ok(!incoming.includes('status-received') && !incoming.includes('Lida:'));
});

test('rich templates, unsupported attachments and internal-note authors remain safe', () => {
  const template = Renderers.renderMessage({ ...base, type: 'template', content: { template: { name: 'welcome', header: 'Olá', body: 'Corpo', footer: 'Rodapé', resolved_parameters: ['Ana'], buttons: [{ title: 'Confirmar' }] } } }, { location });
  const unsupported = Renderers.renderMessage({ ...base, type: 'future', content: { attachments: [{ url: '/rise/chatwoot_plugin/api/media/8', file_name: 'file.bin', mime_type: 'application/octet-stream' }] } }, { location });
  const note = Renderers.renderMessage({ ...base, type: 'internal_note', is_internal_note: true, sender: { name: 'Ana' }, sender_name: '99', content: { text: 'Interna' } }, { location });
  assert.ok(template.includes('Olá') && template.includes('Rodapé') && template.includes('Confirmar') && template.includes('Ana'));
  assert.ok(unsupported.includes('file.bin') && unsupported.includes('Abrir anexo'));
  assert.ok(note.includes('Ana') && !note.includes('Nota interna">99'));
});

test('action gating allows only safe operations and never retries ambiguous sends', () => {
  const caps = { actions: { reply: true, react: true }, reaction: { enabled: true, groups: false, max_target_age_seconds: 2592000, supports_remove: true } };
  const permissions = { send: true, manageSettings: true };
  const actions = Actions.getMessageActions({ ...base, direction: 'outgoing', metadata: { send_state: 'retryable_failure' }, status: 'failed', client_message_id: 'c1' }, caps, permissions);
  assert.ok(actions.includes('copy') && actions.includes('create_quick_reply') && actions.includes('retry'));
  assert.ok(!actions.includes('reply') && !actions.includes('react'));
  const ambiguous = Actions.getMessageActions({ ...base, direction: 'outgoing', metadata: { send_state: 'ambiguous_failure' }, status: 'failed', client_message_id: 'c2' }, caps, permissions);
  assert.ok(!ambiguous.includes('retry'));
  const note = Actions.getMessageActions({ ...base, type: 'internal_note', is_internal_note: true }, caps, permissions);
  assert.ok(!note.includes('reply') && !note.includes('react'));
  assert.ok(!actions.includes('delete') && !actions.includes('translate'));
  assert.ok(!Actions.getMessageActions({ ...base, is_group_message: true }, caps, permissions).includes('react'));
  assert.ok(!Actions.getMessageActions({ ...base, message_timestamp: Math.floor(Date.now() / 1000) - 2592001 }, caps, permissions).includes('react'));
  assert.ok(!require('fs').readFileSync(require.resolve('../../Assets/js/inbox/message_actions.js'), 'utf8').includes('window.prompt'));
});

test('reaction updates merge locally without message polling', () => {
  const chat = require('fs').readFileSync(require.resolve('../../Assets/js/chatwoot.js'), 'utf8');
  assert.ok(chat.includes('reaction_after'));
  assert.ok(chat.includes('mergeReactionUpdates'));
  assert.ok(!chat.includes('onReaction: function () { loadMessages'));
});

console.log(`\n${passed} passed, 0 failed.`);
