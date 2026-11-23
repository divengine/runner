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

    /**
     * Same YAML flow untraced, traced and simulated: tracing must stay opt-in
     * and must not slow down `run()`.
     */
    public function benchRunYaml(): void
    {
        $context = ["_root_folder" => self::yamlFixture()];
        runner::run(self::yamlFixture() . "/flow.yml", $context);
    }

    public function benchRunTracedYaml(): void
    {
        $context = ["_root_folder" => self::yamlFixture()];
        runner::runTraced(self::yamlFixture() . "/flow.yml", $context);
    }

    public function benchSimulateYaml(): void
    {
        $context = [];
        runner::simulate(self::yamlFixture() . "/flow.yml", $context, [
            "inc" => ["returns" => 1],
            "more" => ["sequence" => array_merge(array_fill(0, 49, ["returns" => true]), [["returns" => false]])],
        ]);
    }

    private static function yamlFixture(): string
    {
        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "runner-bench-yaml";
        if (!is_file($dir . "/flow.yml")) {
            @mkdir($dir, 0777, true);
            file_put_contents($dir . "/inc.php", '<?php return function (array &$context): int { return $context["n"] = ($context["n"] ?? 0) + 1; };');
            file_put_contents($dir . "/more.php", '<?php return fn (array &$context): bool => $context["n"] < 50;');
            file_put_contents($dir . "/flow.yml", "id: bench\nblocks:\n  main:\n    steps:\n      a:\n        activity: inc\n"
                . "      b:\n        condition: more\n        jump: main.a\n");
        }

        return $dir;
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
