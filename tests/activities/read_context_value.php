<?php

declare(strict_types=1);

return function (array &$context): mixed {
    $key = (string) ($context["read_key"] ?? "");
    if ($key === "" || !array_key_exists($key, $context)) {
        $context["read_value"] = null;
        return null;
    }

    $value = $context[$key];
    $context["read_value"] = $value;

    return $value;
};
