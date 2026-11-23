<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests of bin/run.php as Composer installs it: the package is
 * built from the working tree with the dist rules (export-ignore) applied and
 * installed offline in a clean project; every run is a fresh PHP process.
 */
final class CliTest extends TestCase
{
    private static ?string $workDir = null;
    private static ?string $installedScript = null;
    private string $project = "";

    public static function setUpBeforeClass(): void
    {
        $repo = dirname(__DIR__);
        if (!self::commandExists("git") || !self::commandExists("composer") || !is_dir($repo . "/.git")) {
            return;
        }

        $work = sys_get_temp_dir() . "/runner-cli-" . bin2hex(random_bytes(6));
        mkdir($work . "/package", 0777, true);
        mkdir($work . "/installed", 0777, true);
        self::$workDir = $work;

        // Archive of the working tree (tracked and untracked, not ignored) using
        // a temporary index, so the repository index is not touched.
        $git = "GIT_INDEX_FILE=" . escapeshellarg($work . "/index") . " git -C " . escapeshellarg($repo);
        self::mustRun("{$git} add -A");
        $tree = trim(self::mustRun("{$git} write-tree"));
        self::mustRun("{$git} archive --format=tar {$tree} | tar -x -C " . escapeshellarg($work . "/package"));

        file_put_contents($work . "/installed/composer.json", json_encode([
            "name" => "test/runner-cli",
            "type" => "project",
            "repositories" => [
                ["packagist.org" => false],
                [
                    "type" => "path",
                    "url" => $work . "/package",
                    "options" => ["symlink" => false, "versions" => ["divengine/runner" => "0.0.1"]],
                ],
            ],
            "require" => ["divengine/runner" => "0.0.1"],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        self::mustRun("composer --working-dir=" . escapeshellarg($work . "/installed")
            . " install --no-interaction --no-progress --quiet");

        self::$installedScript = "vendor/divengine/runner/bin/run.php";
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$workDir !== null) {
            self::deleteDirectory(self::$workDir);
        }
        self::$workDir = null;
        self::$installedScript = null;
    }

    protected function setUp(): void
    {
        if (self::$installedScript === null) {
            $this->markTestSkipped("git and composer are needed to install the package");
        }

        // A fresh project folder per test that shares the installed vendor folder.
        $this->project = self::$workDir . "/project-" . bin2hex(random_bytes(4));
        mkdir($this->project);
        symlink(self::$workDir . "/installed/vendor", $this->project . "/vendor");
    }

    protected function tearDown(): void
    {
        if ($this->project !== "") {
            unlink($this->project . "/vendor");
            self::deleteDirectory($this->project);
        }
    }

    public function testPackageShipsTheBootstrapWithoutDevelopmentFiles(): void
    {
        $package = self::$workDir . "/installed/vendor/divengine/runner";
        $this->assertFileExists($package . "/bin/run.php");
        $this->assertFileExists($package . "/src/runner.php");
        $this->assertDirectoryDoesNotExist($package . "/tests");
        $this->assertDirectoryDoesNotExist($package . "/vendor");
    }

    public function testExampleFlowPausesAndResumesFromSavedContext(): void
    {
        $this->copyExample();
        $this->writeJson("input.json", ["items" => [["price" => 10, "qty" => 2], ["price" => 5, "qty" => 1]]]);

        $first = $this->cli(["flows/order.yml", "--context", "input.json", "--root", "functions", "--save-context", "state.json"]);
        $this->assertSame([3, "paused\n", ""], [$first["exit"], $first["stdout"], $first["stderr"]]);

        $second = $this->cli(["flows/order.yml", "--context", "state.json", "--save-context", "state.json"]);
        $this->assertSame([0, "done\n", ""], [$second["exit"], $second["stdout"], $second["stderr"]]);

        $state = $this->readJson("state.json");
        $this->assertSame("Total: 25.00 (reviewed)", $state["receipt"]);
        $this->assertSame(25.0, $state["total"], "floats keep their type through JSON");
        $this->assertSame(realpath($this->project . "/functions"), $state["_root_folder"]);
    }

    public function testNestedCallsPauseAndResumeAcrossProcessesWithoutRepeatingSteps(): void
    {
        $this->writeMarkActivity();
        $this->writeFile("flows/nested.yml", <<<'YAML'
            id: nested
            blocks:
              main:
                steps:
                  a1:
                    activity: mark
                  a2:
                    call: b.b1
                  a3:
                    activity: mark
              b:
                steps:
                  b1:
                    activity: mark
                  b2:
                    call: c.c1
                  b3:
                    activity: mark
              c:
                steps:
                  c1:
                    activity: mark
                  c2:
                    pause: true
                  c3:
                    activity: mark
                  c4:
                    pause: true
                  c5:
                    activity: mark
            YAML);

        $run = ["flows/nested.yml", "--root", "functions", "--save-context", "state.json"];
        $first = $this->cli($run);
        $this->assertSame([3, "paused\n"], [$first["exit"], $first["stdout"]], $first["stderr"]);
        $this->assertSame(["main", "b"], array_column($this->readJson("state.json")["_flow_stack"], "block"));

        $resume = ["flows/nested.yml", "--context", "state.json", "--save-context", "state.json"];
        $second = $this->cli($resume);
        $this->assertSame([3, "paused\n"], [$second["exit"], $second["stdout"]], $second["stderr"]);

        $third = $this->cli($resume);
        $this->assertSame([0, "done\n"], [$third["exit"], $third["stdout"]], $third["stderr"]);

        $expected = ["a1", "b1", "c1", "c3", "c5", "b3", "a3"];
        $state = $this->readJson("state.json");
        $this->assertSame($expected, $state["trace"]);
        $this->assertSame($expected, file($this->project . "/trace.log", FILE_IGNORE_NEW_LINES));
        $this->assertSame([], $state["_flow_state"]);
        $this->assertSame([], $state["_flow_stack"]);
    }

    public function testRuntimeErrorExitsOneAndSavesClearedResumeState(): void
    {
        $this->writeFile("functions/fail.php", <<<'PHP'
            <?php
            return function (array &$context): void {
                $context["before_error"] = true;
                throw new RuntimeException("boom in activity");
            };
            PHP);
        $this->writeFile("flows/fail.yml", "id: fail\nblocks:\n  main:\n    steps:\n      stop:\n        activity: fail\n");

        $result = $this->cli(["flows/fail.yml", "--root", "functions", "--save-context", "state.json"]);

        $this->assertSame([1, "error\n"], [$result["exit"], $result["stdout"]]);
        $this->assertStringContainsString("boom in activity", $result["stderr"]);
        $state = $this->readJson("state.json");
        $this->assertSame("error", $state["_runner_state"]);
        $this->assertTrue($state["before_error"]);
        $this->assertSame([], $state["_flow_state"]);
        $this->assertSame([], $state["_flow_stack"]);
    }

    public function testSetupErrorExitsOneWithDiagnostics(): void
    {
        $this->writeFile("flows/broken.yml", "id: broken\nblocks:\n  main:\n    steps:\n      x:\n        unknown_key: 1\n");

        $result = $this->cli(["flows/broken.yml", "--json"]);

        $this->assertSame(1, $result["exit"]);
        $this->assertSame("error", json_decode($result["stdout"], true, 512, JSON_THROW_ON_ERROR)["_runner_state"]);
        $this->assertStringContainsString("unknown_key", $result["stderr"]);
    }

    public function testJsonOutputIsNotCorruptedByFlowOutputOrWarnings(): void
    {
        $this->writeFile("functions/noisy.php", <<<'PHP'
            <?php
            return function (array &$context): string {
                echo "noise from activity\n";
                trigger_error("warning from activity", E_USER_WARNING);
                ob_start();
                echo "left in an open buffer\n";
                return "ok";
            };
            PHP);
        $this->writeFile("flows/noisy.yml", "id: noisy\nblocks:\n  main:\n    steps:\n      talk:\n        activity: noisy\n");

        $result = $this->cli(["flows/noisy.yml", "--root", "functions", "--json"]);

        $this->assertSame(0, $result["exit"], $result["stderr"]);
        $context = json_decode($result["stdout"], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame("done", $context["_runner_state"]);
        $this->assertSame("ok", $context["talk"]);
        $this->assertStringContainsString("noise from activity", $result["stderr"]);
        $this->assertStringContainsString("warning from activity", $result["stderr"]);
        $this->assertStringContainsString("left in an open buffer", $result["stderr"]);
    }

    /**
     * Values that json_encode() would turn into something else, or cannot encode.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function unrestorableValues(): array
    {
        $values = [
            "nested object" => ['$value = ["a" => [1, ["when" => new DateTimeImmutable("2026-01-01")]]];',
                'context["data"]["a"][1]["when"]: object of class DateTimeImmutable'],
            "plain object" => ['$value = new stdClass();', 'context["data"]: object of class stdClass'],
            "closure" => ['$value = ["callback" => fn (): int => 1];', 'context["data"]["callback"]: object of class Closure'],
            "open resource" => ['$value = [fopen("php://memory", "r")];', 'context["data"][0]: resource (stream)'],
            "closed resource" => ['$handle = fopen("php://memory", "r"); fclose($handle); $value = $handle;',
                'context["data"]: resource (closed)'],
            "too deep" => ['$value = "leaf"; for ($i = 0; $i < 512; $i++) { $value = [$value]; }',
                'nested deeper than 512 levels or recursive'],
            "recursive array" => ['$value = ["x" => 1]; $value["self"] = &$value;', 'nested deeper than 512 levels or recursive'],
            "nan" => ['$value = ["ratio" => NAN];', 'Inf and NaN cannot be JSON encoded'],
            "invalid utf-8" => ['$value = "\xB1\x31";', 'Malformed UTF-8'],
        ];

        $cases = [];
        foreach ($values as $name => [$code, $message]) {
            $cases["{$name}, --save-context"] = [$code, $message, "save"];
            $cases["{$name}, --json"] = [$code, $message, "json"];
        }

        return $cases;
    }

    /**
     * @dataProvider unrestorableValues
     */
    public function testUnrestorableContextFailsVisiblyAndKeepsPreviousFile(string $code, string $message, string $mode): void
    {
        $this->writeFile("functions/store.php", "<?php\nreturn function (array &\$context): void {\n    {$code}\n    \$context['data'] = \$value;\n};\n");
        $this->writeFile("flows/store.yml", "id: store\nblocks:\n  main:\n    steps:\n      store:\n        activity: store\n");
        $this->writeFile("state.json", "{\"previous\": true}\n");

        $output = $mode === "save" ? ["--save-context", "state.json"] : ["--json"];
        $result = $this->cli(array_merge(["flows/store.yml", "--root", "functions"], $output));

        $this->assertSame([2, ""], [$result["exit"], $result["stdout"]], $result["stderr"]);
        $this->assertStringContainsString("runner: flow ended done, but the context is not JSON-serializable: ", $result["stderr"]);
        $this->assertStringContainsString($message, $result["stderr"]);
        $this->assertSame("{\"previous\": true}\n", file_get_contents($this->project . "/state.json"));
        $this->assertSame(["flows", "functions", "state.json", "vendor"], $this->projectEntries());
    }

    /**
     * The deepest data (context + data + 510 arrays = 512 levels) is saved and
     * read back with --context; one level more is rejected (see unrestorableValues).
     */
    public function testNestedDataArraysRoundTripThroughSavedContext(): void
    {
        $this->writeFile("functions/store.php", <<<'PHP'
            <?php
            return function (array &$context): void {
                $deep = "leaf";
                for ($i = 0; $i < 510; $i++) {
                    $deep = [$deep];
                }
                $context["data"] = [
                    "int" => 7, "negative" => -3, "float" => 2.0, "fraction" => 0.25, "true" => true,
                    "false" => false, "null" => null, "text" => "ñandú €", "empty" => [],
                    "list" => [1, [2, [3, ["four" => 4.0]]]], "sparse" => [3 => "c", 7 => "g"],
                    "numeric keys" => ["10" => "ten"], "deep" => $deep,
                ];
            };
            PHP);
        $this->writeFile("flows/store.yml", "id: store
blocks:
  main:
    steps:
      store:
        activity: store
      wait:
        pause: true
");

        $first = $this->cli(["flows/store.yml", "--root", "functions", "--save-context", "state.json"]);
        $this->assertSame(3, $first["exit"], $first["stderr"]);
        $saved = $this->readJson("state.json", 513)["data"];

        $second = $this->cli(["flows/store.yml", "--context", "state.json", "--json"]);
        $this->assertSame(0, $second["exit"], $second["stderr"]);
        $resumed = json_decode($second["stdout"], true, 513, JSON_THROW_ON_ERROR)["data"];

        $deep = "leaf";
        for ($i = 0; $i < 510; $i++) {
            $deep = [$deep];
        }
        $expected = [
            "int" => 7, "negative" => -3, "float" => 2.0, "fraction" => 0.25, "true" => true,
            "false" => false, "null" => null, "text" => "ñandú €", "empty" => [],
            "list" => [1, [2, [3, ["four" => 4.0]]]], "sparse" => [3 => "c", 7 => "g"],
            "numeric keys" => [10 => "ten"], "deep" => $deep,
        ];
        $this->assertSame($expected, $saved);
        $this->assertSame($expected, $resumed);
    }

    public function testSaveReplacesTheFileAtomicallyWithoutLeftovers(): void
    {
        $this->writeFile("flows/hello.php", <<<'PHP'
            <?php
            return function (array &$context): void {
                $context["greeting"] = "Hello " . ($context["name"] ?? "World");
            };
            PHP);
        $this->writeFile("state.json", "{\"name\": \"Runner\"}\n");
        chmod($this->project . "/state.json", 0640);

        $result = $this->cli(["flows/hello.php", "--context", "state.json", "--save-context", "state.json"]);

        $this->assertSame([0, "done\n", ""], [$result["exit"], $result["stdout"], $result["stderr"]]);
        $this->assertSame("Hello Runner", $this->readJson("state.json")["greeting"]);
        $this->assertSame(0640, fileperms($this->project . "/state.json") & 0777);
        $this->assertSame(["flows", "state.json", "vendor"], $this->projectEntries());
    }

    public function testPhpFlowPathIsRelativeToWorkingDirectoryNotRoot(): void
    {
        $this->writeFile("flows/hello.php", "<?php\nreturn function (array &\$context): void { \$context['from'] = 'cwd'; };\n");
        $this->writeFile("functions/flows/hello.php", "<?php\nreturn function (array &\$context): void { \$context['from'] = 'root'; };\n");

        $result = $this->cli(["flows/hello.php", "--root", "functions", "--json"]);

        $this->assertSame(0, $result["exit"], $result["stderr"]);
        $this->assertSame("cwd", json_decode($result["stdout"], true, 512, JSON_THROW_ON_ERROR)["from"]);
    }

    public function testModulesFromContextFileResolveRelativeToWorkingDirectory(): void
    {
        $this->writeFile("lib/billing/total.php", "<?php\nreturn fn (array &\$context): string => 'billing';\n");
        $this->writeFile("lib/shipping/total.php", "<?php\nreturn fn (array &\$context): string => 'shipping';\n");
        $this->writeFile("flows/modules.yml", <<<'YAML'
            id: modules
            blocks:
              main:
                steps:
                  invoice:
                    module: billing
                    activity: total
                  shipping:
                    module: shipping
                    activity: total
            YAML);
        $this->writeJson("input.json", ["_modules" => ["billing" => "lib/billing", "shipping" => "lib/shipping"]]);

        $result = $this->cli(["flows/modules.yml", "--context", "input.json", "--json"]);

        $this->assertSame(0, $result["exit"], $result["stderr"]);
        $context = json_decode($result["stdout"], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(["billing", "shipping"], [$context["invoice"], $context["shipping"]]);
    }

    public function testProjectLocalRunShortcut(): void
    {
        $this->copyExample();
        $this->writeFile("run", "<?php\nrequire __DIR__ . '/vendor/divengine/runner/bin/run.php';\n");
        $this->writeJson("input.json", ["items" => [["price" => 30, "qty" => 1]]]);

        $result = $this->cli(["run", "flows/order.yml", "--context", "input.json", "--root", "functions"], false);

        $this->assertSame([3, "paused\n", ""], [$result["exit"], $result["stdout"], $result["stderr"]]);
    }

    public function testTraceKeepsStdoutExitCodeAndSavedContext(): void
    {
        $this->copyExample();
        $this->writeJson("input.json", ["items" => [["price" => 10, "qty" => 2], ["price" => 5, "qty" => 1]]]);
        $args = ["flows/order.yml", "--context", "input.json", "--root", "functions"];

        $plain = $this->cli(array_merge($args, ["--save-context", "plain.json"]));
        $traced = $this->cli(array_merge($args, ["--save-context", "traced.json", "--trace", "trace.json"]));

        $this->assertSame([3, "paused\n", ""], [$plain["exit"], $plain["stdout"], $plain["stderr"]]);
        $this->assertSame([$plain["exit"], $plain["stdout"], $plain["stderr"]], [$traced["exit"], $traced["stdout"], $traced["stderr"]]);
        $this->assertSame($this->comparableContext($this->readJson("plain.json")), $this->comparableContext($this->readJson("traced.json")));
        $this->assertSame(array_keys($this->readJson("plain.json")), array_keys($this->readJson("traced.json")));

        $trace = $this->readJson("trace.json");
        $this->assertSame(["divengine.runner.trace", 1, "run"], [$trace["protocol"], $trace["version"], $trace["mode"]]);
        $this->assertSame("paused", $trace["result"]["state"]);
        $this->assertSame(["flow", "function", "function"], array_column($trace["sources"], "role"));
        $this->assertSame(["flows", "functions", "input.json", "plain.json", "trace.json", "traced.json", "vendor"], $this->projectEntries());

        $resumed = $this->cli(["flows/order.yml", "--context", "traced.json", "--save-context", "traced.json", "--trace", "trace.json"]);
        $this->assertSame([0, "done\n"], [$resumed["exit"], $resumed["stdout"]], $resumed["stderr"]);
        $this->assertTrue($this->readJson("trace.json")["events"][0]["resume"]);
    }

    public function testSimulationResumesAcrossProcessesWithoutLoadingFunctions(): void
    {
        $this->copySimulationExample();
        $segment = ["flows/invoice.yml", "--simulate", "doubles.json", "--session", "session.json"];

        $first = $this->cli(array_merge($segment, ["--context", "context.json", "--trace", "trace-1.json"]));
        $this->assertSame([3, "paused\n", ""], [$first["exit"], $first["stdout"], $first["stderr"]]);
        $session = $this->readJson("session.json");
        $this->assertSame(["divengine.runner.simulation-session", 1], [$session["protocol"], $session["version"]]);
        $this->assertSame(["block" => "approval", "step_index" => 1], $session["context"]["_flow_state"]);
        $this->assertSame([["block" => "main", "step_index" => 3]], $session["context"]["_flow_stack"]);

        $second = $this->cli(array_merge($segment, ["--trace", "trace-2.json"]));
        $this->assertSame([0, "done\n", ""], [$second["exit"], $second["stdout"], $second["stderr"]]);

        $traces = [$this->readJson("trace-1.json"), $this->readJson("trace-2.json")];
        $this->assertExampleGolden("segment-1", $traces[0]);
        $this->assertExampleGolden("segment-2", $traces[1]);
        $this->assertSame($traces[0]["fingerprint"], $traces[1]["fingerprint"]);
        $this->assertSame(["flow", "doubles"], array_column($traces[1]["sources"], "role"));

        $final = $this->readJson("session.json")["context"];
        $this->assertSame(["done", "receipt sent", "captured"], [$final["_runner_state"], $final["receipt"], $final["last_charge"]]);
        $this->assertFileDoesNotExist($this->project . "/outbox.log");
        $this->assertFileDoesNotExist($this->project . "/payments.log");
        $this->assertStringNotContainsString("tok_visa_4242", (string) file_get_contents($this->project . "/trace-1.json"));
    }

    public function testSimulationNeverOverwritesANormalRunCheckpoint(): void
    {
        $this->copySimulationExample();
        $this->writeFile("state.json", "{\"_flow_state\": {\"block\": \"main\", \"step_index\": 2}}\n");

        $result = $this->cli(["flows/invoice.yml", "--simulate", "doubles.json", "--session", "state.json"]);

        $this->assertSame([2, ""], [$result["exit"], $result["stdout"]]);
        $this->assertStringContainsString("is not a simulation session", $result["stderr"]);
        $this->assertSame("{\"_flow_state\": {\"block\": \"main\", \"step_index\": 2}}\n", file_get_contents($this->project . "/state.json"));
    }

    public function testChangedDoublesRefuseToResumeASession(): void
    {
        $this->copySimulationExample();
        $segment = ["flows/invoice.yml", "--simulate", "doubles.json", "--session", "session.json"];
        $this->assertSame(3, $this->cli(array_merge($segment, ["--context", "context.json"]))["exit"]);
        $saved = file_get_contents($this->project . "/session.json");

        $doubles = $this->readJson("doubles.json");
        $doubles["doubles"]["send_receipt"]["returns"] = "changed";
        $this->writeJson("doubles.json", $doubles);
        $result = $this->cli($segment);

        $this->assertSame([2, ""], [$result["exit"], $result["stdout"]]);
        $this->assertStringContainsString("changed: expected fingerprint sha256:", $result["stderr"]);
        $this->assertSame($saved, file_get_contents($this->project . "/session.json"));
    }

    public function testUnmockedFunctionEndsTheSimulationInError(): void
    {
        $this->copySimulationExample();
        $doubles = $this->readJson("doubles.json");
        unset($doubles["doubles"]["billing:compute_total"]);
        $this->writeJson("doubles.json", $doubles);

        $result = $this->cli(["flows/invoice.yml", "--simulate", "doubles.json", "--context", "context.json", "--session", "s.json", "--trace", "t.json"]);

        $this->assertSame([1, "error\n"], [$result["exit"], $result["stdout"]]);
        $this->assertStringContainsString("no double for function 'billing:compute_total'", $result["stderr"]);
        $this->assertSame("error", $this->readJson("s.json")["context"]["_runner_state"]);
        $this->assertContains("function.missing", array_column($this->readJson("t.json")["events"], "type"));
        $this->assertFileDoesNotExist($this->project . "/payments.log");
    }

    public function testSimulationBudgetEndsTheRunInError(): void
    {
        $this->writeFile("flows/loop.yml", "id: loop\nblocks:\n  main:\n    steps:\n      spin:\n        jump: main.spin\n");
        $this->writeJson("doubles.json", ["doubles" => [], "budget" => ["transfers" => 3]]);

        $result = $this->cli(["flows/loop.yml", "--simulate", "doubles.json", "--trace", "t.json"]);

        $this->assertSame([1, "error\n"], [$result["exit"], $result["stdout"]]);
        $this->assertStringContainsString("budget exhausted: transfers (limit 3)", $result["stderr"]);
        $types = array_column($this->readJson("t.json")["events"], "type");
        $this->assertSame(["budget.exhausted", "run.end"], array_slice($types, -2));
    }

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function simulationInvocationErrors(): array
    {
        $sim = ["flows/invoice.yml", "--simulate", "doubles.json"];
        return [
            "with --save-context" => [array_merge($sim, ["--save-context", "out.json"]), "--save-context cannot be used with --simulate"],
            "with --root" => [array_merge($sim, ["--root", "functions"]), "--root cannot be used with --simulate"],
            "php flow" => [["flows/hello.php", "--simulate", "doubles.json"], "--simulate runs YAML flows only"],
            "session without simulate" => [["flows/invoice.yml", "--session", "s.json"], "--session needs --simulate"],
            "context and existing session" => [array_merge($sim, ["--session", "session.json", "--context", "context.json"]),
                "--context cannot be used to resume"],
            "trace over context" => [array_merge($sim, ["--context", "context.json", "--trace", "context.json"]),
                "--trace must not be the same file as --context"],
            "session over doubles" => [array_merge($sim, ["--session", "doubles.json"]), "is not a simulation session"],
            "trace over session" => [array_merge($sim, ["--session", "s.json", "--trace", "s.json"]), "--trace must not be the same file as --session"],
            "doubles not json" => [["flows/invoice.yml", "--simulate", "context.txt"], "invalid JSON in --simulate file"],
            "unknown key" => [["flows/invoice.yml", "--simulate", "unknown.json"], "unknown key in --simulate file: mocks"],
            "invalid double" => [["flows/invoice.yml", "--simulate", "invalid.json"], "needs exactly one of"],
            "invalid budget" => [["flows/invoice.yml", "--simulate", "budget.json"], "must be a positive integer"],
        ];
    }

    /**
     * @dataProvider simulationInvocationErrors
     *
     * @param list<string> $args
     */
    public function testSimulationInvocationErrorsExitTwoWithoutWriting(array $args, string $message): void
    {
        $this->copySimulationExample();
        $this->writeFile("flows/hello.php", "<?php\nreturn fn (array &\$context) => null;\n");
        $this->writeFile("session.json", json_encode(["protocol" => "divengine.runner.simulation-session", "version" => 1,
            "fingerprint" => "sha256:x", "double_calls" => [], "context" => ["a" => 1]]) . "\n");
        $this->writeFile("context.txt", "not json");
        $this->writeJson("unknown.json", ["mocks" => []]);
        $this->writeJson("invalid.json", ["doubles" => ["send_receipt" => ["returns" => 1, "throws" => "x"]]]);
        $this->writeJson("budget.json", ["doubles" => [], "budget" => ["steps" => 0]]);
        $before = $this->projectEntries();

        $result = $this->cli($args);

        $this->assertSame([2, ""], [$result["exit"], $result["stdout"]], $result["stderr"]);
        $this->assertStringContainsString($message, $result["stderr"]);
        $this->assertSame($before, $this->projectEntries(), "nothing was written");
    }

    public function testHelpGoesToStdoutAndExitsZero(): void
    {
        $result = $this->cli(["--help"]);

        $this->assertSame(0, $result["exit"]);
        $this->assertStringStartsWith("Usage: php vendor/divengine/runner/bin/run.php <flow>", $result["stdout"]);
        $this->assertSame("", $result["stderr"]);
    }

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function invocationErrors(): array
    {
        return [
            "no arguments" => [[], "missing flow path"],
            "unknown option" => [["flows/side.yml", "--verbose"], "unknown option: --verbose"],
            "short option" => [["flows/side.yml", "-x"], "unknown option: -x"],
            "missing value" => [["flows/side.yml", "--root"], "option --root needs a value"],
            "empty value" => [["flows/side.yml", "--root="], "needs a non-empty value"],
            "repeated option" => [["flows/side.yml", "--root", "functions", "--root=functions"], "given more than once"],
            "two flows" => [["flows/side.yml", "flows/side.yml"], "only one flow"],
            "missing flow" => [["flows/none.yml"], "flow file not found: flows/none.yml"],
            "unsupported extension" => [["input.txt"], "must be a .yml, .yaml or .php file"],
            "missing context" => [["flows/side.yml", "--context", "none.json"], "context file not found"],
            "invalid json" => [["flows/side.yml", "--context", "input.txt"], "invalid JSON in context file"],
            "json list" => [["flows/side.yml", "--context", "list.json"], "must contain a JSON object"],
            "root not a folder" => [["flows/side.yml", "--root", "input.txt"], "--root is not a folder"],
            "save folder missing" => [["flows/side.yml", "--save-context", "none/state.json"], "folder for --save-context does not exist"],
            "save to folder" => [["flows/side.yml", "--save-context", "flows"], "points to a folder"],
        ];
    }

    /**
     * @dataProvider invocationErrors
     *
     * @param list<string> $args
     */
    public function testInvocationErrorsExitTwoWithoutRunningTheFlow(array $args, string $message): void
    {
        $this->writeFile("functions/side_effect.php", "<?php\nreturn function (array &\$context): void { touch('ran.flag'); };\n");
        $this->writeFile("flows/side.yml", "id: side\nblocks:\n  main:\n    steps:\n      touch:\n        activity: side_effect\n");
        $this->writeFile("input.txt", "not json");
        $this->writeFile("list.json", "[1, 2]");

        $result = $this->cli($args);

        $this->assertSame(2, $result["exit"]);
        $this->assertSame("", $result["stdout"]);
        $this->assertStringContainsString($message, $result["stderr"]);
        $this->assertFileDoesNotExist($this->project . "/ran.flag");
    }

    public function testCheckoutBootstrapRunsWithoutAnInstalledProject(): void
    {
        $this->writeFile("flows/hello.php", "<?php\nreturn function (array &\$context): void { \$context['ok'] = true; };\n");

        $result = $this->cli([dirname(__DIR__) . "/bin/run.php", "flows/hello.php"], false);

        $this->assertSame([0, "done\n", ""], [$result["exit"], $result["stdout"], $result["stderr"]]);
    }

    /**
     * @param list<string> $args
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function cli(array $args, bool $installedScript = true): array
    {
        if ($installedScript) {
            array_unshift($args, (string) self::$installedScript);
        }
        $command = array_merge([PHP_BINARY, "-d", "display_errors=1"], $args);
        $process = proc_open($command, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes, $this->project);
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ["exit" => proc_close($process), "stdout" => $stdout, "stderr" => $stderr];
    }

    private function copySimulationExample(): void
    {
        $source = dirname(__DIR__) . "/examples/simulation";
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = substr((string) $file, strlen($source) + 1);
            if (!str_starts_with($relative, "expected")) {
                $this->writeFile($relative, (string) file_get_contents((string) $file));
            }
        }
    }

    /**
     * The deterministic part of an example trace, as stored in examples/simulation/expected.
     * Regenerate with RUNNER_UPDATE_GOLDEN=1 after an intended change.
     *
     * @param array<string, mixed> $trace
     */
    private function assertExampleGolden(string $name, array $trace): void
    {
        $semantic = [
            "protocol" => $trace["protocol"],
            "version" => $trace["version"],
            "mode" => $trace["mode"],
            "flow_id" => $trace["flow"]["id"],
            "result" => $trace["result"],
            "simulation" => $trace["simulation"],
            "truncated" => $trace["truncated"],
            "events" => $trace["events"],
        ];
        $json = json_encode($semantic, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
        $file = dirname(__DIR__) . "/examples/simulation/expected/{$name}.json";
        if (getenv("RUNNER_UPDATE_GOLDEN") === "1") {
            file_put_contents($file, $json);
        }
        $this->assertSame((string) file_get_contents($file), $json, "example golden {$name}");
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function comparableContext(array $context): array
    {
        foreach (["_runner_started_at", "_runner_ended_at", "_runner_result", "_runner_logs", "_exception_pause_token",
            "_exception_jump_token", "_exception_pause", "_exception_jump"] as $key) {
            unset($context[$key]);
        }

        return $context;
    }

    private function copyExample(): void
    {
        $source = dirname(__DIR__) . "/examples/order";
        foreach (["flows/order.yml", "functions/compute_total.php", "functions/needs_review.php", "functions/format_receipt.php"] as $file) {
            $this->writeFile($file, (string) file_get_contents($source . "/" . $file));
        }
    }

    private function writeMarkActivity(): void
    {
        $this->writeFile("functions/mark.php", <<<'PHP'
            <?php
            return function (array &$context): int {
                $step = $context["_current_step_id"];
                $context["trace"][] = $step;
                file_put_contents("trace.log", $step . "\n", FILE_APPEND);
                return count($context["trace"]);
            };
            PHP);
    }

    private function writeFile(string $relative, string $contents): void
    {
        $path = $this->project . "/" . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $relative, array $data): void
    {
        $this->writeFile($relative, json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $relative, int $depth = 512): array
    {
        $data = json_decode((string) file_get_contents($this->project . "/" . $relative), true, $depth, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        return $data;
    }

    /**
     * @return list<string>
     */
    private function projectEntries(): array
    {
        $entries = array_values(array_diff((array) scandir($this->project), [".", ".."]));
        sort($entries);

        return $entries;
    }

    private static function commandExists(string $command): bool
    {
        exec("command -v " . escapeshellarg($command) . " 2>/dev/null", $output, $exitCode);

        return $exitCode === 0;
    }

    private static function mustRun(string $command): string
    {
        exec($command . " 2>&1", $output, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException("command failed ({$exitCode}): {$command}\n" . implode("\n", $output));
        }

        return implode("\n", $output);
    }

    private static function deleteDirectory(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry !== "." && $entry !== "..") {
                self::deleteDirectory($dir . "/" . $entry);
            }
        }
        rmdir($dir);
    }
}
