<?php

declare(strict_types=1);

use divengine\runner\runner;
use PHPUnit\Framework\TestCase;

final class RunnerTest extends TestCase
{
    private array $tempDirs = [];

    protected function tearDown(): void
    {
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

    public function testImporterReturnsExistingCallable(): void
    {
        $callable = runner::importer("strlen");

        $this->assertIsCallable($callable);
        $this->assertSame(5, $callable("abcde"));
    }

    public function testImporterLoadsFunctionFromProjectFunctionsFolder(): void
    {
        $projectDir = $this->createTempProject();
        $functionName = "job_" . bin2hex(random_bytes(6));

        $this->writeFunctionFile(
            $projectDir,
            $functionName,
            '$context["loaded_from_file"] = true;'
        );

        $callable = runner::importer($functionName, "unused_module", $projectDir);
        $context = [];
        $callable($context);

        $this->assertIsCallable($callable);
        $this->assertTrue((bool) ($context["loaded_from_file"] ?? false));
    }

    public function testRunLoadsStringFlowUsingImporter(): void
    {
        $projectDir = $this->createTempProject();
        $functionName = "flow_" . bin2hex(random_bytes(6));

        $this->writeFunctionFile(
            $projectDir,
            $functionName,
            '$context["imported_flow_executed"] = true;'
        );

        $context = [];
        runner::run($functionName, $context, [
            "base_path" => $projectDir,
            "module" => "unused_module",
        ]);

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

    private function writeFunctionFile(string $projectDir, string $functionName, string $bodyLine): void
    {
        $content = "<?php\n";
        $content .= "function {$functionName}(array &\$context): void\n";
        $content .= "{\n";
        $content .= "    {$bodyLine}\n";
        $content .= "}\n";

        file_put_contents(
            $projectDir . DIRECTORY_SEPARATOR . "functions" . DIRECTORY_SEPARATOR . $functionName . ".php",
            $content
        );
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
