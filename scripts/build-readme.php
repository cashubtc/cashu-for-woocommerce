#!/usr/bin/env php
<?php
/**
 * Convert readme.txt (WordPress plugin readme) to README.md (GitHub).
 *
 * Replaces grunt-wp-readme-to-markdown. Header transformations:
 *   === Title ===          -> # Title #
 *   Contributors: x        -> **Contributors:** [x](https://profiles.wordpress.org/x/)
 *   Other: value           -> **Other:** value
 *   == Section ==          -> ## Section ##
 *   = Subsection =         -> ### Subsection ###
 *
 * Two trailing spaces on header lines so Markdown renders the hard line break.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

$in  = 'readme.txt';
$out = 'README.md';

$lines = file($in, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "Cannot read $in\n");
    exit(1);
}

$result = [];
$in_header = true; // true until the first blank line after the title block

foreach ($lines as $idx => $line) {
    // Top-level title.
    if (preg_match('/^=== (.+) ===\s*$/', $line, $m)) {
        $result[] = '# ' . trim($m[1]) . ' #';
        continue;
    }
    // Level 2 / 3 sections. Preserve internal whitespace so cosmetic quirks
    // in the source (e.g. ==  Features ==) round-trip identically.
    if (preg_match('/^== (.+) ==\s*$/', $line, $m)) {
        $result[] = '## ' . $m[1] . ' ##';
        $in_header = false;
        continue;
    }
    if (preg_match('/^= (.+) =\s*$/', $line, $m)) {
        $result[] = '### ' . $m[1] . ' ###';
        $in_header = false;
        continue;
    }
    if ($in_header) {
        if ($line === '') {
            $result[] = '';
            $in_header = false;
            continue;
        }
        if (preg_match('/^([A-Za-z][A-Za-z ]+):\s*(.*)$/', $line, $m)) {
            $field = trim($m[1]);
            $value = trim($m[2]);
            if ($field === 'Contributors') {
                $users = array_map('trim', explode(',', $value));
                $links = array_map(function ($u) {
                    return "[$u](https://profiles.wordpress.org/$u/)";
                }, $users);
                $value = implode(', ', $links);
            }
            $result[] = "**$field:** $value  "; // two trailing spaces = <br>
            continue;
        }
    }
    $result[] = $line;
}

file_put_contents($out, implode("\n", $result) . "\n");
fwrite(STDERR, "Wrote $out\n");
