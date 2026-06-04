<?php
$envPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '.env';
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $pos = strpos($line, '=');
    if ($pos === false) continue;
    putenv(trim(substr($line, 0, $pos)) . '=' . trim(substr($line, $pos + 1)));
}

$tns = "(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST=" . getenv('ORACLE_HOST') . ")(PORT=1521))(CONNECT_DATA=(SERVICE_NAME=" . getenv('ORACLE_SERVICE_NAME') . ")))";
$conn = @oci_connect(getenv('ORACLE_USERNAME'), getenv('ORACLE_PASSWORD'), $tns);
if (!$conn) { echo "Cannot connect\n"; exit(1); }

echo "=== CPI user issue codes in GIIS_USER_ISS_CD ===\n";
$stmt = oci_parse($conn, "SELECT USERID, ISS_CD, TRAN_CD FROM GIIS_USER_ISS_CD WHERE UPPER(USERID) = 'CPI' ORDER BY ISS_CD");
oci_execute($stmt);
$n = 0;
while ($r = oci_fetch_assoc($stmt)) {
    echo "ISS_CD: " . ($r['ISS_CD'] ?? '') . "  TRAN_CD: " . ($r['TRAN_CD'] ?? '') . "\n";
    $n++;
}
if ($n === 0) echo "(No entries found for CPI user)\n";
oci_free_statement($stmt);

echo "\n=== GIIS_USERS table - CPI user details ===\n";
$stmt = oci_parse($conn, "SELECT user_id, iss_cd FROM giis_users WHERE UPPER(user_id) = 'CPI'");
oci_execute($stmt);
while ($r = oci_fetch_assoc($stmt)) {
    echo "USER_ID: " . ($r['USER_ID'] ?? '') . "  ISS_CD: " . ($r['ISS_CD'] ?? 'NULL') . "\n";
}
oci_free_statement($stmt);

oci_close($conn);
