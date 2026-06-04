<?php
declare(strict_types=1);

function syncGeniisysCustomer(
    $conn,
    string $policyTable,
    int $policyId,
    string $firstName,
    string $lastName,
    string $middleName,
    string $birthDateStored,
    string $gender,
    string $email,
    string $houseUnit,
    string $street,
    string $barangay,
    string $city,
    string $province,
    string $zip,
    string $phoneRaw,
    string $tin,
    ?int $assuredIntmNo,
    string $providedCustomerId
): array {
    $result = [
        'status' => 'skipped',
        'message' => '',
        'geniisys_customer_id' => null
    ];

    $firstName = trim($firstName);
    $lastName = trim($lastName);
    $birthDateStored = trim($birthDateStored);

    if ($firstName === '' || $lastName === '' || $birthDateStored === '') {
        $result['message'] = 'Missing required customer identity fields.';
        return $result;
    }

    try {
        $providedCustomerId = trim($providedCustomerId);
        if ($providedCustomerId !== '' && ctype_digit($providedCustomerId)) {
            $validated = findExistingGeniisysPolicy(
                $conn,
                $policyTable,
                $policyId,
                $firstName,
                $lastName,
                $birthDateStored,
                $providedCustomerId
            );

            if ($validated) {
                updatePolicyGeniisysCustomerId($conn, $policyTable, $policyId, $providedCustomerId);
                upsertOracleAssuredIntm($assuredIntmNo, $providedCustomerId);
                $result['status'] = 'reused';
                $result['message'] = 'Reused validated GeniiSys ID from input.';
                $result['geniisys_customer_id'] = $providedCustomerId;
                return $result;
            }
        }

        $existing = findExistingGeniisysPolicy(
            $conn,
            $policyTable,
            $policyId,
            $firstName,
            $lastName,
            $birthDateStored,
            null
        );

        if ($existing && !empty($existing['geniisys_customer_id'])) {
            $existingId = (string) $existing['geniisys_customer_id'];
            updatePolicyGeniisysCustomerId($conn, $policyTable, $policyId, $existingId);
            upsertOracleAssuredIntm($assuredIntmNo, $existingId);
            $result['status'] = 'reused';
            $result['message'] = 'Reused existing GeniiSys ID.';
            $result['geniisys_customer_id'] = $existingId;
            return $result;
        }

        $apiUrl = envValue('GENIISYS_CUSTOMER_API');
        if ($apiUrl === '') {
            $result['message'] = 'GENIISYS_CUSTOMER_API is not configured.';
            return $result;
        }

        $userId = envValue('GENIISYS_USERID');
        $salt = envValue('GENIISYS_SALT');
        if ($userId === '' || $salt === '') {
            $result['message'] = 'Missing GENIISYS_USERID or GENIISYS_SALT.';
            return $result;
        }

        // Parse birth date into separate day / month-name / year fields
        $birthParsed = parseDateInput($birthDateStored);
        $birthDay = $birthParsed ? $birthParsed->format('d') : '';
        $birthMonthName = $birthParsed ? ucfirst(strtolower($birthParsed->format('F'))) : '';
        $birthYearStr = $birthParsed ? $birthParsed->format('Y') : '';

        // Normalize phone to digits-only, 11-digit PH format
        $phoneDigits = preg_replace('/[^0-9]/', '', $phoneRaw);
        if ($phoneDigits === null) {
            $phoneDigits = '';
        }
        if (strlen($phoneDigits) === 12 && substr($phoneDigits, 0, 2) === '63') {
            $phoneDigits = '0' . substr($phoneDigits, 2);
        } elseif (strlen($phoneDigits) === 10 && substr($phoneDigits, 0, 1) === '9') {
            $phoneDigits = '0' . $phoneDigits;
        } elseif (strlen($phoneDigits) === 13 && substr($phoneDigits, 0, 3) === '639') {
            $phoneDigits = '0' . substr($phoneDigits, 2);
        }

        $emailLower = strtolower(trim($email));

        // Structured address fields with defaults matching the Laravel implementation
        $houseNoVal = trim($houseUnit) !== '' ? trim($houseUnit) : '-';
        $streetVal = trim($street) !== '' ? trim($street) : '-';
        $provinceVal = strtoupper(trim($province) !== '' ? $province : 'METRO MANILA');
        $cityVal = strtoupper(trim($city));
        $brgyVal = strtoupper(trim($barangay));
        $zipVal = trim($zip);

        $middleInitial = $middleName !== '' ? strtoupper(substr(trim($middleName), 0, 1)) : '';
        $tinNormalized = normalizeGeniisysTin($tin);

        $payload = [
            'corporateTag' => 'I',
            'firstName' => strtoupper($firstName),
            'lastName' => strtoupper($lastName),
            'middleInitial' => $middleInitial,
            'birthDate' => $birthDay,
            'birthMonth' => $birthMonthName,
            'birthYear' => $birthYearStr,
            'assdName' => '',
            'gender' => strtoupper(substr($gender !== '' ? $gender : 'M', 0, 1)),
            'emailAddress' => $emailLower,
            'houseNo' => $houseNoVal,
            'streetName' => $streetVal,
            'provinceDesc' => $provinceVal,
            'cityDesc' => $cityVal,
            'brgyDesc' => $brgyVal,
            'zipCd' => $zipVal,
            'phoneNo' => $phoneDigits,
            'assdTin' => $tinNormalized,
            'noTinReason' => (trim($tin) === '') ? 'eCommerce transaction' : '',
            'vatTag' => '3',
            'industryCd' => '',
            'userId' => (string) $userId,
        ];

        // Hash formula: salt + email + phone (matches working Laravel syncCustomersTest)
        $hashSource = $salt . $emailLower . $phoneDigits;
        $hashKey = strtoupper(hash('sha256', $hashSource));
        $timeout = (int) envValue('GENIISYS_TIMEOUT', '30');

        $apiResult = requestGeniisysCustomerApi($apiUrl, $payload, $hashKey, $timeout);
        if (!$apiResult['ok']) {
            $result['status'] = 'failed';
            $result['message'] = $apiResult['error'] ?: 'Unable to reach GeniiSys API.';
            return $result;
        }

        if ($apiResult['status_code'] < 200 || $apiResult['status_code'] >= 300) {
            $result['status'] = 'failed';
            $result['message'] = 'GeniiSys API returned HTTP ' . $apiResult['status_code'] . '.';
            if (is_array($apiResult['json'])) {
                $result['api_response'] = $apiResult['json'];
            } elseif ($apiResult['body'] !== '') {
                $result['api_response'] = substr($apiResult['body'], 0, 500);
            }
            return $result;
        }

        $customerId = extractGeniisysCustomerId($apiResult['json']);
        if ($customerId !== null && ctype_digit($customerId)) {
            updatePolicyGeniisysCustomerId($conn, $policyTable, $policyId, $customerId);
            upsertOracleAssuredIntm($assuredIntmNo, $customerId);
            $result['status'] = 'created';
            $result['message'] = 'GeniiSys ID created.';
            $result['geniisys_customer_id'] = $customerId;
            return $result;
        }

        $result['status'] = 'duplicate';
        $result['message'] = 'GeniiSys returned no new ID.';
        return $result;
    } catch (Throwable $e) {
        $result['status'] = 'failed';
        $result['message'] = 'GeniiSys sync error: ' . $e->getMessage();
        return $result;
    }
}

function normalizeNameForMatch(string $value): string
{
    $value = strtoupper(trim($value));
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/[^A-Z0-9]/', '', $value);
    return $value ?? '';
}

function normalizedNameExpression(string $column): string
{
    $expr = "UPPER(LTRIM(RTRIM({$column})))";
    $expr = "REPLACE({$expr}, ' ', '')";
    $expr = "REPLACE({$expr}, '.', '')";
    $expr = "REPLACE({$expr}, '-', '')";
    $expr = "REPLACE({$expr}, '''', '')";
    $expr = "REPLACE({$expr}, ',', '')";
    return $expr;
}

function findExistingGeniisysPolicy(
    $conn,
    string $policyTable,
    int $policyId,
    string $firstName,
    string $lastName,
    string $birthDateStored,
    ?string $customerId
): ?array {
    $firstKey = normalizeNameForMatch($firstName);
    $lastKey = normalizeNameForMatch($lastName);

    if ($firstKey === '' || $lastKey === '' || trim($birthDateStored) === '') {
        return null;
    }

    $firstExpr = normalizedNameExpression('client_fname');
    $lastExpr = normalizedNameExpression('client_lname');

    $sql = "SELECT TOP 1 pol_ref_id, geniisys_customer_id FROM {$policyTable}"
        . " WHERE {$firstExpr} = ?"
        . " AND {$lastExpr} = ?"
        . " AND birthdate = ?"
        . " AND pol_ref_id <> ?"
        . " AND geniisys_customer_id IS NOT NULL"
        . " AND geniisys_customer_id > 0";

    $params = [$firstKey, $lastKey, $birthDateStored, $policyId];
    if ($customerId !== null && $customerId !== '' && ctype_digit($customerId)) {
        $sql .= " AND geniisys_customer_id = ?";
        $params[] = (int) $customerId;
    }

    return fetchOne($conn, $sql, $params);
}

function updatePolicyGeniisysCustomerId($conn, string $policyTable, int $policyId, string $customerId): void
{
    $value = null;
    if ($customerId !== '' && ctype_digit($customerId)) {
        $value = (int) $customerId;
    }

    execQuery($conn, "UPDATE {$policyTable} SET geniisys_customer_id = ? WHERE pol_ref_id = ?", [$value, $policyId]);
}

function buildGeniisysAddress(
    string $houseUnit,
    string $street,
    string $barangay,
    string $city,
    string $province,
    string $zip
): string {
    $parts = [
        trim($houseUnit),
        trim($street),
        trim($barangay),
        trim($city),
        trim($province),
        trim($zip)
    ];

    $parts = array_values(array_filter($parts, function ($value) {
        return $value !== '';
    }));

    return trim(implode(' ', $parts));
}

function formatGeniisysBirthDate(string $value): string
{
    $format = envValue('GENIISYS_BIRTHDATE_FORMAT', 'm/d/Y');
    $parsed = parseDateInput($value);
    if (!$parsed) {
        return $value;
    }

    return $parsed->format($format);
}

function normalizeGeniisysTin(string $value): string
{
    $digits = preg_replace('/[^0-9]/', '', $value);
    return $digits ?? '';
}

function formatGeniisysPhone(string $value): string
{
    $digits = preg_replace('/[^0-9]/', '', $value);
    if ($digits === null || $digits === '') {
        return '';
    }

    if (strlen($digits) === 12 && substr($digits, 0, 2) === '63') {
        $digits = '0' . substr($digits, 2);
    } elseif (strlen($digits) === 10 && substr($digits, 0, 1) === '9') {
        $digits = '0' . $digits;
    } elseif (strlen($digits) === 13 && substr($digits, 0, 3) === '639') {
        $digits = '0' . substr($digits, 2);
    }

    $mode = strtoupper(envValue('GENIISYS_PHONE_FORMAT', 'HYPHEN'));
    if ($mode === 'HYPHEN' && strlen($digits) >= 7) {
        return substr($digits, 0, 4) . '-' . substr($digits, 4);
    }

    return $digits;
}

function requestGeniisysCustomerApi(string $url, array $payload, string $hashKey, int $timeout): array
{
    $result = [
        'ok' => false,
        'status_code' => 0,
        'body' => '',
        'json' => null,
        'error' => ''
    ];

    $payloadJson = json_encode($payload);
    if ($payloadJson === false) {
        $result['error'] = 'Unable to encode payload.';
        return $result;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout > 0 ? $timeout : 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'hashKey: ' . $hashKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $body = curl_exec($ch);
        if ($body === false) {
            $result['error'] = curl_error($ch);
            curl_close($ch);
            return $result;
        }

        $result['status_code'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $result['body'] = (string) $body;
        $decoded = json_decode((string) $body, true);
        $result['json'] = is_array($decoded) ? $decoded : null;
        $result['ok'] = true;
        return $result;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => $timeout > 0 ? $timeout : 30,
            'header' => "hashKey: {$hashKey}\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $payloadJson,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        $result['error'] = 'HTTP request failed.';
        return $result;
    }

    $result['body'] = (string) $body;
    $decoded = json_decode((string) $body, true);
    $result['json'] = is_array($decoded) ? $decoded : null;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/[0-9.]+\s+(\d+)/', $header, $matches)) {
                $result['status_code'] = (int) $matches[1];
                break;
            }
        }
    }

    $result['ok'] = true;
    return $result;
}

function extractGeniisysCustomerId($payload): ?string
{
    if (!is_array($payload)) {
        return null;
    }

    // Only trust explicit assdNo/assd_no fields — never use 'message' as a candidate
    // to prevent accidental ID spoofing from error messages that look like numbers.
    $candidates = [];
    if (isset($payload['assdNo'])) {
        $candidates[] = $payload['assdNo'];
    }
    if (isset($payload['assd_no'])) {
        $candidates[] = $payload['assd_no'];
    }
    if (isset($payload['data']) && is_array($payload['data'])) {
        $data = $payload['data'];
        if (isset($data['assdNo'])) {
            $candidates[] = $data['assdNo'];
        }
        if (isset($data['assd_no'])) {
            $candidates[] = $data['assd_no'];
        }
    }

    foreach ($candidates as $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        return $value;
    }

    return null;
}

function upsertOracleAssuredIntm(?int $intmNo, string $assdNo): void
{
    if ($intmNo === null) {
        return;
    }

    if (!ctype_digit($assdNo)) {
        return;
    }

    if (!function_exists('oci_parse')) {
        return;
    }

    $conn = connectOracleFromEnv();
    if ($conn === null) {
        return;
    }

    $lineCd = 'AH';
    $checkSql = 'SELECT 1 FROM giis_assured_intm WHERE assd_no = :assd_no AND line_cd = :line_cd AND intm_no = :intm_no';
    $stmt = oci_parse($conn, $checkSql);
    if ($stmt === false) {
        oci_close($conn);
        return;
    }

    oci_bind_by_name($stmt, ':assd_no', $assdNo);
    oci_bind_by_name($stmt, ':line_cd', $lineCd);
    oci_bind_by_name($stmt, ':intm_no', $intmNo);

    $exists = false;
    if (@oci_execute($stmt)) {
        $ociNum = defined('OCI_NUM') ? constant('OCI_NUM') : 0;
        $ociReturnNulls = defined('OCI_RETURN_NULLS') ? constant('OCI_RETURN_NULLS') : 0;
        $exists = (bool) @oci_fetch_array($stmt, $ociNum | $ociReturnNulls);
    }
    @oci_free_statement($stmt);

    if (!$exists) {
        $insertSql = 'INSERT INTO giis_assured_intm (assd_no, line_cd, intm_no, last_update) VALUES (:assd_no, :line_cd, :intm_no, SYSDATE)';
        $insertStmt = @oci_parse($conn, $insertSql);
        if ($insertStmt !== false) {
            @oci_bind_by_name($insertStmt, ':assd_no', $assdNo);
            @oci_bind_by_name($insertStmt, ':line_cd', $lineCd);
            @oci_bind_by_name($insertStmt, ':intm_no', $intmNo);
            $ociCommit = defined('OCI_COMMIT_ON_SUCCESS') ? constant('OCI_COMMIT_ON_SUCCESS') : 0;
            @oci_execute($insertStmt, $ociCommit);
            @oci_free_statement($insertStmt);
        }
    }

    oci_close($conn);
}

function connectOracleFromEnv()
{
    $host = envValue('ORACLE_HOST');
    $serviceName = envValue('ORACLE_SERVICE_NAME');
    $username = envValue('ORACLE_USERNAME');
    $password = envValue('ORACLE_PASSWORD');

    if ($host === '' || $serviceName === '' || $username === '') {
        return null;
    }

    if (!function_exists('oci_connect')) {
        return null;
    }

    $protocol = envValue('ORACLE_PROTOCOL', 'TCP');
    $port = envValue('ORACLE_PORT', '1521');
    $tns = "(DESCRIPTION=(ADDRESS=(PROTOCOL={$protocol})(HOST={$host})(PORT={$port}))(CONNECT_DATA=(SERVICE_NAME={$serviceName})))";

    $conn = @oci_connect($username, $password, $tns, 'AL32UTF8');
    return $conn ?: null;
}

function insertOraclePolgeninTest($geniisysPolicyId, string $planDtl = ''): void
{
    if (!$geniisysPolicyId || !ctype_digit((string) $geniisysPolicyId)) {
        return;
    }

    if (!function_exists('oci_parse')) {
        return;
    }

    $conn = connectOracleFromEnv();
    if ($conn === null) {
        return;
    }

    // Clean up HTML tags but preserve line breaks if it's HTML
    $cleanText = preg_replace('/<br\s*\/?>/i', "\n", $planDtl);
    $cleanText = preg_replace('/<\/p>/i', "\n\n", $cleanText);
    // Replace list items and tabs to make it presentable (using simple dash to prevent charset issues)
    $cleanText = preg_replace('/<li[^>]*>/i', "\n - ", $cleanText);
    $cleanText = strip_tags($cleanText);
    
    // Decode HTML entities
    $cleanText = html_entity_decode($cleanText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Replace non-breaking spaces (both single-byte and UTF-8) with standard spaces to avoid inverted question marks (¿)
    $cleanText = str_replace(["\xA0", "\xC2\xA0"], ' ', $cleanText);
    
    // Replace "=" with standard space to remove "=" and preserve layout alignment
    $cleanText = str_replace('=', ' ', $cleanText);
    
    // Normalize newlines (handle \r\n and \r)
    $cleanText = str_replace(["\r\n", "\r"], "\n", $cleanText);
    
    if (trim($cleanText) === '') {
        $cleanText = 'No plan details available.';
    }

    // Prepend blank lines to push the plan detail (e.g. "INTERNATIONAL ASIA (USD 20K)")
    // to the second page. The first page contains the coverage/peril table and up to 3
    // warranty sections (HAZARDOUS SPORT, SPORTS EQUIPMENT, CRUISE HOLIDAY) which may or
    // may not be present. We add a fixed block of newlines so the plan detail always
    // starts on page 2 regardless of warranty count.
    $pageBreakLines = str_repeat("\n", 5);
    $cleanText = $pageBreakLines . $cleanText;

    // Chunk into 2000-character segments, making sure not to cut off words if possible
    $maxLen = 2000;
    $chunks = [];
    $remaining = $cleanText;

    while (strlen($remaining) > 0) {
        if (strlen($remaining) <= $maxLen) {
            $chunks[] = $remaining;
            break;
        }
        
        // Find last newline or space within the limit
        $cutPos = strrpos(substr($remaining, 0, $maxLen), "\n");
        if ($cutPos === false) {
            $cutPos = strrpos(substr($remaining, 0, $maxLen), " ");
        }
        
        if ($cutPos === false || $cutPos === 0) {
            $cutPos = $maxLen; // Force cut
            $chunks[] = substr($remaining, 0, $cutPos);
            $remaining = substr($remaining, $cutPos);
        } else {
            // Include the space or newline in the current chunk so it isn't lost during concatenation
            $chunks[] = substr($remaining, 0, $cutPos + 1);
            $remaining = substr($remaining, $cutPos + 1);
        }
    }

    // Fill up to 8 elements with null for binding
    $gen = array_pad($chunks, 8, null);

    $sql = "INSERT INTO GIPI_POLGENIN 
            (POLICY_ID, USER_ID, GEN_INFO01, GEN_INFO02, GEN_INFO03, GEN_INFO04, GEN_INFO05, GEN_INFO06, GEN_INFO07, GEN_INFO08) 
            VALUES 
            (:policy_id, 'ROSE', :gen1, :gen2, :gen3, :gen4, :gen5, :gen6, :gen7, :gen8)";

    $stmt = oci_parse($conn, $sql);
    if ($stmt !== false) {
        oci_bind_by_name($stmt, ':policy_id', $geniisysPolicyId);
        oci_bind_by_name($stmt, ':gen1', $gen[0]);
        oci_bind_by_name($stmt, ':gen2', $gen[1]);
        oci_bind_by_name($stmt, ':gen3', $gen[2]);
        oci_bind_by_name($stmt, ':gen4', $gen[3]);
        oci_bind_by_name($stmt, ':gen5', $gen[4]);
        oci_bind_by_name($stmt, ':gen6', $gen[5]);
        oci_bind_by_name($stmt, ':gen7', $gen[6]);
        oci_bind_by_name($stmt, ':gen8', $gen[7]);

        @oci_execute($stmt, OCI_COMMIT_ON_SUCCESS);
    }
    
    oci_close($conn);
} 



/**
 * Insert warranty/clauses into Oracle GIPI_POLWC based on selected surcharges.
 * Maps SQL Server tbl_surcharge IDs to Oracle GIIS_WARRCLA warranty codes.
 *
 * Data Mapping:
 *   SQL id=1 (HAZARDOUS SPORTS)      => WC_CD=1,   PFTF only
 *   SQL id=2 (COVER SPORTS EQUIPMENT) => WC_CD=SEW, PFTF only
 *   SQL id=3 (CRUISE)                 => WC_CD=CHW, both PFTF and PFTL
 */
function insertGeniisysWarranties($geniisysPolicyId, array $selectedSurcharges, string $sublineCd): void
{
    if (!$geniisysPolicyId || empty($selectedSurcharges) || !function_exists('oci_parse')) {
        return;
    }

    $conn = connectOracleFromEnv();
    if ($conn === null) {
        return;
    }

    $subline = strtoupper(trim($sublineCd));
    $lineCd = 'AH';

    // Map SQL Server surcharge IDs to Oracle GIIS_WARRCLA WC_CD codes
    $surchargeToWcMap = [
        1 => ['wc_cd' => '1',   'allowed_sublines' => ['PFTF']],           // HAZARDOUS SPORTS
        2 => ['wc_cd' => 'SEW', 'allowed_sublines' => ['PFTF']],           // COVER SPORTS EQUIPMENT
        3 => ['wc_cd' => 'CHW', 'allowed_sublines' => ['PFTF', 'PFTL']],   // CRUISE
    ];

    // Get next available PRINT_SEQ_NO for this policy
    $seqSql = 'SELECT NVL(MAX(PRINT_SEQ_NO), 0) AS MAX_SEQ FROM GIPI_POLWC WHERE POLICY_ID = :policy_id';
    $seqStmt = oci_parse($conn, $seqSql);
    $printSeqNo = 1;
    if ($seqStmt !== false) {
        oci_bind_by_name($seqStmt, ':policy_id', $geniisysPolicyId);
        if (@oci_execute($seqStmt)) {
            $row = @oci_fetch_array($seqStmt, OCI_ASSOC | OCI_RETURN_NULLS);
            if ($row && isset($row['MAX_SEQ'])) {
                $printSeqNo = (int) $row['MAX_SEQ'] + 1;
            }
        }
        @oci_free_statement($seqStmt);
    }

    foreach ($selectedSurcharges as $surchargeId) {
        if (!isset($surchargeToWcMap[$surchargeId])) {
            continue;
        }

        $mapping = $surchargeToWcMap[$surchargeId];
        $wcCd = $mapping['wc_cd'];
        $allowedSublines = $mapping['allowed_sublines'];

        // Skip if this surcharge is not valid for the current subline
        if (!in_array($subline, $allowedSublines)) {
            continue;
        }

        // Fetch WC_TITLE and WC_TEXT01 from GIIS_WARRCLA reference table
        $refSql = 'SELECT WC_TITLE, WC_TEXT01 FROM GIIS_WARRCLA WHERE LINE_CD = :line_cd AND MAIN_WC_CD = :wc_cd AND SUBLINE_CD = :subline_cd AND ROWNUM = 1';
        $refStmt = oci_parse($conn, $refSql);
        if ($refStmt === false) {
            continue;
        }

        oci_bind_by_name($refStmt, ':line_cd', $lineCd);
        oci_bind_by_name($refStmt, ':wc_cd', $wcCd);
        oci_bind_by_name($refStmt, ':subline_cd', $subline);

        $wcTitle = '';
        $wcText01 = '';

        if (@oci_execute($refStmt)) {
            $refRow = @oci_fetch_array($refStmt, OCI_ASSOC | OCI_RETURN_NULLS);
            if ($refRow) {
                $wcTitle = $refRow['WC_TITLE'] ?? '';
                $wcText01 = $refRow['WC_TEXT01'] ?? '';
            }
        }
        @oci_free_statement($refStmt);

        // If we couldn't find a reference row, skip
        if ($wcTitle === '' && $wcText01 === '') {
            continue;
        }

        // Insert into GIPI_POLWC
        $insertSql = 'INSERT INTO GIPI_POLWC (POLICY_ID, LINE_CD, WC_CD, SWC_SEQ_NO, PRINT_SEQ_NO, WC_TITLE, WC_TEXT01, PRINT_SW, CHANGE_TAG, SUBLINE_CD) VALUES (:policy_id, :line_cd, :wc_cd, 0, :print_seq_no, :wc_title, :wc_text01, :print_sw, :change_tag, :subline_cd)';

        $insertStmt = @oci_parse($conn, $insertSql);
        if ($insertStmt === false) {
            continue;
        }

        $printSw = 'Y';
        $changeTag = 'N';

        @oci_bind_by_name($insertStmt, ':policy_id', $geniisysPolicyId);
        @oci_bind_by_name($insertStmt, ':line_cd', $lineCd);
        @oci_bind_by_name($insertStmt, ':wc_cd', $wcCd);
        @oci_bind_by_name($insertStmt, ':print_seq_no', $printSeqNo);
        @oci_bind_by_name($insertStmt, ':wc_title', $wcTitle);
        @oci_bind_by_name($insertStmt, ':wc_text01', $wcText01);
        @oci_bind_by_name($insertStmt, ':print_sw', $printSw);
        @oci_bind_by_name($insertStmt, ':change_tag', $changeTag);
        @oci_bind_by_name($insertStmt, ':subline_cd', $subline);

        @oci_execute($insertStmt, OCI_COMMIT_ON_SUCCESS);
        @oci_free_statement($insertStmt);

        $printSeqNo++;
    }

    oci_close($conn);
}

/**
 * Call the Geniisys createTravelPA API after the customer has been synced.
 * Uses the assdNo (geniisys_customer_id) obtained from the customer sync.
 */
function syncGeniisysTravelPA(
    $conn,
    string $policyTable,
    int $policyId,
    string $geniisysCustomerId,
    string $polRefNumber,
    string $departureDateStored,
    string $returnDateStored,
    string $birthDateStored,
    int $age,
    string $gender,
    string $civilStatus,
    string $height,
    string $weight,
    string $passportNo,
    string $travelItinerary,
    string $clientBeneficiary,
    ?int $assuredIntmNo,
    string $houseUnit,
    string $street,
    string $barangay,
    string $city,
    string $province,
    string $zip,
    ?array $selectedPlanUpdate,
    string $fullName = '',
    string $email = '',
    string $phone = '',
    string $firstName = '',
    string $middleName = '',
    string $lastName = '',
    array $selectedSurcharges = [],
    string $sublineCd = 'PFTF'
): array {
    $result = [
        'status' => 'skipped',
        'message' => '',
        'geniisys_policy_id' => null,
        'geniisys_policy_no' => null,
        'geniisys_prem_seq_no' => null
    ];

    // Guard: require a valid Geniisys customer ID
    if ($geniisysCustomerId === '' || !ctype_digit($geniisysCustomerId)) {
        $result['message'] = 'No valid Geniisys customer ID for Travel PA.';
        return $result;
    }

    $apiUrl = envValue('GENIISYS_TRAVEL_API');
    if ($apiUrl === '') {
        $result['message'] = 'GENIISYS_TRAVEL_API is not configured.';
        return $result;
    }

    $userId = envValue('GENIISYS_USERID');
    $salt = envValue('GENIISYS_TRAVEL_SALT', envValue('GENIISYS_SALT'));
    if ($userId === '' || $salt === '') {
        $result['message'] = 'Missing GENIISYS_USERID or GENIISYS_TRAVEL_SALT.';
        return $result;
    }

    try {
        // Build the Travel PA payload
        $payload = buildTravelPaPayload(
            $geniisysCustomerId,
            $polRefNumber,
            $departureDateStored,
            $returnDateStored,
            $birthDateStored,
            $age,
            $gender,
            $civilStatus,
            $height,
            $weight,
            $passportNo,
            $travelItinerary,
            $clientBeneficiary,
            $assuredIntmNo,
            $houseUnit,
            $street,
            $barangay,
            $city,
            $province,
            $zip,
            $userId,
            $selectedPlanUpdate,
            $fullName,
            $email,
            $phone,
            $firstName,
            $middleName,
            $lastName
        );

        // Generate hash key using the PA_ISSUANCE_API spec
        $hashKey = generateTravelPaHashKey($salt, $payload);

        // Add hashKey to the JSON body per documentation: "hashKey" field with "hash" key
        $payload['hashKey'] = [
            'hash' => $hashKey
        ];

        // Only include debug info when APP_DEBUG is explicitly enabled.
        // This prevents leaking salt, hash key, and full payload in production.
        if (strtolower(envValue('APP_DEBUG', 'false')) === 'true') {
            $attrsCsv = envValue('GENIISYS_TRAVEL_HASH_ATTRIBUTES', 'intmNo,inceptDate');
            $attrs = array_map('trim', explode(',', $attrsCsv));
            $actualHashSource = $salt;
            foreach ($attrs as $a) {
                $v = resolvePaPayloadAttribute($payload, $a);
                $actualHashSource .= (string) $v;
            }
            $result['debug'] = [
                'hash_attributes' => $attrsCsv,
                'hash_source' => $actualHashSource,
                'hash_key' => $hashKey,
                'salt' => $salt,
                'sent_payload' => $payload
            ];
        }

        $timeout = (int) envValue('GENIISYS_TIMEOUT', '30');
        $apiResult = requestGeniisysTravelPaApi($apiUrl, $payload, $hashKey, $timeout);

        if (!$apiResult['ok']) {
            $result['status'] = 'failed';
            $result['message'] = $apiResult['error'] ?: 'Unable to reach GeniiSys Travel PA API.';
            return $result;
        }

        if ($apiResult['status_code'] < 200 || $apiResult['status_code'] >= 300) {
            $result['status'] = 'failed';

            // Extract the real error message from the GeniiSys API response
            $apiMsg = '';
            if (is_array($apiResult['json'])) {
                $result['api_response'] = $apiResult['json'];
                $apiMsg = $apiResult['json']['message'] ?? '';
            } elseif ($apiResult['body'] !== '') {
                $result['api_response'] = substr($apiResult['body'], 0, 500);
            }

            $result['message'] = $apiMsg !== ''
                ? 'GeniiSys Travel PA API error: ' . $apiMsg
                : 'GeniiSys Travel PA API returned HTTP ' . $apiResult['status_code'] . '.';
            return $result;
        }

        // Parse response
        $responseData = $apiResult['json'];
        $travelPaResult = extractTravelPaResponse($responseData);

        if ($travelPaResult !== null) {
            $formattedCustomerName = formatTravelItemTitle($firstName, $middleName, $lastName, '');
            $formattedCustomerName = trim($formattedCustomerName);
            $customerNameFallback = $formattedCustomerName !== '' ? $formattedCustomerName : null;

            // Update staging table with Geniisys policy info
            updatePolicyGeniisysTravelPa(
                $conn,
                $policyTable,
                $policyId,
                $travelPaResult['policyId'] ?? null,
                $travelPaResult['policyNo'] ?? null
            );

            // Insert genInfo data directly into Oracle for testing purposes
            if (!empty($travelPaResult['policyId'])) {
                $planDtl = $selectedPlanUpdate['plan_dtl'] ?? '';
                insertOraclePolgeninTest($travelPaResult['policyId'], $planDtl);
                insertGeniisysWarranties($travelPaResult['policyId'], $selectedSurcharges, $sublineCd);
            }

            $result['status'] = 'created';
            $result['message'] = 'Travel PA policy created in GeniiSys.';
            $result['geniisys_policy_id'] = $travelPaResult['policyId'] ?? null;
            $result['geniisys_policy_no'] = $travelPaResult['policyNo'] ?? null;
            $result['geniisys_prem_seq_no'] = $travelPaResult['premSeqNo'] ?? null;
            $result['salesInvoiceNo'] = $travelPaResult['salesInvoiceNo'] ?? null;
            $result['particulars'] = $travelPaResult['particulars'] ?? null;
            $customerName = $travelPaResult['customerName'] ?? null;
            if (is_string($customerName) && trim($customerName) === '') {
                $customerName = null;
            }
            if ($customerName === null) {
                $customerName = $customerNameFallback;
            }
            $result['customerName'] = $customerName;

            if (isset($travelPaResult['type'])) {
                $result['response_type'] = $travelPaResult['type'];
            }
        } else {
            $result['status'] = 'failed';
            $result['message'] = 'Travel PA API returned unexpected response format.';
            if (is_array($responseData)) {
                $result['api_response'] = $responseData;
            }
        }

        return $result;
    } catch (Throwable $e) {
        $result['status'] = 'failed';
        $result['message'] = 'Travel PA sync error: ' . $e->getMessage();
        return $result;
    }
}

/**
 * Build the JSON payload for the createTravelPA API.
 * Maps staging data to the Geniisys PA Issuance API format.
 */
function formatTravelItemTitle(string $firstName, string $middleName, string $lastName, string $defaultTitle): string
{
    $first = strtoupper(trim($firstName));
    $last = strtoupper(trim($lastName));
    if ($first === '' || $last === '') {
        return $defaultTitle;
    }

    $middleInitial = '';
    $middleTrim = strtoupper(trim($middleName));
    if ($middleTrim !== '') {
        $middleInitial = substr($middleTrim, 0, 1);
    }

    $title = $last . ', ' . $first;
    if ($middleInitial !== '') {
        $title .= ' ' . $middleInitial . '.';
    }

    return $title;
}

function buildTravelPaPayload(
    string $assdNo,
    string $polRefNumber,
    string $inceptDate,
    string $expiryDate,
    string $birthDateStored,
    int $age,
    string $gender,
    string $civilStatus,
    string $height,
    string $weight,
    string $passportNo,
    string $travelDestination,
    string $clientBeneficiary,
    ?int $assuredIntmNo,
    string $houseUnit,
    string $street,
    string $barangay,
    string $city,
    string $province,
    string $zip,
    string $userId,
    ?array $selectedPlanUpdate,
    string $fullName = '',
    string $email = '',
    string $phone = '',
    string $firstName = '',
    string $middleName = '',
    string $lastName = ''
): array {
    // Defaults from env to match GeniiSys configuration
    $lineCd = strtoupper(trim(envValue('GENIISYS_TRAVEL_LINE_CD', 'AH')));
    $issCd = strtoupper(trim(envValue('GENIISYS_TRAVEL_ISS_CD', 'HO')));
    $currency = strtoupper(trim(envValue('GENIISYS_TRAVEL_CURRENCY', 'PHP')));
    $paytTerm = strtoupper(trim(envValue('GENIISYS_TRAVEL_PAYT_TERM', 'COD')));
    $currencyRt = (float) envValue('GENIISYS_TRAVEL_CURRENCY_RT', '1');
    $defaultItemTitle = envValue('GENIISYS_TRAVEL_ITEM_TITLE', 'TRAVEL PERSONAL ACCIDENT');
    $itemTitle = formatTravelItemTitle($firstName, $middleName, $lastName, $defaultItemTitle);

    $intmNoEnv = trim(envValue('GENIISYS_TRAVEL_INTM_NO', ''));
    if ($intmNoEnv !== '' && ctype_digit($intmNoEnv)) {
        $intmNo = (int) $intmNoEnv;
    } elseif ($assuredIntmNo !== null) {
        $intmNo = $assuredIntmNo;
    } else {
        $intmNo = 76;
    }

    // Travel PA uses a separate userId (MAC) from the customer API (CPI)
    $travelUserId = trim(envValue('GENIISYS_TRAVEL_USERID', 'MAC'));
    if ($travelUserId === '') {
        $travelUserId = 'MAC';
    }
    $travelUserId = strtoupper($travelUserId);
    $regionCd = (int) envValue('GENIISYS_TRAVEL_REGION_CD', '13');
    $positionCd = (int) envValue('GENIISYS_TRAVEL_POSITION_CD', '101');
    $controlTypeCd = (int) envValue('GENIISYS_TRAVEL_CONTROL_TYPE_CD', '184');

    // Subline from the selected plan (tbl_travel_epim_plan_type)
    $sublineCd = '';
    $sublineOverride = trim(envValue('GENIISYS_TRAVEL_SUBLINE_CD', ''));
    if ($sublineOverride !== '') {
        $sublineCd = strtoupper($sublineOverride);
    } elseif ($selectedPlanUpdate !== null && !empty($selectedPlanUpdate['subline_cd'])) {
        $sublineCd = strtoupper((string) $selectedPlanUpdate['subline_cd']);
    }

    // Build the address string
    $assdAddress = buildGeniisysAddress($houseUnit, $street, $barangay, $city, $province, $zip);
    if ($assdAddress === '') {
        $assdAddress = '-';
    }

    // Map civil status to single character (UPPERCASE for Geniisys)
    $civilStatusChar = strtoupper(mapCivilStatusToChar($civilStatus));

    // Map gender to single character (UPPERCASE for Geniisys)
    $genderChar = strtoupper(substr($gender !== '' ? $gender : 'M', 0, 1));

    // Use the provided departure and return dates directly
    $inceptParsed = parseDateInput($inceptDate);

    // Calculate dueDate as inceptDate + 1 day
    $dueDateVal = $inceptDate;
    if ($inceptParsed) {
        $dueDateObj = clone $inceptParsed;
        $dueDateObj->modify('+1 day');
        $dueDateVal = $dueDateObj->format('m/d/Y');
    }

    // Generate refInvNo with pattern INV-YYYY-NNNN
    $refInvNo = generateRefInvNo($inceptDate);

    // Build policy peril array
    $policyPerilArray = buildPerilArrayFromPremium($selectedPlanUpdate, $sublineCd);

    // Build enrollee coverage peril array from computed premium values
    $enrolleePerilArray = buildPerilArrayFromPremium($selectedPlanUpdate, $sublineCd);

    // Build tax array from computed tax values
    $taxArray = buildTaxArrayFromPremium($selectedPlanUpdate);

    // Build beneficiary array
    $beneficiaryArray = [];
    $clientBeneficiary = trim($clientBeneficiary);
    if ($clientBeneficiary !== '') {
        $beneficiaryArray[] = [
            'beneficiaryNo' => 1,
            'beneficiaryName' => strtoupper($clientBeneficiary)
        ];
    }

    // Build enrollee remarks: email, phone, address
    $remarksParts = [];
    if (trim($email) !== '')
        $remarksParts[] = trim($email);
    if (trim($phone) !== '')
        $remarksParts[] = trim($phone);
    $addressStr = trim($assdAddress);
    if ($addressStr !== '' && $addressStr !== '-')
        $remarksParts[] = $addressStr;
    $remarks = implode(', ', $remarksParts);
    if ($remarks === '')
        $remarks = '-';

    // Build enrollee name
    $enrolleeName = strtoupper(trim($fullName));
    if ($enrolleeName === '')
        $enrolleeName = '-';

    // Build groupedItems — the insured person as enrollee
    $groupedItems = [
        [
            'enrolleeNo' => 1,
            'enrolleeName' => $enrolleeName,
            'gender' => $genderChar,
            'positionCd' => $positionCd,
            'civilStatus' => $civilStatusChar,
            'dateOfBirth' => $birthDateStored,
            'age' => $age,
            'controlTypeCd' => $controlTypeCd,
            'controlCd' => $passportNo !== '' ? $passportNo : '-',
            'remarks' => $remarks,
            'enrolleeCoverage' => $enrolleePerilArray,
            'enrolleeBeneficiary' => $beneficiaryArray
        ]
    ];

    $payload = [
        'noOfPersons' => 1,
        'travelDestination' => $travelDestination,
        'policy' => [
            'lineCd' => $lineCd,
            'sublineCd' => $sublineCd,
            'issCd' => $issCd,
            'inceptDate' => $inceptDate,
            'expiryDate' => $expiryDate,
            'assdNo' => (int) $assdNo,
            'assdAddress' => $assdAddress,
            'refPolNo' => $polRefNumber,
            'itemTitle' => $itemTitle,
            'currency' => $currency,
            'currencyRt' => $currencyRt,
            'paytTerm' => $paytTerm,
            'dueDate' => $dueDateVal,
            'refInvNo' => $refInvNo,
            'intmNo' => $intmNo,
            'userId' => $travelUserId,
            'regionCd' => $regionCd,
            'peril' => $policyPerilArray,
            'invTax' => $taxArray
        ],
        'accidentItem' => [
            'dateOfBirth' => $birthDateStored,
            'age' => $age,
            'gender' => $genderChar,
            'civilStatus' => $civilStatusChar,
            'height' => $height !== '' ? $height : '-',
            'weight' => $weight !== '' ? $weight : '-',
            'passportNo' => $passportNo !== '' ? $passportNo : '-'
        ],
        'itemBeneficiary' => $beneficiaryArray,
        'groupedItems' => $groupedItems
    ];

    return $payload;
}


/**
 * Generate a reference invoice number with pattern INV-YYYY-NNNN.
 * Uses a simple timestamp-based sequence to ensure uniqueness.
 */
function generateRefInvNo(string $inceptDate): string
{
    $parsed = parseDateInput($inceptDate);
    $year = $parsed ? $parsed->format('Y') : date('Y');
    // Use cryptographically secure random number instead of predictable time-based modulo
    $seq = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    return 'INV-' . $year . '-' . $seq;
}

/**
 * Map civil status text to single-character code for Geniisys.
 */
function mapCivilStatusToChar(string $status): string
{
    $status = strtoupper(trim($status));
    if ($status === '') {
        return 'S';
    }

    $map = [
        'SINGLE' => 'S',
        'MARRIED' => 'M',
        'DIVORCED' => 'D',
        'LEGALLY SEPARATED' => 'L',
        'WIDOW' => 'W',
        'WIDOWER' => 'W',
        'S' => 'S',
        'M' => 'M',
        'D' => 'D',
        'L' => 'L',
        'W' => 'W'
    ];

    return $map[$status] ?? substr($status, 0, 1);
}

/**
 * Build peril array from the computed premium values.
 * Uses sublineCd to determine which perils to add (PFTL vs PFTF).
 *
 * IMPORTANT: Geniisys validates that perilRate * perilTsi == perilPrem.
 * The Geniisys rate is a percentage, so we must multiply (prem/tsi) by 100.
 */
function buildPerilArrayFromPremium(?array $planUpdate, string $sublineCd): array
{
    if ($planUpdate === null) {
        return [];
    }

    // GeniiSys expects just the premium amount (with surcharges if applicable) — NOT total_premium
    // which includes taxes (wtax, dst, lgt). Those are sent separately in invTax.
    $perilPrem = isset($planUpdate['premium_amount']) ? (float) $planUpdate['premium_amount'] : 0.0;

    $planTsi = isset($planUpdate['plan_tsi']) ? (float) $planUpdate['plan_tsi'] : 0.0;

    if ($planTsi <= 0) {
        $planTsi = $perilPrem > 0 ? 1000000.0 : 0.0;
    }

    // Geniisys expects the rate as a percentage (e.g. 0.05 instead of 0.0005)
    $perilRate = $planTsi > 0 ? round(($perilPrem / $planTsi) * 100, 9) : 0.0;

    // Recalculate premium from rate * tsi to guarantee exact proportionality
    $perilPrem = round(($perilRate / 100) * $planTsi, 2);

    $planTsi = round($planTsi, 2);

    $perils = [];
    $subline = strtoupper(trim($sublineCd));

    if ($subline === 'PFTL') {
        $perils[] = [
            'perilCd' => 132,
            'perilRate' => $perilRate,
            'perilTsi' => $planTsi,
            'perilPrem' => $perilPrem
        ];
        $perils[] = [
            'perilCd' => 131,
            'perilRate' => 0.0,
            'perilTsi' => $planTsi,
            'perilPrem' => 0.0
        ];
        $perils[] = [
            'perilCd' => 133,
            'perilRate' => 0.0,
            'perilTsi' => $planTsi,
            'perilPrem' => 0.0
        ];
    } else {
        $perils[] = [
            'perilCd' => 134,
            'perilRate' => $perilRate,
            'perilTsi' => $planTsi,
            'perilPrem' => $perilPrem
        ];
        $halfTsi = round($planTsi * 0.50, 2);
        $perils[] = [
            'perilCd' => 147,
            'perilRate' => 0.0,
            'perilTsi' => $halfTsi,
            'perilPrem' => 0.0
        ];
        $perils[] = [
            'perilCd' => 137,
            'perilRate' => 0.0,
            'perilTsi' => $halfTsi,
            'perilPrem' => 0.0
        ];
    }

    return $perils;
}

/**
 * Build tax array from the computed tax values.
 * Maps staging wtax, lgt, dst to Geniisys tax codes:
 *   Premium Tax (wtax) = taxCd 2
 *   DST = taxCd 8
 *   LGT = taxCd 9
 */
function buildTaxArrayFromPremium(?array $planUpdate): array
{
    if ($planUpdate === null) {
        return [];
    }

    $taxes = [];

    // Tax code 2: Premium Tax (WTAX)
    $wtaxAmount = isset($planUpdate['wtax_amount']) ? (float) $planUpdate['wtax_amount'] : 0.0;
    if ($wtaxAmount > 0) {
        $taxes[] = [
            'taxCd' => 2,
            'taxAmt' => $wtaxAmount
        ];
    }

    // Tax code 8: DST (Documentary Stamp Tax)
    $dstAmount = isset($planUpdate['dst_amount']) ? (float) $planUpdate['dst_amount'] : 0.0;
    if ($dstAmount > 0) {
        $taxes[] = [
            'taxCd' => 8,
            'taxAmt' => $dstAmount
        ];
    }

    // Tax code 9: LGT (Local Government Tax)
    $lgtAmount = isset($planUpdate['lgt_amount']) ? (float) $planUpdate['lgt_amount'] : 0.0;
    if ($lgtAmount > 0) {
        $taxes[] = [
            'taxCd' => 9,
            'taxAmt' => $lgtAmount
        ];
    }

    return $taxes;
}

/**
 * Generate the hash key for the Travel PA API per PA_ISSUANCE_API spec.
 *
 * Hash = SHA256(SALT + value_of_attribute_1 + value_of_attribute_2 + ...)
 * Attributes are defined by GENIISYS_TRAVEL_HASH_ATTRIBUTES env, ordered.
 */
function generateTravelPaHashKey(string $salt, array $payload): string
{
    $attributesCsv = envValue('GENIISYS_TRAVEL_HASH_ATTRIBUTES', 'assdNo,inceptDate');
    $attributes = array_map('trim', explode(',', $attributesCsv));

    $hashSource = $salt;

    foreach ($attributes as $attr) {
        $value = resolvePaPayloadAttribute($payload, $attr);
        $hashSource .= (string) $value;
    }

    return strtoupper(hash('sha256', $hashSource));
}

/**
 * Resolve a named attribute from the Travel PA payload.
 * Looks in policy sub-object first, then top-level.
 */
function resolvePaPayloadAttribute(array $payload, string $attribute)
{
    // Check inside the 'policy' object first
    if (isset($payload['policy'][$attribute])) {
        return $payload['policy'][$attribute];
    }

    // Check top-level
    if (isset($payload[$attribute])) {
        return $payload[$attribute];
    }

    // Check accidentItem
    if (isset($payload['accidentItem'][$attribute])) {
        return $payload['accidentItem'][$attribute];
    }

    return '';
}

/**
 * Send the createTravelPA API request.
 * Hash is sent both as HTTP header and in the JSON body.
 */
function requestGeniisysTravelPaApi(string $url, array $payload, string $hashKey, int $timeout): array
{
    $result = [
        'ok' => false,
        'status_code' => 0,
        'body' => '',
        'json' => null,
        'error' => ''
    ];

    $payloadJson = json_encode($payload);
    if ($payloadJson === false) {
        $result['error'] = 'Unable to encode Travel PA payload.';
        return $result;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout > 0 ? $timeout : 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'hashKey: ' . $hashKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $body = curl_exec($ch);
        if ($body === false) {
            $result['error'] = curl_error($ch);
            curl_close($ch);
            return $result;
        }

        $result['status_code'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $result['body'] = (string) $body;
        $decoded = json_decode((string) $body, true);
        $result['json'] = is_array($decoded) ? $decoded : null;
        $result['ok'] = true;
        return $result;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => $timeout > 0 ? $timeout : 30,
            'header' => "hashKey: {$hashKey}\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $payloadJson,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        $result['error'] = 'HTTP request failed.';
        return $result;
    }

    $result['body'] = (string) $body;
    $decoded = json_decode((string) $body, true);
    $result['json'] = is_array($decoded) ? $decoded : null;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/[0-9.]+\s+(\d+)/', $header, $matches)) {
                $result['status_code'] = (int) $matches[1];
                break;
            }
        }
    }

    $result['ok'] = true;
    return $result;
}

/**
 * Extract Travel PA response fields from the API response.
 */
function extractTravelPaResponse(?array $responseData): ?array
{
    if (!is_array($responseData)) {
        return null;
    }

    $result = [
        'type' => $responseData['type'] ?? null,
        'policyId' => null,
        'policyNo' => null,
        'issCd' => null,
        'premSeqNo' => null,
        'salesInvoiceNo' => null,
        'particulars' => null,
        'customerName' => null
    ];

    // Extract from top-level response
    $fieldsToExtract = ['policyId', 'policyNo', 'issCd', 'premSeqNo', 'salesInvoiceNo', 'particulars', 'customerName'];
    foreach ($fieldsToExtract as $field) {
        if (isset($responseData[$field])) {
            $result[$field] = is_string($responseData[$field]) || is_numeric($responseData[$field]) ? (string) $responseData[$field] : $responseData[$field];
        }
    }

    // Also check nested 'data' if present
    if (isset($responseData['data']) && is_array($responseData['data'])) {
        $data = $responseData['data'];
        foreach ($fieldsToExtract as $field) {
            if ($result[$field] === null && isset($data[$field])) {
                $result[$field] = is_string($data[$field]) || is_numeric($data[$field]) ? (string) $data[$field] : $data[$field];
            }
        }
    }

    // Some responses nest details under a policy object
    $policyCandidates = [];
    if (isset($responseData['policy']) && is_array($responseData['policy'])) {
        $policyCandidates[] = $responseData['policy'];
    }
    if (isset($responseData['data']['policy']) && is_array($responseData['data']['policy'])) {
        $policyCandidates[] = $responseData['data']['policy'];
    }
    foreach ($policyCandidates as $policyData) {
        foreach ($fieldsToExtract as $field) {
            if ($result[$field] === null && isset($policyData[$field])) {
                $result[$field] = is_string($policyData[$field]) || is_numeric($policyData[$field]) ? (string) $policyData[$field] : $policyData[$field];
            }
        }
    }

    // Check if we got at least a type or policyNo
    $type = $result['type'] ?? '';
    if ($type === 'error' || $type === 'not valid' || $type === 'not found') {
        return $result;
    }

    return $result;
}

/**
 * Update the staging policy table with the returned Geniisys Travel PA policy info.
 */
function updatePolicyGeniisysTravelPa(
    $conn,
    string $policyTable,
    int $policyId,
    ?string $geniisysPolicyId,
    ?string $geniisysPolicyNo
): void {
    if ($policyId <= 0) {
        return;
    }

    $valueId = null;
    $status = 'Quoted';

    if ($geniisysPolicyId !== null && ctype_digit($geniisysPolicyId)) {
        $valueId = (int) $geniisysPolicyId;
        $status = 'Issued';
    }

    $valueNo = null;
    if ($geniisysPolicyNo !== null && $geniisysPolicyNo !== '') {
        $valueNo = $geniisysPolicyNo;
        $status = 'Issued';
    }

    execQuery($conn, "UPDATE {$policyTable} SET geniisys_policy_id = ?, geniisys_policy_no = ?, status = ? WHERE pol_ref_id = ?", [$valueId, $valueNo, $status, $policyId]);
}
