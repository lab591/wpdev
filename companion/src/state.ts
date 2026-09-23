import { mkdir, readFile, rename, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { STATE_DIR } from './config.js';

/**
 * `.wpdev/state.json`: for every synchronized file, `h_base` is the hash of the
 * file on the server at the last successful pull/deploy; `s`/`m` are size and
 * mtime (ms) of the local copy right after that sync, used as a fast path to
 * detect unchanged local files without re-hashing them.
 */
export interface FileState {
  p: string;
  h_base: string;
  s: number;
  m: number;
}

export interface StateData {
  version: 1;
  files: Record<string, FileState>;
}

export class State {
  private constructor(
    private readonly file: string,
    private readonly data: StateData,
  ) {}

  static async load(projectRoot: string): Promise<State> {
    const file = path.join(projectRoot, STATE_DIR, 'state.json');
    let data: StateData = { version: 1, files: {} };
    try {
      const parsed = JSON.parse(await readFile(file, 'utf8')) as Partial<StateData>;
      if (parsed && parsed.version === 1 && parsed.files && typeof parsed.files === 'object') {
        data = { version: 1, files: parsed.files };
      }
    } catch (e) {
      if ((e as NodeJS.ErrnoException).code !== 'ENOENT') {
        throw new Error(`state.json illeggibile: ${(e as Error).message}`);
      }
    }
    return new State(file, data);
  }

  get(p: string): FileState | undefined {
    return this.data.files[p];
  }

  set(entry: FileState): void {
    this.data.files[entry.p] = entry;
  }

  delete(p: string): void {
    delete this.data.files[p];
  }

  /** All entries whose path is inside `root` (or equal to it). */
  entriesUnder(root: string): FileState[] {
    const prefix = `${root}/`;
    return Object.values(this.data.files).filter((e) => e.p.startsWith(prefix));
  }

  async save(): Promise<void> {
    await mkdir(path.dirname(this.file), { recursive: true });
    const sorted: Record<string, FileState> = {};
    for (const key of Object.keys(this.data.files).sort()) {
      sorted[key] = this.data.files[key] as FileState;
    }
    const tmp = `${this.file}.tmp`;
    await writeFile(tmp, `${JSON.stringify({ version: 1, files: sorted }, null, 1)}\n`, 'utf8');
    await rename(tmp, this.file);
  }
}
