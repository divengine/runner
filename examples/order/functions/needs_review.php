<?php

declare(strict_types=1);

return fn (array &$context): bool => $context["total"] >= 20 && empty($context["reviewed"]);
