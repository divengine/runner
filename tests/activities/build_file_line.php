<?php

declare(strict_types=1);

return function (array &$context): string {
    $customer = (string) ($context["customer_name"] ?? "guest");
    $sum = (int) ($context["sum"] ?? 0);
    $line = "customer=" . $customer . "; sum=" . $sum;
    $context["file_line"] = $line;

    return $line;
};
