import { mkdir, readFile, rename, unlink, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { STATE_DIR } from './config.js';

/** `.wpdev/rescue.json`: last rescue token and its release. The token is never printed. */
export interface RescueInfo {
  release_id: string;
  token: string;
  saved_at: number;
}

function file(projectRoot: string): string {
  return path.join(projectRoot, STATE_DIR, 'rescue.json');
}

export async function loadRescue(projectRoot: string): Promise<RescueInfo | undefined> {
  try {
    const data = JSON.parse(await readFile(file(projectRoot), 'utf8')) as Partial<RescueInfo>;
    if (typeof data.token === 'string' && typeof data.release_id === 'string') {
      return { release_id: data.release_id, token: data.token, saved_at: Number(data.saved_at ?? 0) };
    }
  } catch {
    // missing or unreadable: no rescue available
  }
  return undefined;
}

export async function saveRescue(projectRoot: string, releaseId: string, token: string): Promise<void> {
  const target = file(projectRoot);
  await mkdir(path.dirname(target), { recursive: true });
  const tmp = `${target}.tmp`;
  const info: RescueInfo = { release_id: releaseId, token, saved_at: Math.floor(Date.now() / 1000) };
  await writeFile(tmp, `${JSON.stringify(info, null, 1)}\n`, { encoding: 'utf8', mode: 0o600 });
  await rename(tmp, target);
}

export async function deleteRescue(projectRoot: string): Promise<void> {
  await unlink(file(projectRoot)).catch(() => undefined);
}
