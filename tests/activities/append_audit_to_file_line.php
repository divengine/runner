<?php

declare(strict_types=1);

return function (array &$context): string {
    $line = (string) ($context["file_line"] ?? "");
    if ($line !== "") {
        $line .= "; ";
    }
    $line .= "audited=1";
    $context["file_line"] = $line;

    return $line;
};
