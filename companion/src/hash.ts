import { createReadStream } from 'node:fs';
import { createXXHash128, type IHasher } from 'hash-wasm';

/**
 * xxh128 hex digest, identical to PHP `hash('xxh128', ...)`.
 * The WASM hasher is created once and reused (calls are sequential per await).
 */
let hasherPromise: Promise<IHasher> | undefined;
let queue: Promise<unknown> = Promise.resolve();

function getHasher(): Promise<IHasher> {
  hasherPromise ??= createXXHash128();
  return hasherPromise;
}

function serialize<T>(fn: () => Promise<T>): Promise<T> {
  const run = queue.then(fn, fn);
  queue = run.catch(() => undefined);
  return run;
}

export function xxh128(data: Uint8Array | string): Promise<string> {
  return serialize(async () => {
    const h = await getHasher();
    h.init();
    h.update(data);
    return h.digest('hex');
  });
}

export function xxh128File(file: string): Promise<string> {
  return serialize(async () => {
    const h = await getHasher();
    h.init();
    for await (const chunk of createReadStream(file, { highWaterMark: 1 << 20 })) {
      h.update(chunk as Buffer);
    }
    return h.digest('hex');
  });
}
