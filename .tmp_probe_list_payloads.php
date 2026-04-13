<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$api = app(App\Services\CongressGovApi::class);
$list = $api->getBills(null, 0, 5);

if (!$list || !isset($list['bills'])) {
    echo "federal_list_missing" . PHP_EOL;
    exit;
}

echo "federal_list_count=" . count($list['bills']) . PHP_EOL;
foreach ($list['bills'] as $idx => $bill) {
    echo "-- federal_item_{$idx}" . PHP_EOL;
    echo "keys=" . implode(',', array_keys($bill)) . PHP_EOL;
    echo "number=" . ($bill['number'] ?? 'null') . " type=" . ($bill['type'] ?? 'null') . " congress=" . ($bill['congress'] ?? 'null') . PHP_EOL;
    echo "introducedDate=" . ($bill['introducedDate'] ?? 'null') . PHP_EOL;
    echo "status_field=" . (is_scalar($bill['status'] ?? null) ? ($bill['status'] ?? 'null') : json_encode($bill['status'] ?? null)) . PHP_EOL;
    echo "latestActionText=" . ($bill['latestAction']['text'] ?? 'null') . PHP_EOL;
    echo "latestActionDate=" . ($bill['latestAction']['actionDate'] ?? 'null') . PHP_EOL;
}

$os = app(App\Services\OpenStatesApi::class);
$stateList = $os->getBills('AL', null, 1, 20);
if (!$stateList || !isset($stateList['results'])) {
    echo "state_list_missing" . PHP_EOL;
    exit;
}

echo "state_list_count=" . count($stateList['results']) . PHP_EOL;
$firstState = $stateList['results'][0] ?? null;
if ($firstState) {
    echo "state_first_keys=" . implode(',', array_keys($firstState)) . PHP_EOL;
    echo "state_first_status=" . (is_scalar($firstState['status'] ?? null) ? ($firstState['status'] ?? 'null') : json_encode($firstState['status'] ?? null)) . PHP_EOL;
    echo "state_first_created_at=" . ($firstState['created_at'] ?? 'null') . PHP_EOL;
    echo "state_first_updated_at=" . ($firstState['updated_at'] ?? 'null') . PHP_EOL;
    echo "state_first_id=" . ($firstState['id'] ?? 'null') . PHP_EOL;

    $detail = $os->getBill($firstState['id']);
    if ($detail) {
        echo "state_detail_keys=" . implode(',', array_keys($detail)) . PHP_EOL;
        echo "state_detail_abstracts_count=" . count($detail['abstracts'] ?? []) . PHP_EOL;
        echo "state_detail_actions_count=" . count($detail['actions'] ?? []) . PHP_EOL;
        echo "state_detail_versions_count=" . count($detail['versions'] ?? []) . PHP_EOL;
        echo "state_detail_documents_count=" . count($detail['documents'] ?? []) . PHP_EOL;
        echo "state_detail_related_bills_count=" . count($detail['related_bills'] ?? []) . PHP_EOL;
    }
}
