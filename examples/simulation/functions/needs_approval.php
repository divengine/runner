<?php

return fn (array &$context): bool => $context["total"] >= 1000 && empty($context["approval_requested"]);
