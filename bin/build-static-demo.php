<?php
/**
 * php bin/build-static-demo.php — builds docs/demo/, a static public demo (for GitHub Pages) from the SYNTHETIC demo database.
 * Nothing real is read: the data comes from bin/seed-demo.php, third-party logos are left out (letter badges are used),
 * actions/exports are disabled. The page is index.php + the demo API response + GUIDE.md embedded in one file.
 */
$root = dirname(__DIR__);
$php = PHP_BINARY;
passthru(escapeshellarg($php) . ' ' . escapeshellarg("$root/bin/seed-demo.php") . ' >/dev/null');

// 1. The demo API response (api.php in demo mode; the environment flag lets the CLI pass the web-only guard)
$code = '$_GET["demo"]="1"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; $_SERVER["HTTP_HOST"]="127.0.0.1"; require ' . var_export("$root/api.php", true) . ';';
$json = shell_exec('AIWATCH_SHARED_WEB_USER=1 ' . escapeshellarg($php) . ' -r ' . escapeshellarg($code));
$data = json_decode((string) $json, true);
if (!is_array($data) || empty($data['ready'])) {
    fwrite(STDERR, "Could not build the demo API response\n");
    exit(1);
}
$data['icons'] = new stdClass();   // no third-party logos in the public demo
$data['token'] = null;
$data['launcher'] = 'manual';
$data['home'] = '/Users/demo';
$blob = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

// 2. The page: index.php without its PHP guard, plus an offline data layer
$html = (string) file_get_contents("$root/index.php");
$html = preg_replace('/^<\?php.*?\?>/s', '', $html, 1);
$guide = json_encode((string) file_get_contents("$root/GUIDE.md"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
$layer = <<<JS
<script>
/* Static demo: the panel's endpoints are answered from this file. Actions and exports are disabled. */
(function () {
  if (new URLSearchParams(location.search).get('demo') !== '1') history.replaceState(null, '', location.pathname + '?demo=1' + location.hash);
  const DATA = $blob, GUIDE = $guide, real = window.fetch.bind(window);
  const reply = (body, type, status) => Promise.resolve(new Response(body, {status: status || 200, headers: {'Content-Type': type}}));
  window.fetch = (u, o) => {
    const s = String(u);
    if (s.startsWith('api.php')) return reply(JSON.stringify(DATA), 'application/json');
    if (s.startsWith('guide.php')) return reply(GUIDE, 'text/plain');
    if (s.startsWith('action.php')) return reply(JSON.stringify({ok: false, error: 'Static demo: actions are disabled'}), 'application/json', 403);
    return real(u, o);
  };
})();
</script>
<style>#expBtn, #expMenu { display: none !important; }</style>
JS;
$pos = strpos($html, '<head>');
$html = substr_replace($html, "<head>\n" . $layer, $pos, strlen('<head>')); // not preg_replace: the data contains "$" sequences
$html = str_replace('<title>SecAIQ Watch</title>', '<title>SecAIQ Watch: live demo</title>', $html);
$html = str_replace(
    '<a class="underline font-medium" href="./">Exit demo</a>',
    '<a class="underline font-medium" href="https://github.com/Spaksu/secaiq-watch" target="_blank" rel="noopener noreferrer">Get SecAIQ Watch (free, MIT)</a>',
    $html
);
$html = str_replace('DEMO DATA — everything here is synthetic.', 'STATIC DEMO — everything here is synthetic and frozen at build time.', $html);

$out = "$root/docs/demo";
@mkdir("$out/vendor", 0755, true);
@mkdir("$out/img", 0755, true);
file_put_contents("$out/index.html", $html);
copy("$root/vendor/tailwind.js", "$out/vendor/tailwind.js");
copy("$root/img/secaiq-watch.svg", "$out/img/secaiq-watch.svg");
file_put_contents("$root/docs/.nojekyll", '');

// 3. Safety net: nothing personal may end up in a public page
$bad = [];
$check = str_ireplace(['github.com/Spaksu/secaiq-watch', 'Spaksu.github.io/secaiq-watch'], '', $html); // the public repo / pages URLs are fine
foreach (['spaksu', 'gmail', getenv('HOME') ?: '/nonexistent-home'] as $needle) {
    if (stripos($check, $needle) !== false) {
        $bad[] = $needle;
    }
}
if (preg_match('#/Users/(?!demo)[A-Za-z0-9_.-]+#', $html, $m)) {
    $bad[] = $m[0];
}
if ($bad) {
    fwrite(STDERR, 'REFUSING: personal-looking strings in the demo: ' . implode(', ', array_unique($bad)) . "\n");
    unlink("$out/index.html");
    exit(2);
}
printf("Built %s (%d KB, %d tools, %d findings)\n", "docs/demo/index.html", round(strlen($html) / 1024), count($data['tools']), count($data['findings']));
