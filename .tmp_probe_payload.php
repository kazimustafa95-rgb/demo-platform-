<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Bill;
use Illuminate\Support\Facades\Http;

$bill = Bill::where('external_id', 'HR-144-119')->first() ?: Bill::first();
if (!$bill) {
    echo "no_bill" . PHP_EOL;
    exit;
}

[$type, $number, $congress] = explode('-', $bill->external_id);
$type = strtolower($type);
$api = app(App\Services\CongressGovApi::class);
$details = $api->getBillDetails((int) $congress, $type, $number);

if (!$details || !isset($details['bill'])) {
    echo "details_missing" . PHP_EOL;
    exit;
}

$data = $details['bill'];
echo "bill_keys=" . implode(',', array_keys($data)) . PHP_EOL;

echo "introducedDate=" . ($data['introducedDate'] ?? 'null') . PHP_EOL;
echo "latestActionDate=" . ($data['latestAction']['actionDate'] ?? 'null') . PHP_EOL;
echo "latestActionText=" . ($data['latestAction']['text'] ?? 'null') . PHP_EOL;
echo "status=" . ($data['status'] ?? 'null') . PHP_EOL;
echo "summaries_url=" . ($data['summaries']['url'] ?? 'null') . PHP_EOL;
echo "actions_url=" . ($data['actions']['url'] ?? 'null') . PHP_EOL;
echo "amendments_url=" . ($data['amendments']['url'] ?? 'null') . PHP_EOL;
echo "committees_url=" . ($data['committees']['url'] ?? 'null') . PHP_EOL;

$actionsUrl = $data['actions']['url'] ?? null;
if ($actionsUrl) {
    $actions = $api->getBillActions($actionsUrl);
    echo "actions_count=" . count($actions['actions'] ?? []) . PHP_EOL;
    $first = $actions['actions'][0] ?? null;
    if ($first) {
        echo "first_action_keys=" . implode(',', array_keys($first)) . PHP_EOL;
        echo "first_action_type=" . ($first['type'] ?? 'null') . PHP_EOL;
        echo "first_action_date=" . ($first['actionDate'] ?? 'null') . PHP_EOL;
        echo "first_action_text=" . ($first['text'] ?? 'null') . PHP_EOL;
    }
}

$summaryUrl = $data['summaries']['url'] ?? null;
if ($summaryUrl) {
    $s = $api->getBillSummary($summaryUrl);
    echo "summaries_count=" . count($s['summaries'] ?? []) . PHP_EOL;
    $firstSummary = $s['summaries'][0]['text'] ?? null;
    echo "summary_len=" . ($firstSummary ? strlen($firstSummary) : 0) . PHP_EOL;
}

$amendmentsUrl = $data['amendments']['url'] ?? null;
if ($amendmentsUrl) {
    $aResponse = Http::get($amendmentsUrl, [
        'api_key' => config('services.congress_gov.api_key'),
        'format' => 'json',
    ]);

    if ($aResponse->successful()) {
        $a = $aResponse->json();
        echo "amendments_count=" . count($a['amendments'] ?? []) . PHP_EOL;
        $fa = $a['amendments'][0] ?? null;
        if ($fa) {
            echo "first_amendment_keys=" . implode(',', array_keys($fa)) . PHP_EOL;
        }
    } else {
        echo "amendments_request_status=" . $aResponse->status() . PHP_EOL;
    }
}
