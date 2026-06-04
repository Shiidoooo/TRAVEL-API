<?php
declare(strict_types=1);

function connectSqlServer(string $host, string $database, string $username, string $password)
{
    if (!function_exists('sqlsrv_connect')) {
        return null;
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
    return $conn ?: null;
}

function resolveSchema($conn, string $preferred): string
{
    $preferred = trim($preferred);
    $schemas = [];
    $stmt = sqlsrv_query($conn, 'SELECT TABLE_SCHEMA FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?', ['tbl_travel_policy']);
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['TABLE_SCHEMA'])) {
                $schemas[] = (string) $row['TABLE_SCHEMA'];
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    if ($preferred !== '' && in_array($preferred, $schemas, true)) {
        return $preferred;
    }

    if (in_array('dbo', $schemas, true)) {
        return 'dbo';
    }

    if (!empty($schemas)) {
        return $schemas[0];
    }

    return $preferred !== '' ? $preferred : 'dbo';
}

function quoteIdentifier(string $identifier): string
{
    return '[' . str_replace(']', ']]', $identifier) . ']';
}

function qualifyTable(string $schema, string $table): string
{
    $tableName = quoteIdentifier($table);
    if ($schema === '') {
        return $tableName;
    }

    return quoteIdentifier($schema) . '.' . $tableName;
}

function execQuery($conn, string $sql, array $params = []): void
{
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        $message = $errors && isset($errors[0]['message']) ? $errors[0]['message'] : 'Query failed.';
        throw new RuntimeException($message);
    }
    sqlsrv_free_stmt($stmt);
}

function fetchOne($conn, string $sql, array $params = []): ?array
{
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        $message = $errors && isset($errors[0]['message']) ? $errors[0]['message'] : 'Query failed.';
        throw new RuntimeException($message);
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ?: null;
}

function fetchAll($conn, string $sql, array $params = []): array
{
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        $message = $errors && isset($errors[0]['message']) ? $errors[0]['message'] : 'Query failed.';
        throw new RuntimeException($message);
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);

    return $rows;
}

function getAgentTableName($conn, string $schema): string
{
    $tables = ['tbl_agent_informations', 'tbl_agent_information'];
    foreach ($tables as $table) {
        $row = fetchOne(
            $conn,
            'SELECT 1 AS ok FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ? AND TABLE_SCHEMA = ?',
            [$table, $schema]
        );
        if ($row) {
            return $table;
        }
    }

    foreach ($tables as $table) {
        $row = fetchOne($conn, 'SELECT 1 AS ok FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?', [$table]);
        if ($row) {
            return $table;
        }
    }

    return 'tbl_agent_informations';
}

function buildInClause(array $values): string
{
    return implode(',', array_fill(0, count($values), '?'));
}

function generatePolicyNumberFromPolicy($conn, string $policyTable, int $intmNo): string
{
    $year = date('y');
    $prefix = 'AH-' . $intmNo . '-' . $year . '-';
    $like = $prefix . '%';

    $row = fetchOne(
        $conn,
        "SELECT TOP 1 pol_ref_number FROM {$policyTable} WHERE pol_ref_number LIKE ? ORDER BY pol_ref_number DESC",
        [$like]
    );

    $latest = 0;
    if ($row && isset($row['pol_ref_number'])) {
        $current = (string) $row['pol_ref_number'];
        if (preg_match('/-(\d+)$/', $current, $matches)) {
            $latest = (int) $matches[1];
        }
    }

    $next = $latest + 1;
    $suffix = str_pad((string) $next, 3, '0', STR_PAD_LEFT);

    return $prefix . $suffix;
}
