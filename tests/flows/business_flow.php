<?php

declare(strict_types=1);

return function (array &$context): void {
    $importer = $context["_importer"] ?? null;
    $flowLoop = $context["__flow_loop"] ?? null;
    $wrapActivity = $context["_wrap_activity"] ?? null;
    $wrapCondition = $context["_wrap_condition"] ?? null;
    $wrapCall = $context["_wrap_call"] ?? null;
    $wrapJump = $context["_wrap_jump"] ?? null;

    if (
        !is_callable($importer)
        || !is_callable($flowLoop)
        || !is_callable($wrapActivity)
        || !is_callable($wrapCondition)
        || !is_callable($wrapCall)
        || !is_callable($wrapJump)
    ) {
        throw new \RuntimeException("Missing flow helpers in context.");
    }

    $addValues = $importer(__DIR__ . "/../activities/add_values.php");
    $readContextValue = $importer(__DIR__ . "/../activities/read_context_value.php");
    $uppercaseText = $importer(__DIR__ . "/../activities/uppercase_text.php");
    $stampNowIso = $importer(__DIR__ . "/../activities/stamp_now_iso.php");
    $writeFileLine = $importer(__DIR__ . "/../activities/write_file_line.php");

    if (
        !is_callable($addValues)
        || !is_callable($readContextValue)
        || !is_callable($uppercaseText)
        || !is_callable($stampNowIso)
        || !is_callable($writeFileLine)
    ) {
        throw new \RuntimeException("Activities could not be imported.");
    }

    $context["_main"] = function (array &$context) use (
        $wrapActivity,
        $wrapCondition,
        $wrapCall,
        $wrapJump,
        $addValues
    ): mixed {
        $wrapActivity($addValues, "sum_step", 1, $context);

        $prepareFileLine = function (array &$context): void {
            $customer = (string) ($context["customer_name"] ?? "guest");
            $sum = (int) ($context["sum"] ?? 0);
            $context["file_line"] = "customer=" . $customer . "; sum=" . $sum;
        };
        $wrapCall($prepareFileLine, "prepare_file_line", 2, $context, "main", 3);

        $shouldAudit = (bool) $wrapCondition(
            static function (array &$context): bool {
                return (bool) ($context["enable_audit"] ?? false);
            },
            "audit_check",
            3,
            $context
        );

        if ($shouldAudit) {
            $wrapJump(
                static function (array &$context): void {
                },
                "jump_to_audit",
                4,
                $context,
                "audit",
                1
            );
        }

        $wrapJump(
            static function (array &$context): void {
            },
            "jump_to_finalize",
            4,
            $context,
            "finalize",
            1
        );

        return null;
    };

    $context["_audit"] = function (array &$context) use ($wrapActivity, $wrapJump, $stampNowIso): mixed {
        $wrapActivity($stampNowIso, "audit_stamp", 1, $context);

        $line = (string) ($context["file_line"] ?? "");
        $context["file_line"] = $line . "; audited=1";

        $wrapJump(
            static function (array &$context): void {
            },
            "jump_audit_finalize",
            2,
            $context,
            "finalize",
            1
        );

        return null;
    };

    $context["_finalize"] = function (array &$context) use (
        $wrapActivity,
        $readContextValue,
        $uppercaseText,
        $writeFileLine
    ): string {
        $context["read_key"] = "sum";
        $wrapActivity($readContextValue, "read_sum", 1, $context);
        $wrapActivity($uppercaseText, "normalize_text", 2, $context);
        $wrapActivity($writeFileLine, "write_result", 3, $context);

        $context["flow_result"] = "business_done";
        return "business_done";
    };

    $flowLoop("main", $context);
};
