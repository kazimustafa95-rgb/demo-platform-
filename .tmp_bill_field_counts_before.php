<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$total = DB::table('bills')->count();
$intro = DB::table('bills')->whereNotNull('introduced_date')->count();
$statusActive = DB::table('bills')->where('status','active')->count();
$statusPassed = DB::table('bills')->where('status','passed')->count();
$statusFailed = DB::table('bills')->where('status','failed')->count();
$official = DB::table('bills')->whereNotNull('official_vote_date')->count();
$deadline = DB::table('bills')->whereNotNull('voting_deadline')->count();
$relatedNonNull = DB::table('bills')->whereNotNull('related_documents')->count();
$amendNonNull = DB::table('bills')->whereNotNull('amendments_history')->count();
$summary = DB::table('bills')->whereNotNull('summary')->count();

echo "total={$total}\n";
echo "introduced_not_null={$intro}\n";
echo "status_active={$statusActive}\nstatus_passed={$statusPassed}\nstatus_failed={$statusFailed}\n";
echo "official_vote_date_not_null={$official}\n";
echo "voting_deadline_not_null={$deadline}\n";
echo "related_documents_not_null={$relatedNonNull}\n";
echo "amendments_history_not_null={$amendNonNull}\n";
echo "summary_not_null={$summary}\n";
