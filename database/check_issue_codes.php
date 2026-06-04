<?php
$envPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '.env';
$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
    $pos = strpos($line, '=');
    if ($pos === false) continue;
    putenv(trim(substr($line, 0, $pos)) . '=' . trim(substr($line, $pos + 1)));
}

$host = getenv('ORACLE_HOST');
$port = getenv('ORACLE_PORT') ?: '1521';
$svc = getenv('ORACLE_SERVICE_NAME');
$user = getenv('ORACLE_USERNAME');
$pass = getenv('ORACLE_PASSWORD');
$proto = getenv('ORACLE_PROTOCOL') ?: 'TCP';
$tns = "(DESCRIPTION=(ADDRESS=(PROTOCOL={$proto})(HOST={$host})(PORT={$port}))(CONNECT_DATA=(SERVICE_NAME={$svc})))";

$conn = @oci_connect($user, $pass, $tns);
if (!$conn) { echo "Cannot connect\n"; exit(1); }

echo "=== Valid Issue Sources (GIIS_ISSOURCE) ===\n\n";
$stmt = oci_parse($conn, 'SELECT iss_cd, iss_name FROM giis_issource ORDER BY iss_cd');
oci_execute($stmt);
while ($row = oci_fetch_assoc($stmt)) {
    echo str_pad($row['ISS_CD'] ?? '', 6) . ($row['ISS_NAME'] ?? '') . "\n";
}
oci_free_statement($stmt);
oci_close($conn);
