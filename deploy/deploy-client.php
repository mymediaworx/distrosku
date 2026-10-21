<?php
/**
 * Box-side client for public_html/deploy.php.
 *   php deploy-client.php deploy <zip> <url>   |  status <url>  |  rollback <url>
 * url: https://host or https://host/deploy.php. Secrets: $VEGA_SECRETS (default /workspace/vega-erp/secrets):
 *   deploy_hmac_secret (shared with the server), deploy_ed25519_secret (base64 64-byte sodium secret key; never leaves the box).
 */
declare(strict_types=1);

$dir = getenv('VEGA_SECRETS') ?: '/workspace/vega-erp/secrets';
[$cmd, $a1, $a2] = array_pad(array_slice($argv, 1), 3, null);
if (! in_array($cmd, ['deploy', 'status', 'rollback'], true) || ($cmd === 'deploy' ? ! $a2 : ! $a1)) {
    fwrite(STDERR, "usage: deploy.sh <zip> <url> | deploy.sh status <url> | deploy.sh rollback <url>\n");
    exit(2);
}
$url = $cmd === 'deploy' ? $a2 : $a1;
if (! str_ends_with(parse_url($url, PHP_URL_PATH) ?? '', '.php')) {
    $url = rtrim($url, '/').'/deploy.php';
}
$secret = trim((string) @file_get_contents("{$dir}/deploy_hmac_secret"));
if (strlen($secret) < 32) {
    fwrite(STDERR, "Missing {$dir}/deploy_hmac_secret\n");
    exit(2);
}

function call(string $url, string $secret, array $q, string $body = ''): array
{
    ksort($q);
    $qs = http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    $ts = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $sig = hash_hmac('sha256', "VEGA-DEPLOY-HMAC-V1\n{$ts}\n{$nonce}\n{$qs}\n".hash('sha256', $body), $secret);
    $ch = curl_init($url.'?'.$qs);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 900,
        CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', "X-Vega-Timestamp: {$ts}", "X-Vega-Nonce: {$nonce}", "X-Vega-Signature: {$sig}", 'Expect:']]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = is_string($res) ? json_decode($res, true) : null;
    if (! is_array($json)) {
        fwrite(STDERR, "HTTP {$code} ".($err ?: 'non-JSON response (bad auth returns 404)')."\n");
        exit(1);
    }

    return [$code, $json];
}

function show(array $j): void
{
    foreach ($j['log'] ?? [] as $l) {
        echo "  {$l}\n";
    }
    unset($j['log']);
    echo json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}

if ($cmd !== 'deploy') {
    [$code, $j] = call($url, $secret, ['action' => $cmd]);
    show($j);
    exit($j['ok'] ?? false ? 0 : 1);
}

$zip = $a1;
if (! is_file($zip)) {
    fwrite(STDERR, "No such file {$zip}\n");
    exit(2);
}
$sk = base64_decode(trim((string) @file_get_contents("{$dir}/deploy_ed25519_secret")), true);
if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "Missing/invalid {$dir}/deploy_ed25519_secret\n");
    exit(2);
}
$sha = hash_file('sha256', $zip);
$sig = base64_encode(sodium_crypto_sign_detached("VEGA-DEPLOY-PKG-V1\n{$sha}", $sk));
$size = filesize($zip);
[, $st] = call($url, $secret, ['action' => 'status']);
echo "Server: version {$st['version']}, PHP {$st['php']}, post_max_size {$st['limits']['post_max_size']}\n";
$id = bin2hex(random_bytes(16));
[$code, $j] = call($url, $secret, ['action' => 'init', 'id' => $id, 'size' => (string) $size, 'sha256' => $sha, 'sig' => $sig]);
if (! ($j['ok'] ?? false)) {
    show($j);
    exit(1);
}
$chunk = (int) min($j['max_chunk'], (int) (getenv('VEGA_CHUNK') ?: 8 << 20));
$fh = fopen($zip, 'rb');
for ($off = 0; $off < $size; $off += strlen($buf)) {
    $buf = fread($fh, $chunk);
    [$code, $j] = call($url, $secret, ['action' => 'chunk', 'id' => $id, 'offset' => (string) $off], $buf);
    if (! ($j['ok'] ?? false)) {
        show($j);
        exit(1);
    }
    printf("  uploaded %d / %d bytes\n", $j['received'], $size);
}
fclose($fh);
echo "Finalizing (verify, swap, migrate, health check)...\n";
[$code, $j] = call($url, $secret, ['action' => 'finalize', 'id' => $id]);
show($j);
exit($j['ok'] ?? false ? 0 : 1);
