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

$tns = "(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST=" . getenv('ORACLE_HOST') . ")(PORT=1521))(CONNECT_DATA=(SERVICE_NAME=" . getenv('ORACLE_SERVICE_NAME') . ")))";
$conn = @oci_connect(getenv('ORACLE_USERNAME'), getenv('ORACLE_PASSWORD'), $tns);
if (!$conn) { echo "Cannot connect\n"; exit(1); }

echo "=== Issue codes actually used in AH policies (gipi_polbasic) ===\n";
$sql = "SELECT DISTINCT iss_cd FROM gipi_polbasic WHERE line_cd = 'AH' ORDER BY iss_cd";
$stmt = oci_parse($conn, $sql);
if (oci_execute($stmt)) {
    while ($r = oci_fetch_assoc($stmt)) { echo $r['ISS_CD'] . "\n"; }
}
oci_free_statement($stmt);

echo "\n=== Checking if QP (Quick Policy) is available ===\n";
$sql2 = "SELECT iss_cd, iss_name FROM giis_issource WHERE iss_cd = 'QP'";
$stmt2 = oci_parse($conn, $sql2);
if (oci_execute($stmt2)) {
    while ($r = oci_fetch_assoc($stmt2)) { echo $r['ISS_CD'] . " => " . $r['ISS_NAME'] . "\n"; }
}
oci_free_statement($stmt2);

echo "\n=== Recent AH policies created via API ===\n";
$sql3 = "SELECT policy_id, line_cd, subline_cd, iss_cd, ref_pol_no FROM gipi_polbasic WHERE line_cd = 'AH' AND ROWNUM <= 10 ORDER BY policy_id DESC";
$stmt3 = oci_parse($conn, $sql3);
if (oci_execute($stmt3)) {
    $n = 0;
    while ($r = oci_fetch_assoc($stmt3)) {
        if ($n === 0) { echo implode(' | ', array_keys($r)) . "\n" . str_repeat('-', 80) . "\n"; }
        echo implode(' | ', array_map(function($v) { return $v ?? 'NULL'; }, $r)) . "\n";
        $n++;
    }
    if ($n === 0) echo "(no AH policies found)\n";
}
oci_free_statement($stmt3);

oci_close($conn);
