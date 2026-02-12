<?php

declare(strict_types=1);

return function (array &$context): int {
    $left = (int) ($context["left"] ?? 0);
    $right = (int) ($context["right"] ?? 0);
    $sum = $left + $right;
    $context["sum"] = $sum;

    return $sum;
};
