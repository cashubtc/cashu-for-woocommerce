#!/usr/bin/env php
<?php
/**
 * Generate languages/cashu-for-woocommerce.pot from the plugin source.
 *
 * Replaces grunt-wp-i18n's makepot. Pure PHP, zero new deps — uses the
 * built-in tokenizer to find WP i18n calls and emits a standard gettext
 * .pot file.
 *
 * Supported functions: __, _x, esc_attr__, esc_html__, esc_html_e.
 * The codebase doesn't use plurals (_n / _nx) so we don't either.
 *
 * Translator comments (block comments starting with "translators:" on the
 * lines immediately preceding the call) are preserved as #. comments.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

$domain  = 'cashu-for-woocommerce';
$out     = 'languages/cashu-for-woocommerce.pot';
$plugin  = 'Cashu For WooCommerce';
$version = parse_plugin_version("$root/cashu-for-woocommerce.php");

// Functions to extract. The integer is the position of the msgid arg (1-based).
// _x takes (text, context, domain) so context is recorded separately.
$funcs = [
    '__'           => ['msgid' => 0, 'msgctxt' => null],
    'esc_attr__'   => ['msgid' => 0, 'msgctxt' => null],
    'esc_html__'   => ['msgid' => 0, 'msgctxt' => null],
    'esc_html_e'   => ['msgid' => 0, 'msgctxt' => null],
    '_x'           => ['msgid' => 0, 'msgctxt' => 1],
];

// Source files.
$files = ['cashu-for-woocommerce.php', 'uninstall.php'];
foreach (rii('src') as $path) {
    if (substr($path, -4) === '.php') {
        $files[] = $path;
    }
}
sort($files);

// Aggregate: key = msgctxt . "\x04" . msgid -> entry array
$entries = [];

// Plugin-header strings (grunt-wp-i18n surfaces these too). The bootstrap is
// the only file with a WP plugin header so we scan just that.
$plugin_file = 'cashu-for-woocommerce.php';
$header_map  = [
    'Plugin Name' => 'Plugin Name of the plugin',
    'Plugin URI'  => 'Plugin URI of the plugin',
    'Description' => 'Description of the plugin',
    'Author'      => 'Author of the plugin',
    'Author URI'  => 'Author URI of the plugin',
];
$plugin_src = file_get_contents($plugin_file);
foreach ($header_map as $field => $comment) {
    if (preg_match('/^\s*\*\s*' . preg_quote($field, '/') . ':\s*(.+)$/m', $plugin_src, $m)) {
        $value = trim($m[1]);
        if ($value === '') {
            continue;
        }
        $key  = "\x04" . $value;
        $entries[$key] = [
            'msgid'     => $value,
            'msgctxt'   => null,
            'comments'  => [$comment],
            'locations' => [],
        ];
    }
}

foreach ($files as $relpath) {
    $tokens = token_get_all(file_get_contents($relpath));
    $count  = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok) || $tok[0] !== T_STRING) {
            continue;
        }
        $name = $tok[1];
        if (!isset($funcs[$name])) {
            continue;
        }
        // Must be a function call: skip the namespace separator / object
        // operators that precede method calls, etc.
        $prev = prev_significant($tokens, $i);
        if ($prev !== null) {
            $pt = $tokens[$prev];
            if (is_array($pt) && in_array($pt[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }
        }
        // Next significant token must be '('
        $next = next_significant($tokens, $i);
        if ($next === null || $tokens[$next] !== '(') {
            continue;
        }
        $line = $tok[2];
        $args = collect_string_args($tokens, $next, 3);
        if (!isset($args[$funcs[$name]['msgid']])) {
            continue;
        }
        $msgid   = $args[$funcs[$name]['msgid']];
        $msgctxt = null;
        if ($funcs[$name]['msgctxt'] !== null && isset($args[$funcs[$name]['msgctxt']])) {
            $msgctxt = $args[$funcs[$name]['msgctxt']];
        }
        // Find any "translators:" comment immediately preceding the call.
        $comment = find_translator_comment($tokens, $i);

        $key = ($msgctxt ?? '') . "\x04" . $msgid;
        if (!isset($entries[$key])) {
            $entries[$key] = [
                'msgid'     => $msgid,
                'msgctxt'   => $msgctxt,
                'comments'  => [],
                'locations' => [],
            ];
        }
        $entries[$key]['locations'][] = "$relpath:$line";
        if ($comment !== null && !in_array($comment, $entries[$key]['comments'], true)) {
            $entries[$key]['comments'][] = $comment;
        }
    }
}

// Sort entries by first location for stable output. Plugin-header entries
// have no location; sort them first.
uasort($entries, function ($a, $b) {
    $la = $a['locations'][0] ?? '';
    $lb = $b['locations'][0] ?? '';
    return strcmp($la, $lb);
});

// Build .pot output.
$year   = date('Y');
$author = 'Rob Woodgate';
$out_buf = "# Copyright (C) $year $author\n";
$out_buf .= "# This file is distributed under the MIT.\n";
$out_buf .= "msgid \"\"\n";
$out_buf .= "msgstr \"\"\n";
$out_buf .= "\"Project-Id-Version: $plugin $version\\n\"\n";
$out_buf .= "\"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/$domain\\n\"\n";
$out_buf .= "\"MIME-Version: 1.0\\n\"\n";
$out_buf .= "\"Content-Type: text/plain; charset=utf-8\\n\"\n";
$out_buf .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$out_buf .= "\"Language-Team: LANGUAGE <LL@li.org>\\n\"\n";
$out_buf .= "\"Language: en\\n\"\n";
$out_buf .= "\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n";
$out_buf .= "\"X-Generator: scripts/build-pot.php\\n\"\n";

foreach ($entries as $e) {
    $out_buf .= "\n";
    if (!empty($e['locations'])) {
        $out_buf .= '#: ' . implode(' ', $e['locations']) . "\n";
    }
    foreach ($e['comments'] as $c) {
        foreach (preg_split('/\r?\n/', $c) as $line) {
            $out_buf .= "#. " . rtrim($line) . "\n";
        }
    }
    if ($e['msgctxt'] !== null) {
        $out_buf .= 'msgctxt ' . pot_quote($e['msgctxt']) . "\n";
    }
    $out_buf .= 'msgid ' . pot_quote($e['msgid']) . "\n";
    $out_buf .= "msgstr \"\"\n";
}

file_put_contents($out, $out_buf);
fwrite(STDERR, "Wrote $out (" . count($entries) . " strings)\n");

// --- helpers --- //

function rii(string $dir): array
{
    $out = [];
    $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $out[] = $f->getPathname();
    }
    return $out;
}

function parse_plugin_version(string $path): string
{
    if (preg_match('/^\s*\*\s*Version:\s*(.+)$/m', file_get_contents($path), $m)) {
        return trim($m[1]);
    }
    return '0.0.0';
}

function prev_significant(array $tokens, int $i): ?int
{
    for ($j = $i - 1; $j >= 0; $j--) {
        $t = $tokens[$j];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $j;
    }
    return null;
}

function next_significant(array $tokens, int $i): ?int
{
    $count = count($tokens);
    for ($j = $i + 1; $j < $count; $j++) {
        $t = $tokens[$j];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $j;
    }
    return null;
}

/**
 * Starting at the index of '(', collect up to $max string-literal args.
 * Returns indexed array of decoded PHP strings (in PHP source value form).
 * Stops at the matching ')' or after $max args. Non-string args become null
 * and abort that slot but we keep counting commas.
 */
function collect_string_args(array $tokens, int $openParen, int $max): array
{
    $args  = [];
    $depth = 1;
    $argIdx = 0;
    $count  = count($tokens);
    $curStr = null;     // accumulating string for current arg (handles concat)
    $curBad = false;    // current arg has non-string content

    for ($j = $openParen + 1; $j < $count; $j++) {
        $t = $tokens[$j];
        if (is_array($t)) {
            if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $val = decode_php_string($t[1]);
                $curStr = ($curStr === null) ? $val : $curStr . $val;
                continue;
            }
            // Anything else within current arg slot disqualifies it.
            $curBad = true;
            continue;
        }
        // Single-char tokens.
        if ($t === '(') {
            $depth++;
            $curBad = true;
            continue;
        }
        if ($t === ')') {
            $depth--;
            if ($depth === 0) {
                if (!$curBad && $curStr !== null) {
                    $args[$argIdx] = $curStr;
                }
                break;
            }
            continue;
        }
        if ($t === ',' && $depth === 1) {
            if (!$curBad && $curStr !== null) {
                $args[$argIdx] = $curStr;
            }
            $argIdx++;
            $curStr = null;
            $curBad = false;
            if ($argIdx >= $max) {
                break;
            }
            continue;
        }
        if ($t === '.' && $depth === 1) {
            // string concatenation between literals — keep going.
            continue;
        }
        $curBad = true;
    }
    return $args;
}

/**
 * Decode a T_CONSTANT_ENCAPSED_STRING token value into its actual string.
 * Handles both single-quoted ('...') and double-quoted ("...") forms.
 */
function decode_php_string(string $raw): string
{
    if ($raw === '') {
        return '';
    }
    $first = $raw[0];
    $inner = substr($raw, 1, -1);
    if ($first === "'") {
        // Single quotes: only \\ and \' are escapes.
        return strtr($inner, ["\\\\" => "\\", "\\'" => "'"]);
    }
    // Double quotes: full escape table.
    return stripcslashes($inner);
}

/**
 * Find the closest /* translators: ... *​/ block comment preceding the
 * function call at $i, skipping whitespace.
 */
function find_translator_comment(array $tokens, int $i): ?string
{
    for ($j = $i - 1; $j >= 0; $j--) {
        $t = $tokens[$j];
        if (!is_array($t)) {
            return null;
        }
        if ($t[0] === T_WHITESPACE) {
            continue;
        }
        if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
            $text = $t[1];
            // Strip /* ... */ or // wrappers.
            $text = preg_replace('#^/\*+#', '', $text);
            $text = preg_replace('#\*+/$#', '', $text);
            $text = preg_replace('#^//\s*#', '', $text);
            // Strip leading "* " from each line of a block comment.
            $lines = preg_split('/\r?\n/', $text);
            $lines = array_map(function ($l) {
                return ltrim(preg_replace('/^\s*\*\s?/', '', $l));
            }, $lines);
            $text = trim(implode("\n", $lines));
            if (stripos($text, 'translators:') === 0) {
                return $text;
            }
            return null;
        }
        return null;
    }
    return null;
}

/**
 * Quote a string for .pot output, with line-wrapping at ~76 chars.
 * Multi-line strings are emitted as msgid "" followed by continuation lines.
 */
function pot_quote(string $s): string
{
    $esc = strtr($s, [
        "\\" => "\\\\",
        '"'  => '\\"',
        "\n" => '\\n',
        "\t" => '\\t',
        "\r" => '\\r',
    ]);
    // Single-line if short enough.
    if (strlen($esc) <= 72 && strpos($esc, '\\n') === false) {
        return '"' . $esc . '"';
    }
    // Split into lines, each ending with literal \n (the gettext convention)
    // wherever the original had a newline.
    $parts = preg_split('/(\\\\n)/', $esc, -1, PREG_SPLIT_DELIM_CAPTURE);
    $lines = [];
    $cur   = '';
    foreach ($parts as $p) {
        if ($p === '\\n') {
            $lines[] = $cur . '\\n';
            $cur     = '';
        } else {
            $cur .= $p;
        }
    }
    if ($cur !== '') {
        $lines[] = $cur;
    }
    $out = "\"\"\n";
    foreach ($lines as $l) {
        $out .= '"' . $l . "\"\n";
    }
    return rtrim($out, "\n");
}
