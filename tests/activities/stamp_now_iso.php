<?php

declare(strict_types=1);

return function (array &$context): string {
    $now = new \DateTimeImmutable("now", new \DateTimeZone("UTC"));
    $formatted = $now->format(\DateTimeInterface::ATOM);
    $context["now_iso"] = $formatted;

    return $formatted;
};
