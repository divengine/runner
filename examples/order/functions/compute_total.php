<?php

declare(strict_types=1);

// The returned value is stored in $context["total"] (the step id).
return function (array &$context): float {
    $total = 0.0;
    foreach ($context["items"] as $item) {
        $total += $item["price"] * $item["qty"];
    }

    return $total;
};
