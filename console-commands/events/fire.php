<?php

if (empty($_COMMAND['ARGS'])) {
    die('Usage: events:fire <event> <context> [payload_json]');
}

// parse input
[$event, $context, $payload] = preg_split('/\s+/', $_COMMAND['ARGS'], 3);
$payload = empty($payload) ? [] : json_decode($payload, true);

$_COMMAND['LOGGER']->info("Firing {event}@{context}", ['event' => $event, 'context' => $context, 'payload' => $payload]);

// fire event
$event = Emergence\EventBus::fireEvent($event, $context, $payload);

// print results
foreach ($event['RESULTS'] as $handler => $result) {
    $_COMMAND['LOGGER']->debug("Result for handler {handler}:", ['handler' => $handler]);
    $result = print_r($result, true);
    $output = trim($result);

    if ($output !== '' && $output !== '0') {
        $output = explode(PHP_EOL, $output);
        foreach ($output as $line) {
            $_COMMAND['LOGGER']->debug("    $line");
        }
    }
}
