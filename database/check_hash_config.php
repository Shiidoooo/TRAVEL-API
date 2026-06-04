<?php
/**
 * Diagnostic script: Query CG_REF_CODES from Oracle to find the actual
 * PA_ISSUANCE_API hash configuration (salt + unique attributes).
 *
 * Usage: php check_hash_config.php
 */

$envPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '.env';
loadEnvSimple($envPath);

$host = getenv('ORACLE_HOST') ?: '';
$port = getenv('ORACLE_PORT') ?: '1521';
$serviceName = getenv('ORACLE_SERVICE_NAME') ?: '';
$username = getenv('ORACLE_USERNAME') ?: '';
$password = getenv('ORACLE_PASSWORD') ?: '';
$protocol = getenv('ORACLE_PROTOCOL') ?: 'TCP';

echo "=== PA_ISSUANCE_API Hash Configuration Check ===\n\n";
echo "Oracle Host: {$host}\n";
echo "Service Name: {$serviceName}\n";
echo "Username: {$username}\n\n";

if ($host === '' || $serviceName === '' || $username === '') {
    echo "ERROR: Oracle connection details missing in .env\n";
    exit(1);
}

if (!function_exists('oci_connect')) {
    echo "ERROR: OCI8 extension not available.\n";
    echo "Trying alternative: PDO_OCI...\n\n";

    // Try PDO_OCI as fallback
    try {
        $tns = "(DESCRIPTION=(ADDRESS=(PROTOCOL={$protocol})(HOST={$host})(PORT={$port}))(CONNECT_DATA=(SERVICE_NAME={$serviceName})))";
        $dsn = "oci:dbname={$tns}";
        $pdo = new PDO($dsn, $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $sql = "SELECT RV_LOW_VALUE, RV_HIGH_VALUE, RV_DOMAIN 
                FROM CG_REF_CODES 
                WHERE RV_DOMAIN LIKE 'PA_ISSUANCE_API%' 
                ORDER BY RV_DOMAIN, RV_LOW_VALUE";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            echo "No rows found for PA_ISSUANCE_API in CG_REF_CODES.\n";
        } else {
            echo "Found " . count($rows) . " row(s):\n\n";
            echo str_pad('RV_LOW_VALUE', 15) . str_pad('RV_HIGH_VALUE', 30) . "RV_DOMAIN\n";
            echo str_repeat('-', 75) . "\n";
            foreach ($rows as $row) {
                echo str_pad($row['RV_LOW_VALUE'] ?? '', 15)
                   . str_pad($row['RV_HIGH_VALUE'] ?? '', 30)
                   . ($row['RV_DOMAIN'] ?? '') . "\n";
            }
        }
    } catch (Exception $e) {
        echo "PDO_OCI also failed: " . $e->getMessage() . "\n";
        exit(1);
    }
    exit(0);
}

// Use OCI8
$tns = "(DESCRIPTION=(ADDRESS=(PROTOCOL={$protocol})(HOST={$host})(PORT={$port}))(CONNECT_DATA=(SERVICE_NAME={$serviceName})))";
$conn = @oci_connect($username, $password, $tns);

if (!$conn) {
    $err = oci_error();
    echo "ERROR: Cannot connect to Oracle: " . ($err['message'] ?? 'Unknown error') . "\n";
    exit(1);
}

echo "Connected to Oracle successfully.\n\n";

$sql = "SELECT RV_LOW_VALUE, RV_HIGH_VALUE, RV_DOMAIN 
        FROM CG_REF_CODES 
        WHERE RV_DOMAIN LIKE 'PA_ISSUANCE_API%' 
        ORDER BY RV_DOMAIN, TO_NUMBER(RV_LOW_VALUE)";

$stmt = oci_parse($conn, $sql);
if (!oci_execute($stmt)) {
    $err = oci_error($stmt);
    echo "ERROR: Query failed: " . ($err['message'] ?? 'Unknown error') . "\n";
    oci_close($conn);
    exit(1);
}

$rows = [];
while ($row = oci_fetch_assoc($stmt)) {
    $rows[] = $row;
}

oci_free_statement($stmt);
oci_close($conn);

if (empty($rows)) {
    echo "No rows found for PA_ISSUANCE_API in CG_REF_CODES.\n";
    echo "The hash configuration may not be set up yet.\n";
} else {
    echo "Found " . count($rows) . " row(s):\n\n";
    echo str_pad('RV_LOW_VALUE', 15) . str_pad('RV_HIGH_VALUE', 30) . "RV_DOMAIN\n";
    echo str_repeat('-', 75) . "\n";

    $salt = '';
    $attributes = [];

    foreach ($rows as $row) {
        $low = $row['RV_LOW_VALUE'] ?? '';
        $high = $row['RV_HIGH_VALUE'] ?? '';
        $domain = $row['RV_DOMAIN'] ?? '';

        echo str_pad($low, 15) . str_pad($high, 30) . $domain . "\n";

        if ($domain === 'PA_ISSUANCE_API.SALT') {
            $salt = $high;
        } elseif ($domain === 'PA_ISSUANCE_API.UNIQUE_ATTRIBUTE') {
            $attributes[(int)$low] = $high;
        }
    }

    echo "\n=== Resolved Configuration ===\n";
    echo "Salt: {$salt}\n";
    ksort($attributes);
    echo "Attributes (ordered): " . implode(', ', $attributes) . "\n";

    // Show what the hash formula should be
    echo "\nHash formula: SHA256(\"{$salt}\" + " . implode(' + ', $attributes) . ")\n";
}

function loadEnvSimple(string $path): void
{
    if (!is_file($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if ($value !== '' && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        putenv("{$key}={$value}");
    }
}
