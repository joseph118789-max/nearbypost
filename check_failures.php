<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$failures = DB::table('failed_ingests')->orderBy('created_at','desc')->limit(10)->get(['title','failure_reason','created_at']);
foreach ($failures as $f) {
    echo "[{$f->created_at}] {$f->failure_reason}\n  {$f->title}\n\n";
}
echo "Total failed: " . DB::table('failed_ingests')->count() . "\n";
