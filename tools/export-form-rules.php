<?php

declare(strict_types=1);

/**
 * Writes resources/js/generated/route-rules.json: the fields each route requires, read from the
 * controllers. Run `php tools/export-form-rules.php` after changing validation; a test fails
 * while the file is out of date. Then `npm run forms` rebuilds the per-screen file.
 */

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\FormRuleExtractor;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../tests/Support/FormRuleExtractor.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$rules = (new FormRuleExtractor)->extract($app->make('router'));
file_put_contents(__DIR__.'/../resources/js/generated/route-rules.json', json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

echo count($rules)." routes with validation rules written\n";
