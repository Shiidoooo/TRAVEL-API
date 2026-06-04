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

// Check line-specific issue sources
echo "=== Issue Sources for AH line (GIIS_LINE_ISSOURCE) ===\n";
$sql = "SELECT * FROM giis_line_issource WHERE line_cd = 'AH' ORDER BY iss_cd";
$stmt = oci_parse($conn, $sql);
if (oci_execute($stmt)) {
    $n = 0;
    while ($row = oci_fetch_assoc($stmt)) {
        if ($n === 0) { echo implode(' | ', array_keys($row)) . "\n" . str_repeat('-', 60) . "\n"; }
        echo implode(' | ', $row) . "\n";
        $n++;
    }
    if ($n === 0) echo "(no rows found)\n";
}
oci_free_statement($stmt);

echo "\n=== Also checking PA line ===\n";
$stmt = oci_parse($conn, "SELECT * FROM giis_line_issource WHERE line_cd = 'PA' ORDER BY iss_cd");
if (oci_execute($stmt)) {
    $n = 0;
    while ($row = oci_fetch_assoc($stmt)) {
        if ($n === 0) { echo implode(' | ', array_keys($row)) . "\n" . str_repeat('-', 60) . "\n"; }
        echo implode(' | ', $row) . "\n";
        $n++;
    }
    if ($n === 0) echo "(no rows found)\n";
}
oci_free_statement($stmt);

// Also check what subline codes exist for AH
echo "\n=== Sublines for AH line (GIIS_SUBLINE) ===\n";
$stmt = oci_parse($conn, "SELECT subline_cd, subline_name FROM giis_subline WHERE line_cd = 'AH' ORDER BY subline_cd");
if (oci_execute($stmt)) {
    $n = 0;
    while ($row = oci_fetch_assoc($stmt)) {
        echo str_pad($row['SUBLINE_CD'] ?? '', 10) . ($row['SUBLINE_NAME'] ?? '') . "\n";
        $n++;
    }
    if ($n === 0) echo "(no rows found)\n";
}
oci_free_statement($stmt);

// Check GIIS_LINE for AH
echo "\n=== Line config for AH (GIIS_LINE) ===\n";
$stmt = oci_parse($conn, "SELECT line_cd, line_name, menu_line_cd FROM giis_line WHERE line_cd = 'AH'");
if (oci_execute($stmt)) {
    while ($row = oci_fetch_assoc($stmt)) {
        foreach ($row as $k => $v) echo "{$k}: {$v}\n";
    }
}
oci_free_statement($stmt);

oci_close($conn);
