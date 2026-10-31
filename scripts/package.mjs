import { spawnSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { dirname, resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const status = spawnSync('git', ['status','--porcelain','--untracked-files=all','--','commerce_paymento'], {cwd:root,encoding:'utf8',windowsHide:true});
if (status.status !== 0 || status.stdout.trim()) throw new Error('Commit the reviewed commerce_paymento/ source before packaging; no uncommitted or untracked module files are allowed.');
const artifacts = join(root,'artifacts');
mkdirSync(artifacts,{recursive:true});
const archive = join(artifacts,'paymento-drupal-0.1.0.zip');
const result = spawnSync('git',['archive','--format=zip','--prefix=commerce_paymento/',`--output=${archive}`,'HEAD:commerce_paymento'],{cwd:root,encoding:'utf8',windowsHide:true});
if (result.error || result.status !== 0) throw new Error(result.error?.message || result.stderr);
const hash=createHash('sha256').update(readFileSync(archive)).digest('hex');
writeFileSync(archive+'.sha256',`${hash}  paymento-drupal-0.1.0.zip\n`);
console.log(`${archive}\nSHA256 ${hash}`);
