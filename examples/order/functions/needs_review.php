<?php

declare(strict_types=1);

return function (array &$context): bool {
    return $context["total"] >= 20 && empty($context["reviewed"]);
};
