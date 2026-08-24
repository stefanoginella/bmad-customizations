/**
 * The one design-token build for woptimize.io.
 *
 * Reads the DTCG sources in ./tokens plus the hand-written base-styles partial
 * in ./src/base.css, and writes exactly the four AD-3 artifacts. Every artifact
 * is generated, gitignored, and must never be hand-edited.
 *
 *   apps/www/themes/woptimize-theme/theme.json              (merged into theme.base.json)
 *   apps/www/themes/woptimize-theme/assets/css/tokens.css
 *   apps/portal/resources/css/tokens.theme.css
 *   apps/portal/resources/css/tokens.base.css
 *
 * Run it from the repo root: `npm run tokens:build`.
 *
 * Every check runs before Style Dictionary writes anything, so a bad input
 * leaves the artifacts untouched rather than half-written.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import StyleDictionary from 'style-dictionary';

const PACKAGE_DIR = path.dirname(fileURLToPath(import.meta.url));

/** Repo-relative paths of the four artifacts. Fixed by AD-3. */
export const ARTIFACTS = Object.freeze([
    'apps/www/themes/woptimize-theme/theme.json',
    'apps/www/themes/woptimize-theme/assets/css/tokens.css',
    'apps/portal/resources/css/tokens.theme.css',
    'apps/portal/resources/css/tokens.base.css',
]);

/** Repo-relative path of the committed, theme-owned half of theme.json. */
export const THEME_BASE_RELATIVE = 'apps/www/themes/woptimize-theme/theme.base.json';

/**
 * theme.json keys the token build owns. The theme owns every other key in
 * theme.base.json. One writer per section — a key in both is an error, not a
 * silent overwrite.
 */
export const TOKEN_OWNED_THEME_JSON_KEYS = Object.freeze([
    'settings.color.palette',
    'settings.typography.fontFamilies',
    'settings.typography.fontSizes',
    'settings.spacing.spacingSizes',
]);

const GENERATED_NOTICE = [
    'GENERATED FILE — DO NOT EDIT.',
    '',
    'Built from packages/design-tokens by `npm run tokens:build` (repo root).',
    'Edit the DTCG sources in packages/design-tokens/tokens/ instead.',
];

const CSS_HEADER = `/*\n${GENERATED_NOTICE.map((line) => (line ? ` * ${line}` : ' *')).join('\n')}\n */\n`;

/** JSON carries no comments, so the notice is the first key instead. */
const JSON_HEADER_KEY = '__generated';
const JSON_HEADER_VALUE = GENERATED_NOTICE.filter(Boolean).join(' ');

// --- Errors ------------------------------------------------------------------

/** A build failure the CLI reports as a plain message, without a stack. */
export class TokenBuildError extends Error {
    constructor(message) {
        super(message);
        this.name = 'TokenBuildError';
    }
}

// --- Small helpers -----------------------------------------------------------

/** DTCG-aware value read: transformed tokens keep `$value` when usesDtcg is on. */
function tokenValue(token) {
    return token.$value !== undefined ? token.$value : token.value;
}

/** `brand-600` -> `Brand 600`. */
function titleCase(slug) {
    return String(slug)
        .split('-')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

function isPlainObject(value) {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function getIn(object, segments) {
    return segments.reduce(
        (node, key) => (node && typeof node === 'object' ? node[key] : undefined),
        object
    );
}

function setIn(object, segments, value) {
    let node = object;
    for (const key of segments.slice(0, -1)) {
        if (!isPlainObject(node[key])) {
            node[key] = {};
        }
        node = node[key];
    }
    node[segments.at(-1)] = value;
}

// --- Naming ------------------------------------------------------------------

/**
 * The one custom-property vocabulary, shared by both apps.
 *
 *   color.brand.600      -> --color-brand-600
 *   text.base.size       -> --text-base          (a trailing `size` segment is dropped)
 *   text.base.line-height-> --text-base--line-height
 *   button.font-size     -> --button-font-size   (only a whole `size` segment goes)
 *
 * The leading `--` is added by the CSS formats, not here.
 */
export function tokenName(pathSegments) {
    const segments = [...pathSegments];

    if (segments[0] === 'text' && segments.at(-1) === 'line-height') {
        return `${segments.slice(0, -1).join('-')}--line-height`;
    }

    if (segments.length > 1 && segments.at(-1) === 'size') {
        segments.pop();
    }

    return segments.join('-');
}

// --- theme.json --------------------------------------------------------------

/**
 * Which theme.json preset list a token feeds, and under which slug.
 * Returns null for a token no preset list takes.
 */
function presetEntry(segments) {
    if (segments[0] === 'color') {
        return { group: 'palette', slug: segments.slice(1).join('-') };
    }
    if (segments[0] === 'font') {
        return { group: 'fontFamilies', slug: segments.slice(1).join('-') };
    }
    if (segments[0] === 'text' && segments.at(-1) === 'size') {
        return { group: 'fontSizes', slug: segments.slice(1, -1).join('-') };
    }
    if (segments[0] === 'spacing') {
        return { group: 'spacingSizes', slug: segments.slice(1).join('-') };
    }
    return null;
}

/**
 * Read and validate the theme-owned half of theme.json.
 *
 * Throws when the file is missing, is not a JSON object, or claims a token-owned
 * key. Called before Style Dictionary runs, so an ownership clash writes no
 * artifact at all.
 */
export function readThemeBase(themeBasePath) {
    if (!fs.existsSync(themeBasePath)) {
        throw new TokenBuildError(
            `theme.base.json is missing at ${themeBasePath}\n` +
                'The theme owns that file and it is committed. Restore it, then build again.'
        );
    }

    let base;
    try {
        base = JSON.parse(fs.readFileSync(themeBasePath, 'utf8'));
    } catch (cause) {
        throw new TokenBuildError(`theme.base.json is not valid JSON (${themeBasePath}): ${cause.message}`);
    }

    if (!isPlainObject(base)) {
        throw new TokenBuildError(
            `theme.base.json must be a JSON object (${themeBasePath}) — found ${
                base === null ? 'null' : Array.isArray(base) ? 'an array' : typeof base
            }.`
        );
    }

    for (const key of TOKEN_OWNED_THEME_JSON_KEYS) {
        if (getIn(base, key.split('.')) !== undefined) {
            throw new TokenBuildError(
                `theme.base.json defines ${key}, which the token build owns.\n` +
                    `Remove ${key} from ${themeBasePath} — change the DTCG sources instead.`
            );
        }
    }

    return base;
}

/** Build the four token-owned theme.json preset arrays from the dictionary. */
function themeJsonPresets(allTokens) {
    const presets = { palette: [], fontFamilies: [], fontSizes: [], spacingSizes: [] };

    for (const token of allTokens) {
        const entry = presetEntry(token.path);
        if (entry === null) {
            continue;
        }

        const { group, slug } = entry;
        const value = tokenValue(token);
        const name = titleCase(slug);

        if (group === 'palette') {
            presets.palette.push({ slug, name, color: value });
        } else if (group === 'fontFamilies') {
            presets.fontFamilies.push({ slug, name, fontFamily: value });
        } else if (group === 'fontSizes') {
            presets.fontSizes.push({ slug, name, size: value });
        } else {
            presets.spacingSizes.push({ slug, name, size: String(value) });
        }
    }

    return presets;
}

// --- Formats -----------------------------------------------------------------

function cssCustomProperties(allTokens, indent) {
    return allTokens.map((token) => `${indent}--${token.name}: ${tokenValue(token)};`).join('\n');
}

function readBaseCss(baseCssPath) {
    if (!fs.existsSync(baseCssPath)) {
        throw new TokenBuildError(`base styles partial is missing at ${baseCssPath}`);
    }
    return fs.readFileSync(baseCssPath, 'utf8').trimEnd();
}

function hooks() {
    return {
        transforms: {
            'name/wo': {
                type: 'name',
                transform: (token) => tokenName(token.path),
            },
        },
        formats: {
            // apps/www — custom properties on :root, then the base styles.
            'css/root+base': ({ dictionary, options }) =>
                `${CSS_HEADER}\n:root {\n${cssCustomProperties(dictionary.allTokens, '    ')}\n}\n\n` +
                `${readBaseCss(options.baseCssPath)}\n`,

            // apps/portal — the Tailwind 4 theme file. `static` so every token
            // reaches :root, including the ones only tokens.base.css reads.
            'tailwind/theme-static': ({ dictionary }) =>
                `${CSS_HEADER}\n@theme static {\n${cssCustomProperties(dictionary.allTokens, '    ')}\n}\n`,

            // apps/portal — the same base styles, no custom properties.
            'css/base': ({ options }) => `${CSS_HEADER}\n${readBaseCss(options.baseCssPath)}\n`,

            // apps/www — theme.base.json with the token-owned sections injected.
            'wp/theme-json-merge': ({ dictionary, options }) => {
                // Drop any __generated the theme half carries: the notice always
                // wins, and it must stay the first key.
                const { [JSON_HEADER_KEY]: _themeNotice, ...base } = readThemeBase(options.themeBasePath);
                const presets = themeJsonPresets(dictionary.allTokens);

                const merged = { [JSON_HEADER_KEY]: JSON_HEADER_VALUE, ...base };
                setIn(merged, ['settings', 'color', 'palette'], presets.palette);
                setIn(merged, ['settings', 'typography', 'fontFamilies'], presets.fontFamilies);
                setIn(merged, ['settings', 'typography', 'fontSizes'], presets.fontSizes);
                setIn(merged, ['settings', 'spacing', 'spacingSizes'], presets.spacingSizes);

                return `${JSON.stringify(merged, null, 4)}\n`;
            },
        },
    };
}

// --- Source loading ----------------------------------------------------------

/** Sorted, explicit source list — never a glob, so token order is stable. */
function tokenSources(tokensDir) {
    if (!fs.existsSync(tokensDir)) {
        throw new TokenBuildError(`token source folder is missing at ${tokensDir}`);
    }

    const sources = fs
        .readdirSync(tokensDir)
        .filter((entry) => entry.endsWith('.json'))
        .sort()
        .map((entry) => path.join(tokensDir, entry));

    if (sources.length === 0) {
        throw new TokenBuildError(`no DTCG token files found in ${tokensDir}`);
    }

    return sources;
}

function deepMerge(target, source) {
    for (const [key, value] of Object.entries(source)) {
        if (isPlainObject(value)) {
            target[key] = deepMerge(isPlainObject(target[key]) ? target[key] : {}, value);
        } else {
            target[key] = value;
        }
    }
    return target;
}

/** Merge every DTCG source file into one raw tree, in the given order. */
export function mergeTokenFiles(sourceFiles) {
    const tree = {};
    for (const file of sourceFiles) {
        let parsed;
        try {
            parsed = JSON.parse(fs.readFileSync(file, 'utf8'));
        } catch (cause) {
            throw new TokenBuildError(`${file} is not valid JSON: ${cause.message}`);
        }
        if (!isPlainObject(parsed)) {
            throw new TokenBuildError(`${file} must contain a JSON object at the top level.`);
        }
        deepMerge(tree, parsed);
    }
    return tree;
}

function collectTokens(node, segments, found) {
    if (!isPlainObject(node)) {
        return found;
    }

    if ('$value' in node) {
        found.push({ path: segments, value: node.$value });
        return found;
    }

    for (const [key, child] of Object.entries(node)) {
        if (!key.startsWith('$')) {
            collectTokens(child, [...segments, key], found);
        }
    }

    return found;
}

// --- Pre-write validation ----------------------------------------------------

/** Every string that could hold a `{group.path}` reference, value or array item. */
function referenceCandidates(value) {
    if (typeof value === 'string') {
        return [value];
    }
    if (Array.isArray(value)) {
        return value.filter((item) => typeof item === 'string');
    }
    return [];
}

/**
 * Fail on a `{group.path}` reference that points at nothing.
 *
 * Style Dictionary throws too, but its message only names the count unless the
 * log is verbose. Checking here names every offender and, like the other input
 * checks, runs before any artifact is written. Array values (fontFamily,
 * cubicBezier) are checked item by item.
 */
export function assertReferencesResolve(sourceFiles) {
    const tree = mergeTokenFiles(sourceFiles);
    checkReferences(tree, collectTokens(tree, [], []));
}

function checkReferences(tree, tokens) {
    const broken = [];

    for (const token of tokens) {
        for (const candidate of referenceCandidates(token.value)) {
            for (const [, reference] of candidate.matchAll(/\{([^{}]+)\}/g)) {
                const target = getIn(tree, reference.split('.'));
                if (!isPlainObject(target) || !('$value' in target)) {
                    broken.push(
                        `  ${token.path.join('.')} references {${reference}}, which does not exist`
                    );
                }
            }
        }
    }

    if (broken.length > 0) {
        throw new TokenBuildError(`unresolved token references:\n${broken.join('\n')}`);
    }
}

/**
 * Two token paths must never collapse to one custom property. `color.foo-bar`
 * and `color.foo.bar` both want `--color-foo-bar`; one would silently shadow
 * the other.
 */
export function checkNamesUnique(tokens) {
    const byName = new Map();

    for (const token of tokens) {
        const name = tokenName(token.path);
        const paths = byName.get(name);
        if (paths) {
            paths.push(token.path.join('.'));
        } else {
            byName.set(name, [token.path.join('.')]);
        }
    }

    const clashes = [...byName.entries()]
        .filter(([, paths]) => paths.length > 1)
        .map(([name, paths]) => `  --${name} is claimed by ${paths.join(' and ')}`);

    if (clashes.length > 0) {
        throw new TokenBuildError(`duplicate custom-property names:\n${clashes.join('\n')}`);
    }
}

/**
 * The same collapse, seen from theme.json: two tokens must not share a preset
 * slug. Under today's mapping every preset slug is its custom-property name
 * minus the group prefix, so this can only fire where `checkNamesUnique`
 * already does. It is kept as a separate guard because the mapping is the part
 * most likely to change.
 */
export function checkPresetSlugsUnique(tokens) {
    const byGroup = new Map();

    for (const token of tokens) {
        const entry = presetEntry(token.path);
        if (entry === null) {
            continue;
        }

        const key = `${entry.group}/${entry.slug}`;
        const paths = byGroup.get(key);
        if (paths) {
            paths.push(token.path.join('.'));
        } else {
            byGroup.set(key, [token.path.join('.')]);
        }
    }

    const clashes = [...byGroup.entries()]
        .filter(([, paths]) => paths.length > 1)
        .map(([key, paths]) => `  ${key} is claimed by ${paths.join(' and ')}`);

    if (clashes.length > 0) {
        throw new TokenBuildError(`duplicate theme.json preset slugs:\n${clashes.join('\n')}`);
    }
}

/**
 * Every `var(--x)` in the base-styles partial must name a token that exists.
 * A typo there is invisible in the browser — the declaration just does nothing.
 */
function checkBaseCssVars(baseCss, definedNames) {
    const missing = new Set();

    for (const [, property] of baseCss.matchAll(/var\(\s*(--[\w-]+)/g)) {
        if (!definedNames.has(property.slice(2))) {
            missing.add(property);
        }
    }

    if (missing.size > 0) {
        throw new TokenBuildError(
            'base styles use custom properties no token defines:\n' +
                [...missing].sort().map((property) => `  ${property}`).join('\n')
        );
    }
}

// --- Public API --------------------------------------------------------------

/**
 * Build the four artifacts.
 *
 * @param {object}  [options]
 * @param {string}  [options.repoRoot]    Repo root the artifact paths hang off.
 *                                        Defaults to $WO_TOKENS_REPO_ROOT, else
 *                                        two levels above this package.
 * @param {string}  [options.tokensDir]   Folder of DTCG `*.json` sources.
 * @param {string}  [options.baseCssPath] The base-styles partial.
 * @returns {Promise<string[]>} Absolute paths of the artifacts written.
 */
export async function buildTokens({
    repoRoot = process.env.WO_TOKENS_REPO_ROOT ?? path.resolve(PACKAGE_DIR, '..', '..'),
    tokensDir = path.join(PACKAGE_DIR, 'tokens'),
    baseCssPath = path.join(PACKAGE_DIR, 'src', 'base.css'),
} = {}) {
    const themeBasePath = path.join(repoRoot, THEME_BASE_RELATIVE);

    // --- Validate every input before a single artifact is written ------------
    const source = tokenSources(tokensDir);
    const tree = mergeTokenFiles(source);
    const tokens = collectTokens(tree, [], []);

    if (tokens.length === 0) {
        throw new TokenBuildError(
            `no tokens found in ${tokensDir} — the source files hold no \`$value\` anywhere.`
        );
    }

    checkReferences(tree, tokens);
    checkNamesUnique(tokens);
    checkPresetSlugsUnique(tokens);
    checkBaseCssVars(readBaseCss(baseCssPath), new Set(tokens.map((token) => tokenName(token.path))));
    readThemeBase(themeBasePath);

    // `size/rem` and `time/seconds` from the `css` transform group would rewrite
    // values that already carry their unit, so the list is explicit.
    const transforms = ['name/wo', 'fontFamily/css', 'cubicBezier/css'];

    const dictionary = new StyleDictionary({
        hooks: hooks(),
        log: {
            // Silent success output — the CLI below prints the artifact list.
            // Warnings and errors are unaffected.
            verbosity: 'silent',
            warnings: 'warn',
            errors: { brokenReferences: 'throw' },
        },
        usesDtcg: true,
        source,
        platforms: {
            www: {
                transforms,
                buildPath: path.join(repoRoot, 'apps/www/themes/woptimize-theme/'),
                options: { showFileHeader: false },
                files: [
                    {
                        destination: 'assets/css/tokens.css',
                        format: 'css/root+base',
                        options: { baseCssPath },
                    },
                    {
                        destination: 'theme.json',
                        format: 'wp/theme-json-merge',
                        options: { themeBasePath },
                    },
                ],
            },
            portal: {
                transforms,
                buildPath: path.join(repoRoot, 'apps/portal/resources/css/'),
                options: { showFileHeader: false },
                files: [
                    { destination: 'tokens.theme.css', format: 'tailwind/theme-static' },
                    {
                        destination: 'tokens.base.css',
                        format: 'css/base',
                        options: { baseCssPath },
                    },
                ],
            },
        },
    });

    await dictionary.buildAllPlatforms();

    // Style Dictionary reports a filtered-away or skipped file on stdout, which
    // the silent log swallows. Prove all four landed.
    const written = ARTIFACTS.map((relative) => path.join(repoRoot, relative));
    const absent = written.filter((file) => !fs.existsSync(file));

    if (absent.length > 0) {
        throw new TokenBuildError(
            `the build finished but these artifacts were not written:\n${absent
                .map((file) => `  ${file}`)
                .join('\n')}`
        );
    }

    return written;
}

// --- CLI ---------------------------------------------------------------------

// Compare real paths, not the literal argv. A symlinked directory anywhere in
// the invocation path (macOS /tmp -> /private/tmp, nvm shims, a checkout behind
// a symlink) makes the two strings differ, and the CLI would then silently do
// nothing and exit 0.
const invokedDirectly = (() => {
    if (process.argv[1] === undefined) {
        return false;
    }
    try {
        return fs.realpathSync(process.argv[1]) === fs.realpathSync(fileURLToPath(import.meta.url));
    } catch {
        return false;
    }
})();

if (invokedDirectly) {
    try {
        await buildTokens();
        for (const artifact of ARTIFACTS) {
            console.log(`  ✓ ${artifact}`);
        }
    } catch (error) {
        // A TokenBuildError is a message for a human. Anything else is a bug in
        // the build or in Style Dictionary — show the stack.
        const detail = error instanceof TokenBuildError ? error.message : error.stack;
        console.error(`\nDesign-token build failed.\n\n${detail}\n`);
        process.exitCode = 1;
    }
}
