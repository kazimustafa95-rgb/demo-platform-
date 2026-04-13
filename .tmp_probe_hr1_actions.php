<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$api = app(App\Services\CongressGovApi::class);
$details = $api->getBillDetails(119, 'hr', '1');
if (!$details || !isset($details['bill'])) {
    echo "missing" . PHP_EOL;
    exit;
}

$data = $details['bill'];
echo "introducedDate=" . ($data['introducedDate'] ?? 'null') . PHP_EOL;
echo "latestActionText=" . ($data['latestAction']['text'] ?? 'null') . PHP_EOL;
$actionsUrl = $data['actions']['url'] ?? null;
if ($actionsUrl) {
    $actions = $api->getBillActions($actionsUrl);
    echo "actions_count=" . count($actions['actions'] ?? []) . PHP_EOL;
    foreach (array_slice($actions['actions'] ?? [], 0, 15) as $i => $action) {
        echo "#{$i} date=" . ($action['actionDate'] ?? 'null') . " type=" . ($action['type'] ?? 'null') . " text=" . ($action['text'] ?? 'null') . PHP_EOL;
    }
}
