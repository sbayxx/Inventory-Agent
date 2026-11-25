<?php
declare(strict_types=1);

$inventoryRoot = dirname(__DIR__).'/backend/inventory';
require $inventoryRoot.'/src/bootstrap.php';
require $inventoryRoot.'/src/itsm-auth.php';

webHeaders();

if (getenv('INV_ENABLED') !== '1') {
    respond(503, ['error' => 'inventory_disabled']);
}

inventoryActor(true);
if (!enabled()) {
    respond(503, ['error' => 'inventory_disabled']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    respond(405, ['error' => 'method_not_allowed']);
}

$groups = [
    'Inventory Agent 3.2.3' => [
        'PC-Inventory-Agent-3.2.3-linux.tar.gz' => 'Linux · amd64 og ARM64 (.tar.gz)',
        'PC-Inventory-Agent-3.2.3-windows.zip' => 'Windows · x64 og ARM64 (.zip)',
        'PC-Inventory-Agent-3.2.3-universal.pkg' => 'macOS · Intel og Apple Silicon (.pkg)',
        'PC-Inventory-Agent-3.2.3-SHA256SUMS' => 'Agentpakkernes SHA-256-checksummer',
    ],
    'Browserudvidelser 1.2.3' => [
        'PC-Inventory-Agent-Web-1.2.3-chrome.zip' => 'Chrome (.zip)',
        'PC-Inventory-Agent-Web-1.2.3-edge.zip' => 'Edge (.zip)',
        'PC-Inventory-Agent-Web-1.2.3-firefox.xpi' => 'Firefox · usigneret (.xpi)',
        'PC-Inventory-Agent-Web-1.2.3-firefox.zip' => 'Firefox · alternativt arkiv (.zip)',
        'PC-Inventory-Agent-Web-1.2.3-safari.zip' => 'Safari · Xcode-kilde (.zip)',
        'PC-Inventory-Agent-Web-1.2.3-SHA256SUMS' => 'Browserudvidelsernes SHA-256-checksummer',
    ],
];

if (isset($_GET['file'])) {
    $filename = $_GET['file'];
    $files = array_merge(...array_values($groups));
    if (!is_string($filename) || !isset($files[$filename])) {
        respond(404, ['error' => 'download_not_found']);
    }

    $path = $inventoryRoot.'/releases/downloads/'.$filename;
    if (!is_file($path) || is_link($path) || !is_readable($path)) {
        respond(404, ['error' => 'download_not_found']);
    }

    $file = fopen($path, 'rb');
    if ($file === false) {
        respond(503, ['error' => 'download_unavailable']);
    }

    $stat = fstat($file);
    if ($stat === false) {
        fclose($file);
        respond(503, ['error' => 'download_unavailable']);
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: '.$stat['size']);
    if ($method === 'GET') {
        fpassthru($file);
    }
    fclose($file);
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
if ($method === 'HEAD') {
    exit;
}
?>
<!doctype html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Downloads · PC Inventory</title>
    <link rel="stylesheet" href="inventory.css">
</head>
<body>
<main>
    <header><a href="./">← Inventory</a><span>PC INVENTORY AGENT</span></header>
    <h1>Downloads</h1>
    <?php foreach ($groups as $title => $entries): ?>
        <section class="panel">
            <h2><?= htmlspecialchars($title) ?></h2>
            <ul>
                <?php foreach ($entries as $name => $label): ?>
                    <li><a href="?file=<?= rawurlencode($name) ?>"><?= htmlspecialchars($label) ?></a></li>
                <?php endforeach ?>
            </ul>
        </section>
    <?php endforeach ?>
    <p>Nye agenter bruger itsm.governit.dk. macOS-pakken er ikke notariseret.</p>
    <p>Firefox-pakken er til midlertidig installation. Safari-arkivet kræver Xcode og signering.</p>
</main>
</body>
</html>
