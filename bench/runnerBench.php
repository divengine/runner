<?php

declare(strict_types=1);

use divengine\runner\runner;

/**
 * @Revs(500)
 * @Iterations(5)
 */
final class runnerBench
{
    private static ?string $fixtureBasePath = null;
    private static ?string $fixtureFlowName = null;

    public function benchRunCallable(): void
    {
        $context = [
            "counter" => 0,
        ];

        $flow = static function (array &$context): void {
            $context["counter"]++;
        };

        runner::run($flow, $context);
    }

    public function benchRunStringFlowImporter(): void
    {
        $this->ensureStringFlowFixture();

        $context = [];
        runner::run(
            self::$fixtureFlowName,
            $context,
            [
                "base_path" => self::$fixtureBasePath,
                "module" => "bench_module",
            ]
        );
    }

    public function benchFlowLoopDirect(): void
    {
        $context = [];
        $context["_start"] = static function (array &$context): string {
            return "done";
        };

        runner::flowLoop("start", $context);
    }

    private function ensureStringFlowFixture(): void
    {
        if (self::$fixtureBasePath !== null && self::$fixtureFlowName !== null) {
            return;
        }

        $basePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . "runner-bench-fixture";
        $functionsDir = $basePath . DIRECTORY_SEPARATOR . "functions";
        if (!is_dir($functionsDir)) {
            mkdir($functionsDir, 0777, true);
        }

        $flowName = "bench_flow";
        $flowPath = $functionsDir . DIRECTORY_SEPARATOR . $flowName . ".php";

        $code = "<?php\n";
        $code .= "function {$flowName}(array &\$context): void\n";
        $code .= "{\n";
        $code .= "    \$context['bench'] = true;\n";
        $code .= "}\n";
        file_put_contents($flowPath, $code);

        self::$fixtureBasePath = $basePath;
        self::$fixtureFlowName = $flowName;
    }
}
