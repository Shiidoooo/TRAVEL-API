<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/handlers/travel_quote_handler.php';

// This endpoint is primarily for GET requests to fetch plans from SQL Server.
// If a POST request is accidentally sent here, we skip GeniiSys sync to be safe.
handleTravelQuote(true);
