/**
 * Covers every row of the story's I/O & Edge-Case Matrix, the build's own
 * guard rails, and the two DDEV setup commands' section 0.
 *
 * Each test builds into a throwaway repo-shaped folder, so the real artifacts
 * are never touched.
 */

import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { after, describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';

import {
    ARTIFACTS,
    THEME_BASE_RELATIVE,
    buildTokens,
    checkNamesUnique,
    checkPresetSlugsUnique,
    tokenName,
} from '../build.js';

const PACKAGE_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const REPO_ROOT = path.resolve(PACKAGE_DIR, '..', '..');
const REAL_TOKENS_DIR = path.join(PACKAGE_DIR, 'tokens');
const REAL_BASE_CSS = path.join(PACKAGE_DIR, 'src', 'base.css');
const REAL_THEME_BASE = path.join(REPO_ROOT, THEME_BASE_RELATIVE);
const BUILD_JS = path.join(PACKAGE_DIR, 'build.js');

const WWW_SETUP = path.join(REPO_ROOT, 'apps/www/.ddev/commands/host/www-setup');
const PORTAL_SETUP = path.join(REPO_ROOT, 'apps/portal/.ddev/commands/host/portal-setup');

const temporaryRoots = [];

after(() => {
    for (const root of temporaryRoots) {
        fs.rmSync(root, { recursive: true, force: true });
    }
});

function temporaryDir(prefix) {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), prefix));
    temporaryRoots.push(dir);
    return dir;
}

/** A temp folder shaped like the repo: theme.base.json in place, nothing else. */
function makeRepo({ themeBase = fs.readFileSync(REAL_THEME_BASE, 'utf8') } = {}) {
    const root = temporaryDir('wo-tokens-');

    fs.mkdirSync(path.join(root, 'apps/www/themes/woptimize-theme'), { recursive: true });
    fs.mkdirSync(path.join(root, 'apps/portal/resources/css'), { recursive: true });

    if (themeBase !== null) {
        fs.writeFileSync(path.join(root, THEME_BASE_RELATIVE), themeBase);
    }

    return root;
}

/** A temp copy of the real token sources, so a test can edit one file. */
function makeTokensDir() {
    const dir = temporaryDir('wo-src-');
    fs.cpSync(REAL_TOKENS_DIR, dir, { recursive: true });
    return dir;
}

/** A temp folder holding exactly the given `{ name: contents }` files. */
function makeDirWith(files) {
    const dir = temporaryDir('wo-fixture-');
    for (const [name, contents] of Object.entries(files)) {
        fs.writeFileSync(path.join(dir, name), contents);
    }
    return dir;
}

/**
 * A runnable copy of the package: build.js, src/, tokens/, and a symlink to the
 * real node_modules. Lets a test seed a bad source and still spawn the real CLI.
 */
function makePackageCopy() {
    const dir = temporaryDir('wo-pkg-');
    fs.copyFileSync(BUILD_JS, path.join(dir, 'build.js'));
    fs.cpSync(path.join(PACKAGE_DIR, 'src'), path.join(dir, 'src'), { recursive: true });
    fs.cpSync(REAL_TOKENS_DIR, path.join(dir, 'tokens'), { recursive: true });
    fs.symlinkSync(path.join(PACKAGE_DIR, 'node_modules'), path.join(dir, 'node_modules'), 'dir');
    return dir;
}

/** Every token path in the real sources, read without going through the build. */
function realTokenPaths() {
    const paths = [];

    const walk = (node, segments) => {
        if (!node || typeof node !== 'object' || Array.isArray(node)) return;
        if ('$value' in node) {
            paths.push(segments);
            return;
        }
        for (const [key, child] of Object.entries(node)) {
            if (!key.startsWith('$')) walk(child, [...segments, key]);
        }
    };

    for (const entry of fs.readdirSync(REAL_TOKENS_DIR).sort()) {
        walk(JSON.parse(fs.readFileSync(path.join(REAL_TOKENS_DIR, entry), 'utf8')), []);
    }

    return paths;
}

const artifactPaths = (root) => ARTIFACTS.map((relative) => path.join(root, relative));
const digest = (file) => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');

describe('buildTokens', () => {
    it('writes the four AD-3 artifacts, each with the generated header', async () => {
        const root = makeRepo();

        const written = await buildTokens({ repoRoot: root, tokensDir: REAL_TOKENS_DIR });

        assert.deepEqual(written, artifactPaths(root));

        for (const file of artifactPaths(root)) {
            assert.ok(fs.existsSync(file), `${file} was not written`);
            const contents = fs.readFileSync(file, 'utf8');

            if (file.endsWith('.json')) {
                // JSON carries no comments — the notice is the first key.
                assert.match(contents, /^\{\n {4}"__generated": "GENERATED FILE — DO NOT EDIT\./);
            } else {
                assert.ok(contents.startsWith('/*\n * GENERATED FILE — DO NOT EDIT.'), file);
            }
        }
    });

    it('gives both apps the same custom-property vocabulary', async () => {
        const root = makeRepo();
        await buildTokens({ repoRoot: root, tokensDir: REAL_TOKENS_DIR });

        const [, wwwCss, portalTheme, portalBase] = artifactPaths(root);
        const wwwText = fs.readFileSync(wwwCss, 'utf8');
        const themeText = fs.readFileSync(portalTheme, 'utf8');

        assert.match(themeText, /^@theme static \{$/m);
        assert.doesNotMatch(themeText, /@layer base/);
        assert.match(wwwText, /^:root \{$/m);
        assert.match(wwwText, /^@layer base \{$/m);

        const names = (text) => new Set([...text.matchAll(/^ {4}(--[a-z0-9-]+):/gm)].map((m) => m[1]));
        assert.deepEqual([...names(wwwText)].sort(), [...names(themeText)].sort());

        for (const required of [
            '--color-primary',
            '--color-selection',
            '--font-sans',
            '--text-base',
            '--text-base--line-height',
            '--font-weight-bold',
            '--spacing-4',
            '--radius-md',
            '--ease-out',
            '--duration-base',
            '--button-radius',
            '--link-color',
        ]) {
            assert.ok(names(themeText).has(required), `${required} is missing`);
        }

        // tokens.base.css is the same base styles, with no custom properties.
        const baseText = fs.readFileSync(portalBase, 'utf8');
        assert.match(baseText, /^@layer base \{$/m);
        assert.doesNotMatch(baseText, /^:root \{$/m);
        assert.ok(wwwText.endsWith(baseText.slice(baseText.indexOf('@layer base'))));
    });

    it('resolves references at build time, in every artifact', async () => {
        const root = makeRepo();
        await buildTokens({ repoRoot: root, tokensDir: REAL_TOKENS_DIR });

        for (const file of artifactPaths(root)) {
            assert.doesNotMatch(fs.readFileSync(file, 'utf8'), /\{[a-z][a-z0-9.-]*\}/);
        }
    });

    it('propagates a token edit to every artifact that carries values', async () => {
        const root = makeRepo();
        const tokensDir = makeTokensDir();
        const colorFile = path.join(tokensDir, 'color.json');

        const edited = fs.readFileSync(colorFile, 'utf8').replace('#2447e0', '#ff0055');
        assert.notEqual(edited, fs.readFileSync(colorFile, 'utf8'), 'fixture no longer matches');
        fs.writeFileSync(colorFile, edited);

        await buildTokens({ repoRoot: root, tokensDir });

        const [themeJson, wwwCss, portalTheme, portalBase] = artifactPaths(root);

        for (const file of [themeJson, wwwCss, portalTheme]) {
            assert.match(fs.readFileSync(file, 'utf8'), /#ff0055/, `${file} kept the old value`);
        }

        // tokens.base.css holds base styles only — by design it carries no
        // literal values at all. It picks the edit up through the custom
        // property that tokens.theme.css declares.
        const baseText = fs.readFileSync(portalBase, 'utf8');
        assert.doesNotMatch(baseText, /#[0-9a-f]{3,8}\b/i);
        assert.match(baseText, /var\(--color-primary\)/);
    });

    it('is byte-deterministic across runs', async () => {
        const root = makeRepo();

        await buildTokens({ repoRoot: root, tokensDir: REAL_TOKENS_DIR });
        const first = artifactPaths(root).map(digest);

        await buildTokens({ repoRoot: root, tokensDir: REAL_TOKENS_DIR });
        const second = artifactPaths(root).map(digest);

        assert.deepEqual(second, first);

        for (const file of artifactPaths(root)) {
            assert.doesNotMatch(
                fs.readFileSync(file, 'utf8'),
                /\b20\d\d-\d\d-\d\d|GMT|UTC\b/,
                `${file} looks like it carries a timestamp`
            );
        }
    });
});

describe('buildTokens input validation', () => {
    it('refuses a theme.base.json that claims a token-owned key', async () => {
        const base = JSON.parse(fs.readFileSync(REAL_THEME_BASE, 'utf8'));
        base.settings.color.palette = [{ slug: 'hand-written', name: 'Hand written', color: '#000000' }];
        const root = makeRepo({ themeBase: JSON.stringify(base, null, 4) });

        await assert.rejects(
            () => buildTokens({ repoRoot: root, tokensDir: REAL_TOKENS_DIR }),
            (error) => {
                assert.match(error.message, /settings\.color\.palette/);
                return true;
            }
        );

        // The clash is caught before Style Dictionary runs, so nothing is written.
        for (const file of artifactPaths(root)) {
            assert.ok(!fs.existsSync(file), `${file} should not exist`);
        }
    });

    it('names the path when theme.base.json is absent', async () => {
        const root = makeRepo({ themeBase: null });

        await assert.rejects(
            () => buildTokens({ repoRoot: root, tokensDir: REAL_TOKENS_DIR }),
            (error) => {
                assert.match(error.message, /theme\.base\.json is missing/);
                assert.ok(error.message.includes(path.join(root, THEME_BASE_RELATIVE)));
                return true;
            }
        );
    });

    it('refuses a theme.base.json that is not a JSON object', async () => {
        for (const [label, contents] of [
            ['null', 'null'],
            ['an array', '[]'],
            ['string', '"nope"'],
        ]) {
            const root = makeRepo({ themeBase: contents });

            await assert.rejects(
                () => buildTokens({ repoRoot: root, tokensDir: REAL_TOKENS_DIR }),
                (error) => {
                    assert.match(error.message, /theme\.base\.json must be a JSON object/, label);
                    return true;
                }
            );

            for (const file of artifactPaths(root)) {
                assert.ok(!fs.existsSync(file), `${file} should not exist for ${label}`);
            }
        }
    });

    it('stops on a broken reference and names the token', async () => {
        const root = makeRepo();
        const tokensDir = makeTokensDir();
        const colorFile = path.join(tokensDir, 'color.json');

        fs.writeFileSync(
            colorFile,
            fs.readFileSync(colorFile, 'utf8').replace('"{color.brand.600}"', '"{color.nope}"')
        );

        await assert.rejects(
            () => buildTokens({ repoRoot: root, tokensDir }),
            (error) => {
                assert.match(error.message, /color\.nope/);
                return true;
            }
        );
    });

    it('checks references inside array values too', async () => {
        const root = makeRepo();
        const tokensDir = makeDirWith({
            'font.json': JSON.stringify({
                font: { sans: { $type: 'fontFamily', $value: ['{font.missing}', 'sans-serif'] } },
            }),
        });

        await assert.rejects(
            () => buildTokens({ repoRoot: root, tokensDir }),
            (error) => {
                assert.match(error.message, /font\.sans references \{font\.missing\}/);
                return true;
            }
        );
    });

    it('refuses two token paths that collapse to one custom property', async () => {
        const root = makeRepo();
        const tokensDir = makeDirWith({
            'color.json': JSON.stringify({
                color: {
                    $type: 'color',
                    'foo-bar': { $value: '#000000' },
                    foo: { bar: { $value: '#ffffff' } },
                },
            }),
        });

        await assert.rejects(
            () => buildTokens({ repoRoot: root, tokensDir }),
            (error) => {
                assert.match(error.message, /duplicate custom-property names/);
                assert.match(error.message, /--color-foo-bar/);
                assert.match(error.message, /color\.foo-bar/);
                assert.match(error.message, /color\.foo\.bar/);
                return true;
            }
        );

        for (const file of artifactPaths(root)) {
            assert.ok(!fs.existsSync(file), `${file} should not exist`);
        }
    });

    it('refuses two tokens that collapse to one theme.json preset slug', () => {
        // Under today's mapping a slug clash always comes with a name clash, so
        // this guard is exercised directly rather than through a source fixture.
        for (const [group, pathA, pathB] of [
            ['palette', ['color', 'a'], ['color', 'a']],
            ['fontFamilies', ['font', 'sans'], ['font', 'sans']],
            ['fontSizes', ['text', 'base', 'size'], ['text', 'base', 'size']],
            ['spacingSizes', ['spacing', '4'], ['spacing', '4']],
        ]) {
            assert.throws(
                () => checkPresetSlugsUnique([{ path: pathA }, { path: pathB }]),
                (error) => {
                    assert.match(error.message, /duplicate theme\.json preset slugs/);
                    assert.match(error.message, new RegExp(group));
                    return true;
                },
                group
            );
        }

        // A token no preset list takes never clashes.
        assert.doesNotThrow(() =>
            checkPresetSlugsUnique([{ path: ['radius', 'md'] }, { path: ['radius', 'md'] }])
        );

        // And the real sources are clean on both axes.
        const tokens = realTokenPaths().map((segments) => ({ path: segments }));
        assert.doesNotThrow(() => checkPresetSlugsUnique(tokens));
        assert.doesNotThrow(() => checkNamesUnique(tokens));
    });

    it('refuses a var(--x) in the base styles that no token defines', async () => {
        const root = makeRepo();
        const baseDir = makeDirWith({
            'base.css': '@layer base {\n  body { color: var(--color-text); outline: var(--nope-not-a-token); }\n}\n',
        });

        await assert.rejects(
            () => buildTokens({ repoRoot: root, tokensDir: REAL_TOKENS_DIR, baseCssPath: path.join(baseDir, 'base.css') }),
            (error) => {
                assert.match(error.message, /base styles use custom properties no token defines/);
                assert.match(error.message, /--nope-not-a-token/);
                assert.doesNotMatch(error.message, /--color-text\b/);
                return true;
            }
        );

        for (const file of artifactPaths(root)) {
            assert.ok(!fs.existsSync(file), `${file} should not exist`);
        }
    });

    it('accepts the real base styles against the real tokens', () => {
        // The guard above is only useful if the shipped partial passes it.
        const names = new Set(realTokenPaths().map(tokenName));
        const used = [...fs.readFileSync(REAL_BASE_CSS, 'utf8').matchAll(/var\(\s*(--[\w-]+)/g)].map(
            (match) => match[1].slice(2)
        );

        assert.ok(used.length > 0, 'the base partial reads no custom properties at all');
        assert.deepEqual(used.filter((name) => !names.has(name)), []);
    });

    it('refuses a token source folder with no tokens in it', async () => {
        const root = makeRepo();
        const tokensDir = makeDirWith({ 'empty.json': '{}' });

        await assert.rejects(
            () => buildTokens({ repoRoot: root, tokensDir }),
            (error) => {
                assert.match(error.message, /no tokens found/);
                assert.ok(error.message.includes(tokensDir));
                return true;
            }
        );

        for (const file of artifactPaths(root)) {
            assert.ok(!fs.existsSync(file), `${file} should not exist`);
        }
    });
});

describe('tokenName', () => {
    it('follows the one naming rule', () => {
        assert.equal(tokenName(['color', 'brand', '600']), 'color-brand-600');
        assert.equal(tokenName(['text', 'base', 'size']), 'text-base');
        assert.equal(tokenName(['text', 'base', 'line-height']), 'text-base--line-height');
        assert.equal(tokenName(['font', 'sans']), 'font-sans');
        assert.equal(tokenName(['font-weight', 'bold']), 'font-weight-bold');
        // Only a whole `size` segment is dropped, never a `-size` suffix.
        assert.equal(tokenName(['button', 'font-size']), 'button-font-size');
    });
});

describe('theme.json merge', () => {
    it('injects only the four token-owned keys and keeps the theme half', async () => {
        const root = makeRepo();
        await buildTokens({ repoRoot: root, tokensDir: REAL_TOKENS_DIR });

        const merged = JSON.parse(fs.readFileSync(path.join(root, ARTIFACTS[0]), 'utf8'));
        const base = JSON.parse(fs.readFileSync(REAL_THEME_BASE, 'utf8'));

        assert.equal(merged.version, 3);
        assert.equal(merged.settings.appearanceTools, true);
        assert.equal(merged.settings.color.defaultPalette, false);
        assert.equal(merged.settings.spacing.spacingScale.steps, 0);
        assert.equal(merged.settings.layout.contentSize, base.settings.layout.contentSize);
        assert.equal(merged.styles, undefined, 'the build must never write styles.*');

        for (const preset of [
            merged.settings.color.palette,
            merged.settings.typography.fontFamilies,
            merged.settings.typography.fontSizes,
            merged.settings.spacing.spacingSizes,
        ]) {
            assert.ok(Array.isArray(preset) && preset.length > 0);
            for (const entry of preset) {
                assert.match(entry.slug, /^[a-z0-9-]+$/);
                assert.equal(typeof entry.name, 'string');
            }
        }

        const bySlug = (list, slug) => list.find((entry) => entry.slug === slug);
        assert.equal(bySlug(merged.settings.color.palette, 'primary').color, '#2447e0');
        assert.equal(bySlug(merged.settings.typography.fontSizes, 'base').size, '1rem');
        assert.equal(bySlug(merged.settings.spacing.spacingSizes, '4').size, '1rem');
        assert.match(bySlug(merged.settings.typography.fontFamilies, 'sans').fontFamily, /system-ui/);
    });

    it('keeps the generated notice first, even when theme.base.json sets one', async () => {
        const base = JSON.parse(fs.readFileSync(REAL_THEME_BASE, 'utf8'));
        const root = makeRepo({
            themeBase: JSON.stringify({ __generated: 'hand-written imposter', ...base }, null, 4),
        });

        await buildTokens({ repoRoot: root, tokensDir: REAL_TOKENS_DIR });

        const contents = fs.readFileSync(path.join(root, ARTIFACTS[0]), 'utf8');
        assert.match(contents, /^\{\n {4}"__generated": "GENERATED FILE — DO NOT EDIT\./);
        assert.doesNotMatch(contents, /imposter/);
        assert.equal(Object.keys(JSON.parse(contents))[0], '__generated');
    });
});

describe('build.js as a CLI', () => {
    const runBuild = (buildJs, repoRoot) =>
        spawnSync(process.execPath, [buildJs], {
            encoding: 'utf8',
            env: { ...process.env, WO_TOKENS_REPO_ROOT: repoRoot },
        });

    it('honours WO_TOKENS_REPO_ROOT, exits 0, and lists the artifacts', () => {
        const root = makeRepo();

        const result = runBuild(BUILD_JS, root);

        assert.equal(result.status, 0, result.stderr);
        for (const artifact of ARTIFACTS) {
            assert.ok(result.stdout.includes(artifact), `stdout did not list ${artifact}`);
            assert.ok(fs.existsSync(path.join(root, artifact)), `${artifact} was not written`);
        }
    });

    it('exits 1 and names the token when a reference is broken', () => {
        const root = makeRepo();
        const pkg = makePackageCopy();
        const colorFile = path.join(pkg, 'tokens', 'color.json');

        fs.writeFileSync(
            colorFile,
            fs.readFileSync(colorFile, 'utf8').replace('"{color.brand.600}"', '"{color.nope}"')
        );

        const result = runBuild(path.join(pkg, 'build.js'), root);

        assert.equal(result.status, 1);
        assert.match(result.stderr, /Design-token build failed/);
        assert.match(result.stderr, /color\.nope/);
        assert.equal(result.stdout, '');

        for (const artifact of ARTIFACTS) {
            assert.ok(!fs.existsSync(path.join(root, artifact)), `${artifact} should not exist`);
        }
    });
});

describe('artifacts stay out of git', () => {
    it('has every AD-3 artifact covered by a .gitignore rule', () => {
        for (const artifact of ARTIFACTS) {
            const result = spawnSync('git', ['check-ignore', '-q', path.join(REPO_ROOT, artifact)], {
                cwd: REPO_ROOT,
                encoding: 'utf8',
            });
            assert.equal(result.status, 0, `${artifact} is not gitignored`);
        }
    });
});

describe('setup command section 0', () => {
    const NPM_RECORD = 'npm-argv.txt';

    /**
     * Run a setup command with a stub PATH, so nothing past section 0 can run.
     *
     * @param {'www'|'portal'} app
     * @param {object} stubs  `{ npm: boolean, nodeExitCode: number|null }`
     */
    function runSetup(app, { npm = false, nodeExitCode = null } = {}) {
        const root = makeRepo();
        const bin = temporaryDir('wo-bin-');
        const record = path.join(bin, NPM_RECORD);

        if (npm) {
            // Records what it was called with and where, then fails, so the
            // script stops at the end of section 0.
            fs.writeFileSync(
                path.join(bin, 'npm'),
                `#!/bin/sh\nprintf '%s\\n' "$*" >> '${record}'\npwd >> '${record}'\nexit 1\n`,
                { mode: 0o755 }
            );
        }

        if (nodeExitCode !== null) {
            fs.writeFileSync(
                path.join(bin, 'node'),
                `#!/bin/sh\ncase "$1" in -v) echo 'v24.0.0' ;; esac\nexit ${nodeExitCode}\n`,
                { mode: 0o755 }
            );
        }

        const script = app === 'www' ? WWW_SETUP : PORTAL_SETUP;
        const result = spawnSync('/bin/bash', [script], {
            encoding: 'utf8',
            env: {
                PATH: bin,
                DDEV_APPROOT: path.join(root, 'apps', app),
                HOME: root,
            },
        });

        return {
            ...result,
            root,
            record: fs.existsSync(record) ? fs.readFileSync(record, 'utf8').trim().split('\n') : [],
        };
    }

    for (const app of ['www', 'portal']) {
        it(`${app}-setup stops with the Node 24 message when npm is missing`, () => {
            const result = runSetup(app, { npm: false });

            assert.equal(result.status, 1);
            assert.match(result.stderr, /ERROR: npm was not found on the host\./);
            assert.match(result.stderr, /^ {7}Install Node 24 — see packages\/design-tokens\/README\.md$/m);
            assert.equal(result.stdout, '');
        });

        it(`${app}-setup stops with the Node 24 message when the host Node is too old`, () => {
            const result = runSetup(app, { npm: true, nodeExitCode: 1 });

            assert.equal(result.status, 1);
            assert.match(result.stderr, /host Node is too old/);
            assert.match(result.stderr, /^ {7}Install Node 24 — see packages\/design-tokens\/README\.md$/m);
            assert.equal(result.stdout, '');
            assert.deepEqual(result.record, [], 'npm must not run when the Node check fails');
        });

        it(`${app}-setup runs exactly \`npm run tokens:build\` from the repo root, first`, () => {
            const result = runSetup(app, { npm: true, nodeExitCode: 0 });

            assert.equal(result.status, 1, 'the stub npm fails, so the script must stop');
            assert.equal(result.stdout, '==> Building design tokens ...\n');

            const [argv, cwd] = result.record;
            assert.equal(argv, 'run tokens:build');
            assert.equal(fs.realpathSync(cwd), fs.realpathSync(result.root));
        });
    }
});
