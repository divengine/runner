<?php

declare(strict_types=1);

return fn (array &$context): string =>
    sprintf("Total: %.2f%s", $context["total"], empty($context["reviewed"]) ? "" : " (reviewed)");
