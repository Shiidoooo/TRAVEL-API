<?php
declare(strict_types=1);

// Travel quote API: computes premiums and inserts policy requests into SQL Server.
function handleTravelQuote(bool $skipSync = false): void
{
    $conn = null;

    $method = $_SERVER['REQUEST_METHOD'];
    if ($method !== 'POST' && $method !== 'GET') {
        respond(405, ['status' => 'error', 'message' => 'Method not allowed. Use GET or POST.']);
    }

    $rootDir = dirname(__DIR__, 2);
    $envPath = $rootDir . DIRECTORY_SEPARATOR . '.env';
    loadEnv($envPath);

    $rawBody = file_get_contents('php://input');
    $mode = ($method === 'GET') ? 'plans' : 'submit';

    if ($method === 'GET') {
        $payload = $_GET;
        if ($rawBody !== false && trim($rawBody) !== '') {
            $jsonPayload = json_decode($rawBody, true);
            if (!is_array($jsonPayload)) {
                respond(400, ['status' => 'error', 'message' => 'Invalid JSON payload.']);
            }

            $payload = array_merge($payload, $jsonPayload);
        }
    } else {
        if ($rawBody === false || trim($rawBody) === '') {
            respond(400, ['status' => 'error', 'message' => 'Empty request body.']);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            respond(400, ['status' => 'error', 'message' => 'Invalid JSON payload.']);
        }
    }

    $selectedPlan = trim((string) ($payload['selected_plan'] ?? ''));
    $selectedPackageIdRaw = $payload['selected_package_id'] ?? null;
    if ($selectedPackageIdRaw === null || $selectedPackageIdRaw === '') {
        $selectedPackageIdRaw = $payload['package_id'] ?? null;
    }
    $selectedPackageId = null;
    if ($selectedPackageIdRaw !== null && $selectedPackageIdRaw !== '') {
        $selectedPackageId = ctype_digit((string) $selectedPackageIdRaw) ? (int) $selectedPackageIdRaw : null;
    }

    $required = ($mode === 'plans')
        ? [
            'travel_destination',
            'travel_departure_date',
            'travel_return_date',
            'travel_birthdate'
        ]
        : [
            'travel_plan_type',
            'travel_destination',
            'travel_departure_date',
            'travel_return_date',
            'travel_birthdate',
            'travel_to_itinerary',
            'travel_fname',
            'travel_lname',
            'travel_email'
        ];

    $missing = [];
    foreach ($required as $key) {
        $value = $payload[$key] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $missing[] = $key;
        }
    }
    if (!empty($missing)) {
        respond(400, ['status' => 'error', 'message' => 'Missing required fields.', 'missing' => $missing]);
    }
    if ($mode === 'submit' && $selectedPlan === '' && $selectedPackageId === null) {
        respond(400, ['status' => 'error', 'message' => 'selected_plan or selected_package_id is required in submit mode.']);
    }

    $travelPlanType = (string) ($payload['travel_plan_type'] ?? '');
    $travelDestination = (int) ($payload['travel_destination'] ?? 0);
    $travelDeparture = (string) ($payload['travel_departure_date'] ?? '');
    $travelReturn = (string) ($payload['travel_return_date'] ?? '');
    $travelBirthdate = (string) ($payload['travel_birthdate'] ?? '');
    $travelItinerary = (string) ($payload['travel_to_itinerary'] ?? '');
    $travelFname = trim((string) ($payload['travel_fname'] ?? ''));
    $travelMname = trim((string) ($payload['travel_mname'] ?? ''));
    $travelLname = trim((string) ($payload['travel_lname'] ?? ''));
    $travelSuffixId = $payload['travel_suffix'] ?? null;
    $travelEmail = trim((string) ($payload['travel_email'] ?? ''));
    $utmSource = trim((string) ($payload['utm_source'] ?? ''));

    // Validate email format (audit #11)
    if ($mode === 'submit' && $travelEmail !== '' && !filter_var($travelEmail, FILTER_VALIDATE_EMAIL)) {
        respond(400, ['status' => 'error', 'message' => 'Invalid email address format.']);
    }

    if ($travelDestination <= 0) {
        respond(400, ['status' => 'error', 'message' => 'travel_destination must be a positive integer.']);
    }
    $packageId = $payload['package_id'] ?? null;
    $totalTsi = (float) ($payload['total_tsi'] ?? 0);
    $isFamily = (bool) ($payload['is_family'] ?? false);

    // Always default to 'Quoted' upon initial save.
    // The sync functions will upgrade this to 'Issued' if GeniiSys successfully generates a policy.
    $status = 'Quoted';

    $clientCivilStatus = trim((string) ($payload['client_civil_status'] ?? ''));
    $clientCivilStatusId = $payload['client_civil_status_id'] ?? null;
    $clientGender = trim((string) ($payload['client_gender'] ?? ''));
    $clientNationality = trim((string) ($payload['client_nationality'] ?? ''));
    $clientTin = trim((string) ($payload['client_tin'] ?? ''));
    $clientPhone = trim((string) ($payload['client_phone'] ?? ''));

    // Validate phone format — accept PH mobile (09xxxxxxxxx) or with +63 prefix (audit #11)
    if ($mode === 'submit' && $clientPhone !== '' && !preg_match('/^(\+63|0)9\d{9}$/', $clientPhone)) {
        respond(400, ['status' => 'error', 'message' => 'Invalid phone number format. Expected PH mobile format (e.g., 09171234567).']);
    }
    $clientHouseUnit = trim((string) ($payload['client_house_unit_no'] ?? ''));
    $clientStreet = trim((string) ($payload['client_street_name'] ?? ''));
    $clientBarangay = trim((string) ($payload['client_barangay'] ?? ''));
    $clientCity = trim((string) ($payload['client_city'] ?? ''));
    $clientProvince = trim((string) ($payload['client_province'] ?? ''));
    $clientZip = trim((string) ($payload['client_zip_code'] ?? ''));
    $clientBeneficiary = trim((string) ($payload['client_beneficiary'] ?? ''));
    $clientBeneficiaryPhone = trim((string) ($payload['client_beneficiary_phone'] ?? ''));
    $height = trim((string) ($payload['height'] ?? ''));
    $weight = trim((string) ($payload['weight'] ?? ''));
    $passportNo = trim((string) ($payload['passportNo'] ?? ''));
    $geniisysCustomerIdInput = trim((string) ($payload['geniisys_customer_id'] ?? ''));
    $geniisysCustomerId = '';

    $selectedSurcharges = $payload['selected_surcharge'] ?? [];
    if (!is_array($selectedSurcharges)) {
        respond(400, ['status' => 'error', 'message' => 'selected_surcharge must be an array of IDs.']);
    }
    $selectedSurcharges = array_values(array_unique(array_filter(array_map(function ($value) {
        $value = trim((string) $value);
        return ctype_digit($value) ? (int) $value : null;
    }, $selectedSurcharges), function ($value) {
        return $value !== null && $value > 0;
    })));

    $userCode = trim((string) ($payload['user_code'] ?? ''));
    $userNo = trim((string) ($payload['user_no'] ?? ''));
    $isGuest = ($userCode === '' || $userNo === '');

    if ($travelSuffixId !== null && $travelSuffixId !== '') {
        $travelSuffixId = ctype_digit((string) $travelSuffixId) ? (int) $travelSuffixId : null;
    } else {
        $travelSuffixId = null;
    }

    if (is_string($packageId) && trim($packageId) === '') {
        $packageId = null;
    }

    $birthDate = parseDateInput($travelBirthdate);
    $startDate = parseDateInput($travelDeparture);
    $endDate = parseDateInput($travelReturn);
    if (!$birthDate || !$startDate || !$endDate) {
        respond(400, ['status' => 'error', 'message' => 'Invalid date format.']);
    }
    $startDate->setTime(0, 0, 0);
    $endDate->setTime(0, 0, 0);
    if ($endDate < $startDate) {
        respond(400, ['status' => 'error', 'message' => 'Return date cannot be earlier than departure date.']);
    }

    $departureDateStored = $startDate->format('m/d/Y');
    $returnDateStored = $endDate->format('m/d/Y');
    $birthDateStored = $birthDate->format('m/d/Y');

    $age = (new DateTime())->diff($birthDate)->y;
    $travelDays = $startDate->diff($endDate)->days + 1;
    $maxDays = 180;
    $noOfDays = min($travelDays, $maxDays);
    $diffNoOfDays = max($travelDays - $maxDays, 0);

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
    $defaultParentIntmNo = (int) envValue('DEFAULT_PARENT_INTM_NO', '79');

    try {
        $policyTable = qualifyTable($schema, 'tbl_travel_policy');
        $policySurchargeTable = qualifyTable($schema, 'tbl_policy_surcharge');
        $surchargeTable = qualifyTable($schema, 'tbl_surcharge');
        $suffixTable = qualifyTable($schema, 'tbl_suffix');
        $serviceFeeTable = qualifyTable($schema, 'tbl_service_fee');
        $civilStatusTable = qualifyTable($schema, 'tbl_civil_status');
        $planTypeTable = qualifyTable($schema, 'tbl_travel_epim_plan_type');
        $planPremiumTable = qualifyTable($schema, 'tbl_travel_epim_plan_premium');
        $planTable = qualifyTable($schema, 'tbl_travel_epim_plan');
        $dstTable = qualifyTable($schema, 'tbl_dst');
        $taxTable = qualifyTable($schema, 'tbl_tax');
        $rateTable = qualifyTable($schema, 'tbl_travel_rate_by_age');

        $agentTable = getAgentTableName($conn, $schema);
        $agentTableName = qualifyTable($schema, $agentTable);

        if ($clientCivilStatus === '' && $clientCivilStatusId !== null && ctype_digit((string) $clientCivilStatusId)) {
            $civilRow = fetchOne($conn, "SELECT civil_status FROM {$civilStatusTable} WHERE id = ?", [(int) $clientCivilStatusId]);
            if ($civilRow && isset($civilRow['civil_status'])) {
                $clientCivilStatus = (string) $civilRow['civil_status'];
            }
        }

        $parentIntmNo = null;
        $agentIntmNo = null;
        $travelRate = 1.00;
        $serviceFeeRate = null;
        $parentIntmSfRate = null;

        if (!$isGuest) {
            $agentInfo = fetchOne(
                $conn,
                "SELECT PARENT_INTM_NO, TRAVEL_RATE, SERVICE_FEE, INTM_NO FROM {$agentTableName} WHERE USER_CODE = ?",
                [$userCode]
            );
            if ($agentInfo) {
                $parentIntmNo = $agentInfo['PARENT_INTM_NO'] ?? null;
                $travelRate = isset($agentInfo['TRAVEL_RATE']) ? (float) $agentInfo['TRAVEL_RATE'] : $travelRate;
                $agentIntmNo = $agentInfo['INTM_NO'] ?? null;
            }

            $sfInfo = fetchOne(
                $conn,
                "SELECT intm_sf_rate, parent_intm_sf_rate FROM {$serviceFeeTable} WHERE intm_no = ?",
                [$userNo]
            );
            if ($sfInfo) {
                $serviceFeeRate = $sfInfo['intm_sf_rate'] ?? null;
                $parentIntmSfRate = $sfInfo['parent_intm_sf_rate'] ?? null;
            }
        }

        $parentIntmNo = $parentIntmNo ?? $defaultParentIntmNo;
        $assuredIntmNo = $agentIntmNo !== null ? (int) $agentIntmNo : null;
        $polRefNumber = null;
        $policyId = 0;
        $fullName = '';

        if ($mode === 'submit') {
            $suffixText = '';
            if ($travelSuffixId !== null) {
                $suffixRow = fetchOne($conn, "SELECT suffix FROM {$suffixTable} WHERE id = ?", [$travelSuffixId]);
                if ($suffixRow && isset($suffixRow['suffix'])) {
                    $suffixText = (string) $suffixRow['suffix'];
                }
            }

            $fullName = implode(' ', array_filter([$travelFname, $travelMname, $travelLname, $suffixText]));

            if (!$isGuest) {
                $dupRow = fetchOne(
                    $conn,
                    "SELECT TOP 1 pol_ref_id FROM {$policyTable} WHERE client_name = ? AND client_email = ? AND user_code <> ?",
                    [$fullName, $travelEmail, $userCode]
                );
                if ($dupRow) {
                    respond(409, ['status' => 'error', 'message' => 'Duplicate client.']);
                }
            }

            $intmNoForPolicy = $agentIntmNo !== null ? (int) $agentIntmNo : $parentIntmNo;
            $polRefNumber = generatePolicyNumberFromPolicy($conn, $policyTable, $intmNoForPolicy);

            $generatedDate = (new DateTime())->format('Y-m-d H:i:s');
            $insertSql = "INSERT INTO {$policyTable} (
                pol_ref_number,
                user_code,
                parent_intm_no,
                plan_type,
                destination,
                departure_date,
                return_date,
                birthdate,
                itinerary,
                client_name,
                client_fname,
                client_mname,
                client_lname,
                client_suffix,
                client_civil_status,
                client_email,
                client_gender,
                client_nationality,
                client_tin,
                client_phone,
                client_house_unit_no,
                client_street_name,
                client_barangay,
                client_city,
                client_province,
                client_zip_code,
                client_beneficiary,
                client_beneficiary_phone,
                package_id,
                total_premium,
                premium_amount,
                lgt_amount,
                wtax_amount,
                dst_amount,
                service_fee,
                generated_date,
                status,
                height,
                weight,
                passportNo,
                geniisys_customer_id,
                geniisys_policy_id,
                geniisys_policy_no
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $insertParams = [
                $polRefNumber,
                $isGuest ? null : $userCode,
                $parentIntmNo,
                $travelPlanType,
                $travelDestination,
                $departureDateStored,
                $returnDateStored,
                $birthDateStored,
                $travelItinerary,
                $fullName,
                $travelFname,
                nullIfEmpty($travelMname),
                $travelLname,
                $travelSuffixId,
                nullIfEmpty($clientCivilStatus),
                $travelEmail,
                nullIfEmpty($clientGender),
                nullIfEmpty($clientNationality),
                nullIfEmpty($clientTin),
                nullIfEmpty($clientPhone),
                nullIfEmpty($clientHouseUnit),
                nullIfEmpty($clientStreet),
                nullIfEmpty($clientBarangay),
                nullIfEmpty($clientCity),
                nullIfEmpty($clientProvince),
                nullIfEmpty($clientZip),
                nullIfEmpty($clientBeneficiary),
                nullIfEmpty($clientBeneficiaryPhone),
                $packageId,
                null,
                null,
                null,
                null,
                null,
                null,
                $generatedDate,
                nullIfEmpty($status),
                nullIfEmpty($height),
                nullIfEmpty($weight),
                nullIfEmpty($passportNo),
                nullIfEmpty($geniisysCustomerId),
                null,
                null
            ];

            execQuery($conn, $insertSql, $insertParams);
            $policyRow = fetchOne($conn, 'SELECT CAST(SCOPE_IDENTITY() AS int) AS id');
            $policyId = $policyRow ? (int) $policyRow['id'] : 0;

            if ($policyId <= 0 && $polRefNumber !== null) {
                $policyLookup = fetchOne(
                    $conn,
                    "SELECT pol_ref_id FROM {$policyTable} WHERE pol_ref_number = ?",
                    [$polRefNumber]
                );
                $policyId = $policyLookup ? (int) $policyLookup['pol_ref_id'] : 0;
            }

            if ($policyId > 0 && !empty($selectedSurcharges)) {
                savePolicySurcharges($conn, $policySurchargeTable, $surchargeTable, $policyId, $selectedSurcharges);
            }
        }

        $selectedSurchargeRate = getSelectedSurchargeRate($conn, $surchargeTable, $selectedSurcharges);

        // Fetch individual surcharge details for clean output
        $selectedSurchargeDetails = [];
        if (!empty($selectedSurcharges)) {
            $surchargeRows = getSelectedSurchargeRows($conn, $surchargeTable, $selectedSurcharges);
            foreach ($surchargeRows as $sRow) {
                $selectedSurchargeDetails[] = [
                    'id' => (int) $sRow['id'],
                    'surcharge' => (string) $sRow['surcharge'],
                    'percent_value' => (float) $sRow['percent_value']
                ];
            }
        }

        $planSql = "SELECT a.DEST_ID, a.SUBLINE_CD, c.PLAN_DESC, c.PLAN_DTL, a.WITH_ADDTL, b.PLAN_CD, b.PLAN_NO, b.MIN_DURATION, b.MAX_DURATION, b.PREM_AMT, c.PLAN_TSI, b.TYPE_PREM_NO
            FROM {$planTypeTable} a
            JOIN {$planPremiumTable} b ON a.PLAN_NO = b.PLAN_NO AND a.PLAN_CD = b.PLAN_CD
            JOIN {$planTable} c ON a.PLAN_CD = c.PLAN_CD
            WHERE a.DEST_ID = ?
              AND a.WITH_ADDTL = 'N'
              AND (? BETWEEN b.MIN_DURATION AND b.MAX_DURATION OR ? = b.MIN_DURATION OR ? = b.MAX_DURATION)";

        $plans = fetchAll($conn, $planSql, [$travelDestination, $noOfDays, $noOfDays, $noOfDays]);
        if (empty($plans)) {
            respond(404, ['status' => 'error', 'message' => 'No plans found for the given destination and travel days.']);
        }

        $ageRate = calculateTravelRateByAge($conn, $rateTable, $age);
        $planResults = [];
        $planMap = [];
        $packageMap = [];
        foreach ($plans as $plan) {
            $planCode = (string) $plan['PLAN_CD'];
            $planKey = strtoupper($planCode);
            $typePremNo = isset($plan['TYPE_PREM_NO']) ? (int) $plan['TYPE_PREM_NO'] : null;
            $planSublineCd = isset($plan['SUBLINE_CD']) ? (string) $plan['SUBLINE_CD'] : '';
            $planDesc = (string) $plan['PLAN_DESC'];
            $planDesc = (string) $plan['PLAN_DESC'];
            $planDtl = isset($plan['PLAN_DTL']) ? (string) $plan['PLAN_DTL'] : '';
            $planTsi = isset($plan['PLAN_TSI']) ? (float) $plan['PLAN_TSI'] : 0.0;
            $basePremium = isset($plan['PREM_AMT']) ? (float) $plan['PREM_AMT'] : 0.0;
            $rawPremAmt = $basePremium;
            $serviceAmt = 0.0;

            if ($serviceFeeRate !== null) {
                $lesspl = $parentIntmSfRate !== null ? ($basePremium * (float) $parentIntmSfRate) : 0.0;
                $basePremium -= $lesspl;
                $serviceAmt = $basePremium * (float) $serviceFeeRate;
                $basePremium -= $serviceAmt;
                $serviceAmt += $lesspl;
            }

            // premium_amount = basePremium (potentially reduced by service fee) * travel_rate
            $premiumAmount = round($basePremium * $travelRate, 2);

            // Apply selected surcharges to the premium
            if ($selectedSurchargeRate > 0) {
                $surchargeAmount = round($premiumAmount * $selectedSurchargeRate, 2);
                $premiumAmount += $surchargeAmount;
                
                // Also scale the raw premium used for the GeniiSys payload to maintain correct proportions
                $rawPremAmt += round($rawPremAmt * $selectedSurchargeRate, 2);
            }

            $dst = getDSTByTSI($conn, $dstTable, $planTsi);
            $wtax = calculatePremiumTax($conn, $taxTable, $premiumAmount);
            $lgt = calculateLgtTax($conn, $taxTable, $premiumAmount);

            // total_premium = premium_amount + wtax + lgt + dst + service_fee
            $totalPremium = round($premiumAmount + $wtax + $lgt + $dst + $serviceAmt, 2);

            $planMap[$planKey] = [
                'plan_code' => $planCode,
                'type_prem_no' => $typePremNo,
                'subline_cd' => $planSublineCd,
                'plan_tsi' => $planTsi,
                'plan_dtl' => $planDtl,
                'premium_amount' => $premiumAmount,
                'base_premium' => $totalPremium, // Send total_premium to GeniiSys per request
                'wtax_amount' => $wtax,
                'lgt_amount' => $lgt,
                'dst_amount' => $dst,
                'service_fee' => $serviceAmt,
                'total_premium' => $totalPremium
            ];

            if ($typePremNo !== null) {
                $packageMap[$typePremNo] = $planMap[$planKey];
            }

            $planResults[] = [
                'plan_code' => $planCode,
                'plan_desc' => $planDesc,
                'plan_tsi' => number_format($planTsi, 2, '.', ''),
                'premium_amount' => number_format($premiumAmount, 2, '.', ''),
                'wtax_amount' => number_format($wtax, 2, '.', ''),
                'lgt_amount' => number_format($lgt, 2, '.', ''),
                'dst_amount' => number_format($dst, 2, '.', ''),
                'service_fee' => number_format($serviceAmt, 2, '.', ''),
                'total_premium' => number_format($totalPremium, 2, '.', ''),
                'type_prem_no' => $typePremNo,
                'package_id' => $typePremNo
            ];
        }

        if ($mode === 'plans') {
            respond(200, [
                'status' => 'ok',
                'mode' => 'plans',
                'age' => $age,
                'travel_days' => $travelDays,
                'no_of_days' => $noOfDays,
                'selected_surcharge_rate' => $selectedSurchargeRate,
                'plans' => $planResults
            ]);
        }

        $selectedPlanUpdate = null;
        if ($selectedPackageId !== null) {
            if (!isset($packageMap[$selectedPackageId])) {
                respond(404, ['status' => 'error', 'message' => "Selected package_id ($selectedPackageId) not found for the given destination and travel days. Available: " . implode(', ', array_keys($packageMap))]);
            }
            $selectedPlanUpdate = $packageMap[$selectedPackageId];
            $selectedPlan = $selectedPlanUpdate['plan_code'] ?? $selectedPlan;
        } elseif ($selectedPlan !== '') {
            $selectedKey = strtoupper($selectedPlan);
            if (!isset($planMap[$selectedKey])) {
                respond(404, ['status' => 'error', 'message' => 'Selected plan not found for the given destination and travel days.']);
            }
            $selectedPlanUpdate = $planMap[$selectedKey];
        }

        if ($selectedPlanUpdate !== null) {
            // Validate surcharge selection based on subline
            $sublineCd = strtoupper((string) ($selectedPlanUpdate['subline_cd'] ?? ''));
            if ($sublineCd === 'PFTL') {
                foreach ($selectedSurcharges as $surchargeId) {
                    if ($surchargeId !== 3) {
                        respond(400, ['status' => 'error', 'message' => 'Only Cruise surcharge is allowed for Local Travel (PFTL).']);
                    }
                }
            }

            if ($selectedPackageId !== null) {
                $packageId = $selectedPackageId;
            }
            $packageId = $selectedPlanUpdate['type_prem_no'] ?? $packageId;

            if ($policyId > 0) {
                $updateSql = "UPDATE {$policyTable} SET package_id = ?, total_premium = ?, premium_amount = ?, lgt_amount = ?, wtax_amount = ?, dst_amount = ?, service_fee = ?";
                $updateParams = [
                    $packageId,
                    $selectedPlanUpdate['total_premium'],
                    $selectedPlanUpdate['premium_amount'],
                    $selectedPlanUpdate['lgt_amount'],
                    $selectedPlanUpdate['wtax_amount'],
                    $selectedPlanUpdate['dst_amount'],
                    $selectedPlanUpdate['service_fee']
                ];

                if ($status !== '') {
                    $updateSql .= ', status = ?';
                    $updateParams[] = $status;
                }

                $updateSql .= ' WHERE pol_ref_id = ?';
                $updateParams[] = $policyId;
                execQuery($conn, $updateSql, $updateParams);
            } elseif ($polRefNumber !== null) {
                $updateSql = "UPDATE {$policyTable} SET package_id = ?, total_premium = ?, premium_amount = ?, lgt_amount = ?, wtax_amount = ?, dst_amount = ?, service_fee = ?";
                $updateParams = [
                    $packageId,
                    $selectedPlanUpdate['total_premium'],
                    $selectedPlanUpdate['premium_amount'],
                    $selectedPlanUpdate['lgt_amount'],
                    $selectedPlanUpdate['wtax_amount'],
                    $selectedPlanUpdate['dst_amount'],
                    $selectedPlanUpdate['service_fee']
                ];

                if ($status !== '') {
                    $updateSql .= ', status = ?';
                    $updateParams[] = $status;
                }

                $updateSql .= ' WHERE pol_ref_number = ?';
                $updateParams[] = $polRefNumber;
                execQuery($conn, $updateSql, $updateParams);
            } else {
                respond(500, ['status' => 'error', 'message' => 'Unable to update policy totals.']);
            }
        }

        $geniisysSync = null;
        $travelPaSync = null;
        if ($mode === 'submit' && $policyId > 0 && !$skipSync) {
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

            // After customer sync succeeds, call createTravelPA with the assdNo
            if (
                $geniisysSync !== null &&
                ($geniisysSync['status'] === 'created' || $geniisysSync['status'] === 'reused') &&
                !empty($geniisysSync['geniisys_customer_id'])
            ) {

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
                    $fullName,
                    $travelEmail,
                    $clientPhone,
                    $travelFname,
                    $travelMname,
                    $travelLname,
                    $selectedSurcharges,
                    $sublineCd
                );
            }
        }

        $response = [
            'status' => 'ok',
            'mode' => $mode,
            'policy_id' => $policyId,
            'pol_ref_number' => $polRefNumber,
            'age' => $age,
            'travel_days' => $travelDays,
            'no_of_days' => $noOfDays,
            'selected_surcharges' => $selectedSurchargeDetails
        ];

        if ($mode === 'plans') {
            // GET mode: return all available plans
            $response['plans'] = $planResults;
        } elseif ($selectedPlanUpdate !== null) {
            // POST/submit mode: return only the selected plan
            $matchedPlanDesc = '';
            foreach ($planResults as $pr) {
                if (isset($pr['package_id']) && $pr['package_id'] === $packageId) {
                    $matchedPlanDesc = $pr['plan_desc'] ?? '';
                    break;
                }
            }
            $response['selected_plan'] = [
                'plan_code' => $selectedPlan,
                'plan_desc' => $matchedPlanDesc,
                'package_id' => $packageId,
                'plan_tsi' => number_format($selectedPlanUpdate['plan_tsi'], 2, '.', ''),
                'premium_amount' => number_format($selectedPlanUpdate['premium_amount'], 2, '.', ''),
                'wtax_amount' => number_format($selectedPlanUpdate['wtax_amount'], 2, '.', ''),
                'lgt_amount' => number_format($selectedPlanUpdate['lgt_amount'], 2, '.', ''),
                'dst_amount' => number_format($selectedPlanUpdate['dst_amount'], 2, '.', ''),
                'service_fee' => number_format($selectedPlanUpdate['service_fee'], 2, '.', ''),
                'total_premium' => number_format($selectedPlanUpdate['total_premium'], 2, '.', '')
            ];
        }

        if ($geniisysSync !== null) {
            $response['geniisys_sync'] = $geniisysSync;
        }

        if ($travelPaSync !== null) {
            $response['geniisys_travel_pa'] = $travelPaSync;
        }

        respond(200, $response);
    } catch (Throwable $e) {
        // Log full details server-side but return generic message to client (audit #5)
        error_log('Travel quote API error [' . $e->getFile() . ':' . $e->getLine() . ']: ' . $e->getMessage());
        respond(500, ['status' => 'error', 'message' => 'An unexpected error occurred. Please try again later.']);
    } finally {
        if ($conn) {
            sqlsrv_close($conn);
        }
    }
}
