<?php

declare(strict_types=1);

return function (array &$context): string {
    $suffix = empty($context["reviewed"]) ? "" : " (reviewed)";

    return sprintf("Total: %.2f%s", $context["total"], $suffix);
};
