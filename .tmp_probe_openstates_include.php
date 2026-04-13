<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

$id = 'ocd-bill/4779ace0-104f-4058-96f7-bd91f5dd1a47';
$resp = Http::withHeaders(['X-API-Key' => config('services.open_states.api_key')])
    ->get('https://v3.openstates.org/bills/' . rawurlencode($id), [
        'include' => ['abstracts','actions','sponsorships','documents','versions','votes','related_bills','sources'],
    ]);

echo 'status=' . $resp->status() . PHP_EOL;
if ($resp->successful()) {
    $json = $resp->json();
    echo 'keys=' . implode(',', array_keys($json)) . PHP_EOL;
    echo 'abstracts=' . count($json['abstracts'] ?? []) . PHP_EOL;
    echo 'actions=' . count($json['actions'] ?? []) . PHP_EOL;
    echo 'sponsorships=' . count($json['sponsorships'] ?? []) . PHP_EOL;
    echo 'documents=' . count($json['documents'] ?? []) . PHP_EOL;
    echo 'versions=' . count($json['versions'] ?? []) . PHP_EOL;
    echo 'votes=' . count($json['votes'] ?? []) . PHP_EOL;
    echo 'related_bills=' . count($json['related_bills'] ?? []) . PHP_EOL;
    echo 'sources=' . count($json['sources'] ?? []) . PHP_EOL;
} else {
    echo $resp->body() . PHP_EOL;
}
