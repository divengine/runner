<?php

declare(strict_types=1);

return function (array &$context): string {
    $text = (string) ($context["text"] ?? "");
    $upper = strtoupper($text);
    $context["upper_text"] = $upper;

    return $upper;
};
