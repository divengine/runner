<?php

declare(strict_types=1);

return function (array &$context): bool {
    return (bool) ($context["enable_audit"] ?? false);
};
