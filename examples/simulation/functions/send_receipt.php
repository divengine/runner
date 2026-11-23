<?php

// Real function with a side effect: writes the receipt to outbox.log.
// A simulation never loads this file.
return function (array &$context): string {
    file_put_contents("outbox.log", "Receipt {$context["order"]["id"]}: {$context["total"]}\n", FILE_APPEND);
    return "receipt sent";
};
