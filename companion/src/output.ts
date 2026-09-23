export interface Output {
  info(message: string): void;
  warn(message: string): void;
}

export const consoleOutput: Output = {
  info: (m) => process.stdout.write(`${m}\n`),
  warn: (m) => process.stderr.write(`! ${m}\n`),
};

/** Collects output in memory (tests). */
export function memoryOutput(): Output & { lines: string[]; warnings: string[] } {
  const lines: string[] = [];
  const warnings: string[] = [];
  return {
    lines,
    warnings,
    info: (m) => lines.push(m),
    warn: (m) => warnings.push(m),
  };
}

export const EXIT_OK = 0;
export const EXIT_ERROR = 1;
/** Deploy failed or was rolled back (also every failure of `deploy --hook`). */
export const EXIT_DEPLOY_FAILED = 2;
