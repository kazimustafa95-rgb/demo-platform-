<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

$url = 'https://api.congress.gov/v3/bill/119/hr/1/amendments';
$response = Http::get($url, [
    'api_key' => config('services.congress_gov.api_key'),
    'format' => 'json',
]);

echo 'status=' . $response->status() . PHP_EOL;
if ($response->successful()) {
    $json = $response->json();
    echo 'keys=' . implode(',', array_keys($json)) . PHP_EOL;
    echo 'amendments_count=' . count($json['amendments'] ?? []) . PHP_EOL;
    $first = $json['amendments'][0] ?? null;
    if ($first) {
        echo 'first_keys=' . implode(',', array_keys($first)) . PHP_EOL;
    }
} else {
    echo 'body=' . $response->body() . PHP_EOL;
}
