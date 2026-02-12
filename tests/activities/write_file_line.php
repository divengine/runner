<?php

declare(strict_types=1);

return function (array &$context): int {
    $targetFile = (string) ($context["target_file"] ?? "");
    if ($targetFile === "") {
        return 0;
    }

    $line = (string) ($context["file_line"] ?? "");
    $writtenBytes = file_put_contents($targetFile, $line . PHP_EOL, FILE_APPEND);
    if ($writtenBytes === false) {
        $context["file_written"] = false;
        return 0;
    }

    $context["file_written"] = true;
    return $writtenBytes;
};
