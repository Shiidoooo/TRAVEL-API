# Travel Quote API Endpoints

This document outlines the available endpoints for the Travel Quote system, including how to use them and which JSON payloads they expect.

---

## 1. Main Travel Quote API (Fetch Plans)
**URL:** `http://localhost/test/API/travel_quote.php`
**Method:** `GET`

**Purpose:** 
Used to fetch available travel plans, premium amounts, and package details without saving a policy.

**URL SAMPLE WITH QUERY PARAMETERS:**
```
http://localhost/test/API/travel_quote.php?travel_destination=4&travel_departure_date=12/19/2026&travel_return_date=12/29/2026&travel_birthdate=05/10/1990
```

**Payload to use (as URL query parameters or JSON body):**
```json
{
  "travel_destination": 4,
  "travel_departure_date": "12/19/2026",
  "travel_return_date": "12/29/2026",
  "travel_birthdate": "05/10/1990"
}
```

---

## 2. Main Travel Quote API (SQL Server + GeniiSys Sync)
**URL:** `http://localhost/test/API/travel_quote_geniisys.php`
**Method:** `POST`

**Purpose:** 
This is the primary production endpoint. It calculates the premium, saves the policy details into the SQL Server database (`tbl_travel_policy`), and immediately calls the GeniiSys API to generate the `geniisys_customer_id`, `geniisys_policy_id`, and `geniisys_policy_no`.

**Payload to use:**
Use the comprehensive JSON payload found in `insomnia_geniisys_travel_api_sample.json`.
*Example:*
```json
{
  "travel_plan_type": 1,
  "travel_destination": 4,
  "travel_departure_date": "12/19/2026",
  "travel_return_date": "12/29/2026",
  "travel_birthdate": "05/10/1990",
  "travel_to_itinerary": "PHILIPPINES - CHINA",
  "travel_fname": "Henrich Kem KEM KEM",
  "travel_mname": "S",
  "travel_lname": "Lacao",
  "travel_email": "wantwothree@example.com",
  "client_civil_status": "S",
  "client_gender": "M",
  "client_nationality": "FILIPINO",
  "client_tin": "123-456-789-000",
  "client_phone": "09171234567",
  "client_house_unit_no": "Unit 12B",
  "client_street_name": "Rizal Ave.",
  "client_barangay": "Barangay 123",
  "client_city": "Manila",
  "client_province": "Metro Manila",
  "client_zip_code": "1000",
  "client_beneficiary": "Maria Dela Cruz",
  "client_beneficiary_phone": "09991234567",
  "height": "170cm",
  "weight": "70kg",
  "passportNo": "A1234567",
  "selected_surcharge": [1,2,3],
  "selected_plan": "3",
  "package_id": 341,
  "status": "Quoted",
  "utm_source": "insomnia",
  "user_code": "",
  "user_no": ""
}
```

---

## 3. Test Travel Quote API (SQL Server ONLY)
**URL:** `http://localhost/test/API/travel_portal_quote.php`
**Method:** `POST`

**Purpose:** 
This endpoint is purely for testing the backend logic without spamming or creating dummy records in the GeniiSys system. It will calculate the premiums and save the policy in your SQL Server database, but it entirely skips the GeniiSys sync. The resulting records will have `NULL` values for the GeniiSys IDs.

**Payload to use:**
It accepts the exact same payload as the Main API. Use the JSON found in `insomnia_geniisys_travel_api_sample.json`.

---

## 4. Batch Sync GeniiSys API
**URL:** `http://localhost/test/API/batch_sync_geniisys.php`
**Method:** `POST` or `GET`

**Purpose:** 
This endpoint acts as a bulk processor. It sweeps through your SQL Server database (`tbl_travel_policy`), specifically looking for records that were skipped or failed to sync (where `geniisys_policy_id IS NULL`). It iterates through them, rebuilds their payloads, and syncs them to GeniiSys one by one. It outputs a summary of `successful` and `failed` records.

**Payload to use:**
You can use this endpoint with an **empty payload** `{}` to sync ALL unsynced records, or provide a date range to target specific policies. Use the JSON found in `insomnia_batch_sync_sample.json`.
*Example:*
```json
{
  "start_date": "12/01/2026",
  "end_date": "12/31/2026",
  "limit": 100
}
```
