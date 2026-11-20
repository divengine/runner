<?php

declare(strict_types=1);

use divengine\runner\runner;
use PHPUnit\Framework\TestCase;

final class RunnerTest extends TestCase
{
    private array $tempDirs = [];
    private array $globalLogs = [];

    protected function tearDown(): void
    {
        runner::setLogger(null);
        $this->globalLogs = [];

        foreach ($this->tempDirs as $tempDir) {
            $this->deleteDirectory($tempDir);
        }
        $this->tempDirs = [];
    }

    public function testRunUpdatesContext(): void
    {
        $context = [
            "foo" => "bar",
        ];

        $flow = function (array &$context): void {
            $context["ran"] = true;
        };

        runner::run($flow, $context);

        $this->assertSame("done", $context["_runner_state"] ?? null);
        $this->assertSame(true, $context["ran"] ?? false);
        $this->assertIsArray($context["_runner_logs"] ?? null);
        $this->assertNotEmpty($context["_runner_started_at"] ?? "");
        $this->assertNotEmpty($context["_runner_ended_at"] ?? "");
    }

    public function testRunMarksErrorWhenFlowThrowsRegularException(): void
    {
        $context = [];
        $flow = function (array &$context): void {
            throw new RuntimeException("boom");
        };

        runner::run($flow, $context);

        $this->assertSame("error", $context["_runner_state"] ?? null);
        $this->assertStringContainsString("boom", $context["_runner_result"] ?? "");
        $this->assertStringContainsString("[Message] boom", $context["_runner_error"] ?? "");
    }

    public function testRunPausesWhenPauseTokenIsUsed(): void
    {
        $context = [];
        $flow = function (array &$context): void {
            throw new RuntimeException($context["_exception_pause"] . " pause requested");
        };

        runner::run($flow, $context);

        $this->assertSame("paused", $context["_runner_state"] ?? null);
        $this->assertArrayHasKey("_runner_error", $context);
        $this->assertNull($context["_runner_error"]);
    }

    public function testRunAutoResumesWhenAutoresumeIsEnabled(): void
    {
        $context = [];
        $flow = function (array &$context): void {
            $attempt = $context["attempt"] ?? 0;
            if ($attempt === 0) {
                $context["attempt"] = 1;
                $context["_autoresume"] = true;
                throw new RuntimeException($context["_exception_pause"] . " first pause");
            }

            $context["completed"] = true;
        };

        runner::run($flow, $context);

        $this->assertSame("done", $context["_runner_state"] ?? null);
        $this->assertTrue((bool) ($context["completed"] ?? false));
        $this->assertFalse((bool) ($context["_autoresume"] ?? true));
    }

    public function testRunRejectsFlowWithoutContextParameterName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("first parameter must be named 'context'");

        $context = [];
        $flow = function (array &$ctx): void {
            $ctx["ran"] = true;
        };

        runner::run($flow, $context);
    }

    public function testRunRejectsFlowWithoutContextByReference(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("must pass 'context' by reference");

        $context = [];
        $flow = function (array $context): void {
            $context["ran"] = true;
        };

        runner::run($flow, $context, ["require_context_param_name" => false]);
    }

    public function testImporterLoadsCallableFromExplicitPath(): void
    {
        $projectDir = $this->createTempProject();
        $filePath = $this->writeFunctionFile(
            $projectDir,
            "flow_load_path",
            '$context["value"] = 5;'
        );

        $callable = runner::importer($filePath);
        $context = [
            "value" => null,
        ];
        $callable($context);

        $this->assertIsCallable($callable);
        $this->assertSame(5, $context["value"]);
    }

    public function testImporterLoadsFunctionFromPathWithoutPhpExtension(): void
    {
        $projectDir = $this->createTempProject();
        $functionName = "job_" . bin2hex(random_bytes(6));

        $filePath = $this->writeFunctionFile(
            $projectDir,
            $functionName,
            '$context["loaded_from_file"] = true;'
        );

        $pathWithoutExt = substr($filePath, 0, -4);
        $callable = runner::importer($pathWithoutExt);
        $context = [
            "loaded_from_file" => false,
        ];
        $callable($context);

        $this->assertIsCallable($callable);
        $this->assertTrue((bool) $context["loaded_from_file"]);
    }

    public function testRunLoadsStringFlowUsingImporter(): void
    {
        $projectDir = $this->createTempProject();
        $functionName = "flow_" . bin2hex(random_bytes(6));

        $filePath = $this->writeFunctionFile(
            $projectDir,
            $functionName,
            '$context["imported_flow_executed"] = true;'
        );

        $context = [];
        runner::run($filePath, $context);

        $this->assertSame("done", $context["_runner_state"] ?? null);
        $this->assertTrue((bool) ($context["imported_flow_executed"] ?? false));
    }

    public function testGenerateFlowCodeFromYamlReturnsExecutableClosure(): void
    {
        $yamlPath = __DIR__ . DIRECTORY_SEPARATOR . "flows" . DIRECTORY_SEPARATOR . "business_flow.yml";

        $generatedCode = runner::generateFlowCodeFromYaml($yamlPath);
        $this->assertStringContainsString('return function (array &$context): void {', $generatedCode);
        $this->assertStringContainsString("jump_if_audit_enabled", $generatedCode);

        $flowCallable = runner::flowFromYaml($yamlPath);
        $this->assertIsCallable($flowCallable);
    }

    public function testRunBusinessFixtureFlowUsesActivitiesAndBlocks(): void
    {
        $tempDir = $this->createTempProject();
        $targetFile = $tempDir . DIRECTORY_SEPARATOR . "business-output.txt";
        $flowPath = __DIR__ . DIRECTORY_SEPARATOR . "flows" . DIRECTORY_SEPARATOR . "business_flow.yml";

        $context = [
            "left" => 8,
            "right" => 5,
            "text" => "runner sfm",
            "customer_name" => "rafa",
            "enable_audit" => true,
            "target_file" => $targetFile,
            "_root_folder" => __DIR__ . DIRECTORY_SEPARATOR . "activities",
        ];

        runner::run($flowPath, $context);

        $this->assertSame("done", $context["_runner_state"] ?? null);
        $this->assertSame(13, $context["sum"] ?? null);
        $this->assertSame(13, $context["read_value"] ?? null);
        $this->assertSame("RUNNER SFM", $context["upper_text"] ?? null);
        $this->assertSame("business_done", $context["flow_result"] ?? null);
        $this->assertTrue((bool) ($context["file_written"] ?? false));
        $this->assertIsString($context["now_iso"] ?? null);
        $this->assertFileExists($targetFile);

        $contents = file_get_contents($targetFile);
        $this->assertNotFalse($contents);
        $this->assertStringContainsString("customer=rafa; sum=13; audited=1", (string) $contents);
    }

    public function testRunPauseResumeFixtureFlowResumesFromSavedStep(): void
    {
        $flowPath = __DIR__ . DIRECTORY_SEPARATOR . "flows" . DIRECTORY_SEPARATOR . "pause_resume_flow.yml";
        $context = [
            "left" => 10,
            "right" => 2,
            "text" => "hold me",
            "_root_folder" => __DIR__ . DIRECTORY_SEPARATOR . "activities",
        ];

        runner::run($flowPath, $context);

        $this->assertSame("paused", $context["_runner_state"] ?? null);
        $this->assertTrue((bool) ($context["pause_once_done"] ?? false));
        $this->assertSame(12, $context["sum"] ?? null);
        $this->assertArrayNotHasKey("upper_text", $context);

        runner::run($flowPath, $context);

        $this->assertSame("done", $context["_runner_state"] ?? null);
        $this->assertSame(12, $context["sum"] ?? null);
        $this->assertSame("HOLD ME", $context["upper_text"] ?? null);
        $this->assertSame("resume_done", $context["flow_result"] ?? null);
        $this->assertSame(-1, $context["_flow_state"]["step_index"] ?? -1);
        $this->assertNull($context["_flow_state"]["block"] ?? null);
    }

    public function testRunYamlExecutesFlowDirectly(): void
    {
        $tempDir = $this->createTempProject();
        $targetFile = $tempDir . DIRECTORY_SEPARATOR . "business-output-runyaml.txt";
        $flowPath = __DIR__ . DIRECTORY_SEPARATOR . "flows" . DIRECTORY_SEPARATOR . "business_flow.yml";

        $context = [
            "left" => 3,
            "right" => 4,
            "text" => "yaml mode",
            "customer_name" => "rafageist",
            "enable_audit" => false,
            "target_file" => $targetFile,
            "_root_folder" => __DIR__ . DIRECTORY_SEPARATOR . "activities",
        ];

        runner::runYaml($flowPath, $context);

        $this->assertSame("done", $context["_runner_state"] ?? null);
        $this->assertSame(7, $context["sum"] ?? null);
        $this->assertSame("YAML MODE", $context["upper_text"] ?? null);
        $this->assertSame("business_done", $context["flow_result"] ?? null);
        $this->assertFileExists($targetFile);
    }

    public function testRunYamlUsesRootFolderFromInitialContext(): void
    {
        $tempDir = $this->createTempProject();
        $yamlPath = $tempDir . DIRECTORY_SEPARATOR . "flow-from-context-root.yml";

        $yaml = <<<YAML
id: context-root-sample
blocks:
  main:
    steps:
      sum_step:
        activity: add_values
      normalize_text:
        activity: uppercase_text
YAML;

        file_put_contents($yamlPath, $yaml);

        $context = [
            "left" => 9,
            "right" => 1,
            "text" => "context root",
            "_root_folder" => __DIR__ . DIRECTORY_SEPARATOR . "activities",
        ];

        runner::run($yamlPath, $context);

        $this->assertSame("done", $context["_runner_state"] ?? null);
        $this->assertSame(10, $context["sum"] ?? null);
        $this->assertSame("CONTEXT ROOT", $context["upper_text"] ?? null);
    }

    public function testListFormsOfBlocksAndStepsAreRejected(): void
    {
        $projectDir = $this->createTempProject();
        $cases = [
            "blocks:\n  - main:\n      steps: {}\n" => "'blocks' must be a mapping of block id to block",
            "blocks:\n  main:\n    steps:\n      - s1:\n          pause: true\n" => "'steps' of block 'main' must be a mapping",
            "blocks:\n  main:\n    id: main\n    steps:\n      s1:\n        id: s1\n" => "unknown key 'id' in block 'main'; unknown key 'id' in step 'main.s1'",
        ];

        foreach ($cases as $yaml => $expected) {
            $yamlPath = $projectDir . DIRECTORY_SEPARATOR . md5($yaml) . ".yml";
            file_put_contents($yamlPath, $yaml);
            try {
                runner::generateFlowCodeFromYaml($yamlPath);
                $this->fail("must be rejected: {$expected}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function testFlowLoopHandlesJumpTokenAndContinuesWithSavedBlock(): void
    {
        $context = [];
        $context["_start"] = function (array &$context): mixed {
            if (!isset($context["jumped"])) {
                $context["jumped"] = true;
                $context["_flow_state"] = [
                    "block" => "end",
                    "step_index" => 1,
                ];
                throw new RuntimeException($context["_exception_jump_token"] . " jump");
            }
            return "should_not_repeat_start";
        };
        $context["_end"] = function (array &$context): string {
            return "end";
        };

        $result = runner::flowLoop("start", $context);

        $this->assertSame("end", $result);
        $this->assertTrue((bool) ($context["jumped"] ?? false));
    }

    public function testFlowLoopRethrowsPauseTokenException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("pause requested");

        $context = [];
        $context["_start"] = function (array &$context): void {
            throw new RuntimeException($context["_exception_pause_token"] . " pause requested");
        };

        runner::flowLoop("start", $context);
    }

    public function testCheckPauseUsesSavedFlowState(): void
    {
        $context = [
            "_flow_state" => [
                "block" => "sample",
                "step_index" => 2,
            ],
        ];

        $this->assertFalse(runner::checkPause(2, $context));
        $this->assertTrue(runner::checkPause(3, $context));
        $this->assertSame([], $context["_flow_state"]);
    }

    public function testSetLoggerIsUsedByRunWhenNoPerRunLoggerIsProvided(): void
    {
        runner::setLogger(function (string $level, string $message): void {
            $this->globalLogs[] = $level . "|" . $message;
        });

        $context = [];
        $flow = function (array &$context): void {
            $context["ok"] = true;
        };

        runner::run($flow, $context);

        $this->assertTrue((bool) ($context["ok"] ?? false));
        $this->assertNotEmpty($this->globalLogs);
        $this->assertStringContainsString("Starting job execution", implode("\n", $this->globalLogs));
    }

    public function testImporterMissingFunctionTriggersLoggerPath(): void
    {
        $projectDir = $this->createTempProject();
        $logs = [];

        $callable = runner::importer(
            $projectDir . DIRECTORY_SEPARATOR . "missing_fn_" . bin2hex(random_bytes(4)),
            null,
            [],
            function (string $level, string $message) use (&$logs): void {
                $logs[] = [$level, $message];
            }
        );

        $this->assertNull($callable);
        $this->assertNotEmpty($logs);
        $this->assertSame("ERROR", $logs[0][0]);
        $this->assertStringContainsString("Import target not found or not callable", $logs[0][1]);
    }

    public function testImporterFallsBackToGlobalCallableIdentifier(): void
    {
        $callable = runner::importer("strlen");
        $this->assertIsCallable($callable);
        $this->assertSame(4, $callable("flow"));
    }

    public function testImporterAcceptsNamespacedCallableIdentifier(): void
    {
        $functionName = "fn_" . bin2hex(random_bytes(4));
        $reference = "divengine\\runner\\tests\\{$functionName}";

        eval(
            "namespace divengine\\runner\\tests; " .
            "function {$functionName}(array &\$context): void { " .
            "\$context['namespaced_callable_loaded'] = true; " .
            "}"
        );

        $callable = runner::importer($reference);
        $this->assertIsCallable($callable);

        $context = [
            "namespaced_callable_loaded" => false,
        ];
        $callable($context);
        $this->assertTrue($context["namespaced_callable_loaded"]);
    }

    public function testRunYamlResolvesActivitiesUsingRuntimeRootFolder(): void
    {
        $tempDir = $this->createTempProject();
        $rootA = $tempDir . DIRECTORY_SEPARATOR . "root_a";
        $rootB = $tempDir . DIRECTORY_SEPARATOR . "root_b";
        mkdir($rootA, 0777, true);
        mkdir($rootB, 0777, true);

        file_put_contents(
            $rootA . DIRECTORY_SEPARATOR . "switch_root.php",
            "<?php\nreturn function (array &\$context): void {\n" .
            "    \$context['_root_folder'] = " . var_export($rootB, true) . ";\n" .
            "    \$context['root_switched'] = true;\n" .
            "};\n"
        );

        file_put_contents(
            $rootB . DIRECTORY_SEPARATOR . "load_from_new_root.php",
            "<?php\nreturn function (array &\$context): void {\n" .
            "    \$context['loaded_from_runtime_root'] = true;\n" .
            "};\n"
        );

        $yamlPath = $tempDir . DIRECTORY_SEPARATOR . "runtime-root.yml";
        $yaml = <<<YAML
id: runtime-root-flow
blocks:
  main:
    steps:
      set_root:
        activity: switch_root
      load_next:
        activity: load_from_new_root
YAML;

        file_put_contents($yamlPath, $yaml);

        $context = [
            "_root_folder" => $rootA,
        ];

        runner::run($yamlPath, $context);

        $this->assertSame("done", $context["_runner_state"] ?? null);
        $this->assertTrue((bool) ($context["root_switched"] ?? false));
        $this->assertTrue((bool) ($context["loaded_from_runtime_root"] ?? false));
        $this->assertSame($rootB, $context["_root_folder"] ?? null);
    }

    public function testImporterHandlesRequireErrorAndLogsException(): void
    {
        $projectDir = $this->createTempProject();
        $functionName = "broken_" . bin2hex(random_bytes(4));
        $brokenPath = $projectDir . DIRECTORY_SEPARATOR . "functions" . DIRECTORY_SEPARATOR . $functionName . ".php";
        file_put_contents($brokenPath, "<?php this is invalid php");

        $logs = [];
        $callable = runner::importer(
            $brokenPath,
            null,
            [],
            function (string $level, string $message, array $context = []) use (&$logs): void {
                $logs[] = [$level, $message, $context];
            }
        );

        $this->assertNull($callable);
        $this->assertNotEmpty($logs);
        $this->assertSame("ERROR", $logs[0][0]);
        $this->assertStringContainsString("Error requiring file", $logs[0][1]);
        $this->assertArrayHasKey("exception", $logs[0][2]);
    }

    public function testImporterFailsWhenFileDoesNotReturnCallable(): void
    {
        $projectDir = $this->createTempProject();
        $filePath = $projectDir . DIRECTORY_SEPARATOR . "not_callable.php";
        file_put_contents($filePath, "<?php return 123;");

        $logs = [];
        $callable = runner::importer(
            $filePath,
            null,
            [],
            function (string $level, string $message) use (&$logs): void {
                $logs[] = [$level, $message];
            }
        );

        $this->assertNull($callable);
        $this->assertNotEmpty($logs);
        $this->assertSame("ERROR", $logs[0][0]);
        $this->assertStringContainsString("must return a callable", $logs[0][1]);
    }

    public function testWrappersAndUpdateContextMutateSharedContext(): void
    {
        $context = [];

        runner::updateContext($context, ["a" => 1], "s1", 1);
        $this->assertSame(1, $context["a"]);
        $this->assertSame("s1", $context["_current_step_id"]);
        $this->assertSame(1, $context["_current_step_index"]);

        $condResult = runner::wrapCondition(
            fn (array &$ctx): bool => (($ctx["a"] ?? 0) === 1),
            "cond",
            2,
            $context
        );
        $this->assertTrue($condResult);

        runner::wrapActivity(
            fn (array &$ctx): string => "ok-" . ($ctx["a"] ?? 0),
            "activity_result",
            3,
            $context
        );
        $this->assertSame("ok-1", $context["activity_result"]);

        $called = false;
        $stateDuringCall = null;
        $context["_call_target"] = function (array &$ctx) use (&$called, &$stateDuringCall): void {
            $called = true;
            $stateDuringCall = $ctx["_flow_state"];
            $ctx["call_ran"] = true;
        };
        runner::wrapCall("call", 4, $context, "call_target", 9, "main");
        $this->assertTrue($called);
        $this->assertTrue((bool) ($context["call_ran"] ?? false));
        // The called block starts at the target step...
        $this->assertSame(["block" => "call_target", "step_index" => 9], $stateDuringCall);
        // ...and an unconsumed pointer does not outlive the call.
        $this->assertSame([], $context["_flow_state"]);
        $this->assertSame([], $context["_flow_stack"]);
        $this->assertSame("main", $context["_current_block_id"]);
    }

    public function testWrapJumpSetsStateAndThrowsJumpToken(): void
    {
        /** @var array<string, mixed> $context */
        $context = [];
        $caught = null;

        try {
            runner::wrapJump("jump_step", 7, $context, "go", 11);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, "wrapJump must throw");
        $this->assertStringContainsString("Jump to go[11]", $caught->getMessage());
        $this->assertStringContainsString($context["_exception_jump_token"], $caught->getMessage());

        $this->assertSame("go", $context["_flow_state"]["block"] ?? null);
        $this->assertSame(11, $context["_flow_state"]["step_index"] ?? null);
    }

    public function testWrapPauseSetsStateAndThrowsPauseToken(): void
    {
        /** @var array<string, mixed> $context */
        $context = [];
        $caught = null;

        try {
            runner::wrapPause("blockX", "pause_step", 12, $context);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, "wrapPause must throw");
        $this->assertStringContainsString("Pause at blockX.pause_step[12]", $caught->getMessage());
        $this->assertStringContainsString($context["_exception_pause_token"], $caught->getMessage());

        $this->assertSame("blockX", $context["_flow_state"]["block"] ?? null);
        $this->assertSame(12, $context["_flow_state"]["step_index"] ?? null);
    }

    public function testSafeSerializeHandlesNonSerializableValues(): void
    {
        $resource = fopen("php://memory", "r");
        $stringable = new class {
            public function __toString(): string
            {
                return "stringable";
            }
        };

        $data = [
            "resource" => $resource,
            "date" => new \DateTimeImmutable("2026-02-12T00:00:00+00:00"),
            "stringable" => $stringable,
            "deep" => ["a" => ["b" => ["c" => ["d" => ["e" => ["f" => ["g" => ["h" => ["i" => ["j" => 1]]]]]]]]]],
        ];

        $json = runner::safeSerialize($data);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsString($decoded["resource"]);
        $this->assertStringContainsString("resource(", $decoded["resource"]);
        $this->assertSame("stringable", $decoded["stringable"]);
        $this->assertSame("2026-02-12T00:00:00+00:00", $decoded["date"]);
        $this->assertNull($decoded["deep"]["a"]["b"]["c"]["d"]["e"]["f"]["g"]["h"]["i"] ?? null);

        fclose($resource);
    }

    public function testImporterPrefersFileOverSameNamedCallable(): void
    {
        $projectDir = $this->createTempProject();
        $functionsDir = $projectDir . DIRECTORY_SEPARATOR . "functions";
        $this->writeFunctionFile($projectDir, "strtoupper", "\$context['from_file'] = true;");

        $callable = runner::importer("strtoupper", null, ["_root_folder" => $functionsDir]);
        $this->assertIsCallable($callable);
        $this->assertNotSame("strtoupper", $callable);

        $context = ["from_file" => false];
        $callable($context);
        $this->assertTrue($context["from_file"]);

        // Without a matching file the callable identifier is still the fallback.
        $this->assertSame("strtoupper", runner::importer("strtoupper", null, ["_root_folder" => $projectDir]));
    }

    public function testCallReturnsIntoCallingStepBeforeItsActivity(): void
    {
        $context = $this->runTraceFlow(<<<YAML
blocks:
  main:
    steps:
      a:
        activity: mark
      c:
        call: sub.s1
        activity: mark
      after:
        activity: mark
  sub:
    steps:
      s1:
        activity: mark
      s2:
        activity: mark
YAML);

        $this->assertSame("done", $context["_runner_state"]);
        $this->assertSame(["a", "s1", "s2", "c", "after"], $context["trace"]);
        $this->assertSame([], $context["_flow_stack"]);
    }

    public function testPauseInsideCalledBlockResumesIntoCaller(): void
    {
        $yaml = <<<YAML
blocks:
  main:
    steps:
      a:
        activity: mark
      c:
        context:
          marker: from_step
        call: sub.s1
        activity: mark
      after:
        activity: mark
  sub:
    steps:
      s1:
        condition: first_time
        context:
          marker: from_sub
        pause: true
      s2:
        activity: mark
YAML;

        $context = $this->runTraceFlow($yaml);
        $this->assertSame("paused", $context["_runner_state"]);
        $this->assertSame(["a"], $context["trace"]);
        $this->assertSame([["block" => "main", "step_index" => 2]], $context["_flow_stack"]);

        $context = $this->runTraceFlow($yaml, $context);
        $this->assertSame("done", $context["_runner_state"]);
        $this->assertSame(["a", "s2", "c", "after"], $context["trace"]);
        // The calling step's context update is not re-applied after the call.
        $this->assertSame("from_sub", $context["marker"]);
        $this->assertSame([], $context["_flow_stack"]);
        $this->assertSame([], $context["_flow_state"]);
    }

    public function testPauseInsideNestedCallsResumesThroughEveryCaller(): void
    {
        $yaml = <<<YAML
blocks:
  main:
    steps:
      m1:
        call: a.a1
      m2:
        activity: mark
  a:
    steps:
      a1:
        call: b.b1
      a2:
        activity: mark
  b:
    steps:
      b1:
        condition: first_time
        pause: true
      b2:
        activity: mark
YAML;

        $context = $this->runTraceFlow($yaml);
        $this->assertSame("paused", $context["_runner_state"]);
        $this->assertCount(2, $context["_flow_stack"]);

        $context = $this->runTraceFlow($yaml, $context);
        $this->assertSame("done", $context["_runner_state"]);
        $this->assertSame(["b2", "a2", "m2"], $context["trace"]);
    }

    public function testAutoresumeInsideCalledBlockReturnsIntoCaller(): void
    {
        $context = $this->runTraceFlow(<<<YAML
blocks:
  main:
    steps:
      c:
        call: sub.s1
      after:
        activity: mark
  sub:
    steps:
      s1:
        condition: first_time
        context:
          _autoresume: true
        pause: true
      s2:
        activity: mark
YAML);

        $this->assertSame("done", $context["_runner_state"]);
        $this->assertSame(["s2", "after"], $context["trace"]);
    }

    public function testJumpOutOfCalledBlockDropsCallContinuation(): void
    {
        $context = $this->runTraceFlow(<<<YAML
blocks:
  main:
    steps:
      c:
        call: sub.s1
        activity: mark
      after:
        activity: mark
  sub:
    steps:
      s1:
        jump: other.o1
  other:
    steps:
      o1:
        activity: mark
YAML);

        $this->assertSame("done", $context["_runner_state"]);
        $this->assertSame(["o1"], $context["trace"]);
        $this->assertSame([], $context["_flow_stack"]);
    }

    public function testJumpToCalledBlockRestartsNearestInvocationAndReturns(): void
    {
        $context = $this->runTraceFlow(<<<YAML
blocks:
  main:
    steps:
      c:
        call: sub.s1
        activity: mark
      after:
        activity: mark
  sub:
    steps:
      s1:
        activity: mark
      s2:
        condition: first_time
        jump: sub.s1
      s3:
        activity: mark
YAML);

        $this->assertSame("done", $context["_runner_state"]);
        $this->assertSame(["s1", "s1", "s3", "c", "after"], $context["trace"]);
    }

    public function testJumpToCallerBlockRestartsCallerAtTarget(): void
    {
        $context = $this->runTraceFlow(<<<YAML
blocks:
  main:
    steps:
      c:
        call: sub.s1
      after:
        activity: mark
      tail:
        activity: mark
  sub:
    steps:
      s1:
        jump: main.tail
      s2:
        activity: mark
YAML);

        $this->assertSame("done", $context["_runner_state"]);
        $this->assertSame(["tail"], $context["trace"]);
    }

    public function testJumpFromResumedCalleeToSuspendedCallerRestartsIt(): void
    {
        $yaml = <<<YAML
blocks:
  main:
    steps:
      c:
        call: a.a1
      after:
        activity: mark
  a:
    steps:
      a1:
        call: b.b1
      a2:
        activity: mark
      a3:
        activity: mark
  b:
    steps:
      b1:
        condition: first_time
        pause: true
      b2:
        jump: a.a3
YAML;

        $context = $this->runTraceFlow($yaml);
        $this->assertSame("paused", $context["_runner_state"]);

        $context = $this->runTraceFlow($yaml, $context);
        $this->assertSame("done", $context["_runner_state"]);
        // a restarts at a3 and still returns into main.
        $this->assertSame(["a3", "after"], $context["trace"]);
    }

    public function testActivityPauseCheckpointsItsStepWithoutStoringResult(): void
    {
        $yaml = <<<YAML
blocks:
  main:
    steps:
      s1:
        activity: mark
      s2:
        activity: ask_pause
      s3:
        activity: mark
YAML;

        $context = $this->runTraceFlow($yaml, null, [
            "ask_pause" => "if (empty(\$context['asked'])) { \$context['asked'] = true; "
                . "throw new \\RuntimeException(\$context['_exception_pause'] . ' wait'); } return 'ok';",
        ]);
        $this->assertSame("paused", $context["_runner_state"]);
        $this->assertSame(["block" => "main", "step_index" => 2], $context["_flow_state"]);
        $this->assertArrayNotHasKey("s2", $context);

        $context = $this->runTraceFlow($yaml, $context);
        $this->assertSame("done", $context["_runner_state"]);
        // Resume continues after the paused step.
        $this->assertSame(["s1", "s3"], $context["trace"]);
        $this->assertArrayNotHasKey("s2", $context);
    }

    public function testNestedFlowActivityRestoresParentBlocks(): void
    {
        $child = <<<YAML
blocks:
  main:
    steps:
      c1:
        activity: mark
      c2:
        jump: tail.t1
  tail:
    steps:
      t1:
        activity: mark
YAML;

        $context = $this->runTraceFlow(<<<YAML
blocks:
  main:
    steps:
      s1:
        activity: child_flow
      s2:
        jump: main.s4
      s3:
        activity: mark
      s4:
        activity: mark
YAML, null, [], ["child_flow" => $child]);

        $this->assertSame("done", $context["_runner_state"]);
        $this->assertSame(["c1", "t1", "s4"], $context["trace"]);
        $this->assertArrayNotHasKey("_tail", $context);
        $this->assertArrayNotHasKey("_flow_depth", $context);
    }

    public function testPauseInsideNestedFlowIsAnEarlyReturn(): void
    {
        $child = <<<YAML
blocks:
  main:
    steps:
      c1:
        pause: true
      c2:
        activity: mark
YAML;

        $context = $this->runTraceFlow(<<<YAML
blocks:
  main:
    steps:
      s1:
        activity: child_flow
      s2:
        activity: mark
YAML, null, [], ["child_flow" => $child]);

        $this->assertSame("done", $context["_runner_state"]);
        $this->assertSame(["s2"], $context["trace"]);
        $this->assertArrayHasKey("s1", $context);
        $this->assertNull($context["s1"]);
        $this->assertArrayNotHasKey("_nested_flow_paused", $context);
    }

    public function testRecursiveCallStopsAtMaxCallDepth(): void
    {
        $projectContext = $this->runTraceFlow(<<<YAML
blocks:
  main:
    steps:
      again:
        call: main.again
YAML, ["trace" => [], "_max_call_depth" => 5]);

        $this->assertSame("error", $projectContext["_runner_state"]);
        $this->assertStringContainsString("Call depth exceeds 5", $projectContext["_runner_result"]);
    }

    public function testMissingActivityMarksErrorState(): void
    {
        $context = $this->runTraceFlow(<<<YAML
blocks:
  main:
    steps:
      s1:
        activity: does_not_exist
YAML);

        $this->assertSame("error", $context["_runner_state"]);
        $this->assertStringContainsString("Function import failed: does_not_exist", $context["_runner_result"]);
        $logs = implode("\n", array_column($context["_runner_logs"], "message"));
        $this->assertStringContainsString("Import target not found or not callable: does_not_exist", $logs);
    }

    public function testSchemaRejectsUnknownKeysAndWrongTypes(): void
    {
        $projectDir = $this->createTempProject();
        $yamlPath = $projectDir . DIRECTORY_SEPARATOR . "bad.yml";
        file_put_contents($yamlPath, <<<YAML
id: bad
owner: me
blocks:
  main:
    steps:
      s1:
        activty: mark
      s2:
        pause: "yes"
      s3:
        context: [a, b]
YAML);

        $context = [];
        try {
            runner::run($yamlPath, $context);
            $this->fail("an invalid flow must be rejected");
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString("unknown flow key 'owner'", $message);
            $this->assertStringContainsString("unknown key 'activty' in step 'main.s1'", $message);
            $this->assertStringContainsString("'pause' in step 'main.s2' must be true or false", $message);
        }

        // The context records the failure as well.
        $this->assertSame("error", $context["_runner_state"]);
        $this->assertNotEmpty($context["_runner_logs"]);
        $this->assertNotEmpty($context["_runner_ended_at"]);
    }

    public function testInvalidTargetReferenceIsRejectedAtCompileTime(): void
    {
        $projectDir = $this->createTempProject();
        $yamlPath = $projectDir . DIRECTORY_SEPARATOR . "bad-target.yml";
        file_put_contents($yamlPath, "blocks:\n  main:\n    steps:\n      s1:\n        jump: nope.x\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("jump target block 'nope' not found");
        runner::generateFlowCodeFromYaml($yamlPath);
    }

    public function testYamlParserSupportsBlockScalarsFlowCollectionsAndComments(): void
    {
        $context = $this->runTraceFlow(implode("\n", [
            "blocks:",
            "  main:",
            "    steps:",
            "      s1:",
            "        context:",
            "          script: |",
            "            #!/usr/bin/env bash",
            "            echo \"a # b\"",
            "",
            "            \tindented with tab",
            "          stripped: |-",
            "            no newline",
            "          folded: >",
            "            one",
            "            two",
            "",
            "            three",
            "          empty_list: []",
            "          empty_map: {}",
            "          list: [a, 'b, c', 3, true, null]",
            "          map: {x: 1, y: [2, 3], \"z w\": ok}",
            "          url: http://example.com/#frag # trailing comment",
            "          \"quoted key\": 'it''s'",
            "          escaped: \"tab\\there \\u00e9\"",
            "          items:",
            "            - |",
            "              first",
            "            - name: second",
            "              body: >-",
            "                folded item",
            "",
        ]));

        $this->assertSame("done", $context["_runner_state"]);
        $this->assertSame("#!/usr/bin/env bash\necho \"a # b\"\n\n\tindented with tab\n", $context["script"]);
        $this->assertSame("no newline", $context["stripped"]);
        $this->assertSame("one two\nthree\n", $context["folded"]);
        $this->assertSame([], $context["empty_list"]);
        $this->assertSame([], $context["empty_map"]);
        $this->assertSame(["a", "b, c", 3, true, null], $context["list"]);
        $this->assertSame(["x" => 1, "y" => [2, 3], "z w" => "ok"], $context["map"]);
        $this->assertSame("http://example.com/#frag", $context["url"]);
        $this->assertSame("it's", $context["quoted key"]);
        $this->assertSame("tab\there é", $context["escaped"]);
        $this->assertSame(["first\n", ["name" => "second", "body" => "folded item"]], $context["items"]);
    }

    public function testYamlParserRejectsUnsupportedFeatures(): void
    {
        $projectDir = $this->createTempProject();
        $cases = [
            "anchor: &a value" => "anchors, aliases, tags",
            "multi: [a," => "multi-line flow collections are not supported",
        ];

        foreach ($cases as $line => $expected) {
            $yamlPath = $projectDir . DIRECTORY_SEPARATOR . md5($line) . ".yml";
            file_put_contents($yamlPath, "blocks:\n  main:\n    steps:\n      s1:\n        context:\n          {$line}\n");
            try {
                runner::generateFlowCodeFromYaml($yamlPath);
                $this->fail("'{$line}' must be rejected");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function testFalseConditionSkipsEveryActionOfTheStep(): void
    {
        $context = $this->runTraceFlow(<<<YAML
blocks:
  main:
    steps:
      s1:
        activity: mark
      gated:
        condition: is_false
        context:
          gated_context: true
        call: sub.x1
        activity: mark
        jump: main.s1
        pause: true
      s3:
        activity: mark
  sub:
    steps:
      x1:
        activity: mark
YAML, null, ["is_false" => "return false;"]);

        $this->assertSame("done", $context["_runner_state"]);
        $this->assertSame(["s1", "s3"], $context["trace"]);
        $this->assertArrayNotHasKey("gated_context", $context);
    }

    public function testShortTargetsUseCurrentBlockAndFirstStep(): void
    {
        $context = $this->runTraceFlow(<<<YAML
blocks:
  main:
    steps:
      a:
        call: other.
      b:
        jump: .d
      c:
        activity: mark
      d:
        activity: mark
  other:
    steps:
      o1:
        activity: mark
YAML);

        $this->assertSame("done", $context["_runner_state"]);
        $this->assertSame(["o1", "d"], $context["trace"]);
    }

    public function testBlockIdsCannotShadowRunnerKeys(): void
    {
        $projectDir = $this->createTempProject();
        $yamlPath = $projectDir . DIRECTORY_SEPARATOR . "reserved.yml";
        file_put_contents($yamlPath, "blocks:\n  flow_state:\n    steps:\n      s1:\n        pause: true\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Block id 'flow_state' is reserved");
        runner::generateFlowCodeFromYaml($yamlPath);
    }

    public function testErrorInsideCalledBlockLeavesNoStaleCallFrames(): void
    {
        $yaml = <<<YAML
blocks:
  main:
    steps:
      s1:
        call: sub.b1
      s2:
        activity: mark
  sub:
    steps:
      b1:
        activity: fail_once
      b2:
        activity: mark
YAML;
        $failOnce = "if (empty(\$context['failed'])) { \$context['failed'] = true; throw new \\RuntimeException('boom'); } return 'ok';";

        $context = $this->runTraceFlow($yaml, null, ["fail_once" => $failOnce]);
        $this->assertSame("error", $context["_runner_state"]);
        $this->assertSame([], $context["_flow_stack"]);

        $context = $this->runTraceFlow($yaml, $context);
        $this->assertSame("done", $context["_runner_state"]);
        $this->assertSame(["b2", "s2"], $context["trace"]);
    }

    public function testActivityClosuresUnderUnderscoreKeysPersist(): void
    {
        $context = $this->runTraceFlow(<<<YAML
blocks:
  main:
    steps:
      s1:
        activity: set_helper
      s2:
        activity: use_helper
YAML, ["_svc" => fn (): string => "old"], [
            "set_helper" => "\$context['_fmt'] = fn (string \$v): string => strtoupper(\$v); "
                . "\$context['_svc'] = fn (): string => 'new'; return null;",
            "use_helper" => "return (\$context['_fmt'])('x') . (\$context['_svc'])();",
        ]);

        $this->assertSame("done", $context["_runner_state"]);
        $this->assertSame("Xnew", $context["s2"]);
    }

    public function testIdsStartingWithUnderscoreAreRejected(): void
    {
        $projectDir = $this->createTempProject();
        $cases = [
            "blocks:\n  _x:\n    steps:\n      s1:\n        pause: true\n" => "Block id '_x' is reserved",
            "blocks:\n  main:\n    steps:\n      _flow_stack:\n        pause: true\n" => "Step id '_flow_stack' in block 'main' cannot start with '_'",
        ];

        foreach ($cases as $yaml => $expected) {
            $yamlPath = $projectDir . DIRECTORY_SEPARATOR . md5($yaml) . ".yml";
            file_put_contents($yamlPath, $yaml);
            try {
                runner::generateFlowCodeFromYaml($yamlPath);
                $this->fail("must be rejected: {$expected}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function testFlowUsingItselfAsActivityStopsAtMaxDepth(): void
    {
        $child = "blocks:\n  main:\n    steps:\n      again:\n        activity: child_flow\n";
        $context = $this->runTraceFlow($child, ["_max_call_depth" => 4], [], ["child_flow" => $child]);

        $this->assertSame("error", $context["_runner_state"]);
        $this->assertStringContainsString("Nested flow depth exceeds 4", $context["_runner_result"]);
        $this->assertArrayNotHasKey("_flow_depth", $context);
    }

    public function testContextUpdateKeepsIntegerKeys(): void
    {
        $context = [10 => "orig"];
        runner::updateContext($context, [10 => "x", 7 => "y"], "s1", 1);

        $this->assertSame("x", $context[10]);
        $this->assertSame("y", $context[7]);
    }

    public function testYamlParserMatchesPyYamlOnSequenceItemsAndScalars(): void
    {
        $context = $this->runTraceFlow(implode("\n", [
            "blocks:",
            "  main:",
            "    description: don't touch # trailing comment",
            "    steps:",
            "      s1:",
            "        context:",
            "          items:",
            "            - note: |",
            "                fragile",
            "              qty: 2",
            "            - context:",
            "              a: 1",
            "          nested:",
            "            - - x",
            "              - y",
            "            - - z",
            "          indentless:",
            "          - one",
            "          - two",
            "          yaml11: [yes, Off, 0x1F, 017, .5, +1, 1_000, 1e3, ~, tRuE]",
            "          flow_keys: {true: a, null: b}",
            "          tail: |",
            "            last",
        ]));

        $this->assertSame("done", $context["_runner_state"]);
        $this->assertSame([["note" => "fragile\n", "qty" => 2], ["context" => null, "a" => 1]], $context["items"]);
        $this->assertSame([["x", "y"], ["z"]], $context["nested"]);
        $this->assertSame(["one", "two"], $context["indentless"]);
        $this->assertSame([true, false, 31, 15, 0.5, 1, 1000, "1e3", null, "tRuE"], $context["yaml11"]);
        $this->assertSame(["true" => "a", "null" => "b"], $context["flow_keys"]);
        // Last line of the file without a line break: no final newline (as PyYAML).
        $this->assertSame("last", $context["tail"]);
    }

    public function testYamlParserRejectsMisindentedBlockScalarAndBadQuotes(): void
    {
        $projectDir = $this->createTempProject();
        $cases = [
            "a: |\n    x\n  y\n" => "less indented than its first line",
            "a: \"abc\n" => "unterminated or malformed quoted scalar",
        ];

        foreach ($cases as $body => $expected) {
            $yamlPath = $projectDir . DIRECTORY_SEPARATOR . md5($body) . ".yml";
            $indented = preg_replace('/^/m', "          ", $body);
            file_put_contents($yamlPath, "blocks:\n  main:\n    steps:\n      s1:\n        context:\n" . $indented);
            try {
                runner::generateFlowCodeFromYaml($yamlPath);
                $this->fail("must be rejected: {$expected}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function testGeneratedCodeIsDeterministic(): void
    {
        $flowPath = __DIR__ . DIRECTORY_SEPARATOR . "flows" . DIRECTORY_SEPARATOR . "business_flow.yml";

        $first = runner::generateFlowCodeFromYaml($flowPath);
        $second = runner::generateFlowCodeFromYaml($flowPath);

        $this->assertSame($first, $second);
        $this->assertStringNotContainsString(date("Y-m-d"), $first);
    }

    public function testDeepCallContinuationsResumeFromJsonInFreshProcesses(): void
    {
        $yaml = <<<YAML
blocks:
  a:
    steps:
      a1:
        activity: mark
      a2:
        condition: count_entry
        call: b.b1
        activity: mark
      a3:
        activity: mark
      a4:
        activity: mark
        pause: true
      a5:
        activity: mark
  b:
    steps:
      b1:
        activity: mark
      b2:
        condition: count_entry
        call: c.c1
        activity: mark
      b3:
        activity: mark
        pause: true
      b4:
        activity: mark
  c:
    steps:
      c1:
        activity: mark
      c2:
        activity: mark
        pause: true
      c3:
        activity: mark
      c4:
        activity: mark
        pause: true
      c5:
        activity: mark
YAML;
        $project = $this->createProcessProject($yaml, [
            "count_entry" => "\$context['entries'][\$context['_current_step_id']] = "
                . "(\$context['entries'][\$context['_current_step_id']] ?? 0) + 1; return true;",
        ]);

        $runs = [
            ["paused", ["a1", "b1", "c1", "c2"], [["block" => "a", "step_index" => 2], ["block" => "b", "step_index" => 2]]],
            ["paused", ["c3", "c4"], [["block" => "a", "step_index" => 2], ["block" => "b", "step_index" => 2]]],
            ["paused", ["c5", "b2", "b3"], [["block" => "a", "step_index" => 2]]],
            ["paused", ["b4", "a2", "a3", "a4"], []],
            ["done", ["a5"], []],
        ];

        $trace = [];
        foreach ($runs as $run => [$state, $newSteps, $stack]) {
            $context = $this->runInFreshProcess($project);
            $trace = array_merge($trace, $newSteps);
            $this->assertSame($state, $context["_runner_state"], "run " . ($run + 1));
            $this->assertSame($trace, $context["trace"], "run " . ($run + 1));
            $this->assertSame($stack, $context["_flow_stack"], "run " . ($run + 1));
        }

        // Conditions and calls of the calling steps ran once; nothing ran twice.
        $this->assertSame(["a2" => 1, "b2" => 1], $context["entries"]);
        $this->assertSame([], $context["_flow_state"]);
    }

    public function testRepeatedPauseJumpAndErrorAcrossCallsResumeFromJson(): void
    {
        $yaml = <<<YAML
blocks:
  a:
    steps:
      a1:
        call: b.b1
        activity: mark
      a2:
        activity: mark
  b:
    steps:
      b1:
        call: c.c1
        activity: mark
      b2:
        jump: a.a2
      b3:
        activity: mark
  c:
    steps:
      c1:
        activity: mark
        pause: true
      c2:
        condition: first_time
        jump: c.c1
      c3:
        activity: fail_once
      c4:
        activity: mark
YAML;
        $project = $this->createProcessProject($yaml, [
            "fail_once" => "if (empty(\$context['failed'])) { \$context['failed'] = true; "
                . "throw new RuntimeException('boom'); } return null;",
        ]);
        $frames = [["block" => "a", "step_index" => 1], ["block" => "b", "step_index" => 1]];

        // Pause inside the innermost called block.
        $context = $this->runInFreshProcess($project);
        $this->assertSame("paused", $context["_runner_state"]);
        $this->assertSame(["c1"], $context["trace"]);
        $this->assertSame($frames, $context["_flow_stack"]);

        // Resumed, the block jumps to its own first step and pauses there again:
        // the caller frames stay.
        $context = $this->runInFreshProcess($project);
        $this->assertSame("paused", $context["_runner_state"]);
        $this->assertSame(["c1", "c1"], $context["trace"]);
        $this->assertSame($frames, $context["_flow_stack"]);

        // An error drops every pending continuation.
        $context = $this->runInFreshProcess($project);
        $this->assertSame("error", $context["_runner_state"]);
        $this->assertStringContainsString("boom", $context["_runner_result"]);
        $this->assertSame(["c1", "c1"], $context["trace"]);
        $this->assertSame([], $context["_flow_stack"]);

        // Running again starts the flow from its first block.
        $context = $this->runInFreshProcess($project);
        $this->assertSame("paused", $context["_runner_state"]);
        $this->assertSame(["c1", "c1", "c1"], $context["trace"]);
        $this->assertSame($frames, $context["_flow_stack"]);

        // Resumed: c finishes, b continues after its call and jumps into the
        // suspended caller a, which restarts at a2 (a1's activity is dropped).
        $context = $this->runInFreshProcess($project);
        $this->assertSame("done", $context["_runner_state"]);
        $this->assertSame(["c1", "c1", "c1", "c4", "b1", "a2"], $context["trace"]);
        $this->assertSame([], $context["_flow_stack"]);
        $this->assertSame([], $context["_flow_state"]);
    }

    public function testContextAfterRunHoldsOnlyData(): void
    {
        $yaml = "blocks:\n  main:\n    steps:\n      s1:\n        call: sub.\n  sub:\n    steps:\n"
            . "      p:\n        pause: true\n      q:\n        activity: mark\n";

        $context = $this->runTraceFlow($yaml);
        $this->assertSame("paused", $context["_runner_state"]);
        $this->assertContextHoldsOnlyData($context);

        $context = $this->runTraceFlow($yaml, $context);
        $this->assertSame("done", $context["_runner_state"]);
        $this->assertSame(["q"], $context["trace"]);
        $this->assertContextHoldsOnlyData($context);
        foreach (["_importer", "_logger", "_wrap_call", "__flow_loop", "_main", "_sub", "_flow_blocks"] as $key) {
            $this->assertArrayNotHasKey($key, $context);
        }
    }

    public function testModulesResolveEqualFunctionNamesWithoutCollision(): void
    {
        $yaml = <<<YAML
blocks:
  main:
    steps:
      billing_total:
        module: billing
        condition: is_open
        activity: total
      shipping_total:
        module: shipping
        activity: total
      plain_total:
        activity: total
      tax_total:
        module: tax
        activity: total
      nested_total:
        module: billing
        activity: rates/total
      via_importer:
        module: billing
        activity: use_importer
YAML;
        $projectDir = $this->createTempProject();
        $functionsDir = $projectDir . DIRECTORY_SEPARATOR . "functions";
        $taxDir = $projectDir . DIRECTORY_SEPARATOR . "vendor-tax";
        $this->writeReturningFunction($functionsDir . "/billing/total.php", "return 'billing';");
        $this->writeReturningFunction($functionsDir . "/billing/is_open.php", "return true;");
        $this->writeReturningFunction($functionsDir . "/billing/rates/total.php", "return 'billing rates';");
        $this->writeReturningFunction(
            $functionsDir . "/billing/use_importer.php",
            "return (\$context['_importer'])('total', 'shipping')(\$context) . '+' . (\$context['_importer'])('total')(\$context);"
        );
        $this->writeReturningFunction($functionsDir . "/shipping/total.php", "return 'shipping';");
        $this->writeReturningFunction($functionsDir . "/total.php", "return 'plain';");
        $this->writeReturningFunction($taxDir . "/total.php", "return 'tax';");

        $yamlPath = $projectDir . DIRECTORY_SEPARATOR . "modules.yml";
        file_put_contents($yamlPath, $yaml);
        $context = ["_root_folder" => $functionsDir, "_modules" => ["tax" => $taxDir]];
        runner::run($yamlPath, $context);

        $this->assertSame("done", $context["_runner_state"], (string) $context["_runner_result"]);
        $this->assertSame("billing", $context["billing_total"]);
        $this->assertSame("shipping", $context["shipping_total"]);
        $this->assertSame("plain", $context["plain_total"]);
        $this->assertSame("tax", $context["tax_total"]);
        $this->assertSame("billing rates", $context["nested_total"]);
        $this->assertSame("shipping+plain", $context["via_importer"]);

        $empty = [];
        $billing = runner::importer("total", "billing", ["_root_folder" => $functionsDir]);
        $tax = runner::importer("total.php", "tax", ["_modules" => ["tax" => $taxDir]]);
        $this->assertIsCallable($billing);
        $this->assertIsCallable($tax);
        $this->assertSame("billing", $billing($empty));
        $this->assertSame("tax", $tax($empty));
    }

    public function testModuleReferenceNeverFallsBackOutsideItsModule(): void
    {
        $projectDir = $this->createTempProject();
        $functionsDir = $projectDir . DIRECTORY_SEPARATOR . "functions";
        $this->writeReturningFunction($functionsDir . "/plain_only.php", "return 'plain';");
        $yamlPath = $projectDir . DIRECTORY_SEPARATOR . "missing.yml";
        file_put_contents($yamlPath, "blocks:\n  main:\n    steps:\n      s1:\n        module: billing\n        activity: plain_only\n");

        $context = ["_root_folder" => $functionsDir];
        runner::run($yamlPath, $context);

        $this->assertSame("error", $context["_runner_state"]);
        $this->assertStringContainsString("Function import failed: billing:plain_only", $context["_runner_result"]);
        $logs = implode("\n", array_column($context["_runner_logs"], "message"));
        $this->assertStringContainsString("Import target not found or not callable: billing:plain_only", $logs);

        // Callable names and paths are unqualified references only.
        $this->assertNull(runner::importer("strlen", "billing", ["_root_folder" => $functionsDir]));
        $this->assertNull(runner::importer($functionsDir . "/plain_only.php", "billing", ["_root_folder" => $functionsDir]));
        $this->assertNull(runner::importer("../plain_only", "billing", ["_root_folder" => $functionsDir]));
        $this->assertNull(runner::importer("plain_only", "../functions", ["_root_folder" => $functionsDir]));
    }

    public function testModuleReferencesAreValidatedAtCompileTime(): void
    {
        $projectDir = $this->createTempProject();
        $step = "blocks:\n  main:\n    steps:\n      s1:\n";
        $cases = [
            $step . "        module: ../billing\n        activity: total\n" => "module name '../billing' must use letters",
            $step . "        module: billing\n        activity: ../total\n" => "function '../total' of module 'billing' must be a relative name",
            $step . "        module: billing\n        condition: /abs/check\n" => "function '/abs/check' of module 'billing' must be a relative name",
            $step . "        module: billing\n        pause: true\n" => "'module' in step 'main.s1' needs an 'activity' or a 'condition'",
            $step . "        module: 5\n        activity: total\n" => "'module' in step 'main.s1' must be a non-empty string",
        ];

        foreach ($cases as $yaml => $expected) {
            $yamlPath = $projectDir . DIRECTORY_SEPARATOR . md5($yaml) . ".yml";
            file_put_contents($yamlPath, $yaml);
            try {
                runner::generateFlowCodeFromYaml($yamlPath);
                $this->fail("must be rejected: {$expected}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function testFunctionMapUsesQualifiedNames(): void
    {
        $projectDir = $this->createTempProject();
        $functionsDir = $projectDir . DIRECTORY_SEPARATOR . "functions";
        $this->writeReturningFunction($functionsDir . "/billing/total.php", "return 'billing';");
        $this->writeReturningFunction($functionsDir . "/shipping/total.php", "return 'shipping';");
        $this->writeReturningFunction($functionsDir . "/total.php", "return 'plain';");
        $yamlPath = $projectDir . DIRECTORY_SEPARATOR . "mapped.yml";
        file_put_contents($yamlPath, "blocks:\n  main:\n    steps:\n"
            . "      a:\n        module: billing\n        activity: total\n"
            . "      b:\n        activity: total\n"
            . "      c:\n        module: shipping\n        activity: total\n");

        $context = ["_root_folder" => $functionsDir];
        runner::runYaml($yamlPath, $context, ["function_map" => [
            "billing:total" => "shipping:total",
            "total" => "billing:total",
            "shipping:total" => "total",
        ]]);

        $this->assertSame("done", $context["_runner_state"], (string) $context["_runner_result"]);
        $this->assertSame("shipping", $context["a"]);
        $this->assertSame("billing", $context["b"]);
        $this->assertSame("plain", $context["c"]);
    }

    /**
     * Runs a YAML flow with `mark` (appends the step id to `trace`) and
     * `first_time` (true once per step id) available as functions.
     *
     * @param array<string, mixed>|null $context   Context to resume from; without `_root_folder` a project is created
     *                                             and these keys are used as the initial context.
     * @param array<string, string>     $functions Extra functions: name => body returning a value.
     * @param array<string, string>     $flows     Child flows: name => YAML, compiled to PHP function files.
     *
     * @return array<string, mixed>
     */
    private function runTraceFlow(string $yaml, ?array $context = null, array $functions = [], array $flows = []): array
    {
        if ($context === null || !isset($context["_root_folder"])) {
            $projectDir = $this->createTempProject();
            $functionsDir = $projectDir . DIRECTORY_SEPARATOR . "functions";
            $this->writeFunctionFile($projectDir, "mark", "\$context['trace'][] = \$context['_current_step_id'];");
            $functions["first_time"] = "\$key = 'seen_' . \$context['_current_step_id']; "
                . "if (!empty(\$context[\$key])) { return false; } \$context[\$key] = true; return true;";
            foreach ($functions as $name => $body) {
                file_put_contents(
                    $functionsDir . DIRECTORY_SEPARATOR . $name . ".php",
                    "<?php\nreturn function (array &\$context): mixed {\n    {$body}\n};\n"
                );
            }
            foreach ($flows as $name => $flowYaml) {
                $flowYamlPath = $projectDir . DIRECTORY_SEPARATOR . $name . ".yml";
                file_put_contents($flowYamlPath, $flowYaml);
                file_put_contents(
                    $functionsDir . DIRECTORY_SEPARATOR . $name . ".php",
                    "<?php\n" . runner::generateFlowCodeFromYaml($flowYamlPath)
                );
            }
            $context = array_merge(["trace" => []], $context ?? [], ["_root_folder" => $functionsDir]);
        }

        $yamlPath = $context["_root_folder"] . DIRECTORY_SEPARATOR . "flow-" . md5($yaml) . ".yml";
        file_put_contents($yamlPath, $yaml);
        runner::run($yamlPath, $context);

        return $context;
    }

    /**
     * A project whose flow runs in a separate PHP process per run, with the
     * context stored as JSON between runs (`context.json`).
     *
     * @param array<string, string> $functions Extra functions: name => body returning a value.
     *
     * @return array{flow:string,context:string,script:string}
     */
    private function createProcessProject(string $yaml, array $functions = []): array
    {
        $projectDir = $this->createTempProject();
        $functionsDir = $projectDir . DIRECTORY_SEPARATOR . "functions";
        $functions["mark"] = "\$context['trace'][] = \$context['_current_step_id']; return null;";
        $functions["first_time"] = "\$key = 'seen_' . \$context['_current_step_id']; "
            . "if (!empty(\$context[\$key])) { return false; } \$context[\$key] = true; return true;";
        foreach ($functions as $name => $body) {
            $this->writeReturningFunction($functionsDir . DIRECTORY_SEPARATOR . $name . ".php", $body);
        }

        $project = [
            "flow" => $projectDir . DIRECTORY_SEPARATOR . "flow.yml",
            "context" => $projectDir . DIRECTORY_SEPARATOR . "context.json",
            "script" => $projectDir . DIRECTORY_SEPARATOR . "run.php",
        ];
        file_put_contents($project["flow"], $yaml);
        file_put_contents($project["context"], json_encode(["trace" => [], "_root_folder" => $functionsDir]));
        file_put_contents($project["script"], "<?php\n"
            . "require " . var_export(dirname(__DIR__) . "/vendor/autoload.php", true) . ";\n"
            . "\$context = json_decode(file_get_contents(\$argv[2]), true, 512, JSON_THROW_ON_ERROR);\n"
            . "divengine\\runner\\runner::run(\$argv[1], \$context);\n"
            . "file_put_contents(\$argv[2], json_encode(\$context, JSON_THROW_ON_ERROR));\n");

        return $project;
    }

    /**
     * @param array{flow:string,context:string,script:string} $project
     *
     * @return array<string, mixed>
     */
    private function runInFreshProcess(array $project): array
    {
        $command = implode(" ", array_map("escapeshellarg", [PHP_BINARY, $project["script"], $project["flow"], $project["context"]]));
        exec($command . " 2>&1", $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));

        $context = json_decode((string) file_get_contents($project["context"]), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($context);

        return $context;
    }

    /**
     * @param array<mixed> $context
     */
    private function assertContextHoldsOnlyData(array $context): void
    {
        array_walk_recursive($context, function (mixed $value, int|string $key): void {
            $this->assertFalse(is_object($value) || is_resource($value), "non-data value under '{$key}'");
        });
        $this->assertSame($context, json_decode(json_encode($context, JSON_THROW_ON_ERROR), true));
    }

    private function writeReturningFunction(string $path, string $body): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, "<?php\nreturn function (array &\$context): mixed {\n    {$body}\n};\n");
    }

    private function createTempProject(): string
    {
        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . "runner-tests-"
            . bin2hex(random_bytes(6));

        mkdir($tempDir . DIRECTORY_SEPARATOR . "functions", 0777, true);
        $this->tempDirs[] = $tempDir;

        return $tempDir;
    }

    private function writeFunctionFile(string $projectDir, string $functionName, string $bodyLine): string
    {
        $path = $projectDir . DIRECTORY_SEPARATOR . "functions" . DIRECTORY_SEPARATOR . $functionName . ".php";
        $content = "<?php\n";
        $content .= "return function (array &\$context): void {\n";
        $content .= "    {$bodyLine}\n";
        $content .= "};\n";

        file_put_contents($path, $content);
        return $path;
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($fullPath)) {
                $this->deleteDirectory($fullPath);
                continue;
            }

            @unlink($fullPath);
        }

        @rmdir($path);
    }
}
