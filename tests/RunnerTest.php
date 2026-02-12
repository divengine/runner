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
        $this->expectExceptionMessage("must accept 'context' as the only parameter");

        $context = [];
        $flow = function (array &$ctx): void {
            $ctx["ran"] = true;
        };

        runner::run($flow, $context);
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

    public function testFlowLoopHandlesJumpTokenAndContinuesWithSavedStrider(): void
    {
        $context = [];
        $context["_start"] = function (array &$context): mixed {
            if (!isset($context["jumped"])) {
                $context["jumped"] = true;
                $context["_flow_state"] = [
                    "strider" => "end",
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
                "strider" => "sample",
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
            function (string $level, string $message) use (&$logs): void {
                $logs[] = [$level, $message];
            }
        );

        $this->assertNull($callable);
        $this->assertNotEmpty($logs);
        $this->assertSame("ERROR", $logs[0][0]);
        $this->assertStringContainsString("Import file not found", $logs[0][1]);
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
        runner::wrapCall(
            function (array &$ctx) use (&$called): void {
                $called = true;
                $ctx["call_ran"] = true;
            },
            "call",
            4,
            $context,
            "call_target",
            9
        );
        $this->assertTrue($called);
        $this->assertTrue((bool) ($context["call_ran"] ?? false));
        $this->assertSame(9, $context["_flow_state"]["step_index"] ?? null);
    }

    public function testWrapJumpSetsStateAndThrowsJumpToken(): void
    {
        $context = [];

        try {
            runner::wrapJump(
                function (array &$ctx): void {
                },
                "jump_step",
                7,
                $context,
                "go",
                11
            );
            $this->fail("wrapJump must throw");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Jump to go", $e->getMessage());
            $this->assertStringContainsString($context["_exception_jump_token"], $e->getMessage());
        }

        $this->assertSame(11, $context["_flow_state"]["step_index"] ?? null);
    }

    public function testWrapPauseSetsStateAndThrowsPauseToken(): void
    {
        $context = [];

        try {
            runner::wrapPause("striderX", "pause_step", 12, $context);
            $this->fail("wrapPause must throw");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Pause at striderX.pause_step[12]", $e->getMessage());
            $this->assertStringContainsString($context["_exception_pause_token"], $e->getMessage());
        }

        $this->assertSame("striderX", $context["_flow_state"]["strider"] ?? null);
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
