<?php

declare(strict_types=1);

// Autoloader of the project that installed divengine/runner.
require getenv("RUNNER_AUTOLOAD") ?: __DIR__ . "/../../vendor/autoload.php";

use divengine\runner\runner;

$flow = __DIR__ . "/flows/order.yml";
$context = [
    "items" => [
        ["price" => 10, "qty" => 2],
        ["price" => 5, "qty" => 1],
    ],
    "_root_folder" => __DIR__ . "/functions",
];

runner::run($flow, $context);
echo $context["_runner_state"], PHP_EOL;   // paused: review.check paused inside the call

runner::run($flow, $context);              // resume with the same context
echo $context["_runner_state"], PHP_EOL;   // done
echo $context["receipt"], PHP_EOL;         // Total: 25.00 (reviewed)

// A flow can also be a plain PHP callable.
$greeting = ["name" => "World"];
runner::run(function (array &$context): void {
    $context["greeting"] = "Hello " . $context["name"];
}, $greeting);
echo $greeting["greeting"], PHP_EOL;       // Hello World
