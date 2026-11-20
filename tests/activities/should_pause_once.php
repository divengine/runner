<?php

declare(strict_types=1);

return function (array &$context): bool {
    return empty($context["pause_once_done"]);
};
