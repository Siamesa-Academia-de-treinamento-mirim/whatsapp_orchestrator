'use strict';

const assert = require('assert');
const Policy = require('../../Assets/js/inbox/media_policy.js');

assert.strictEqual(Policy.normalizeMime('  Audio/WebM;codecs=opus  '), 'audio/webm');
assert.strictEqual(Policy.allows('audio/webm;codecs=opus', ['audio/webm']), true);
assert.strictEqual(Policy.allowsFile(
    { type: 'audio/webm;codecs=opus', size: 100 },
    { enabled: true, recording_input_mime_types: ['audio/webm'] },
    { recording: true }
), true);
assert.strictEqual(Policy.allows('audio/webm;codecs=opus', ['audio/ogg']), false);
assert.strictEqual(Policy.allows('video/webm;codecs=opus', ['audio/webm']), false);

const recorded = { type: 'audio/webm;codecs=opus' };
Policy.allowsFile(recorded, { enabled: true, recording_input_mime_types: ['audio/webm'] }, { recording: true });
assert.strictEqual(recorded.type, 'audio/webm;codecs=opus', 'the original MIME hint is not mutated');

console.log('MIME policy test passed; audio/webm;codecs=opus is accepted only as a declared recording input.');
