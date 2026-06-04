<?php
declare(strict_types=1);

function getSelectedSurchargeRows($conn, string $surchargeTable, array $surchargeIds): array
{
    if (empty($surchargeIds)) {
        return [];
    }

    $placeholders = buildInClause($surchargeIds);
    $sql = "SELECT id, surcharge, percent_value FROM {$surchargeTable} WHERE id IN ({$placeholders})";

    return fetchAll($conn, $sql, $surchargeIds);
}

function getSelectedSurchargeRate($conn, string $surchargeTable, array $surchargeIds): float
{
    $rows = getSelectedSurchargeRows($conn, $surchargeTable, $surchargeIds);
    if (empty($rows)) {
        return 0.0;
    }

    $totalRate = 0.0;
    foreach ($rows as $row) {
        $raw = isset($row['percent_value']) ? (string) $row['percent_value'] : '0';
        $clean = str_replace('%', '', trim($raw));
        if ($clean === '' || !is_numeric($clean)) {
            continue;
        }
        $value = (float) $clean;
        $totalRate += ($value > 1) ? ($value / 100) : $value;
    }

    return $totalRate;
}

function savePolicySurcharges($conn, string $policySurchargeTable, string $surchargeTable, int $policyId, array $surchargeIds): void
{
    if ($policyId <= 0) {
        return;
    }

    execQuery($conn, "DELETE FROM {$policySurchargeTable} WHERE pol_ref_id = ?", [$policyId]);

    $rows = getSelectedSurchargeRows($conn, $surchargeTable, $surchargeIds);
    if (empty($rows)) {
        return;
    }

    $insertSql = "INSERT INTO {$policySurchargeTable} (pol_ref_id, surcharge_id, surcharge_label, percent_value) VALUES (?, ?, ?, ?)";
    foreach ($rows as $row) {
        $params = [
            $policyId,
            (int) $row['id'],
            (string) $row['surcharge'],
            (float) $row['percent_value']
        ];
        execQuery($conn, $insertSql, $params);
    }
}

function getDSTByTSI($conn, string $dstTable, float $tsi): float
{
    $row = fetchOne($conn, "SELECT tax_amount FROM {$dstTable} WHERE min_value <= ? AND max_value >= ?", [$tsi, $tsi]);
    if (!$row || !isset($row['tax_amount'])) {
        return 0.0;
    }

    return round((float) $row['tax_amount'], 2);
}

function calculateTravelRateByAge($conn, string $rateTable, int $age): float
{
    $row = fetchOne($conn, "SELECT travel_rate FROM {$rateTable} WHERE min_value <= ? AND max_value >= ?", [$age, $age]);
    if (!$row || !isset($row['travel_rate'])) {
        return 1.0;
    }

    return (float) $row['travel_rate'];
}

function calculatePremiumTax($conn, string $taxTable, float $premium): float
{
    $row = fetchOne($conn, "SELECT tax_rate FROM {$taxTable} WHERE tax_cd = 2", []);
    if (!$row || !isset($row['tax_rate'])) {
        return 0.0;
    }

    $rate = (float) $row['tax_rate'];
    return round($premium * ($rate / 100), 2);
}

function calculateLgtTax($conn, string $taxTable, float $premium): float
{
    $row = fetchOne($conn, "SELECT tax_rate FROM {$taxTable} WHERE tax_cd = 9", []);
    if (!$row || !isset($row['tax_rate'])) {
        return 0.0;
    }

    $rate = (float) $row['tax_rate'];
    return round($premium * ($rate / 100), 2);
}

function calculateAdditionalPremiumBy10Days(float $noOfDays, float $additionalPremium): float
{
    $completePeriods = floor($noOfDays / 10);
    $remainingDays = $noOfDays % 10;
    $total = $completePeriods * $additionalPremium;

    if ($remainingDays > 0) {
        $total += $additionalPremium;
    }

    return round($total, 2);
}

function getAdditionalPremium($conn, string $planTable, string $planCode): float
{
    $row = fetchOne($conn, "SELECT PLAN_ADDITIONAL FROM {$planTable} WHERE PLAN_CD = ?", [$planCode]);
    if (!$row || !isset($row['PLAN_ADDITIONAL'])) {
        return 0.0;
    }

    return (float) $row['PLAN_ADDITIONAL'];
}
