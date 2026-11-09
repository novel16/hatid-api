# Hatid API

## Read-only recovery probes — 6 November

POST `/api/v1/parcels/recovery`, `/api/v1/deliveries/{parcel}/recovery` and `/api/v1/remittances/recovery` accept the original validated operation body through the rider Bearer/role/ability middleware. Existing services share their exact write canonicalizer with the probe, query owned UUID evidence and reject changed payloads with409. They recognize original attempts after a later resume and remittances after settlement, without creating another business record or alert. A missing match returns not_recorded and does not cancel an earlier request.

New application source: `app/Http/Resources/RecoveryResource.php`, allowlisting state, target kind/id and original confirmation time. New test support: `tests/Feature/RecoveryTest.php`. Read routes/api.php → existing ParcelRequest/CollectionRequest → existing controller recover method → service recover/submission → RecoveryResource → RecoveryTest. No migration/dependency/seed/native data change is needed. Full API suite passed266tests/1964assertions; Pint passed. Existing write behavior/history/receipt allocation is unchanged.

Publishing authorization updated5 November: the user now includes this API repository in the main-branch push, with separate feature commits for the accumulated implementation. This supersedes earlier local-only notes for completed stages below. Private .env/keys, developer database contents, vendor and generated output remain local. Publishing applies no new migrations or seed operations and preserves the running servers.

Laravel 13.39 / PHP 8.4 / Sanctum 4.3.3. This API serves the rider PWA and the separate admin app.

## Native Windows development

Notifications & Activity was connected6 November; its endpoints and verification are documented below. Only its additive notifications migration is needed when updating an existing checkout. Normal deployments must run pending migrations before enabling the new application code; publishing itself does not run them on another environment.

The active project is C:/Users/Novel/Desktop/laravel projects/hatid/api. PHP8.4.27 runs from XAMPP, with the API at http://127.0.0.1:8000 and the rider client at http://localhost:3011. In Git Bash:

```bash
cd "/c/Users/Novel/Desktop/laravel projects/hatid/api"
php artisan serve
```

The local APP_URL matches port8000 and PHP_CLI_SERVER_WORKERS=1. XAMPP MariaDB uses the local hatid database. On3 November the user chose a new empty database; six additive migrations were applied and the user will register their own rider account. Earlier Sail instructions describe the legacy environment; its data was preserved. Avoid starting another server when this terminal is already running.

## Architecture and coding standards

AuthController coordinates the auth endpoints, and AuthRequest keeps their validation in one place. Focused services under app/Services/Auth handle accounts, credentials, token lifecycle and passwords. API Resources explicitly select public response fields. TokenResource::collection() uses Laravel's built-in paginated collection without exposing token hashes.

The Ponytail refactor reduced the core auth implementation from 17 files / 692 lines to 8 files / 515 lines. This count covers the controller, requests, services, resources and former session DTO; security middleware, the role enum, password-byte rule, configuration, migrations and tests remain separate.

Read one flow in this order:

1. routes/api.php: endpoint and middleware.
2. app/Http/Requests/Auth/AuthRequest.php: rules for the controller action and shared newPasswordRules().
3. app/Http/Controllers/Api/V1/Auth/AuthController.php: HTTP coordination; authenticate() is shared by rider/admin login.
4. app/Services/Auth/AuthService.php, TokenService.php or PasswordService.php: the relevant business logic.
5. app/Http/Resources/Auth/AuthSessionResource.php, UserResource.php or TokenResource.php: controlled output.

User::normalizeEmail() is reused by request validation, services, login throttling and admin provisioning. Token issuance returns Sanctum's existing NewAccessToken rather than a custom DTO; TokenService retains the issuing user on its tokenable relation so serialization uses that same account without another query.

AuthRequest chooses rules by the server-side controller action. When adding or renaming an action that uses it, update the rules match and the required-fields regression cases together.

UserRole is a backed enum. Every protected route requires a valid personal access token, the correct database role and the matching token ability. A role change does not upgrade an old token. Public registration can create riders only.

The API is session-free: pass Authorization: Bearer, omit cookies, and do not call /sanctum/csrf-cookie. Sanctum stateful domains and session fallback guards are empty; its cookie endpoint is disabled. Tokens are hashed, stored in the database, expire and can be revoked. They are not self-contained JWTs.

## Rider in-app notifications — 6 November

| Route | Behavior |
|---|---|
| GET /api/v1/notifications | Owned paginated alerts, full unread_count; state=all/unread, page>=1, per_page1..100(default10) |
| GET /api/v1/notifications/unread-count | Small owned unread count for the workspace bell |
| PATCH /api/v1/notifications/{uuid}/read | Owned-only read confirmation; preserve the first server timestamp |
| PATCH /api/v1/notifications/read-all | Mark all currently owned unread alerts across pages;204 |

The existing rider Bearer/role/ability middleware and private response policy protect all four routes. User/owner/read_at/data/target overrides are prohibited. Future unrelated framework notification types stay outside this inbox. NotificationResource exposes only id, category, title, message, a fixed target(kind/id), read_at and created_at; no raw data/owner identifiers/URLs.

Use Laravel's installed DatabaseNotification and the existing User::notifications relation. No custom model, notification class, event/listener, queue, provider or dependency. The new table stores private read state and owner/unread/date indexes. NotificationService is called after replay validation, inside the existing DeliveryService/CollectionService transaction. Invalid or rolled-back business actions leave no alerts; an insert failure rolls the whole action back, and identical retries never duplicate alerts.

Preferences are read from the latest locked rider row, not a stale caller. At most one alert appears for each grouped outcome. On delivered/full manually confirmed receipts, collectionAlerts produces a combined delivery/payment alert; if only deliveryAlerts is enabled, a delivery-only alert is used. Held/failed/resumed/returned activity uses deliveryAlerts. Recorded remittances use collectionAlerts. Both toggles off suppress new alerts; old alerts, attempts, receipts and remittance evidence remain. Re-enabling does not backfill. This is in-app activity, with no external payment verification, transfer, browser push, due-hold scheduling or historical backfill.

New application files: NotificationRequest validates controls; NotificationService owns creation/query/read behavior; NotificationController delegates HTTP; NotificationResource allowlists output. Support files:2026_11_06_050025_create_notifications_table.php and NotificationTest.php. Review route → request → service/two transaction callers → resource → tests. Native additive migration applied52.31ms, with no reset/seed or existing financial/credential changes.

Verified:31 notification cases/178 assertions; full253 API tests/1870 assertions in isolated SQLite; Pint and diff checks. Tests cover ownership/notifiable types/role/ability/expiry, all UI page sizes, read-state idempotence, current preferences, grouped outcomes, replay and atomic insertion failure. Native missing-Bearer GET/PATCH routes return401 without cookies. No simultaneous native-write execution is claimed. User API/dev servers remain running.

## Actual earnings reports and dashboard — 5 November

Three rider-only read routes reuse the existing Bearer, role, ability and private-response middleware:

| Route | Result |
|---|---|
| GET /api/v1/reports/earnings | ParcelResource ledger pagination plus full filtered report metadata |
| GET /api/v1/reports/earnings/export | Complete filtered UTF-8 CSV; page/per_page are ignored |
| GET /api/v1/dashboard | Today's confirmed receipts/fees, all-week work and lifetime seller debt |

Report inputs: period=day/week/month/custom (default week), optional date=YYYY-MM-DD for noncustom periods, or required start/end for custom. Week is Monday–Sunday; month is a calendar month. All bounds are Asia/Manila local midnights converted to half-open UTC intervals over received_at. Optional q(max100), actual method=all/cash/gcash, page>=1 and per_page1..100(default10). Ownership and timezone cannot be overridden. Dashboard always uses server today and rejects period overrides.

CollectionService::receiptQuery shares owner/full-receipt/delivered/actual-method/search eligibility with reports and export. Summary centavos and chart totals cover every match, independent of pagination. Long periods use at most60 labelled day-interval buckets; zero remains zero. A short read transaction keeps aggregate/current-page reads consistent. Native MySQL REPEATABLE-READ was verified; no concurrent-write benchmark is claimed. Dashboard work spans all intake weeks while its money covers today, and seller_outstanding is separately lifetime-scoped through CollectionService.

CSV uses200-row batches, native tmpfile/fputcsv, UTF-8 BOM, CRLF, exact centavo decimals and formula-safe names. It is fully prepared before success headers and always closes/removes its temporary stream. It contains original batch, actual receipt method and Manila confirmation time; it exports all matching rows. No gateway, wallet, transfer, remittance mutation or new fee recognition is introduced. No schema or dependency changes.

Four new application files: ReportRequest validates the date/filter contract; ReportService owns periods, shared-query aggregation and CSV preparation; ReportResource allowlists metadata/totals; ReportController delegates and serializes. The fifth new API file, tests/Feature/ReportTest.php, is test support. Review routes/api.php → ReportRequest → ReportService/CollectionService::receiptQuery → ReportResource/ParcelResource → ReportTest. Existing receipt/pricing/remittance history stays unchanged.

Verification: focused Report/Collection38 tests/310 assertions, then full222 tests/1692 assertions passed in isolated SQLite; scoped Pint and diff checks passed. CSV coverage crosses the200-row chunk boundary and proves page/per_page do not limit export. Native report/dashboard/CSV reads and financial invariants passed without record changes. Missing-Bearer routes returned401 with no cookies. Signed-in browser report layouts and simultaneous native writes remain unverified; user API/dev servers were preserved. API changes remain local/uncommitted under the user's publishing scope.

## Collections and seller handovers — 5 November

All routes require the existing rider Bearer token, role and ability:

- GET /api/v1/collections: full successful receipts, actual payment method/search and native10/50/100 pagination.
- GET /api/v1/collections/sellers: owned active/archived seller balances, name/contact search, optional owned seller_id and all/outstanding/settled filter.
- GET /api/v1/remittances: handover history, actual handover method/search and pagination.
- GET /api/v1/remittances/{id}: fixed10-row original parcel allocations plus a controlled remittance header.
- POST /api/v1/remittances: operation_id UUID, seller_id, strict integer-centavo amount, cash/gcash method, optional reference(max200), and handover_confirmed. No update/delete route.

List responses include a separately labelled lifetime account summary: cash, gcash, fees, seller_share, remitted, outstanding and receipts count. A single aggregate SQL statement keeps its own money terms consistent; list filters/pages never redefine its scope. Conditional ParcelResource settlement/allocated_amount and SellerResource balance fields appear only on financial queries. RemittanceResource exposes the recorded header without user IDs, operation keys or hashes.

CollectionService shares DeliveryService's rider-first transaction lock. It checks identical canonical UUID/payload replay before a seller's current balance, so retry works after full settlement. Changed reuse returns409. Partial amounts allocate oldest received_at first, id tie-break, to positive outstanding seller shares across original batches. The same transaction creates the header and allocations, rejecting excess amounts atomically. Archived seller identity and immutable parcel pricing/receipts/fees survive settlement. The amount must describe a completed real handover; Hatid never initiates or verifies an external transfer.

New application files: CollectionController (HTTP), CollectionRequest (validation), CollectionService (queries/transaction), Remittance (header and native amount pivot relationship), RemittanceResource (allowlist). One migration and one focused feature-test module are support files; there is no new dependency or allocation model. Read routes → request → controller → service → Resource → CollectionTest for one handover.

Verified:202 API tests/1552 assertions and scoped Pint passed in isolated SQLite. Native database summary/list/balance/history reads and money invariants passed; migration2026_11_04_225754 was applied141.16ms without reset/seed or existing money changes. Actual MySQL concurrent execution and signed-in browser mutation are not claimed. The frontend's interrupted→429 retry regression passed after retaining the original operation until confirmed. User API/dev servers were preserved. Native handover dates use server confirmation time; correction/backdating workflows and durable reload/offline recovery remain separate stages.

## Parcel entry and manual deliveries

All endpoints use the existing cookie-free Bearer/rider/rider:access boundary:

| Method | Path | Behavior |
|---|---|---|
| POST | /api/v1/parcels | One owned parcel, reviewed immutable pricing and replay-safe operation_id;201 |
| GET | /api/v1/parcels/{parcel} | Owned saved parcel and controlled history |
| GET | /api/v1/deliveries | Native pagination of matching customer/address stops across weeks |
| GET | /api/v1/deliveries/{parcel} | All parcels at that owned anchor's customer/address across original batches |
| POST | /api/v1/deliveries/{parcel}/outcomes | Atomic selected-parcel outcome and explicit full receipts; updated selected Resources |

Intake requires operation_id(UUID), batch_id, customer_id, address_id, seller_id, quote/fee(integer centavos), mode(included/excluded), expected_method(cash/gcash), optional description/note/override_reason. All parents must be active/owned and address must belong to customer; archived directory records remain usable for existing delivery history. Quote is1..999999999 centavos, fee0..999999999; included fee<=quote. Server derives customer total and net seller share, with a reason required when seller defaults differ. Fee mode and amounts stay frozen after later seller edits. No update/delete parcel endpoint.

Delivery queries: q(name/phone/address/seller/description/reference), page>=1, per_page1..100(default10), optional owned batch_id, status(open default/ready/all/pending/held/failed/delivered/returned), method(all/cash/gcash). Status and method match the same parcel. Open includes pending/held/failed; Ready includes pending/due holds. A list row contains only matching parcels; detail shows the complete customer/address group across weeks. Query alternatives stay under the owner scope; pagination is over grouped stops, not client slicing or an unbounded directory download.

Outcome body: operation_id, distinct parcel_ids(max100 at the same stop), outcome, note(3..500). Held requires a future retry_at with seconds and timezone offset (`YYYY-MM-DDTHH:mm:ss+00:00`); shape is validated in ParcelRequest, future-time business validation follows replay lookup. Delivered requires receipt_confirmed=true and exactly one receipt per selected parcel: parcel_id, method(cash/gcash), amount(equal to frozen full total), optional reference. Partial payment is not supported. Returned requires handover_confirmed=true. Failed is not returned; failed/future-held work must explicitly resume to pending before delivery. Delivered/returned states are terminal. Server timestamps receipt evidence; no client/GPS/owner overrides.

An owned rider-row transaction serializes create/outcome writes. Native unique(user_id,operation_id) and unique(parcel_id,operation_id), plus canonical request hashes, protect retries. Identical retries return saved evidence even after archiving or retry-time expiry. Reusing a key with changed content returns422. Selection validation and all receipts commit atomically. One receipt lives on each parcel, and attempts are append-only. Money totals are future derived reports; no wallet, transfer, remittance or earnings ledger endpoint was invented. No maps/routing/GPS writes, admin UI or durable offline queue in this stage.

Nine new source classes: Parcel/DeliveryAttempt models; ParcelRequest; ParcelController/DeliveryController; ParcelService/DeliveryService; ParcelResource/DeliveryResource. Read route → Request → Controller → Service → Resource. One additive migration (`2026_11_04_055525_create_parcels_table`), ParcelFactory, ParcelTest and DeliveryTest are schema/test support. The migration was applied to native Hatid without resetting old data. Tests use isolated SQLite memory and real Bearer headers. Final test/browser evidence is in project memory and the implementation plan.

Verified4 November: full165 API tests/1160 assertions and Pint passed, including changed/identical replays, elapsed-hold replay, owned/nested/archived relations, fixed prices, native grouped pagination, mixed payment/status filters, partial/atomic receipts, terminal guards and hold/fail/retry/return history. A native temporary QA account exercised the actual responsive frontend and was then removed with only its owned fixtures. Native401/CORS/no-cookie behavior was checked. Existing user records/servers were preserved; no native concurrent-device test is claimed.

## Weekly intake batches

Rider-only endpoints use the existing Bearer/role/rider:access boundary:

| Method | Path | Behavior |
|---|---|---|
| GET | /api/v1/batches?q=...&page=1&per_page=10 | Owned name search, stable date/id ordering, native pagination and current_week defaults |
| POST | /api/v1/batches | Create with name, start and end; returns 201 |
| GET | /api/v1/batches/{id} | Owned detail, including older weeks; foreign/missing IDs return 404 |

An exact start/end range is unique per rider. BatchService maps native unique-constraint collisions to a 422 start-field error; another rider can use the same dates. Names are trimmed (2–60 characters), dates must be real YYYY-MM-DD values and end must be on/after start. Default calendar metadata is Monday–Sunday in Asia/Manila, including UTC Sunday/local Monday and year transitions. Date storage uses start_date/end_date; request/resource fields use start/end to match the existing UI. There is no new overlap or strict seven-day restriction.

BatchResource returns id, name, start, end, period and created_at only. period is current/previous/upcoming calendar context, separate from delivery completion or selected UI state. No fictitious parcel/fee totals, date-expiry lock or update/delete/archive route is exposed. Parcel entry and cross-week manual deliveries now use these owned batch IDs while retaining original intake dates.

New application files: Batch is the owned model, BatchRequest validates queries/intake data, BatchController coordinates HTTP, BatchService performs business operations, and BatchResource controls output. Read routes → request → controller → service → resource. One additive migration and BatchFactory/BatchTest are supporting schema/test files, separate from those five source files. The native migration was applied on 4 November without inserting or resetting user data.

Verification: focused26 batch tests passed, then full125 API tests/905 assertions and scoped Pint. Tests cover privacy, validation, native uniqueness, pagination, previous-week access and Manila calendar boundaries.

## Rider account settings

PATCH /api/v1/settings accepts partial name, optional phone, and preferences containing deliveryAlerts, collectionAlerts and compactView booleans. Name/phone are trimmed; an empty phone clears it. Email, role, password and ownership fields are prohibited. Rider role and rider:access ability are required, and the owner comes exclusively from the Bearer token.

UserResource returns phone and only the three known preferences through both this endpoint and /auth/me, with safe defaults for existing accounts. SettingsService merges partial preferences while locking the owned user row. A saved preference does not create live notification or sync infrastructure.

New application files: SettingsController coordinates the response, SettingsRequest validates editable fields, and SettingsService performs the update. Read routes/api.php → SettingsRequest → SettingsController → SettingsService → the existing UserResource. The separate additive migration adds nullable phone/preferences columns; SettingsTest covers defaults, ownership, protected-field validation and partial saves. Existing token tests cover owned pagination and revocation. The migration was applied to the native database on 4 November without resetting data.

Verified: 99 API tests / 745 assertions passed in isolated SQLite memory. Native PATCH /settings without a token returned 401, allowed the configured rider origin and set no cookie.

## Local setup

The existing Sail stack serves the API at http://localhost (port 80) and phpMyAdmin at http://localhost:8089. MySQL uses mysql:3306 inside Compose; a host database client uses 127.0.0.1:3308.

From /home/novel/projects/hatid/api:

~~~bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan app:create-admin
~~~

The last command is interactive: name, email, hidden password and confirmation. It does not accept passwords as CLI arguments, create default credentials or promote an existing rider. No admin account has been silently provisioned. DatabaseSeeder creates no default users.

New passwords require at least 12 characters, upper/lowercase and a number, with a 72-byte maximum compatible with bcrypt. Passwords support Unicode within that byte limit.

## Routes

Public endpoints:

| Method | Path | Inputs |
|---|---|---|
| POST | /api/v1/auth/register | name, email, password, password_confirmation, device_name |
| POST | /api/v1/auth/login | email, password, device_name; riders only |
| POST | /api/v1/admin/auth/login | email, password, device_name; admins only |
| POST | /api/v1/auth/forgot-password | email; shared by both clients |
| POST | /api/v1/auth/reset-password | email, token, password, password_confirmation; shared |

For protected routes, use /api/v1/auth for riders or /api/v1/admin/auth for admins:

| Method | Suffix | Behavior |
|---|---|---|
| GET | /me | Current user through UserResource |
| POST | /logout | Revoke the current device token |
| POST | /logout-all | Revoke every token owned by the current account |
| GET | /tokens?page=1&per_page=10 | Owned, unexpired tokens; default 10, maximum 100; client choices 10/50/100 |
| DELETE | /tokens/{id} | Revoke an owned token; foreign/unknown IDs return 404 |
| PUT | /password | current_password, password, password_confirmation; revoke all devices |

Login/register returns:

~~~json
{
  "data": {
    "user": {
      "id": 1,
      "name": "Example Rider",
      "email": "rider@example.com",
      "phone": null,
      "preferences": { "deliveryAlerts": true, "collectionAlerts": true, "compactView": false },
      "role": "rider",
      "email_verified_at": null,
      "created_at": "2026-11-01T00:00:00.000000Z"
    },
    "access_token": "<returned once>",
    "token_type": "Bearer",
    "expires_at": "<UTC timestamp>"
  }
}
~~~

Only login and registration reveal a token's plaintext secret. Resources never return password hashes, remember tokens or token hashes. Auth responses use Cache-Control: no-store.

Example browser request:

~~~javascript
const response = await fetch("http://localhost/api/v1/auth/login", {
  method: "POST",
  credentials: "omit",
  headers: { Accept: "application/json", "Content-Type": "application/json" },
  body: JSON.stringify({
    email: "your-rider@example.com",
    password: "your-password",
    device_name: "Chrome",
  }),
});
const result = await response.json();

await fetch("http://localhost/api/v1/auth/me", {
  credentials: "omit",
  headers: {
    Accept: "application/json",
    Authorization: "Bearer " + result.data.access_token,
  },
});
~~~

401 means missing/invalid/expired/revoked token; 403 means wrong role/ability; 422 includes validation errors; 429 includes Retry-After. Both clients use the same account table. This first role model gives each account one role; there is no public admin signup.

## Private rider directory

Pagination update, 3 November: both list controllers default to ten entries. CustomerService/SellerService retain native paginate($perPage)->withQueryString(), deterministic ordering, rider ownership and Resource collections. Explicit per_page=10, 50 and 100 are verified; existing API validation still permits integers 1..100. The full isolated suite passed 82 tests / 629 assertions, including search links, partial/empty pages and foreign-owner exclusion. No migration or development-data change was needed.

Customers, their addresses and sellers belong to the authenticated rider. Directory routes require the rider database role and rider:access token ability, use the existing Bearer-only middleware and send no-store responses. A foreign parent/child ID returns 404. Owner IDs come from the server and cannot be supplied by clients. No admin directory UI or cross-rider management endpoint is included.

| Method | Path after /api/v1 | Behavior |
|---|---|---|
| GET | /customers or /sellers | Search/paginated listing: q, page, per_page (default 10, max 100), archived=0/1 |
| POST | /customers or /sellers | Create and return a resource with status 201 |
| GET, PATCH/PUT | /customers/{customer} or /sellers/{seller} | Read/update an active owned record |
| DELETE | /customers/{customer} or /sellers/{seller} | Reversible archive, status 204 |
| POST | /customers/{customer}/restore or /sellers/{seller}/restore | Restore an owned archived record |
| POST | /customers/{customer}/addresses | Add label/text to an active owned customer |
| PATCH, DELETE | /customers/{customer}/addresses/{address} | Edit or archive an independently owned address |
| POST | /customers/{customer}/addresses/{address}/restore | Restore an address under its active customer |

Customer inputs: name (2–100), optional phone (max 30), and on creation an optional address object containing label (max 50) and nullable text (max 300). A customer always starts with one address; the default label is Home when no address is submitted. Phone spacing/parentheses/hyphens are removed by Customer::normalizePhone(), shared by writes and search. A known phone is unique per rider including archived customers, while names can repeat. Restore the archived customer rather than creating a duplicate phone record. Country-code conversions are not inferred.

For local pagination fixtures, register one rider and run `php artisan db:seed --class=CustomerSeeder`. The existing `CustomerFactory::dummy()` state labels names `[Dummy]`; `CustomerSeeder` tops up to 2,000 marked customers with one unverified address each, in batches of 100. Phones and GPS remain null. Re-running counts existing marked records, including archives, without duplicating or restoring them. Existing customers and account credentials are preserved. The default `DatabaseSeeder` calls this only in local/testing environments; direct class invocation also rejects other environments. With zero or multiple riders it stops rather than guessing the owner. Review factory → seeder → tests/Feature/CustomerSeederTest.php.

Seller inputs: name (2–100), optional contact (max 100), explicit mode (included/excluded), and fee in integer centavos (0–9007199254740991). For PHP 15 use fee:1500, not 15 or a decimal string. JSON booleans/floats are rejected as fees. These are future-parcel defaults; directory updates do not recalculate saved parcel snapshots or create money records.

CustomerResource allows only id,name,phone,addresses,archived_at; its address entries allow id,label,text,latitude,longitude,verified_at,archived_at. SellerResource allows id,name,contact,mode,fee,archived_at. Lists use native paginated Resource::collection() with data/links/meta; mutations return a data resource, or 204 for archive. Address writes return the updated parent customer resource. API responses do not include owner IDs or authentication fields. GPS is read-only: directory requests reject coordinate/verification writes until a delivery API can establish successful-delivery evidence.

Archive preserves addresses; restoring a parent does not undo an address's separate archive. Unknown address text/coordinates do not block directory creation. Directory mutations are online; offline reconciliation, parcels, deliveries and remittance APIs remain future stages.

Review one customer save in this order: routes/api.php → CustomerRequest::rules()/prepareForValidation() → CustomerController → CustomerService::save() → CustomerResource → tests/Feature/DirectoryTest.php. Seller files follow the same path. Requests choose action-specific rules, services own rider scoping/transactions and resources allowlist output. Search OR conditions stay grouped inside the owner scope. Unique-phone database conflicts become useful 422 validation errors.

This stage adds 11 application source files: three models, two controllers, two requests, two services and two resources. Addresses remain in CustomerService/CustomerResource instead of adding forwarding classes. Supporting files are one additive migration, three factories and one feature-test module; User relationships and existing routes are extended. No repository/interface/dependency layer was added.

| New application file | Purpose |
|---|---|
| app/Models/Customer.php | Owned customer identity, phone normalization and address relationship |
| app/Models/CustomerAddress.php | Separate address metadata and read-only GPS evidence fields |
| app/Models/Seller.php | Owned seller contact and integer-centavo fee defaults |
| app/Http/Requests/Directory/CustomerRequest.php | Customer/list/address validation and prohibited owner/GPS inputs |
| app/Http/Requests/Directory/SellerRequest.php | Seller/list validation, explicit fee mode and strict integer fee |
| app/Http/Controllers/Api/V1/Directory/CustomerController.php | Customer/address HTTP actions and resource/status responses |
| app/Http/Controllers/Api/V1/Directory/SellerController.php | Seller HTTP actions and resource/status responses |
| app/Services/Directory/CustomerService.php | Private customer search, transactional saves and parent/child archive operations |
| app/Services/Directory/SellerService.php | Private seller search, preference updates and reversible archive operations |
| app/Http/Resources/Directory/CustomerResource.php | Allowlisted customer/address response fields |
| app/Http/Resources/Directory/SellerResource.php | Allowlisted seller response fields |

Supporting files: database/migrations/2026_11_03_005308_create_directory_tables.php creates the three tables/ownership constraints. CustomerFactory.php, CustomerAddressFactory.php and SellerFactory.php provide test records for their respective models. tests/Feature/DirectoryTest.php checks the directory HTTP/security contract. No generated application source or lockfile change is needed for this feature.

## Configuration and recovery

See .env.example. Defaults are explicit and configurable:

- FRONTEND_URL=http://localhost:3011
- ADMIN_URL=http://localhost:3012
- AUTH_RIDER_TOKEN_TTL=1440 minutes
- AUTH_ADMIN_TOKEN_TTL=60 minutes
- CORS_ALLOWED_ORIGINS optionally overrides the two allowed client origins.

CORS permits Bearer headers from both configured clients and does not allow credentialed cookies. Align client URLs/origins with deployment addresses before deployment.

Expired tokens require login again; there is no JWT refresh-token flow. A daily scheduled Sanctum command prunes tokens expired for at least 24 hours when the Laravel scheduler is running.

Password recovery always returns the same public response for known/unknown emails. Reset links target the rider or admin /reset-password page according to the stored account role. Successful reset/change revokes every device token. Changing a password signs out the current device too.

Development currently uses MAIL_MAILER=log: reset links go to the local Laravel mail log. Configure the real mail transport for actual email delivery. Client reset pages are a separate UI concern.

## Verification

~~~bash
./vendor/bin/sail composer test
./vendor/bin/sail pint --dirty --format agent
~~~

PHPUnit uses forced SQLite :memory: databases, not the local MySQL data. Feature tests use real Bearer headers and cover both roles, session-cookie rejection, privilege escalation, token expiry/ownership/revocation, pagination/serialization, throttling/CORS, password recovery/change and admin provisioning.

Directory stage verified on 3 November: the full API suite passed 80 tests / 549 assertions, including 23 directory cases / 232 assertions, and scoped Pint passed. Coverage includes rider/role/ability boundaries, grouped search, nested-address isolation, archives/restores, read-only GPS, controlled serialization, normalized/duplicate/optional phones, strict integer fees and an empty initial-address regression. Only the additive directory migration was applied to local Hatid MySQL; no existing data was reset or default users seeded. Live MySQL requests also verified foreign customer/seller GET and PATCH return 404 and foreign searches return zero results. Mobile/desktop browser review verified management forms, archives/restores, fee previews, persistence after re-login and demo separation. Both disposable QA users and their directory rows/tokens were removed afterward; existing users were preserved.

Verified after the 2 November 2026 refactor: 57 tests / 317 assertions passed, including all original assertions and seven added required-field cases. Pint, optimized Composer autoload generation with strict PSR checks, route inspection and live missing-token/login-validation checks passed. All 17 endpoints retain their paths, route names, response contracts and security middleware.

The repository's AGENTS.md records the ongoing coding standards. Laravel Boost was installed as a development dependency to complete the repository's required agent bootstrap; it is not part of the authentication runtime.

## Vehicle, verified GPS and free maps — 4 November

New rider registration requires `vehicle`: `motorcycle`, `bicycle` or `car`. Existing riders keep a nullable vehicle and can still sign in and deliver manually; PATCH `/api/v1/settings` completes/changes the vehicle. Auth/Settings reuse their existing request/service/resource files. `/auth/me` returns the verified profile; provider modes are derived server-side (`car` becomes `drive`), never accepted from an arbitrary client profile.

POST `/api/v1/deliveries/{parcel}/location` is separate from the saved handover. It requires an owned delivered parcel, full receipt and a real delivered attempt. Inputs are latitude/longitude, original gps_latitude/gps_longitude, accuracy in meters, captured_at, review_confirmed, expected_version and optional replace_confirmed. Readings must be within five minutes, no more than fifteen seconds ahead, and after the handover with five seconds clock/precision tolerance. Replacement requires explicit confirmation; an outdated version returns409. Weak accuracy stays visible for review/correction. This verifies evidence/validation, not cryptographic authenticity of browser GPS. Skip/error never changes money. Directory GPS writes remain prohibited; CustomerResource::addressData shares the controlled pin metadata across directory/parcels/deliveries without exposing evidence IDs.

| Endpoint | Behavior |
|---|---|
| GET `/api/v1/map` | Free metadata/configuration and app-accounted shared allowance; no automatic provider call |
| POST `/api/v1/map/recommendations` | Fresh latitude/longitude/accuracy/captured_at; server shortlists up to five owned ready pinned stops across intake weeks, then ranks returned time, road distance and stable identity |
| GET `/api/v1/map/tiles/{z}/{x}/{y}` | Bearer-protected fixed PNG proxy; bounded coordinates, cached bytes and a separate tile throttle preserve delivery API capacity |

Provider configuration is only in ignored `api/.env`: `GEOAPIFY_API_KEY` and `GEOAPIFY_CREDIT_LIMIT=2500` (clamped to2500). One server key serves maps and comparisons; no public browser key or paid Google API is needed. Keep the provider project on Free and rotate keys exposed in messages. Provider URLs/bodies/errors/keys never reach clients; the provider receives coordinates and vehicle only. Preserve Geoapify/OpenStreetMap attribution. Production phone GPS needs HTTPS and browser permission; a LAN HTTP IP is not localhost.

The quota is the app's conservative accounting, not a live provider balance. SQL `map_usage` buckets store quarter-credits under a global row lock, covering the current and previous24 UTC hours so midnight/cache clearing cannot reset spent credits. Tiles reserve0.25; matrix calls reserve the candidate count plus500 credits for distance surcharges, releasing a successful known remainder. Uncertain failures retain the reservation and may pause comparisons earlier. No provider retry loop or automatic paid upgrade exists. Cached geometry/tiles require no new provider request; clearing `php artisan cache:clear --store=maps` does not reset SQL usage. The maps file-cache store lives in storage/framework/cache/maps; include its normal cleanup in deployment operations. Untracked usage of the same provider project/keys is outside this counter.

Five new application source files: `MapRequest` validates inputs/shared GPS rules; `MapController` handles HTTP; `MapResource` allowlists output and reuses DeliveryResource; `MapService` owns private shortlist/ranking; `GeoapifyService` owns fixed HTTP/cache/quota. Supporting files are config/maps.php, one additive migration and tests/Feature/MapTest.php. Existing Auth/Settings/delivery/directory classes are extended; no repository/interface/provider-factory layer was added.

Reading order: routes/api.php → MapRequest → MapController → MapService → GeoapifyService → MapResource → MapTest. For GPS, read ParcelRequest location rules → DeliveryController::location → DeliveryService::saveLocation → shared address resource → DeliveryTest. Tests use SQLite memory and isolated array caches; native provider smoke checks use public sample coordinates, never real customer/payment records. Final verification and any browser/phone limitations are recorded in PROJECT_MEMORY.md and the maps plan.

## EC2 API deployment

The Hatid API workflow runs the existing isolated Laravel tests and packages the verified main commit on a GitHub-hosted runner. Ubuntu 26.04 provides PHP 8.5 and MySQL 8.4 on the Sydney EC2 host. Laravel uses a separate MySQL database bound to loopback. GitHub Actions assumes a short-lived AWS role scoped to this repository and branch. SSM activates the checksummed bundle from a private bucket.

1. On the designated EC2 host, run `sudo bash deploy/setup-ec2.sh prepare` once. It creates the private production environment and database, and installs the release helper. The initial web listener is loopback only.
2. In the authorized AWS CloudShell, run `bash deploy/setup-aws.sh INSTANCE_ID REGION`. The script checks the instance, applies the scoped CloudFormation stack, attaches the instance profile and web security group, and associates an Elastic IP. It prints a JSON deployment target.
3. On the instance, run `sudo bash deploy/setup-ec2.sh https ELASTIC_IP RELEASE_BUCKET`. This prepares Nginx and a Let's Encrypt IP certificate with automatic renewal. Set `FRONTEND_URL` and `ADMIN_URL` in `/srv/hatid/shared/.env` to the actual client origins when those clients are hosted. Configure live email and map credentials privately on the server.
4. Save the target JSON as the GitHub Actions repository variable `HATID_DEPLOY_TARGET`. Run the Hatid API workflow on main for the first deploy. Later main pushes run it automatically. Use the verified HTTPS origin for the rider and admin API URL.

Each deploy verifies the bundle digest, saves the current database under `/srv/hatid/backups/`, applies pending migrations and switches the web release after the checks. Retries preserve the backup made for the previous active release. Keep migrations compatible with the previous app release so a failed health check can restore the prior code. Use a backup outside the EC2 volume before accepting live customer records.

After editing the server environment, run `sudo -u www-data php8.5 /srv/hatid/current/artisan config:cache --no-interaction` and `sudo systemctl reload php8.5-fpm`. Reinstall the root-owned release helper through the server setup when changing the helper itself.
