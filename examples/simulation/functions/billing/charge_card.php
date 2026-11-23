<?php

// Real function with a side effect: records the charge in payments.log.
// A simulation never loads this file.
return function (array &$context): array {
    file_put_contents("payments.log", $context["order"]["id"] . " " . $context["total"] . "\n", FILE_APPEND);
    $context["last_charge"] = "captured";
    return ["status" => "captured"];
};
