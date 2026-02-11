<?php

declare(strict_types=1);

use Divengine\Runner\Runner;
use PHPUnit\Framework\TestCase;

final class RunnerTest extends TestCase
{
    public function testRunUpdatesContext(): void
    {
        $context = [
            "foo" => "bar",
        ];

        $flow = function (array &$context): void {
            $context["ran"] = true;
        };

        Runner::run($flow, $context);

        $this->assertSame("done", $context["_runner_state"] ?? null);
        $this->assertSame(true, $context["ran"] ?? false);
        $this->assertIsArray($context["_runner_logs"] ?? null);
        $this->assertNotEmpty($context["_runner_started_at"] ?? "");
        $this->assertNotEmpty($context["_runner_ended_at"] ?? "");
    }
}