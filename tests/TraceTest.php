<?php

declare(strict_types=1);

namespace divengine\runner\Tests;

use divengine\runner\runner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * A callable name a flow can reference; a simulation must never call it.
 */
function sideEffectCallable(array &$context): void
{
    file_put_contents((string) getenv("RUNNER_SIDE_EFFECT_FILE"), "named callable ran\n", FILE_APPEND);
}

/**
 * Tracing (`runner::runTraced()`) and simulation (`runner::simulate()`).
 *
 * Golden files in tests/golden hold the deterministic part of a trace
 * (protocol, mode, flow id, result, events). Regenerate them after an
 * intended change with RUNNER_UPDATE_GOLDEN=1 and review the diff.
 */
final class TraceTest extends TestCase
{
    private const FLOWS = __DIR__ . "/flows/trace";

    private string $dir = "";

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . "/runner-trace-" . bin2hex(random_bytes(6));
        mkdir($this->dir . "/functions", 0777, true);
        $this->writeRealFunctions();
        putenv("RUNNER_SIDE_EFFECT_FILE=" . $this->dir . "/side-effects.log");
    }

    protected function tearDown(): void
    {
        putenv("RUNNER_SIDE_EFFECT_FILE");
        $this->deleteDirectory($this->dir);
    }

    // ------------------------------------------------------------------
    // Golden semantic event sequences
    // ------------------------------------------------------------------

    public function testGoldenOrdinaryRun(): void
    {
        $context = [];
        $trace = runner::simulate(self::FLOWS . "/ordinary.yml", $context, $this->doubles());

        $this->assertGolden("ordinary", $trace);
        $this->assertSame("done", $context["_runner_state"]);
    }

    public function testGoldenSkippedCondition(): void
    {
        $context = [];
        $trace = runner::simulate(self::FLOWS . "/skipped.yml", $context, $this->doubles(["is_enabled" => ["returns" => false]]));

        $this->assertGolden("skipped_condition", $trace);
        $this->assertArrayNotHasKey("gate_opened", $context, "a false condition skips the context update");
        $this->assertArrayNotHasKey("gate", $context);
    }

    public function testGoldenContextDiffOfATracedRealRun(): void
    {
        $context = ["obsolete" => true, "api_key" => "k-123", "_root_folder" => $this->dir . "/functions"];
        $trace = runner::runTraced(self::FLOWS . "/diff.yml", $context);

        $this->assertGolden("context_diff", $trace);
        $this->assertStringNotContainsString("k-123", json_encode($trace, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString("hunter2", json_encode($trace, JSON_THROW_ON_ERROR));
        $this->assertSame("hunter2", $context["password"], "redaction affects the trace only");
    }

    public function testGoldenCallAndReturn(): void
    {
        $context = [];
        $trace = runner::simulate(self::FLOWS . "/call_return.yml", $context, $this->doubles());

        $this->assertGolden("call_return", $trace);
    }

    public function testGoldenJumpDropsTheCallContinuation(): void
    {
        $context = [];
        $trace = runner::simulate(self::FLOWS . "/jump.yml", $context, $this->doubles());

        $this->assertGolden("jump", $trace);
        $this->assertContains("call.drop", array_column($trace["events"], "type"));
    }

    public function testGoldenFailure(): void
    {
        $context = [];
        $trace = runner::simulate(self::FLOWS . "/failure.yml", $context, $this->doubles(["fail" => ["throws" => "card declined"]]));

        $this->assertGolden("failure", $trace);
        $this->assertSame("error", $context["_runner_state"]);
        $this->assertSame([], $context["_flow_state"]);
    }

    public function testGoldenActivityPauseAndResume(): void
    {
        $doubles = $this->doubles(["wait_for_input" => ["pause" => true, "context" => ["asked" => true]]]);
        $context = [];
        $first = runner::simulate(self::FLOWS . "/activity_pause.yml", $context, $doubles);
        $this->assertGolden("activity_pause_1", $first);
        $this->assertSame("paused", $context["_runner_state"]);

        $second = runner::simulate(self::FLOWS . "/activity_pause.yml", $context, $doubles, [
            "double_calls" => $first["simulation"]["double_calls"],
            "fingerprint" => $first["fingerprint"],
        ]);
        $this->assertGolden("activity_pause_2", $second);
        $this->assertSame("done", $context["_runner_state"]);
    }

    public function testGoldenBudgetExhaustion(): void
    {
        $context = [];
        $trace = runner::simulate(self::FLOWS . "/loop.yml", $context, $this->doubles(), ["budget" => ["steps" => 3]]);

        $this->assertGolden("budget_steps", $trace);
        $this->assertSame("error", $context["_runner_state"]);
        $this->assertSame([], $context["_flow_state"]);
    }

    // ------------------------------------------------------------------
    // Tracing never changes a normal run
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function realFlows(): array
    {
        return [
            "call and return" => ["call_return.yml", ["done"]],
            "jump" => ["jump.yml", ["done"]],
            "pause inside nested calls, jump to a caller and resume" => ["nested_pause.yml", ["paused", "done"]],
            "activity pause and resume" => ["activity_pause.yml", ["paused", "done"]],
            "error" => ["failure.yml", ["error"]],
        ];
    }

    /**
     * @dataProvider realFlows
     *
     * @param list<string> $states
     */
    public function testTracingDoesNotChangeTheRun(string $flow, array $states): void
    {
        $plain = ["_root_folder" => $this->dir . "/functions", "order" => ["id" => 7]];
        $traced = $plain;

        foreach ($states as $state) {
            runner::run(self::FLOWS . "/" . $flow, $plain);
            $trace = runner::runTraced(self::FLOWS . "/" . $flow, $traced);

            $this->assertSame($state, $plain["_runner_state"]);
            $this->assertSame($this->comparable($plain), $this->comparable($traced));
            $this->assertSame(array_keys($plain), array_keys($traced), "same keys, in the same order");
            $this->assertSame($state, $trace["result"]["state"]);
            $this->assertSame($traced, json_decode(json_encode($traced, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true), "data only");
            $this->assertNull($this->activeTrace(), "no trace remains active after the run");
        }
    }

    public function testNestedRunInsideATracedActivityIsNotTraced(): void
    {
        $this->writeFunction("nested_run", <<<'PHP'
            return function (array &$context): string {
                $inner = [];
                divengine\runner\runner::run(fn (array &$context) => $context["x"] = 1, $inner);
                return $inner["_runner_state"];
            };
            PHP);
        $context = ["_root_folder" => $this->dir . "/functions"];
        $flow = $this->writeFlow("nested_run", "main:\n    steps:\n      inner:\n        activity: nested_run\n");

        $trace = runner::runTraced($flow, $context);

        $this->assertSame("done", $context["inner"]);
        $this->assertSame(["run.start", "block.enter", "step.start", "activity.start", "activity.end", "block.exit", "run.end"],
            array_column($trace["events"], "type"));
    }

    public function testOldGeneratedCodeWithoutStepIdsStillRunsAndTraces(): void
    {
        $code = runner::generateFlowCodeFromYaml(self::FLOWS . "/ordinary.yml");
        $legacy = preg_replace('/\$checkPause\((\d+), \$context, \'[^\']+\'\)/', '$checkPause($1, $context)', $code, -1, $count);
        $this->assertSame(2, $count);
        $file = $this->dir . "/legacy_flow.php";
        file_put_contents($file, "<?php\n" . $legacy);

        $context = ["_root_folder" => $this->dir . "/functions"];
        $trace = runner::runTraced($file, $context);

        $this->assertSame("done", $context["_runner_state"]);
        $starts = array_values(array_filter($trace["events"], fn (array $e): bool => $e["type"] === "step.start"));
        $this->assertSame([null, null], array_column($starts, "step"));
        $this->assertSame([1, 2], array_column($starts, "index"));
    }

    // ------------------------------------------------------------------
    // Simulation never loads or calls real functions
    // ------------------------------------------------------------------

    public function testSimulationNeverRequiresOrCallsRealFunctions(): void
    {
        $doubles = [
            "billing:total" => ["returns" => 10],
            "shipping:total" => ["returns" => 4],
            "side_effect" => ["returns" => "double"],
            "divengine\\runner\\Tests\\sideEffectCallable" => ["returns" => "double"],
        ];
        $context = ["_root_folder" => $this->dir . "/functions"];
        $trace = runner::simulate(self::FLOWS . "/modules.yml", $context, $doubles);

        $this->assertSame("done", $context["_runner_state"], (string) ($context["_runner_result"] ?? ""));
        $this->assertSame([10, 4, "double", "double"], [$context["invoice"], $context["shipping"], $context["plain"], $context["named"]]);
        $this->assertFileDoesNotExist($this->dir . "/side-effects.log");
        $this->assertSame([], $this->includedFunctionFiles(), "no function file was required");
        $this->assertSame(["flow", "doubles"], array_column($trace["sources"], "role"));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unmockedReferences(): array
    {
        return [
            "module file" => ["billing:total"],
            "plain file" => ["side_effect"],
            "callable name" => ["divengine\\runner\\Tests\\sideEffectCallable"],
        ];
    }

    /**
     * @dataProvider unmockedReferences
     */
    public function testUnmockedReferenceFailsWithoutFallingBackToRealCode(string $missing): void
    {
        $doubles = [
            "billing:total" => ["returns" => 10],
            "shipping:total" => ["returns" => 4],
            "side_effect" => ["returns" => "double"],
            "divengine\\runner\\Tests\\sideEffectCallable" => ["returns" => "double"],
        ];
        unset($doubles[$missing]);
        $context = ["_root_folder" => $this->dir . "/functions"];

        $trace = runner::simulate(self::FLOWS . "/modules.yml", $context, $doubles);

        $this->assertSame("error", $context["_runner_state"]);
        $this->assertStringContainsString("no double for function '{$missing}'", (string) $context["_runner_result"]);
        $missingEvents = array_values(array_filter($trace["events"], fn (array $e): bool => $e["type"] === "function.missing"));
        $this->assertSame($missing, $missingEvents[0]["function"]);
        $this->assertFileDoesNotExist($this->dir . "/side-effects.log");
        $this->assertSame([], $this->includedFunctionFiles());
    }

    public function testSimulationRefusesPhpFlowsWithoutLoadingThem(): void
    {
        $file = $this->dir . "/flow.php";
        file_put_contents($file, "<?php\nfile_put_contents(getenv('RUNNER_SIDE_EFFECT_FILE'), 'flow loaded');\nreturn fn (array &\$c) => null;\n");
        $context = [];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Simulation runs YAML flows only");
        try {
            runner::simulate($file, $context, []);
        } finally {
            $this->assertFileDoesNotExist($this->dir . "/side-effects.log");
            $this->assertSame([], $context);
        }
    }

    public function testNestedFlowDoubleIsSimulatedWithTheSameDoubles(): void
    {
        $doubles = $this->doubles(["child_flow" => ["flow" => self::FLOWS . "/child.yml"]]);
        $context = [];

        $trace = runner::simulate(self::FLOWS . "/parent.yml", $context, $doubles);

        $this->assertSame("done", $context["_runner_state"]);
        $this->assertNull($context["child"], "a pause inside a nested flow is its early return");
        $nested = array_values(array_filter($trace["events"], fn (array $e): bool => $e["flow_depth"] === 1));
        $this->assertSame(["flow.enter", "block.enter", "step.start", "activity.start", "double", "activity.end",
            "step.start", "pause", "flow.exit"], array_column($nested, "type"));
        $this->assertSame("paused", end($nested)["outcome"]);
        $this->assertSame(["flow", "doubles", "nested_flow"], array_column($trace["sources"], "role"));
    }

    public function testNestedFlowFunctionsWithoutDoublesFail(): void
    {
        $doubles = ["child_flow" => ["flow" => self::FLOWS . "/child.yml"], "consume" => ["returns" => 1]];
        $context = [];

        runner::simulate(self::FLOWS . "/parent.yml", $context, $doubles);

        $this->assertSame("error", $context["_runner_state"]);
        $this->assertStringContainsString("no double for function 'produce'", (string) $context["_runner_result"]);
    }

    public function testSequenceOutcomesPersistAcrossResumeAndExhaustExplicitly(): void
    {
        $doubles = $this->doubles(["produce" => ["sequence" => [["returns" => "a"], ["pause" => true], ["returns" => "c"]]]]);
        $flow = $this->writeFlow("three", "main:\n    steps:\n      s1:\n        activity: produce\n      s2:\n        activity: produce\n"
            . "      s3:\n        activity: produce\n      s4:\n        activity: produce\n");
        $context = [];

        $first = runner::simulate($flow, $context, $doubles);
        $this->assertSame(["paused", "a", 2], [$context["_runner_state"], $context["s1"], $first["simulation"]["double_calls"]["produce"]]);

        runner::simulate($flow, $context, $doubles, ["double_calls" => $first["simulation"]["double_calls"]]);
        $this->assertSame("error", $context["_runner_state"]);
        $this->assertSame("c", $context["s3"]);
        $this->assertStringContainsString("has no outcome for call 4 (its sequence has 3)", (string) $context["_runner_result"]);
    }

    public function testDoublesCanOnlyPauseActivitiesAndFlowDoublesOnlyActAsActivities(): void
    {
        $context = [];
        runner::simulate(self::FLOWS . "/skipped.yml", $context, $this->doubles(["is_enabled" => ["pause" => true]]));
        $this->assertStringContainsString("only activities can pause", (string) $context["_runner_result"]);

        $context = [];
        runner::simulate(self::FLOWS . "/skipped.yml", $context, $this->doubles(["is_enabled" => ["flow" => self::FLOWS . "/child.yml"]]));
        $this->assertStringContainsString("use it as an activity only", (string) $context["_runner_result"]);
    }

    public function testSimulationIsDeterministic(): void
    {
        $doubles = $this->doubles(["is_enabled" => ["sequence" => [["returns" => true], ["returns" => false]]]]);
        $first = [];
        $second = [];

        $a = runner::simulate(self::FLOWS . "/nested_pause.yml", $first, $doubles);
        $b = runner::simulate(self::FLOWS . "/nested_pause.yml", $second, $doubles);

        $this->assertSame($a["events"], $b["events"]);
        $this->assertSame($a["fingerprint"], $b["fingerprint"]);
        $this->assertSame($a["result"], $b["result"]);
        $this->assertNotSame([], $a["events"]);
    }

    public function testFingerprintDetectsChangedSources(): void
    {
        $context = [];
        $trace = runner::simulate(self::FLOWS . "/activity_pause.yml", $context, $this->doubles());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("changed: expected fingerprint {$trace["fingerprint"]}");
        runner::simulate(self::FLOWS . "/activity_pause.yml", $context, $this->doubles(["produce" => ["returns" => "other"]]), [
            "fingerprint" => $trace["fingerprint"],
        ]);
    }

    public function testFingerprintIgnoresLocationButNotContent(): void
    {
        copy(self::FLOWS . "/ordinary.yml", $this->dir . "/ordinary.yml");
        $a = [];
        $b = [];
        $here = runner::simulate(self::FLOWS . "/ordinary.yml", $a, $this->doubles());
        $there = runner::simulate($this->dir . "/ordinary.yml", $b, $this->doubles());
        $this->assertSame($here["fingerprint"], $there["fingerprint"]);

        file_put_contents($this->dir . "/ordinary.yml", "\n", FILE_APPEND);
        $c = [];
        $this->assertNotSame($here["fingerprint"], runner::simulate($this->dir . "/ordinary.yml", $c, $this->doubles())["fingerprint"]);
    }

    // ------------------------------------------------------------------
    // Budgets and caps
    // ------------------------------------------------------------------

    public function testTransferBudgetStopsAJumpLoop(): void
    {
        $context = [];
        $trace = runner::simulate(self::FLOWS . "/loop.yml", $context, $this->doubles(), ["budget" => ["transfers" => 4]]);

        $exhausted = $this->eventsOfType($trace, "budget.exhausted");
        $this->assertSame([["budget" => "transfers", "limit" => 4, "used" => 5]], array_map(
            fn (array $e): array => array_intersect_key($e, array_flip(["budget", "limit", "used"])),
            $exhausted
        ));
        $this->assertStringContainsString("budget exhausted: transfers", (string) $context["_runner_result"]);
    }

    public function testDefaultBudgetStopsAnInfiniteLoop(): void
    {
        $context = [];
        $trace = runner::simulate(self::FLOWS . "/loop.yml", $context, $this->doubles());

        $this->assertSame("error", $context["_runner_state"]);
        $this->assertSame("steps", $this->eventsOfType($trace, "budget.exhausted")[0]["budget"]);
        $this->assertSame(10000, $trace["budget"]["steps"]);
    }

    public function testWallClockBudgetAndCancellation(): void
    {
        $context = [];
        $trace = runner::simulate(self::FLOWS . "/loop.yml", $context, $this->doubles(), ["budget" => ["seconds" => 0.000001]]);
        $this->assertSame("seconds", $this->eventsOfType($trace, "budget.exhausted")[0]["budget"]);

        $calls = 0;
        $context = [];
        $trace = runner::simulate(self::FLOWS . "/loop.yml", $context, $this->doubles(), [
            "cancel" => function () use (&$calls): bool {
                return ++$calls >= 3;
            },
        ]);
        $this->assertSame("cancel", $this->eventsOfType($trace, "budget.exhausted")[0]["budget"]);
        $this->assertStringContainsString("Simulation cancelled", (string) $context["_runner_result"]);
    }

    public function testEventCapTruncatesExplicitlyWithoutChangingTheRun(): void
    {
        $plain = ["_root_folder" => $this->dir . "/functions"];
        $traced = $plain;
        runner::run(self::FLOWS . "/call_return.yml", $plain);
        $trace = runner::runTraced(self::FLOWS . "/call_return.yml", $traced, ["trace" => ["max_events" => 5]]);

        $this->assertSame($this->comparable($plain), $this->comparable($traced));
        $this->assertTrue($trace["truncated"]);
        $this->assertSame("max_events", $trace["truncation"]["reason"]);
        $this->assertSame(["run.end", "trace.truncated"], array_column(array_slice($trace["events"], -2), "type"));
        $this->assertCount(7, $trace["events"]);
        $this->assertGreaterThan(0, $trace["truncation"]["dropped_events"]);
    }

    public function testByteCapTruncates(): void
    {
        $context = [];
        $doubles = $this->doubles(["produce" => ["returns" => str_repeat("x", 1000)]]);
        $trace = runner::simulate(self::FLOWS . "/loop.yml", $context, $doubles, ["trace" => ["max_bytes" => 8192]]);

        $this->assertSame("max_bytes", $trace["truncation"]["reason"]);
        $this->assertGreaterThan(0, $trace["truncation"]["dropped_events"]);
        $this->assertSame(["run.end", "trace.truncated"], array_column(array_slice($trace["events"], -2), "type"));
        $this->assertLessThanOrEqual(8192, $this->eventBytes($trace));
        $ordinary = array_values(array_filter($trace["events"],
            fn (array $e): bool => !in_array($e["type"], ["budget.exhausted", "run.end", "trace.truncated"], true)));
        $this->assertLessThanOrEqual(8192 - 2048, $this->eventBytes($trace, $ordinary),
            "ordinary events leave a quarter of max_bytes to the forced ones");
    }

    public function testValuesAreBoundedJsonSafeAndRedacted(): void
    {
        $recursive = ["x" => 1];
        $recursive["self"] = &$recursive;
        $handle = fopen("php://memory", "r");
        $closed = fopen("php://memory", "r");
        fclose($closed);
        $context = [
            "closure" => fn (): int => 1,
            "object" => new \DateTimeImmutable("2026-01-01"),
            "resource" => $handle,
            "closed" => $closed,
            "nan" => NAN,
            "inf" => -INF,
            "binary" => "\xB1\x31",
            "long" => str_repeat("é", 20),
            "list" => range(1, 8),
            "map" => ["a" => 1, "b" => 2, "c" => 3, "d" => 4, "e" => 5],
            "recursive" => $recursive,
            "nested" => ["user" => ["Password" => "p", "name" => "n", "hidden" => "h"]],
            "pin" => "1234",
        ];

        $trace = runner::simulate(self::FLOWS . "/ordinary.yml", $context, $this->doubles(), ["trace" => [
            "max_string" => 9, "max_items" => 3, "max_depth" => 3, "redact_keys" => ["PIN"], "exclude_keys" => ["hidden"],
        ]]);
        $snapshot = $trace["events"][0]["context"];

        $this->assertSame(['$type' => "closure"], $snapshot["closure"]);
        $this->assertSame(['$type' => "object", "class" => "DateTimeImmutable"], $snapshot["object"]);
        $this->assertSame(['$type' => "resource", "kind" => "stream"], $snapshot["resource"]);
        $this->assertSame(['$type' => "resource", "kind" => "closed"], $snapshot["closed"]);
        $this->assertSame(['$type' => "float", "value" => "NAN"], $snapshot["nan"]);
        $this->assertSame(['$type' => "float", "value" => "-INF"], $snapshot["inf"]);
        $this->assertSame(['$type' => "binary", "length" => 2], $snapshot["binary"]);
        $this->assertSame(['$type' => "string", "truncated" => true, "length" => 40, "value" => "éééé"], $snapshot["long"]);
        $this->assertSame([1, 2, 3, ['$type' => "more", "count" => 5]], $snapshot["list"]);
        $this->assertSame(["a" => 1, "b" => 2, "c" => 3, '$more' => 2], $snapshot["map"]);
        $this->assertSame(
            ["x" => 1, "self" => ["x" => 1, "self" => ["x" => 1, "self" => ['$type' => "array", "truncated" => true, "count" => 2]]]],
            $snapshot["recursive"],
            "max_depth 3 keeps three array levels; the recursion stops there"
        );
        $this->assertSame(["user" => ["Password" => ['$type' => "redacted"], "name" => "n"]], $snapshot["nested"]);
        $this->assertSame(['$type' => "redacted"], $snapshot["pin"]);
        $this->assertNotFalse(json_encode($trace));
        fclose($handle);
    }

    public function testNodeCapBoundsLargeValues(): void
    {
        $context = ["big" => array_fill(0, 50, array_fill(0, 50, 1))];
        $trace = runner::simulate(self::FLOWS . "/ordinary.yml", $context, $this->doubles(), [
            "trace" => ["max_nodes" => 30, "max_items" => 1000],
        ]);

        $encoded = (string) json_encode($trace["events"][0]["context"]);
        $this->assertStringContainsString('{"$type":"more","count":49}', $encoded, "items past max_nodes are counted");
        $this->assertLessThan(2000, strlen($encoded));
    }

    public function testValueCaptureCanBeDisabled(): void
    {
        $context = ["order" => ["id" => 1]];
        $trace = runner::simulate(self::FLOWS . "/ordinary.yml", $context, $this->doubles(), ["trace" => ["capture_values" => false]]);

        $this->assertSame(["order" => ['$type' => "array"]], $trace["events"][0]["context"]);
        $end = $this->eventsOfType($trace, "activity.end")[0];
        $this->assertSame(['$type' => "string"], $end["result"]);
    }

    public function testLogsOfTheFlowAreCorrelatedButRunnerInternalsAreNot(): void
    {
        $this->writeFunction("talk", 'return function (array &$context): void { $context["_logger"]("INFO", "hello from talk"); };');
        $context = ["_root_folder" => $this->dir . "/functions"];
        $trace = runner::runTraced($this->writeFlow("talk", "main:\n    steps:\n      speak:\n        activity: talk\n"), $context, [
            "trace" => ["capture_messages" => true],
        ]);

        $logs = $this->eventsOfType($trace, "log");
        $this->assertCount(1, $logs);
        $this->assertSame(["block" => "main", "step" => "speak", "level" => "INFO", "message" => "hello from talk"],
            array_intersect_key($logs[0], array_flip(["block", "step", "level", "message"])));
        $this->assertNotEmpty($context["_runner_logs"], "Runner's own logs are unchanged");
    }

    public function testTraceDocumentEnvelope(): void
    {
        $context = ["_root_folder" => $this->dir . "/functions"];
        $trace = runner::runTraced(self::FLOWS . "/ordinary.yml", $context);

        $this->assertSame(runner::TRACE_PROTOCOL, $trace["protocol"]);
        $this->assertSame(1, $trace["version"]);
        $this->assertSame(["path" => self::FLOWS . "/ordinary.yml", "id" => "ordinary", "callable" => null], $trace["flow"]);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $trace["fingerprint"]);
        $this->assertSame(["flow", "function", "function"], array_column($trace["sources"], "role"));
        $this->assertSame(hash_file("sha256", $this->dir . "/functions/produce.php"), $trace["sources"][1]["sha256"]);
        $this->assertNull($trace["budget"]);
        $this->assertNull($trace["simulation"]);
        $this->assertSame(range(1, count($trace["events"])), array_column($trace["events"], "seq"));
        $this->assertSame(["started_at", "duration_ms", "php_version"], array_keys($trace["meta"]));
    }

    public function testSetupErrorsAreRecordedNotThrown(): void
    {
        $context = [];
        $trace = runner::runTraced($this->dir . "/missing.yml", $context);

        $this->assertSame("error", $context["_runner_state"]);
        $this->assertTrue($trace["result"]["setup_error"]);
        $this->assertSame(["run.start", "run.end"], array_column($trace["events"], "type"));
    }

    public function testEventTypesAreTheDocumentedOnes(): void
    {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__) . "/docs/trace.schema.json"), true, 512, JSON_THROW_ON_ERROR);
        $documented = $schema['$defs']["event"]["properties"]["type"]["enum"];
        foreach (glob(__DIR__ . "/golden/*.json") ?: [] as $file) {
            $golden = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            foreach ($golden["events"] as $event) {
                $this->assertContains($event["type"], $documented, basename($file));
                foreach (["seq", "type", "flow_depth", "call_depth"] as $key) {
                    $this->assertArrayHasKey($key, $event);
                }
            }
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidDoubles(): array
    {
        return [
            "no outcome" => [["f" => []], "needs exactly one of"],
            "two outcomes" => [["f" => ["returns" => 1, "throws" => "x"]], "needs exactly one of"],
            "list spec" => [["f" => [1, 2]], "must be a mapping"],
            "empty sequence" => [["f" => ["sequence" => []]], "non-empty list"],
            "bad sequence item" => [["f" => ["sequence" => [["returns" => 1, "pause" => true]]]], "f.sequence[0]"],
            "empty throws" => [["f" => ["throws" => " "]], "takes an error message"],
            "pause false" => [["f" => ["pause" => false]], "'pause' must be true"],
            "reserved context key" => [["f" => ["returns" => 1, "context" => ["_flow_state" => []]]], "reserved or invalid"],
            "context with throws" => [["f" => ["throws" => "x", "context" => ["a" => 1]]], "used with returns or pause"],
            "bad module" => [["-bad:f" => ["returns" => 1]], "Invalid module"],
            "missing nested flow" => [["f" => ["flow" => "/nonexistent/child.yml"]], "nested flow not found"],
            "php nested flow" => [["f" => ["flow" => "child.php"]], "takes only a .yml or .yaml path"],
        ];
    }

    /**
     * @dataProvider invalidDoubles
     *
     * @param array<string, mixed> $doubles
     */
    public function testInvalidDoublesAreRejectedBeforeRunning(array $doubles, string $message): void
    {
        $context = ["untouched" => true];
        try {
            runner::simulate(self::FLOWS . "/ordinary.yml", $context, $doubles);
            $this->fail("expected InvalidArgumentException");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertSame(["untouched" => true], $context);
    }

    public function testInvalidOptionsAreRejected(): void
    {
        $context = [];
        foreach ([
            [["budget" => ["steps" => 0]], "positive integer"],
            [["budget" => ["loops" => 1]], "Unknown simulation budget"],
            [["trace" => ["max_events" => "10"]], "positive integer"],
            [["trace" => ["unknown" => 1]], "Unknown trace option"],
            [["trace" => ["capture_messages" => 1]], "must be a boolean"],
            [["trace" => ["max_bytes" => 8191]], "at least 8192"],
            [["unknown" => 1], "Unknown simulation option"],
            [["cancel" => "not callable at all"], "must be callable"],
            [["double_calls" => ["f" => -1]], "call counts"],
        ] as [$options, $message]) {
            try {
                runner::simulate(self::FLOWS . "/ordinary.yml", $context, $this->doubles(), $options);
                $this->fail("expected InvalidArgumentException for " . json_encode($options));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->assertSame([], $context);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // Messages: redacted unless captured explicitly
    // ------------------------------------------------------------------

    public function testErrorAndLogMessagesAreRedactedByDefault(): void
    {
        $this->writeFunction("leak", 'return function (array &$context): void { $context["_logger"]("WARNING", "retrying with token=tk-LOGSECRET"); throw new RuntimeException("login failed for password=pw-ERRSECRET"); };');
        $logged = [];
        $context = ["_root_folder" => $this->dir . "/functions"];
        $trace = runner::runTraced($this->writeFlow("leak", "main:\n    steps:\n      go:\n        activity: leak\n"), $context, [
            "logger" => function (string $level, string $message) use (&$logged): void {
                $logged[] = $message;
            },
        ]);

        $encoded = (string) json_encode($trace);
        $this->assertStringNotContainsString("LOGSECRET", $encoded);
        $this->assertStringNotContainsString("ERRSECRET", $encoded);
        $redacted = ['$type' => "redacted"];
        $this->assertSame(["class" => "RuntimeException", "message" => $redacted], $trace["result"]["error"]);
        $this->assertSame($redacted, $this->eventsOfType($trace, "activity.error")[0]["error"]["message"]);
        $this->assertSame($redacted, $this->eventsOfType($trace, "log")[0]["message"]);
        $this->assertSame("WARNING", $this->eventsOfType($trace, "log")[0]["level"]);
        $this->assertFalse($trace["limits"]["capture_messages"]);

        // Normal Runner logging and error reporting are unchanged.
        $this->assertContains("retrying with token=tk-LOGSECRET", $logged);
        $this->assertStringContainsString("ERRSECRET", $context["_runner_result"]);
        $this->assertStringContainsString("ERRSECRET", (string) json_encode($context["_runner_logs"]));
    }

    public function testSimulationMessagesAreRedactedByDefault(): void
    {
        $context = [];
        $trace = runner::simulate(self::FLOWS . "/failure.yml", $context, $this->doubles(["fail" => ["throws" => "secret=DOUBLESECRET"]]));

        $this->assertStringNotContainsString("DOUBLESECRET", (string) json_encode($trace));
        $this->assertSame(['$type' => "redacted"], $trace["result"]["error"]["message"]);
    }

    public function testMessagesAreCapturedOnlyOnRequestAndCapped(): void
    {
        $this->writeFunction("leak", 'return function (array &$context): void { $context["_logger"]("INFO", "a log message that is long"); throw new RuntimeException("an error message that is long"); };');
        $context = ["_root_folder" => $this->dir . "/functions"];
        $trace = runner::runTraced($this->writeFlow("leak", "main:\n    steps:\n      go:\n        activity: leak\n"), $context, [
            "trace" => ["capture_messages" => true, "max_string" => 16],
        ]);

        $this->assertSame("an error message...", $trace["result"]["error"]["message"]);
        $this->assertSame("a log message th...", $this->eventsOfType($trace, "log")[0]["message"]);
        $this->assertTrue($trace["limits"]["capture_messages"]);
    }

    // ------------------------------------------------------------------
    // function_map takes part in the fingerprint
    // ------------------------------------------------------------------

    public function testFunctionMapTakesPartInTheFingerprint(): void
    {
        $doubles = $this->doubles(["produce_v2" => ["returns" => "v2"], "produce_v3" => ["returns" => "v3"]]);
        $fingerprint = function (array $options) use ($doubles): string {
            $context = [];
            return runner::simulate(self::FLOWS . "/ordinary.yml", $context, $doubles, $options)["fingerprint"];
        };

        $none = $fingerprint([]);
        $v2 = $fingerprint(["function_map" => ["produce" => "produce_v2"]]);
        $this->assertSame($none, $fingerprint(["function_map" => []]), "an empty map is no map");
        $this->assertNotSame($none, $v2);
        $this->assertNotSame($v2, $fingerprint(["function_map" => ["produce" => "produce_v3"]]));
        $this->assertSame($v2, $fingerprint(["function_map" => [" produce " => " produce_v2 "]]), "the normalized map counts");

        $context = [];
        $trace = runner::simulate(self::FLOWS . "/ordinary.yml", $context, $doubles, ["function_map" => ["produce" => "produce_v2"]]);
        $this->assertSame("v2", $context["first"], "the map changes what runs");
        $roles = array_column($trace["sources"], "role");
        $this->assertContains("function_map", $roles);
    }

    public function testResumeRefusesAChangedFunctionMap(): void
    {
        $doubles = $this->doubles(["consume_v2" => ["returns" => "v2"]]);
        $context = [];
        $first = runner::simulate(self::FLOWS . "/activity_pause.yml", $context, $doubles);
        $this->assertSame("paused", $context["_runner_state"]);
        $paused = $context;

        try {
            runner::simulate(self::FLOWS . "/activity_pause.yml", $context, $doubles, [
                "double_calls" => $first["simulation"]["double_calls"],
                "fingerprint" => $first["fingerprint"],
                "function_map" => ["consume" => "consume_v2"],
            ]);
            $this->fail("expected InvalidArgumentException");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("function_map changed", $e->getMessage());
        }
        $this->assertSame($paused, $context, "a refused resume runs nothing");

        runner::simulate(self::FLOWS . "/activity_pause.yml", $context, $doubles, [
            "double_calls" => $first["simulation"]["double_calls"],
            "fingerprint" => $first["fingerprint"],
        ]);
        $this->assertSame("done", $context["_runner_state"]);
    }

    public function testAnInvalidFunctionMapIsRejectedBeforeSimulating(): void
    {
        $context = ["untouched" => true];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("function_map");
        runner::simulate(self::FLOWS . "/ordinary.yml", $context, $this->doubles(), ["function_map" => ["produce" => ""]]);
    }

    public function testTracedRunFingerprintCoversItsFunctionMap(): void
    {
        $this->writeFunction("produce_v2", 'return fn (array &$context): string => "v2";');
        $a = ["_root_folder" => $this->dir . "/functions"];
        $b = $a;
        $plain = runner::runTraced(self::FLOWS . "/ordinary.yml", $a);
        $mapped = runner::runTraced(self::FLOWS . "/ordinary.yml", $b, ["function_map" => ["produce" => "produce_v2"]]);

        $this->assertNotSame($plain["fingerprint"], $mapped["fingerprint"]);
        $this->assertSame("v2", $b["first"]);
    }

    // ------------------------------------------------------------------
    // Strict size bounds
    // ------------------------------------------------------------------

    public function testHugeContextKeepsTheTraceWithinMaxBytes(): void
    {
        $context = ["_root_folder" => $this->dir . "/functions"];
        for ($i = 0; $i < 5000; $i++) {
            $context["key{$i}"] = str_repeat("v", 1500);
        }
        $plain = $context;
        runner::run(self::FLOWS . "/ordinary.yml", $plain);
        $trace = runner::runTraced(self::FLOWS . "/ordinary.yml", $context);

        $this->assertSame($this->comparable($plain), $this->comparable($context), "bounding the trace never changes the run");
        $this->assertLessThanOrEqual(4194304, $this->eventBytes($trace));
        $this->assertTrue($trace["truncated"]);
        $this->assertGreaterThan(0, $trace["truncation"]["compacted_events"]);
        $this->assertSame("done", $trace["result"]["state"]);

        $start = $this->eventsOfType($trace, "run.start")[0];
        $end = $this->eventsOfType($trace, "run.end")[0];
        $this->assertSame(3000, $start["context"]['$more'], "top-level keys past max_nodes are counted, not copied");
        $this->assertCount(2001, $start["context"]);
        $this->assertSame(['$type' => "truncated", "reason" => "max_bytes"], $end["context"]);
        $this->assertSame([], $end["checkpoint"]["flow_state"], "run.end keeps its checkpoint while it fits");
        $this->assertSame("trace.truncated", $trace["events"][count($trace["events"]) - 1]["type"]);
    }

    public function testOversizedForcedEventsAreCompactedToFit(): void
    {
        $context = ["blob" => str_repeat("b", 50000)];
        $doubles = $this->doubles(["fail" => ["throws" => str_repeat("m", 50000)]]);
        $trace = runner::simulate(self::FLOWS . "/failure.yml", $context, $doubles, ["trace" => [
            "max_bytes" => 8192, "max_string" => 1000000, "capture_messages" => true,
        ]]);

        $this->assertLessThanOrEqual(8192, $this->eventBytes($trace));
        $end = $this->eventsOfType($trace, "run.end")[0];
        $marker = ['$type' => "truncated", "reason" => "max_bytes"];
        $this->assertSame($marker, $end["context"]);
        $this->assertSame($marker, $end["checkpoint"]);
        $this->assertSame(["class" => "RuntimeException", "message" => $marker], $end["error"]);
        $this->assertSame("error", $end["state"]);
        $this->assertSame("trace.truncated", $trace["events"][count($trace["events"]) - 1]["type"]);
        $this->assertSame($marker, $this->eventsOfType($trace, "run.start")[0]["context"]);
    }

    public function testManyChangesAreCountedPastMaxNodes(): void
    {
        $added = [];
        for ($i = 0; $i < 5000; $i++) {
            $added["k{$i}"] = $i;
        }
        $context = [];
        $trace = runner::simulate(self::FLOWS . "/ordinary.yml", $context, $this->doubles([
            "produce" => ["returns" => 1, "context" => $added],
        ]), ["trace" => ["max_nodes" => 100]]);

        $changes = $this->eventsOfType($trace, "activity.end")[0]["changes"];
        $this->assertCount(101, $changes);
        $this->assertSame(['$type' => "more", "count" => 4900 + 1], $changes[100], "the result key of the step is counted too");
    }

    public function testLongKeysAreCountedNotCopied(): void
    {
        $long = str_repeat("k", 2000);
        $context = [$long => 1, "short" => [$long => 2, "ok" => 3]];
        $trace = runner::simulate(self::FLOWS . "/ordinary.yml", $context, $this->doubles());

        $snapshot = $trace["events"][0]["context"];
        $this->assertSame(["short" => ["ok" => 3, '$more' => 1], '$more' => 1], $snapshot);
        $this->assertStringNotContainsString($long, (string) json_encode($trace));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function boundedTraces(): iterable
    {
        foreach ([8192, 9000, 16384, 65536, 200000] as $bytes) {
            foreach (["loop", "big_context", "big_results", "failure"] as $shape) {
                yield "{$shape} at {$bytes}" => [$bytes, $shape];
            }
        }
    }

    /**
     * @dataProvider boundedTraces
     */
    public function testEventsNeverExceedMaxBytes(int $maxBytes, string $shape): void
    {
        $context = [];
        $doubles = $this->doubles();
        $flow = "/loop.yml";
        if ($shape === "big_context") {
            for ($i = 0; $i < 3000; $i++) {
                $context["k{$i}"] = str_repeat("c", 300);
            }
        } elseif ($shape === "big_results") {
            $doubles["produce"] = ["returns" => array_fill(0, 200, str_repeat("r", 500))];
        } elseif ($shape === "failure") {
            $flow = "/failure.yml";
            $context["blob"] = str_repeat("b", 3 * $maxBytes);
            $doubles["fail"] = ["throws" => str_repeat("e", 3 * $maxBytes)];
        }
        $trace = runner::simulate(self::FLOWS . $flow, $context, $doubles, [
            "budget" => ["steps" => 2000],
            "trace" => ["max_bytes" => $maxBytes, "max_string" => 4 * $maxBytes, "capture_messages" => true],
        ]);

        $this->assertLessThanOrEqual($maxBytes, $this->eventBytes($trace));
        $this->assertCount(1, $this->eventsOfType($trace, "run.end"), "run.end is always kept, once");
        $this->assertLessThanOrEqual(1, count($this->eventsOfType($trace, "budget.exhausted")));
        if ($trace["truncated"]) {
            $last = $trace["events"][count($trace["events"]) - 1];
            $this->assertSame("trace.truncated", $last["type"]);
            $this->assertSame($trace["truncation"], array_intersect_key($last, $trace["truncation"]));
        }
        $this->assertSame(range(1, count($trace["events"])), array_column($trace["events"], "seq"));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function doubles(array $overrides = []): array
    {
        return $overrides + [
            "produce" => ["returns" => "produced"],
            "consume" => ["returns" => 1.0, "context" => ["consumed" => true]],
            "never_runs" => ["throws" => "never_runs must not run"],
            "is_enabled" => ["returns" => true],
            "wait_for_input" => ["pause" => true],
            "fail" => ["throws" => "failure"],
        ];
    }

    /**
     * @param array<string, mixed> $trace
     */
    private function assertGolden(string $name, array $trace): void
    {
        $semantic = [
            "protocol" => $trace["protocol"],
            "version" => $trace["version"],
            "mode" => $trace["mode"],
            "flow_id" => $trace["flow"]["id"],
            "result" => $trace["result"],
            "truncated" => $trace["truncated"],
            "events" => $trace["events"],
        ];
        $file = __DIR__ . "/golden/{$name}.json";
        $json = json_encode($semantic, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";

        if (getenv("RUNNER_UPDATE_GOLDEN") === "1") {
            file_put_contents($file, $json);
        }
        $this->assertFileExists($file, "missing golden file; generate it with RUNNER_UPDATE_GOLDEN=1");
        $this->assertSame((string) file_get_contents($file), $json, "golden {$name}");
    }

    /**
     * @param array<string, mixed> $trace
     *
     * @return list<array<string, mixed>>
     */
    private function eventsOfType(array $trace, string $type): array
    {
        return array_values(array_filter($trace["events"], fn (array $e): bool => $e["type"] === $type));
    }

    /**
     * Bytes of events as the trace counts them: one compact JSON line each.
     *
     * @param array<string, mixed>              $trace
     * @param list<array<string, mixed>>|null   $events
     */
    private function eventBytes(array $trace, ?array $events = null): int
    {
        $bytes = 0;
        foreach ($events ?? $trace["events"] as $event) {
            $bytes += strlen((string) json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)) + 1;
        }
        return $bytes;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function comparable(array $context): array
    {
        unset(
            $context["_runner_started_at"], $context["_runner_ended_at"], $context["_runner_result"], $context["_runner_error"],
            $context["_exception_pause_token"], $context["_exception_jump_token"], $context["_exception_pause"],
            $context["_exception_jump"]
        );
        // Messages differ only in random tokens and, for errors, in the PHP
        // stack trace of the caller (run() or runTraced()).
        $context["_runner_logs"] = array_map(
            fn (array $record): array => [
                $record["level"],
                preg_replace(['/(pause|jump)-[0-9a-f]{32}/', '/\[Traceback\].*/s'], ['TOKEN', ''], $record["message"]),
            ],
            $context["_runner_logs"]
        );

        return $context;
    }

    /**
     * @return list<string>
     */
    private function includedFunctionFiles(): array
    {
        return array_values(array_filter(get_included_files(), fn (string $f): bool => str_starts_with($f, $this->dir)));
    }

    private function activeTrace(): mixed
    {
        return (new ReflectionProperty(runner::class, "trace"))->getValue();
    }

    private function writeRealFunctions(): void
    {
        $this->writeFunction("produce", 'return function (array &$context): int { $context["log"][] = $context["_current_block_id"] . "." . $context["_current_step_id"]; return count($context["log"]); };');
        $this->writeFunction("consume", 'return function (array &$context): float { $context["consumed"] = true; return 1.0; };');
        $this->writeFunction("never_runs", 'return function (array &$context): void { throw new RuntimeException("never_runs must not run"); };');
        $this->writeFunction("is_enabled", 'return fn (array &$context): bool => true;');
        $this->writeFunction("fail", 'return function (array &$context): void { throw new RuntimeException("failure"); };');
        $this->writeFunction("mutate", 'return function (array &$context): array { $context["status"] = "done"; $context["count"]++; unset($context["obsolete"]); $context["password"] = "hunter2"; return ["ok" => true]; };');
        $this->writeFunction("wait_for_input", 'return function (array &$context): string { if (empty($context["asked"])) { $context["asked"] = true; throw new RuntimeException($context["_exception_pause"] . " waiting"); } return "answered"; };');
        $sideEffect = 'file_put_contents((string) getenv("RUNNER_SIDE_EFFECT_FILE"), __FILE__ . " loaded\n", FILE_APPEND);' . "\n"
            . 'return function (array &$context): string { file_put_contents((string) getenv("RUNNER_SIDE_EFFECT_FILE"), "called\n", FILE_APPEND); return "real"; };';
        $this->writeFunction("side_effect", $sideEffect);
        $this->writeFunction("billing/total", $sideEffect);
        $this->writeFunction("shipping/total", $sideEffect);
    }

    private function writeFunction(string $name, string $body): void
    {
        $path = $this->dir . "/functions/{$name}.php";
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, "<?php\n" . $body . "\n");
    }

    private function writeFlow(string $id, string $blocks): string
    {
        $path = $this->dir . "/{$id}.yml";
        file_put_contents($path, "id: {$id}\nblocks:\n  {$blocks}");

        return $path;
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff((array) scandir($dir), [".", ".."]) as $entry) {
            $path = $dir . "/" . $entry;
            is_dir($path) && !is_link($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
