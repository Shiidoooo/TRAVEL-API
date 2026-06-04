<?php
// Simple DB connection checks for SQL Server, Oracle, and MySQL.

$envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
loadEnv($envPath);

header('Content-Type: text/plain; charset=UTF-8');

echo "DB connection checks" . PHP_EOL;
if (!is_file($envPath)) {
    echo "Warning: .env not found at {$envPath}; using existing environment values." . PHP_EOL;
}

echo PHP_EOL;

$sqlsrvResult = testSqlServer(
    envValue('DB_HOST'),
    envValue('DB_DATABASE'),
    envValue('DB_USERNAME'),
    envValue('DB_PASSWORD')
);

$oracleResult = testOracle(
    envValue('ORACLE_HOST'),
    envValue('ORACLE_PORT', '1521'),
    envValue('ORACLE_SERVICE_NAME'),
    envValue('ORACLE_USERNAME'),
    envValue('ORACLE_PASSWORD'),
    envValue('ORACLE_PROTOCOL', 'TCP')
);

$mysqlResult = testMySql(
    envValue('MYSQL_HOST'),
    envValue('MYSQL_PORT', '3306'),
    envValue('MYSQL_DATABASE'),
    envValue('MYSQL_USERNAME'),
    envValue('MYSQL_PASSWORD')
);

outputResult('SQL Server', $sqlsrvResult);
outputResult('Oracle', $oracleResult);
outputResult('MySQL', $mysqlResult);

function loadEnv($path)
{
    if (!is_file($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if (
            $value !== '' && (
                ($value[0] === '"' && substr($value, -1) === '"') ||
                ($value[0] === "'" && substr($value, -1) === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }

    return true;
}

function envValue($key, $default = '')
{
    $value = getenv($key);
    if ($value === false) {
        if (array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
        } elseif (array_key_exists($key, $_SERVER)) {
            $value = $_SERVER[$key];
        }
    }

    if ($value === false || $value === null) {
        return $default;
    }

    return $value;
}

function outputResult($label, array $result)
{
    $status = $result['ok'] ? 'OK' : 'FAIL';
    echo $label . ': ' . $status . ' - ' . $result['message'] . PHP_EOL;
}

function testSqlServer($host, $database, $username, $password)
{
    if ($host === '' || $database === '') {
        return ['ok' => false, 'message' => 'Missing DB_HOST or DB_DATABASE'];
    }

    if (!function_exists('sqlsrv_connect')) {
        return ['ok' => false, 'message' => 'sqlsrv extension not installed'];
    }

    $options = [
        'Database' => $database,
        'LoginTimeout' => 5,
        'TrustServerCertificate' => true
    ];

    if ($username !== '') {
        $options['UID'] = $username;
        $options['PWD'] = $password;
    }

    $conn = @sqlsrv_connect($host, $options);
    if ($conn === false) {
        $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        $message = $errors && isset($errors[0]['message']) ? $errors[0]['message'] : 'Connection failed';
        return ['ok' => false, 'message' => $message];
    }

    sqlsrv_close($conn);
    return ['ok' => true, 'message' => 'Connected'];
}

function testOracle($host, $port, $serviceName, $username, $password, $protocol)
{
    if ($host === '' || $serviceName === '' || $username === '') {
        return ['ok' => false, 'message' => 'Missing ORACLE_HOST, ORACLE_SERVICE_NAME, or ORACLE_USERNAME'];
    }

    if (!function_exists('oci_connect')) {
        return ['ok' => false, 'message' => 'oci8 extension not installed'];
    }

    $protocol = $protocol !== '' ? $protocol : 'TCP';
    $port = $port !== '' ? $port : '1521';
    $tns = "(DESCRIPTION=(ADDRESS=(PROTOCOL={$protocol})(HOST={$host})(PORT={$port}))(CONNECT_DATA=(SERVICE_NAME={$serviceName})))";

    $conn = @oci_connect($username, $password, $tns);
    if ($conn === false) {
        $error = oci_error();
        $message = $error && isset($error['message']) ? $error['message'] : 'Connection failed';
        return ['ok' => false, 'message' => $message];
    }

    oci_close($conn);
    return ['ok' => true, 'message' => 'Connected'];
}

function testMySql($host, $port, $database, $username, $password)
{
    if ($host === '' || $database === '' || $username === '') {
        return ['ok' => false, 'message' => 'Missing MYSQL_HOST, MYSQL_DATABASE, or MYSQL_USERNAME'];
    }

    if (!function_exists('mysqli_connect')) {
        return ['ok' => false, 'message' => 'mysqli extension not installed'];
    }

    $port = $port !== '' ? (int) $port : 3306;
    $conn = @mysqli_connect($host, $username, $password, $database, $port);
    if (!$conn) {
        return ['ok' => false, 'message' => mysqli_connect_error()];
    }

    mysqli_close($conn);
    return ['ok' => true, 'message' => 'Connected'];
}
