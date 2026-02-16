<?php

declare(strict_types=1);

return function (array &$context): void {
    $importer = $context["_importer"] ?? null;
    $flowLoop = $context["__flow_loop"] ?? null;
    $wrapActivity = $context["_wrap_activity"] ?? null;
    $wrapPause = $context["_wrap_pause"] ?? null;
    $checkPause = $context["_check_pause"] ?? null;

    if (
        !is_callable($importer)
        || !is_callable($flowLoop)
        || !is_callable($wrapActivity)
        || !is_callable($wrapPause)
        || !is_callable($checkPause)
    ) {
        throw new \RuntimeException("Missing flow helpers in context.");
    }

    $addValues = $importer(__DIR__ . "/../activities/add_values.php");
    $uppercaseText = $importer(__DIR__ . "/../activities/uppercase_text.php");
    if (!is_callable($addValues) || !is_callable($uppercaseText)) {
        throw new \RuntimeException("Activities could not be imported.");
    }

    $context["_main"] = function (array &$context) use ($checkPause, $wrapActivity, $wrapPause, $addValues, $uppercaseText): string {
        if ($checkPause(1, $context)) {
            $wrapActivity($addValues, "sum_step", 1, $context);
        }

        if ($checkPause(2, $context) && empty($context["pause_once_done"])) {
            $context["pause_once_done"] = true;
            $wrapPause("main", "pause_gate", 2, $context);
        }

        if ($checkPause(3, $context)) {
            $wrapActivity($uppercaseText, "uppercase_step", 3, $context);
            $context["flow_result"] = "resume_done";
        }

        return "pause_flow_done";
    };

    $flowLoop("main", $context);
};
