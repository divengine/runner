<?php

// Real function: rejects orders without items.
return function (array &$context): bool {
    if (empty($context["order"]["items"])) {
        throw new RuntimeException("Order has no items");
    }
    return true;
};
