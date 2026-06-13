# PMS API — Complete cURL Documentation

Practical, copy-paste cURL examples for **every** endpoint in the Property
Management System API, matching the implementation in this repository.

- **Base path:** all endpoints are served under `/api` (Apache rewrites to `index.php`).
- **Content type:** every response is `application/json`.
- **Auth:** send `Authorization: Bearer <token>` on all protected endpoints.
- **Tokens:** opaque 64-hex strings, valid for 3600 seconds.

Throughout this doc:

```bash
# Set these once in your shell.
export BASE="https://yourdomain.com/api"
export TOKEN="<paste-token-from-login>"
```

### Response envelope conventions

Most success responses use one of these shapes:

```jsonc
// Wrapped (generic GET/PUT):
{ "success": true, "message": "Success", "data": { /* ... */ } }

// Flat (login, lists, and action endpoints with a fixed contract):
{ "success": true, "token": "...", "role": "...", /* ... */ }
```

Errors are always:

```json
{ "success": false, "error": "error_slug", "message": "Human-readable message." }
```

| HTTP | `error` slug | Meaning |
|------|--------------|---------|
| 400 | `bad_request` | Validation / missing field |
| 401 | `unauthorized` / `invalid_credentials` | Missing/invalid token or bad login |
| 403 | `forbidden` | Wrong role, IDOR violation, disabled account |
| 404 | `not_found` | Resource doesn't exist |
| 409 | `conflict` | Duplicate (email, national ID, already-processed) |
| 422 | `unprocessable` | Business rule failed (e.g. insufficient balance) |
| 429 | `too_many_requests` | Rate limit (login) — includes `Retry-After` |
| 502 | `gateway_error` | PayHero unreachable / not queued |
| 503 | `maintenance_mode` | Maintenance enabled |
| 500 | `server_error` | Internal error (details only in logs) |

---

## 1. Authentication

### POST `/auth/login`

Single login for all roles; the server resolves the role (super admin → client → tenant). Rate limited to 5 attempts/min/IP.

```bash
curl -s -X POST "$BASE/auth/login" \
  -H 'Content-Type: application/json' \
  -d '{
    "email": "admin@pms.co.ke",
    "password": "ChangeMe123!"
  }'
```

**200**
```json
{
  "success": true,
  "token": "a3f9c2...64hex...chars",
  "role": "superadmin",
  "expires_in": 3600,
  "db_name": null,
  "user": { "id": 1, "name": "PMS Super Admin", "email": "admin@pms.co.ke" }
}
```

For a landlord/manager, `role` is `landlord`/`manager` and `db_name` is `pms_client_012`.
For a tenant, `role` is `tenant` and `db_name` is their client's DB.

**401**
```json
{ "success": false, "error": "invalid_credentials", "message": "Invalid email or password." }
```

Grab the token in one line:

```bash
export TOKEN=$(curl -s -X POST "$BASE/auth/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@pms.co.ke","password":"ChangeMe123!"}' \
  | sed -E 's/.*"token":"([^"]+)".*/\1/')
echo "$TOKEN"
```

### POST `/auth/logout`

```bash
curl -s -X POST "$BASE/auth/logout" \
  -H "Authorization: Bearer $TOKEN"
```

**200**
```json
{ "success": true, "message": "Logged out successfully." }
```

---

## 2. Super Admin

All require `Authorization: Bearer <token>` with role `superadmin`.

### GET `/superadmin/dashboard`

```bash
curl -s "$BASE/superadmin/dashboard" -H "Authorization: Bearer $TOKEN"
```

**200**
```json
{
  "success": true,
  "message": "Dashboard loaded.",
  "data": {
    "total_clients": 12,
    "total_properties": 30,
    "total_units": 240,
    "rent_collected_all_time": 4500000.00,
    "rent_collected_this_month": 380000.00,
    "service_fees_earned": 225000.00,
    "pending_withdrawals": 3,
    "active_blacklist_entries": 5
  }
}
```

### POST `/superadmin/clients`

Creates a landlord/manager **and provisions their isolated database**. Returns a temp password (also emailed).

```bash
curl -s -X POST "$BASE/superadmin/clients" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
    "name": "John Mwangi",
    "email": "john@example.com",
    "phone": "0712345678",
    "role": "landlord",
    "service_fee_pct": 5.0
  }'
```

**201**
```json
{
  "success": true,
  "message": "Client account created and database provisioned.",
  "client_id": 12,
  "db_name": "pms_client_012",
  "temp_password": "Hk3mNpQrSt!"
}
```

### GET `/superadmin/clients`

```bash
curl -s "$BASE/superadmin/clients?role=landlord&is_active=1&page=1&limit=20" \
  -H "Authorization: Bearer $TOKEN"
```

**200**
```json
{
  "success": true,
  "total": 45,
  "page": 1,
  "data": [
    {
      "id": 12, "name": "John Mwangi", "email": "john@example.com",
      "phone": "0712345678", "role": "landlord", "db_name": "pms_client_012",
      "service_fee_pct": "5.00", "balance": "12500.00", "is_active": 1,
      "created_at": "2026-06-13 10:00:00"
    }
  ]
}
```

### GET `/superadmin/clients/{client_id}`

```bash
curl -s "$BASE/superadmin/clients/12" -H "Authorization: Bearer $TOKEN"
```

### PUT `/superadmin/clients/{client_id}`

Updatable: `name`, `phone`, `is_active`, `service_fee_pct`.

```bash
curl -s -X PUT "$BASE/superadmin/clients/12" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{ "is_active": 0, "service_fee_pct": 6.5 }'
```

**200** → `{ "success": true, "message": "Client updated.", "data": null }`

### POST `/superadmin/properties/assign`

Assigns a global `PMS-PROP-#####` and mirrors it into the client DB.

```bash
curl -s -X POST "$BASE/superadmin/properties/assign" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
    "client_id": 12,
    "property_name": "Sunset Apartments",
    "location": "Westlands, Nairobi",
    "description": "5-storey residential block"
  }'
```

**201**
```json
{ "success": true, "pms_property_id": "PMS-PROP-00042", "message": "Property registered and global ID assigned." }
```

### POST `/superadmin/units/assign`

Two accepted input shapes.

**A) Explicit units array:**
```bash
curl -s -X POST "$BASE/superadmin/units/assign" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
    "client_id": 12,
    "pms_property_id": "PMS-PROP-00042",
    "units": [
      { "unit_number": "A1", "floor": "Ground", "rent_amount": 15000 },
      { "unit_number": "A2", "floor": "Ground", "rent_amount": 15000 }
    ]
  }'
```

**B) Count + prefix (uniform rent):**
```bash
curl -s -X POST "$BASE/superadmin/units/assign" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
    "client_id": 12,
    "pms_property_id": "PMS-PROP-00042",
    "unit_count": 10,
    "unit_prefix": "A",
    "rent_amount": 15000,
    "floor": "Ground"
  }'
```

**201**
```json
{ "success": true, "units_assigned": 2, "unit_ids": ["PMS-UNIT-00198", "PMS-UNIT-00199"] }
```

### GET `/superadmin/withdrawals`

```bash
curl -s "$BASE/superadmin/withdrawals?status=pending&client_id=12&page=1" \
  -H "Authorization: Bearer $TOKEN"
```

**200**
```json
{
  "success": true, "total": 3, "page": 1,
  "data": [
    {
      "id": 55, "client_id": 12, "client_name": "John Mwangi",
      "amount_requested": "50000.00", "service_fee": "2500.00",
      "amount_disbursed": "47500.00", "status": "pending",
      "notes": "Monthly withdrawal", "requested_at": "2026-06-01 09:32:00",
      "verified_at": null
    }
  ]
}
```

### PUT `/superadmin/withdrawals/{withdrawal_id}/verify`

`action` is `approved` or `rejected`. On approval the client balance is debited and the service fee retained as platform revenue.

```bash
curl -s -X PUT "$BASE/superadmin/withdrawals/55/verify" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{ "action": "approved", "notes": "Verified. Bank transfer initiated." }'
```

**200**
```json
{ "success": true, "message": "Withdrawal approved.", "amount_disbursed": 47500.00, "service_fee_retained": 2500.00 }
```

### POST `/superadmin/payments/cash`

Records a settled cash payment; credits the client (minus fee) and updates platform revenue.

```bash
curl -s -X POST "$BASE/superadmin/payments/cash" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
    "pms_unit_id": "PMS-UNIT-00198",
    "tenant_national_id": "12345678",
    "amount": 15000,
    "notes": "Cash collected at office"
  }'
```

**201** → `{ "success": true, "message": "Cash payment recorded.", "payment_id": 4421 }`

### GET `/superadmin/payments/reconcile`

```bash
curl -s "$BASE/superadmin/payments/reconcile?from=2026-06-01&to=2026-06-30&client_id=12" \
  -H "Authorization: Bearer $TOKEN"
```

**200**
```json
{
  "success": true, "message": "Reconciliation report.",
  "data": {
    "from": "2026-06-01", "to": "2026-06-30",
    "mpesa_collected": 280000.00, "cash_collected": 100000.00,
    "service_fees_earned": 19000.00,
    "pending_transactions": 2, "failed_transactions": 1
  }
}
```

### GET `/superadmin/blacklist`

```bash
curl -s "$BASE/superadmin/blacklist" -H "Authorization: Bearer $TOKEN"
```

**200**
```json
{
  "success": true,
  "data": [
    {
      "id": 7, "tenant_national_id": "12345678", "full_name": "Jane Doe",
      "phone": "0700000000", "email": "jane@example.com",
      "last_pms_unit_id": "PMS-UNIT-00155", "last_client_id": 9,
      "amount_owed": "32000.00", "vacate_report": "Left owing 2 months.",
      "resolved": 0, "flagged_at": "2026-05-15 11:00:00"
    }
  ]
}
```

### DELETE `/superadmin/blacklist/{id}`

Marks the entry resolved (debt settled).

```bash
curl -s -X DELETE "$BASE/superadmin/blacklist/7" -H "Authorization: Bearer $TOKEN"
```

**200** → `{ "success": true, "message": "Blacklist entry resolved.", "data": null }`

### GET `/superadmin/maintenance`

```bash
curl -s "$BASE/superadmin/maintenance" -H "Authorization: Bearer $TOKEN"
```

**200** → `{ "success": true, "maintenance_mode": false, "message": "" }`

### PUT `/superadmin/maintenance`

```bash
curl -s -X PUT "$BASE/superadmin/maintenance" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{ "enabled": true, "message": "System under maintenance. Back at 14:00 EAT." }'
```

**200** → `{ "success": true, "maintenance_mode": true }`

> While enabled, every endpoint returns **503** except `/auth/login`, `/superadmin/maintenance`, and `/webhook/payhero/callback`.

---

## 3. Admin (Landlord / Property Manager)

All require role `landlord` or `manager`. Data is scoped to the client's DB; every unit/property/tenant access is IDOR-checked.

### GET `/admin/dashboard`

```bash
curl -s "$BASE/admin/dashboard" -H "Authorization: Bearer $TOKEN"
```

**200**
```json
{
  "success": true, "message": "Dashboard loaded.",
  "data": {
    "total_units": 10, "occupied": 7, "vacant": 3, "occupancy_rate": 70.0,
    "rent_collected_this_month": 105000.00, "pending_payments": 2,
    "open_complaints": 3, "balance": 12500.00,
    "recent_payments": [
      { "amount": "15000.00", "method": "mpesa", "receipt": "SAE3YULR0Y", "status": "success", "paid_at": "2026-06-01 10:05:00" }
    ]
  }
}
```

### GET `/admin/properties`

```bash
curl -s "$BASE/admin/properties" -H "Authorization: Bearer $TOKEN"
```

**200**
```json
{
  "success": true,
  "data": [
    { "pms_property_id": "PMS-PROP-00042", "name": "Sunset Apartments",
      "address": "Westlands, Nairobi", "description": "5-storey block",
      "total_units": "10", "occupied": "7", "vacant": "3" }
  ]
}
```

### GET `/admin/properties/{pms_property_id}`

```bash
curl -s "$BASE/admin/properties/PMS-PROP-00042" -H "Authorization: Bearer $TOKEN"
```

**200**
```json
{
  "success": true,
  "property": {
    "pms_property_id": "PMS-PROP-00042", "name": "Sunset Apartments",
    "address": "Westlands, Nairobi", "description": "5-storey block",
    "units": [
      { "pms_unit_id": "PMS-UNIT-00198", "unit_number": "A1", "floor": "Ground",
        "rent_amount": "15000.00", "status": "occupied", "tenant_name": "James Kamau" }
    ]
  }
}
```

### GET `/admin/units/{pms_unit_id}`

```bash
curl -s "$BASE/admin/units/PMS-UNIT-00198" -H "Authorization: Bearer $TOKEN"
```

**200**
```json
{
  "success": true,
  "unit": {
    "pms_unit_id": "PMS-UNIT-00198", "unit_number": "A1", "floor": "Ground",
    "rent_amount": 15000.0, "status": "occupied",
    "tenant": { "id": 88, "full_name": "James Kamau", "phone": "0712345678", "email": "james@example.com" },
    "last_payment": { "amount": "15000.00", "receipt": "SAE3YULR0Y", "status": "success", "paid_at": "2026-06-01 10:05:00" }
  }
}
```

### PUT `/admin/units/{pms_unit_id}/status`

**Mark occupied:**
```bash
curl -s -X PUT "$BASE/admin/units/PMS-UNIT-00198/status" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{ "status": "occupied" }'
```

**Mark vacant (vacate report required):**
```bash
curl -s -X PUT "$BASE/admin/units/PMS-UNIT-00198/status" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
    "status": "vacant",
    "vacate_reason": "Tenant relocated for work.",
    "outstanding_balance": 5000.00,
    "vacate_date": "2026-06-01"
  }'
```

**200**
```json
{ "success": true, "message": "Unit status updated. Tenant record archived.", "defaulter_flagged": true }
```

> If `outstanding_balance > 0`: the tenant is archived to `former_tenants` (defaulter), added to the global blacklist, and a defaulter alert is emailed to all other active clients.

### GET `/admin/units/{pms_unit_id}/history`

```bash
curl -s "$BASE/admin/units/PMS-UNIT-00198/history" -H "Authorization: Bearer $TOKEN"
```

**200**
```json
{
  "success": true, "unit": "PMS-UNIT-00198",
  "history": [
    { "amount": "15000.00", "service_fee": "750.00", "method": "mpesa",
      "receipt": "SAE3YULR0Y", "status": "success", "paid_at": "2026-06-01 10:05:00" }
  ]
}
```

### GET `/admin/units/{pms_unit_id}/qr`

```bash
curl -s "$BASE/admin/units/PMS-UNIT-00198/qr" -H "Authorization: Bearer $TOKEN"
```

**200**
```json
{
  "success": true, "pms_unit_id": "PMS-UNIT-00198",
  "payment_url": "https://yourdomain.com/pay?unit=PMS-UNIT-00198",
  "qr_code": "data:image/png;base64,iVBORw0KGgoAAAANS..."
}
```

### POST `/admin/tenants`

Blacklist is checked first; a hit returns a warning but registration still proceeds.

```bash
curl -s -X POST "$BASE/admin/tenants" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
    "pms_unit_id": "PMS-UNIT-00198",
    "national_id": "12345678",
    "full_name": "James Kamau",
    "phone": "0712345678",
    "email": "james@example.com",
    "move_in_date": "2026-06-01"
  }'
```

**201 (clean)**
```json
{ "success": true, "message": "Tenant registered.", "tenant_id": 88, "blacklist_warning": false }
```

**201 (blacklisted)**
```json
{
  "success": true, "message": "Tenant registered with blacklist warning.",
  "tenant_id": 89, "blacklist_warning": true,
  "blacklist_details": { "amount_owed": 32000.00, "previous_unit": "PMS-UNIT-00155", "flagged_at": "2026-05-15 11:00:00" }
}
```

### GET `/admin/tenants`

```bash
curl -s "$BASE/admin/tenants" -H "Authorization: Bearer $TOKEN"
```

**200**
```json
{
  "success": true,
  "data": [
    { "tenant_id": 88, "national_id": "12345678", "full_name": "James Kamau",
      "phone": "0712345678", "email": "james@example.com",
      "pms_unit_id": "PMS-UNIT-00198", "move_in_date": "2026-06-01" }
  ]
}
```

### GET `/admin/tenants/{tenant_id}`

```bash
curl -s "$BASE/admin/tenants/88" -H "Authorization: Bearer $TOKEN"
```

**200**
```json
{
  "success": true, "message": "Tenant detail.",
  "data": {
    "tenant": { "id": 88, "full_name": "James Kamau", "national_id": "12345678", "pms_unit_id": "PMS-UNIT-00198", "phone": "0712345678", "email": "james@example.com", "move_in_date": "2026-06-01", "is_active": 1 },
    "payment_history": [ { "amount": "15000.00", "method": "mpesa", "receipt": "SAE3YULR0Y", "status": "success", "paid_at": "2026-06-01 10:05:00" } ],
    "complaint_count": 2
  }
}
```

### PUT `/admin/tenants/{tenant_id}`

Updatable: `full_name`, `phone`, `email`.

```bash
curl -s -X PUT "$BASE/admin/tenants/88" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{ "phone": "0722333444", "email": "james.k@example.com" }'
```

**200** → `{ "success": true, "message": "Tenant updated.", "data": null }`

### GET `/admin/complaints`

```bash
curl -s "$BASE/admin/complaints?status=open&pms_unit_id=PMS-UNIT-00198" \
  -H "Authorization: Bearer $TOKEN"
```

**200**
```json
{
  "success": true,
  "data": [
    { "id": 14, "pms_unit_id": "PMS-UNIT-00198", "tenant_name": "James Kamau",
      "subject": "Water pipe leaking", "message": "Leaking since Monday.",
      "status": "open", "admin_reply": null,
      "created_at": "2026-06-02 08:15:00", "updated_at": "2026-06-02 08:15:00" }
  ]
}
```

### GET `/admin/complaints/{complaint_id}`

```bash
curl -s "$BASE/admin/complaints/14" -H "Authorization: Bearer $TOKEN"
```

**200** → `{ "success": true, "message": "Complaint detail.", "data": { /* complaint row */ } }`

### PUT `/admin/complaints/{complaint_id}/reply`

`status` optional (`open`/`in_progress`/`resolved`, default `in_progress`). Reply notifies the tenant by email + in-app.

```bash
curl -s -X PUT "$BASE/admin/complaints/14/reply" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{ "admin_reply": "Plumber dispatched, ETA 3 PM today.", "status": "in_progress" }'
```

**200** → `{ "success": true, "message": "Reply sent to tenant.", "data": null }`

### POST `/admin/messages/send`

`channel` is `app`, `email`, or `both`.

```bash
curl -s -X POST "$BASE/admin/messages/send" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
    "pms_unit_id": "PMS-UNIT-00198",
    "channel": "email",
    "subject": "Rent Reminder",
    "body": "Dear James, your rent is due on 5th June."
  }'
```

**200** → `{ "success": true, "message": "Message sent via email." }`

### GET `/admin/messages`

```bash
curl -s "$BASE/admin/messages" -H "Authorization: Bearer $TOKEN"
```

### POST `/admin/withdrawals/request`

Validates `amount <= balance`; computes fee from the client's `service_fee_pct`.

```bash
curl -s -X POST "$BASE/admin/withdrawals/request" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{ "amount": 50000.00, "bank_account": "John Mwangi - KCB 123456789", "notes": "Monthly withdrawal" }'
```

**201**
```json
{ "success": true, "message": "Withdrawal request submitted for super admin review.", "withdrawal_id": 55, "service_fee": 2500.00, "amount_disbursed": 47500.00 }
```

**422** → `{ "success": false, "error": "unprocessable", "message": "Requested amount exceeds your current balance." }`

### GET `/admin/withdrawals`

```bash
curl -s "$BASE/admin/withdrawals" -H "Authorization: Bearer $TOKEN"
```

### GET `/admin/notifications`

Unread admin notifications.

```bash
curl -s "$BASE/admin/notifications" -H "Authorization: Bearer $TOKEN"
```

### PUT `/admin/notifications/{id}/read`

```bash
curl -s -X PUT "$BASE/admin/notifications/3/read" -H "Authorization: Bearer $TOKEN"
```

**200** → `{ "success": true, "message": "Notification marked as read.", "data": null }`

---

## 4. Tenant

All require role `tenant`. Scoped to the tenant's unit + client DB.

### GET `/tenant/unit`

```bash
curl -s "$BASE/tenant/unit" -H "Authorization: Bearer $TOKEN"
```

**200**
```json
{
  "success": true,
  "unit": {
    "pms_unit_id": "PMS-UNIT-00198", "unit_number": "A1",
    "property_name": "Sunset Apartments", "rent_amount": 15000.0, "status": "occupied",
    "latest_payment": { "amount": "15000.00", "receipt": "SAE3YULR0Y", "status": "success", "paid_at": "2026-06-01 10:05:00" }
  }
}
```

### GET `/tenant/payments/history`

```bash
curl -s "$BASE/tenant/payments/history" -H "Authorization: Bearer $TOKEN"
```

### POST `/tenant/payments/pay`

Initiates an M-Pesa STK push via PayHero. `amount` **must equal** the unit rent.

```bash
curl -s -X POST "$BASE/tenant/payments/pay" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{ "amount": 15000, "phone_number": "0712345678" }'
```

**201**
```json
{
  "success": true, "status": "QUEUED", "reference": "E8UWT7CLUW",
  "CheckoutRequestID": "ws_CO_15012024164321519708344109",
  "message": "STK push sent. Complete payment on your phone."
}
```

**422** → amount mismatch. **502** → `gateway_error` if PayHero did not queue.

### POST `/tenant/complaints`

```bash
curl -s -X POST "$BASE/tenant/complaints" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{ "subject": "Water pipe leaking", "message": "Leaking under the kitchen sink since Monday." }'
```

**201** → `{ "success": true, "message": "Complaint submitted.", "complaint_id": 14 }`

### GET `/tenant/complaints`

```bash
curl -s "$BASE/tenant/complaints" -H "Authorization: Bearer $TOKEN"
```

### GET `/tenant/messages`

```bash
curl -s "$BASE/tenant/messages" -H "Authorization: Bearer $TOKEN"
```

### GET `/tenant/notifications`

```bash
curl -s "$BASE/tenant/notifications" -H "Authorization: Bearer $TOKEN"
```

---

## 5. Webhook (PayHero)

### POST `/webhook/payhero/callback`

No bearer token — secured by the IP allowlist in `PAYHERO_ALLOWED_IPS`. Idempotent: a repeat callback for an already-settled reference is a no-op. On success it splits the service fee, credits the client balance, mirrors to the client's `payment_history`, and emails a receipt.

```bash
curl -s -X POST "$BASE/webhook/payhero/callback" \
  -H 'Content-Type: application/json' \
  -d '{
    "forward_url": "",
    "response": {
      "Amount": 15000,
      "CheckoutRequestID": "ws_CO_14012024103543427709099876",
      "ExternalReference": "PMS-UNIT-00198-1717200000",
      "MpesaReceiptNumber": "SAE3YULR0Y",
      "Phone": "+254712345678",
      "ResultCode": 0,
      "ResultDesc": "The service request is processed successfully.",
      "Status": "Success"
    },
    "status": true
  }'
```

**200** → `{ "success": true }`
(`ResultCode` `0` = success; any other value = failed.)

---

## 6. End-to-end smoke flow

```bash
export BASE="https://yourdomain.com/api"

# 1) Super admin login
export TOKEN=$(curl -s -X POST "$BASE/auth/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@pms.co.ke","password":"ChangeMe123!"}' \
  | sed -E 's/.*"token":"([^"]+)".*/\1/')

# 2) Create a landlord (auto-provisions pms_client_XXX)
curl -s -X POST "$BASE/superadmin/clients" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"name":"John Mwangi","email":"john@example.com","phone":"0712345678","role":"landlord","service_fee_pct":5.0}'

# 3) Assign a property + units
curl -s -X POST "$BASE/superadmin/properties/assign" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"client_id":12,"property_name":"Sunset Apartments","location":"Westlands","description":"Block A"}'

curl -s -X POST "$BASE/superadmin/units/assign" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"client_id":12,"pms_property_id":"PMS-PROP-00042","units":[{"unit_number":"A1","floor":"Ground","rent_amount":15000}]}'

# 4) Landlord logs in, registers a tenant
export LTOKEN=$(curl -s -X POST "$BASE/auth/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"john@example.com","password":"<temp_password>"}' \
  | sed -E 's/.*"token":"([^"]+)".*/\1/')

curl -s -X POST "$BASE/admin/tenants" -H "Authorization: Bearer $LTOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"pms_unit_id":"PMS-UNIT-00198","national_id":"12345678","full_name":"James Kamau","phone":"0712345678","email":"james@example.com","move_in_date":"2026-06-01"}'
```

---

*PMS API v1.0.0 — generated to match the implementation under `/api`.*
