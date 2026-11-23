<?php

return fn (array &$context): bool => ($context["payment"]["status"] ?? $context["charge"]["status"] ?? null) === "declined";
