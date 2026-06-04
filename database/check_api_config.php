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

// Check ALL PA_ISSUANCE_API config in CG_REF_CODES
echo "=== All PA_ISSUANCE_API config in CG_REF_CODES ===\n";
$sql = "SELECT RV_LOW_VALUE, RV_HIGH_VALUE, RV_DOMAIN FROM CG_REF_CODES WHERE RV_DOMAIN LIKE '%PA_ISSUANCE%' OR RV_DOMAIN LIKE '%PA_POLICY%' OR RV_DOMAIN LIKE '%TRAVEL%API%' ORDER BY RV_DOMAIN, RV_LOW_VALUE";
$stmt = oci_parse($conn, $sql);
if (oci_execute($stmt)) {
    while ($r = oci_fetch_assoc($stmt)) {
        echo str_pad($r['RV_LOW_VALUE'] ?? '', 15) . str_pad($r['RV_HIGH_VALUE'] ?? '', 30) . ($r['RV_DOMAIN'] ?? '') . "\n";
    }
}
oci_free_statement($stmt);

// Check if issue code is validated via GIAC_ISSOURCE or similar
echo "\n=== Checking GIIS_ISSOURCE for HO with line access ===\n";
$tables = ['giis_line_iss', 'giis_iss_line', 'giis_issource_line', 'giis_user_iss_cd', 'giis_user_issource'];
foreach ($tables as $tbl) {
    $stmt = oci_parse($conn, "SELECT * FROM {$tbl} WHERE ROWNUM <= 5");
    if (@oci_execute($stmt)) {
        echo "\nTable {$tbl} EXISTS. Sample:\n";
        $n = 0;
        while ($r = oci_fetch_assoc($stmt)) {
            if ($n === 0) echo implode(' | ', array_keys($r)) . "\n";
            echo implode(' | ', array_map(function($v) { return $v ?? 'NULL'; }, $r)) . "\n";
            $n++;
        }
    }
    oci_free_statement($stmt);
}

// Check user-specific issue source access
echo "\n=== Checking GIIS_USER_ISS_CD for CPI user ===\n";
$stmt = oci_parse($conn, "SELECT table_name FROM all_tables WHERE table_name LIKE '%USER%ISS%' OR table_name LIKE '%ISS%USER%'");
if (oci_execute($stmt)) {
    while ($r = oci_fetch_assoc($stmt)) {
        echo "Found table: " . $r['TABLE_NAME'] . "\n";
    }
}
oci_free_statement($stmt);

// Check GIIS_USER for CPI
echo "\n=== GIIS_USERS for CPI ===\n";
$stmt = oci_parse($conn, "SELECT user_id, iss_cd, iss_cd_ri FROM giis_users WHERE user_id = 'CPI'");
if (oci_execute($stmt)) {
    while ($r = oci_fetch_assoc($stmt)) {
        foreach ($r as $k => $v) echo "{$k}: " . ($v ?? 'NULL') . "\n";
    }
}
oci_free_statement($stmt);

oci_close($conn);
