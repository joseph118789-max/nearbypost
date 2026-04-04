<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$entities = App\Models\TopicEntity::latest()->take(6)->get();

foreach ($entities as $e) {
    $type = $e->type ?: $e->entity_type ?: 'n/a';
    $cluster = $e->topic_cluster ?: $e->topic_tag ?: 'none';
    echo "{$e->name} | {$type} | {$cluster}\n";
}