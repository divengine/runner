<?php

declare(strict_types=1);

use divengine\runner\runner;

/**
 * @Revs(500)
 * @Iterations(5)
 */
final class runnerBench
{
    private static ?string $fixtureFlowPath = null;

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
        runner::run(self::$fixtureFlowPath, $context);
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
        if (self::$fixtureFlowPath !== null) {
            return;
        }

        $fixturesPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . "runner-bench-fixture";
        if (!is_dir($fixturesPath)) {
            mkdir($fixturesPath, 0777, true);
        }

        $flowPath = $fixturesPath . DIRECTORY_SEPARATOR . "bench_flow.php";

        $code = "<?php\n";
        $code .= "return function (array &\$context): void {\n";
        $code .= "    \$context['bench'] = true;\n";
        $code .= "};\n";
        file_put_contents($flowPath, $code);

        self::$fixtureFlowPath = $flowPath;
    }
}
