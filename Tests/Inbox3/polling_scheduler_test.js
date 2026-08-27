'use strict';

const assert = require('assert');
const Scheduler = require('../../Assets/js/inbox/polling_scheduler.js');

function deferred() {
  let resolve;
  const promise = new Promise((done) => { resolve = done; });
  return { promise, resolve };
}

(async () => {
  const scheduler = Scheduler.create();
  const remote = deferred();
  let localReads = 0;

  const pendingMessages = scheduler.run('messages', () => remote.promise);
  const skippedMessages = scheduler.run('messages', () => { throw new Error('same operation must be skipped'); });
  const conversations = scheduler.run('conversations', () => {
    localReads += 1;
    return Promise.resolve('local list');
  });

  const skipped = await skippedMessages;
  const list = await conversations;
  assert.strictEqual(skipped.status, 'skipped');
  assert.strictEqual(list.status, 'fulfilled');
  assert.strictEqual(localReads, 1, 'a pending message read must not block the independent list read');

  remote.resolve('local messages');
  const message = await pendingMessages;
  assert.strictEqual(message.status, 'fulfilled');

  let scheduled = 0;
  let callback;
  const timers = [];
  const timerScheduler = Scheduler.create({
    setTimeout: (fn) => { callback = fn; timers.push(fn); return timers.length; },
    clearTimeout: () => {},
  });
  timerScheduler.schedule('local', 3000, () => { scheduled += 1; });
  callback();
  assert.strictEqual(scheduled, 1, 'local cycle callback runs independently of remote promises');
  timerScheduler.destroy();
  scheduler.destroy();
  console.log('Polling scheduler behavioral test passed: independent operations and timer lifecycle verified.');
})().catch((error) => {
  console.error(error.stack || error.message);
  process.exitCode = 1;
});
