<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/handlers/travel_quote_handler.php';

// Pass true to skip the GeniiSys synchronization block for testing purposes.
handleTravelQuote(true);
