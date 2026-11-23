<?php

return function (array &$context): float {
    $total = 0.0;
    foreach ($context["order"]["items"] as $item) {
        $total += $item["price"] * $item["qty"];
    }
    return $total;
};
