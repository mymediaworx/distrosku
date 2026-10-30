<?php
// Prints GitHub Actions ::error annotations for failed / errored tests in a PHPUnit JUnit file (max 30).
$file = $argv[1] ?? 'junit.xml';
if (! is_file($file)) {
    echo "::error title=PHPUnit::No JUnit file ({$file}): the run stopped before any result was written (fatal error or timeout).\n";
    exit(0);
}
$xml = simplexml_load_file($file);
$n = 0;
foreach ($xml->xpath('//testcase[failure or error]') as $tc) {
    if (++$n > 30) {
        echo "::error title=PHPUnit::More failures not shown.\n";
        break;
    }
    $node = $tc->failure ?: $tc->error;
    $msg = trim(substr((string) $node, 0, 600));
    $msg = str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $msg);
    $title = str_replace(['%', "\n", ',', ':'], ['%25', ' ', ' ', ' '], $tc['class'].'::'.$tc['name']);
    echo "::error title={$title}::{$msg}\n";
}
