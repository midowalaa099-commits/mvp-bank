# MVP Bank API

A simulated banking API built in reviewable stages with Laravel and MySQL.
Start with EGP; transfers must use the same currency.

## Completed MVP

Laravel 13.33.0 and Sanctum 4.3.3 are installed. Exact dependency versions are recorded in `composer.lock`.
Authentication, accounts, simulated money operations, transaction history, admin controls, audit history, tests, and API documentation are implemented. The local application database is migrated and EGP is seeded. Separate application and test database credentials are configured in ignored environment files. No customer or admin login is pre-created.

## Start here

Requirements: 64-bit PHP 8.3 or newer, Composer, and MySQL 8.4 or newer (verified with MySQL 9.6). No frontend build is needed.

Start this prepared workspace:

```sh
composer dev
```

Import [`docs/openapi.json`](docs/openapi.json) into Postman or another OpenAPI client. The local base URL is `http://localhost:8000/api/v1`. The health endpoint `/health` checks application responsiveness only, not database connectivity.

Register a customer through `/auth/register`. To create an admin, run this in a separate terminal, using your own name and email:

```sh
php artisan bank:create-admin "Bank Admin" admin@example.com
```

The command asks for a hidden password and confirmation. Admins log in through the same `/auth/login` endpoint. Existing customers are never silently promoted or overwritten.

Suggested first API flow: register → save bearer token → open EGP account → deposit → view account/history → register a second customer and open their account → transfer using their account reference. Send `Content-Type: application/json`, `Accept: application/json`, and `Authorization: Bearer <access_token>` on protected JSON requests. Use a new `Idempotency-Key` for each new money operation.

## Review file by file

Start with [`docs/business-rules.md`](docs/business-rules.md), then follow: models → migrations → authentication and policies → `Money.php` and `BankingService.php` → requests/controllers/resources → admin controls → tests and OpenAPI. The tables below explain each group.

### Banking review order

| File | Purpose |
| --- | --- |
| `app/Support/Money.php` | Converts decimal strings to integer minor units and back without floating-point arithmetic. |
| `app/Services/BankingService.php` | Opens accounts and executes deposits, withdrawals, and transfers inside database transactions. |
| `app/Http/Requests/Accounts/StoreAccountRequest.php` | Validates the requested currency and rejects injected ownership or balance fields. |
| `app/Http/Requests/Accounts/MoneyOperationRequest.php` | Validates amount strings, destination references, and the required request-key header. |
| `app/Http/Controllers/Api/V1/AccountController.php` | Creates accounts and returns authorized account lists/details. |
| `app/Http/Controllers/Api/V1/MoneyOperationController.php` | Connects the three money endpoints to the banking service. |
| `app/Http/Controllers/Api/V1/TransactionController.php` | Returns authorized transaction lists and details. |
| `app/Http/Resources/AccountResource.php` | Returns account references, currency, decimal balance strings, and status. |
| `app/Http/Resources/TransactionResource.php` | Returns receipts without exposing another customer's balance or internal request hashes. |

The service locks the acting user's row before checking request keys, then locks affected accounts in ascending ID order before changing balances. Each operation and its history commit together. Deadlocks retry up to three attempts. Serializing one customer's operations is a deliberate simple MVP design; different customers can still operate concurrently.

### Banking endpoints

All paths below begin with `/api/v1` and require a bearer token. Account and transaction placeholders are public UUID references, not numeric IDs.

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/accounts` | Open an account using `{"currency":"EGP"}`. |
| GET | `/accounts` | List accessible accounts. |
| GET | `/accounts/{account}` | View balance and status. |
| POST | `/accounts/{account}/deposits` | Simulate adding money to your account. |
| POST | `/accounts/{account}/withdrawals` | Withdraw from your account. |
| POST | `/accounts/{account}/transfers` | Send money from your account to another account. |
| GET | `/accounts/{account}/transactions` | View incoming and outgoing account history. |
| GET | `/transactions` | List transactions you can access. |
| GET | `/transactions/{transaction}` | View one transaction receipt. |

For money requests, send a new `Idempotency-Key` header, for example `payment-001`, plus a JSON body. Deposits and withdrawals use `{"amount":"125.50"}`. Transfers use:

```json
{
  "amount": "125.50",
  "destination_account": "<recipient-account-reference>"
}
```

Amounts must be strings. EGP supports at most two decimal places; `"10"`, `"10.0"`, and `"10.00"` mean the same amount. JSON numbers, negative/zero amounts, exponents, excess decimals, and integer overflow are rejected. For a transfer, use the recipient's shared account reference; it does not grant permission to view their account.

A new money operation returns 201 with a receipt under `data`. Retry the same operation with the same key after a network failure: it returns 200 with the original receipt and makes no balance change. Reusing that key for different details returns 409. Keys belong to the customer across all money endpoints and are case-sensitive. Failed operations do not reserve a key. Successful retries remain readable after a freeze, provided the caller is still authorized.

Insufficient balance, frozen accounts, balance overflow, and duplicate account opening return 409. Invalid amounts, missing keys, and invalid transfer destinations return 422. Lists return 25 records per page, with pagination links and metadata. Transactions are ordered newest first, using the database ID to break timestamp ties.

### Authentication review order

| File | Purpose |
| --- | --- |
| `routes/api.php` | Public registration/login routes and token-protected me/logout routes. |
| `app/Http/Requests/Auth/RegisterRequest.php` | Validates registration, normalizes email, rejects role input. |
| `app/Http/Requests/Auth/LoginRequest.php` | Validates login input and normalizes email. |
| `app/Rules/BcryptPassword.php` | Rejects passwords exceeding bcrypt's 72-byte limit or containing null characters. |
| `app/Http/Controllers/Api/V1/AuthController.php` | Creates customers, verifies passwords, issues tokens, returns the current user, and revokes the current token. |
| `app/Http/Resources/UserResource.php` | Explicitly exposes only id, name, email, and role. |
| `config/sanctum.php` | Uses bearer tokens with a 24-hour lifetime and no session-cookie authentication. |
| `app/Providers/AppServiceProvider.php` | Registers rate limits, policies, and the admin-access gate. |
| `app/Policies/AccountPolicy.php` | Defines account ownership and admin permissions. |
| `app/Policies/TransactionPolicy.php` | Allows participants and admins to view a transaction. |

Registration saves the customer and token together so a token failure does not leave a partially registered user. Login returns the same 401 message for unknown emails and incorrect passwords. Responses carrying tokens or user details use `Cache-Control: no-store`.

| Method | Path | Input / result |
| --- | --- | --- |
| POST | `/api/v1/auth/register` | name, email, password, password_confirmation → 201 with user and token |
| POST | `/api/v1/auth/login` | email, password → 200 with user and token |
| GET | `/api/v1/auth/me` | Bearer token → 200 with current user |
| POST | `/api/v1/auth/logout` | Bearer token → 204; revokes only that token |

Registration example:

```json
{
  "name": "Demo Customer",
  "email": "customer@example.com",
  "password": "example-password-123",
  "password_confirmation": "example-password-123"
}
```

Registration and login return `data.user`, `data.access_token`, `data.token_type` (Bearer), and `data.expires_at`. Send the returned token as `Authorization: Bearer <access_token>` for protected requests. `/auth/me` returns the user under `data`. Login again after token expiry. Examples are request data, not seeded credentials.

The initial limits are 5 registrations per IP/minute, 5 logins per email/IP/minute plus 30 logins per IP/minute, and 60 authenticated requests per user/minute. Invalid input returns 422, invalid authentication 401, forbidden admin access 403, hidden records 404, and throttled requests 429.

Banking endpoints call the policies and filter customer lists by ownership: `viewAny` permits access to a listing endpoint, not to every row. The money-operation service checks freezes, balance limits, and current permissions. Admins can inspect accounts and history but cannot move customer money. Admin freeze/unfreeze endpoints and audit history are implemented below.

### Migration review order

| File in `database/migrations/` | Purpose |
| --- | --- |
| `2026_09_23_043748_add_role_to_users_table.php` | Adds customer/admin roles; new users default to customer. |
| `2026_09_23_043749_create_currencies_table.php` | Stores unique currency codes and decimal precision. |
| `2026_09_23_043750_create_accounts_table.php` | Stores balances and ownership; allows one account per user per currency. |
| `2026_09_23_043751_create_transactions_table.php` | Stores completed operations and protects their account/currency links. |
| `2026_09_23_053829_create_account_status_changes_table.php` | Records admin status changes, reasons, and actors; protects audit relationships. |

MySQL rejects negative balances, zero or negative transaction amounts, duplicate public references, and invalid deposit/withdrawal/transfer account combinations. Composite foreign keys link each account together with its currency, preventing cross-currency transactions. Referenced accounts and users cannot be deleted. A unique customer/request-key pair prevents duplicate operation records; the banking service handles equivalent and conflicting retries. Request keys are case-sensitive ASCII strings of up to 128 characters.

Balances and amounts use signed BIGINT columns with non-negative/positive checks so their full valid range fits a 64-bit PHP integer. Database constraints do not authorize operations, check freezes, or update balances automatically.

`database/seeders/DatabaseSeeder.php` calls `CurrencySeeder.php`. Seeding adds EGP with two decimal places and can be repeated without duplicating or changing it. No login accounts or sample money are seeded.

`database/factories/` provides test records: customer/admin users, EGP currency, active/frozen accounts, and deposit/withdrawal/transfer records. Seed EGP before using `AccountFactory`, or supply a currency with `for($currency)`. Transaction factories create history fixtures only; they do not move money or adjust balances.

### Model review

| File | Purpose |
| --- | --- |
| `app/Models/User.php` | A customer or admin, their accounts, operations they initiated, and Sanctum token support. Public input cannot assign the role. |
| `app/Models/Currency.php` | Currency code, name, and decimal precision; relates to accounts and transactions. EGP is seeded with two decimal places. |
| `app/Models/Account.php` | Owner, currency, public UUID reference, integer balance in minor units, and active/frozen status. Links to incoming and outgoing transactions. |
| `app/Models/Transaction.php` | One completed deposit, withdrawal, or transfer; relates to its initiator, currency, and affected accounts. |
| `app/Models/AccountStatusChange.php` | An immutable status change with its account, admin, reason, and timestamp. |
| `app/Enums/UserRole.php` | Allowed roles: customer and admin. |
| `app/Enums/AccountStatus.php` | Allowed account states: active and frozen. |
| `app/Enums/TransactionType.php` | Allowed operations: deposit, withdrawal, and transfer. |

An enum defines a fixed set of allowed values. Models cast stored strings into these enum values automatically.

Transaction fields: `reference`, `initiated_by`, `currency_id`, `type`, `amount_minor`, `source_account_id`, `destination_account_id`, `idempotency_key`, `request_hash`, and `created_at`.

| Operation | Source account | Destination account |
| --- | --- | --- |
| Deposit | None | Receiving account |
| Withdrawal | Paying account | None |
| Transfer | Paying account | Receiving account |

Each transaction amount is positive and expressed in minor units: EGP 10.25 is 1025 piastres. Account history must include both incoming and outgoing relationships. A user's initiated transactions do not include transfers initiated by someone else into their account.

Only successful operations create transaction rows, inside the same database transaction as balance changes. No pending status is needed. Request keys and hashes prevent duplicate operations and detect conflicting retries.

Account, currency, and transaction fields use Laravel's default protection against bulk assignment. Trusted application code will set their fields explicitly. This protection does not replace validation or authorization.

Ordinary model updates and deletes reject changes to completed transactions. Account models reject changes to ownership, currency, or public reference; currencies in use reject code or precision changes. Bulk queries, raw SQL, or disabling model events bypass these model protections. API routes expose no such edits. The banking service enforces balances, freezes, and atomic transfers.

Banking tables and relationships are tested in the separate `mvp_bank_test` database. The application database `mvp_bank` is already migrated and seeded in this workspace.

### Files to review

| File | Purpose |
| --- | --- |
| `docs/business-rules.md` | Implemented banking rules and confirmed currency decisions. |
| `composer.json` | PHP dependencies and commands to set up, run, and test the API. |
| `.env.example` | Shareable configuration template using MySQL and database name `mvp_bank`. |
| `bootstrap/app.php` | Loads API routes under `/api/v1` and returns JSON for API errors. |
| `routes/api.php` | Defines the 17 versioned API operations and their middleware. |
| `docs/openapi.json` | Importable API specification, including requests, responses, authentication, errors, and pagination. |
| `routes/web.php` | Provides a small JSON introduction at `/`. |

The setup also includes the official user, cache, job, and Sanctum token migrations. Laravel Boost was added as a development dependency as required by the repository setup instructions.

## Admin controls

All paths below begin with `/api/v1`, require a bearer token, and require the admin role.

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/admin/accounts/{account}/freeze` | Stop money entering or leaving an account. |
| POST | `/admin/accounts/{account}/unfreeze` | Restore money operations. |
| GET | `/admin/accounts/{account}/status-changes` | Read status changes, reasons, actors, and timestamps. |

Freeze and unfreeze requests require a reason:

```json
{"reason":"Account review requested"}
```

Reasons contain 3–500 characters. Status and audit history commit together while holding an account lock. Repeating the current status succeeds without creating another audit entry. Account reads and existing receipts remain available while frozen. Admins cannot edit balances or move customer money. The same account lock serializes freezes with money operations: whichever obtains the lock first completes first.

| File | Purpose |
| --- | --- |
| `app/Services/AccountStatusService.php` | Authorizes and atomically changes status with an audit record. |
| `app/Http/Requests/Admin/ChangeAccountStatusRequest.php` | Validates the reason and admin access. |
| `app/Http/Controllers/Api/V1/Admin/AccountStatusController.php` | Handles freeze, unfreeze, and audit history requests. |
| `app/Http/Resources/AccountStatusChangeResource.php` | Formats audit history without sensitive user fields. |
| `app/Console/Commands/CreateAdmin.php` | Creates an admin privately with a hidden password prompt. |
| `routes/console.php` | Schedules daily removal of expired tokens after a 24-hour grace period. |

Expired tokens are rejected even without cleanup. To run the cleanup schedule locally, use `php artisan schedule:work` in a separate terminal.

## Setup for a fresh copy

The current workspace is ready. For another installation:

1. Run `composer setup` once to install dependencies, create `.env` if absent, and generate the application key. Do not rerun it on an existing application: it generates a new key.
2. Create an empty MySQL database named `mvp_bank` and a database user restricted to that database. Set its credentials in `.env`; template root credentials are placeholders.
3. Run `php artisan migrate --seed` to create tables and seed EGP.
4. Run `composer dev`, then create customer accounts through the API and an admin through the private command above.

Credentials stay in ignored environment files. Sessions and cache use files; queued work runs synchronously. Rollbacks remove tables and their data; use them only on disposable databases.

## Verification

```sh
composer validate --strict
php artisan route:list
composer test
```

The full suite passed on 2026-09-23: **132 tests, 517 assertions**. It covers models, MySQL constraints, authentication, permissions, decimal precision, money operations, retries, history privacy, rollback, admin controls, and admin creation. Five concurrency tests use separate PHP processes and inspect MySQL lock waits to verify overlapping requests: double-spend prevention, duplicate retries, opposing transfers, duplicate account opening, and account freezing.

The dedicated `mvp_bank_test` database and `.env.testing` are already configured in this workspace. Tests rebuild this database; never store application data there. The application database is separate. `phpunit.xml` fixes the test database name, and `tests/TestCase.php` rejects cached configuration or an unexpected database configuration before tests run.

For a fresh copy, create an empty `mvp_bank_test` database, copy `.env.testing.example` to `.env.testing`, generate its key with `php artisan key:generate --env=testing`, and configure a user with access only to that database. Concurrency tests additionally need SELECT permission on `performance_schema.data_locks` and `performance_schema.data_lock_waits`. The local test user has those grants.

OpenAPI documentation is validated against the OpenAPI 3.0 schema and checked against the actual route list. No frontend or real payment-provider integration is included in this API MVP.
