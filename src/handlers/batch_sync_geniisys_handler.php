<?php
declare(strict_types=1);

function handleBatchSyncGeniisys(): void
{
    // Use a reasonable timeout instead of unlimited (audit #10)
    set_time_limit(300);
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method !== 'POST' && $method !== 'GET') {
        respond(405, ['status' => 'error', 'message' => 'Method not allowed. Use POST or GET.']);
    }

    $rootDir = dirname(__DIR__, 2);
    $envPath = $rootDir . DIRECTORY_SEPARATOR . '.env';
    loadEnv($envPath);

    $rawBody = file_get_contents('php://input');
    $payload = [];
    if ($rawBody !== false && trim($rawBody) !== '') {
        $jsonPayload = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            respond(400, ['status' => 'error', 'message' => 'Invalid JSON payload. Please check for syntax errors like trailing commas.']);
        }
        if (is_array($jsonPayload)) {
            $payload = $jsonPayload;
        }
    }

    $startDateInput = $payload['start_date'] ?? $_GET['start_date'] ?? '';
    $endDateInput = $payload['end_date'] ?? $_GET['end_date'] ?? '';

    $limitInput = $payload['limit'] ?? $_GET['limit'] ?? null;

    $offsetInput = $payload['offset'] ?? $_GET['offset'] ?? 0;
    $offset = (int) $offsetInput;
    if ($offset < 0) {
        $offset = 0;
    }

    $dbHost = envValue('DB_HOST');
    $dbName = envValue('DB_DATABASE');
    $dbUser = envValue('DB_USERNAME');
    $dbPass = envValue('DB_PASSWORD');
    if ($dbHost === '' || $dbName === '') {
        respond(500, ['status' => 'error', 'message' => 'SQL Server configuration is missing.']);
    }

    $conn = connectSqlServer($dbHost, $dbName, $dbUser, $dbPass);
    if ($conn === null) {
        respond(500, ['status' => 'error', 'message' => 'Unable to connect to SQL Server.']);
    }

    $schemaPreferred = envValue('DB_SCHEMA', $dbName);
    $schema = resolveSchema($conn, $schemaPreferred);

    try {
        $policyTable = qualifyTable($schema, 'tbl_travel_policy');
        $planTypeTable = qualifyTable($schema, 'tbl_travel_epim_plan_type');
        $planPremiumTable = qualifyTable($schema, 'tbl_travel_epim_plan_premium');
        $planTable = qualifyTable($schema, 'tbl_travel_epim_plan');
        $agentTable = getAgentTableName($conn, $schema);
        $agentTableName = qualifyTable($schema, $agentTable);

        $sql = "SELECT p.*,
                       a.SUBLINE_CD as subline_cd,
                       b.PLAN_CD as plan_code,
                       b.TYPE_PREM_NO as type_prem_no,
                       c.PLAN_TSI as plan_tsi,
                       b.PREM_AMT as base_premium
                FROM {$policyTable} p
                LEFT JOIN {$planPremiumTable} b ON p.package_id = b.TYPE_PREM_NO
                LEFT JOIN {$planTypeTable} a ON a.PLAN_NO = b.PLAN_NO AND a.PLAN_CD = b.PLAN_CD AND a.DEST_ID = p.destination AND a.WITH_ADDTL = 'N'
                LEFT JOIN {$planTable} c ON a.PLAN_CD = c.PLAN_CD
                WHERE p.geniisys_policy_id IS NULL 
                  AND p.package_id IS NOT NULL 
                  AND p.pol_ref_number IS NOT NULL";

        $params = [];

        if ($startDateInput !== '' && $endDateInput !== '') {
            $startParsed = parseDateInput($startDateInput);
            $endParsed = parseDateInput($endDateInput);

            if ($startParsed && $endParsed) {
                // Ensure dates cover the whole day for generated_date
                $startStr = $startParsed->format('Y-m-d 00:00:00');
                $endStr = $endParsed->format('Y-m-d 23:59:59');

                $sql .= " AND p.generated_date >= ? AND p.generated_date <= ?";
                $params[] = $startStr;
                $params[] = $endStr;
            }
        }

        $sql .= " ORDER BY p.pol_ref_id DESC";

        if ($limitInput !== null) {
            $limit = (int) $limitInput;
            if ($limit > 0) {
                // Cap at maximum 100 records per batch to prevent resource exhaustion (audit #10)
                $limit = min($limit, 100);
                $sql .= " OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
            }
        } else {
            // Default limit to 50 if not specified (audit #10)
            $sql .= " OFFSET {$offset} ROWS FETCH NEXT 50 ROWS ONLY";
        }

        $records = fetchAll($conn, $sql, $params);

        $successful = [];
        $failed = [];

        foreach ($records as $row) {
            $policyId = (int) $row['pol_ref_id'];
            $polRefNumber = (string) $row['pol_ref_number'];
            $clientName = (string) $row['client_name'];

            try {
                // Prepare Selected Plan Data
                $selectedPlanUpdate = [
                    'plan_code' => $row['plan_code'] ?? '',
                    'type_prem_no' => $row['type_prem_no'] ?? null,
                    'subline_cd' => $row['subline_cd'] ?? '',
                    'plan_tsi' => (float) ($row['plan_tsi'] ?? 0),
                    'premium_amount' => (float) ($row['premium_amount'] ?? 0),
                    'base_premium' => (float) ($row['base_premium'] ?? 0),
                    'wtax_amount' => (float) ($row['wtax_amount'] ?? 0),
                    'lgt_amount' => (float) ($row['lgt_amount'] ?? 0),
                    'dst_amount' => (float) ($row['dst_amount'] ?? 0),
                    'service_fee' => (float) ($row['service_fee'] ?? 0),
                    'total_premium' => (float) ($row['total_premium'] ?? 0)
                ];

                $userCode = (string) ($row['user_code'] ?? '');
                $assuredIntmNo = null;

                if ($userCode !== '') {
                    $agentInfo = fetchOne(
                        $conn,
                        "SELECT INTM_NO FROM {$agentTableName} WHERE USER_CODE = ?",
                        [$userCode]
                    );
                    if ($agentInfo && isset($agentInfo['INTM_NO'])) {
                        $assuredIntmNo = (int) $agentInfo['INTM_NO'];
                    }
                }

                $travelFname = (string) ($row['client_fname'] ?? '');
                $travelLname = (string) ($row['client_lname'] ?? '');
                $travelMname = (string) ($row['client_mname'] ?? '');
                $birthDateStored = (string) ($row['birthdate'] ?? '');
                $clientGender = (string) ($row['client_gender'] ?? '');
                $travelEmail = (string) ($row['client_email'] ?? '');
                $clientHouseUnit = (string) ($row['client_house_unit_no'] ?? '');
                $clientStreet = (string) ($row['client_street_name'] ?? '');
                $clientBarangay = (string) ($row['client_barangay'] ?? '');
                $clientCity = (string) ($row['client_city'] ?? '');
                $clientProvince = (string) ($row['client_province'] ?? '');
                $clientZip = (string) ($row['client_zip_code'] ?? '');
                $clientPhone = (string) ($row['client_phone'] ?? '');
                $clientTin = (string) ($row['client_tin'] ?? '');
                $geniisysCustomerIdInput = (string) ($row['geniisys_customer_id'] ?? '');

                // Determine Age from birthdate
                $age = 0;
                $birthDateObj = parseDateInput($birthDateStored);
                if ($birthDateObj) {
                    $age = (new DateTime())->diff($birthDateObj)->y;
                }

                $departureDateStored = (string) ($row['departure_date'] ?? '');
                $returnDateStored = (string) ($row['return_date'] ?? '');
                $clientCivilStatus = (string) ($row['client_civil_status'] ?? '');
                $height = (string) ($row['height'] ?? '');
                $weight = (string) ($row['weight'] ?? '');
                $passportNo = (string) ($row['passportNo'] ?? '');
                $travelItinerary = (string) ($row['itinerary'] ?? '');
                $clientBeneficiary = (string) ($row['client_beneficiary'] ?? '');

                // 1. Sync Customer
                $geniisysSync = syncGeniisysCustomer(
                    $conn,
                    $policyTable,
                    $policyId,
                    $travelFname,
                    $travelLname,
                    $travelMname,
                    $birthDateStored,
                    $clientGender,
                    $travelEmail,
                    $clientHouseUnit,
                    $clientStreet,
                    $clientBarangay,
                    $clientCity,
                    $clientProvince,
                    $clientZip,
                    $clientPhone,
                    $clientTin,
                    $assuredIntmNo,
                    $geniisysCustomerIdInput
                );

                if (
                    $geniisysSync !== null &&
                    ($geniisysSync['status'] === 'created' || $geniisysSync['status'] === 'reused') &&
                    !empty($geniisysSync['geniisys_customer_id'])
                ) {
                    // 2. Sync Travel PA Policy
                    $travelPaSync = syncGeniisysTravelPA(
                        $conn,
                        $policyTable,
                        $policyId,
                        (string) $geniisysSync['geniisys_customer_id'],
                        $polRefNumber,
                        $departureDateStored,
                        $returnDateStored,
                        $birthDateStored,
                        $age,
                        $clientGender,
                        $clientCivilStatus,
                        $height,
                        $weight,
                        $passportNo,
                        $travelItinerary,
                        $clientBeneficiary,
                        $assuredIntmNo,
                        $clientHouseUnit,
                        $clientStreet,
                        $clientBarangay,
                        $clientCity,
                        $clientProvince,
                        $clientZip,
                        $selectedPlanUpdate,
                        $clientName,
                        $travelEmail,
                        $clientPhone,
                        $travelFname,
                        $travelMname,
                        $travelLname
                    );

                    if ($travelPaSync !== null && $travelPaSync['status'] === 'created') {
                        $successful[] = [
                            'pol_ref_number' => $polRefNumber,
                            'client_name' => $clientName,
                            'geniisys_customer_id' => $geniisysSync['geniisys_customer_id'] ?? null,
                            'geniisys_customer_status' => $geniisysSync['status'] ?? 'unknown',
                            'geniisys_policy_id' => $travelPaSync['geniisys_policy_id'] ?? null,
                            'geniisys_policy_no' => $travelPaSync['geniisys_policy_no'] ?? null,
                            'salesInvoiceNo' => $travelPaSync['salesInvoiceNo'] ?? null
                        ];
                    } else {
                        $failed[] = [
                            'pol_ref_number' => $polRefNumber,
                            'client_name' => $clientName,
                            'error' => $travelPaSync['message'] ?? 'Failed to create Travel PA policy.',
                            'api_response' => $travelPaSync['api_response'] ?? null
                        ];
                    }
                } else {
                    $errorMsg = $geniisysSync['message'] ?? 'Failed to create Geniisys customer.';
                    $apiResponse = $geniisysSync['debug']['api_response'] ?? null;

                    $failed[] = [
                        'pol_ref_number' => $polRefNumber,
                        'client_name' => $clientName,
                        'error' => $geniisysSync['message'] ?? 'Failed to create Geniisys customer.',
                        'api_response' => $geniisysSync['api_response'] ?? null
                    ];
                }

            } catch (Throwable $e) {
                $failed[] = [
                    'pol_ref_number' => $polRefNumber,
                    'client_name' => $clientName,
                    'error' => 'Exception: ' . $e->getMessage()
                ];
            }
        }

        respond(200, [
            'status' => 'ok',
            'processed_count' => count($records),
            'successful' => $successful,
            'failed' => $failed
        ]);

    } catch (Throwable $e) {
        // Log full details server-side but return generic message to client (audit #5)
        error_log('Batch Sync Geniisys API error [' . $e->getFile() . ':' . $e->getLine() . ']: ' . $e->getMessage());
        respond(500, ['status' => 'error', 'message' => 'An unexpected error occurred during batch sync.']);
    } finally {
        if ($conn) {
            sqlsrv_close($conn);
        }
    }
}
