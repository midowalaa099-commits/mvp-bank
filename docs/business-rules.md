# MVP Bank — business rules

Status: MVP implemented and tested; ready for your review. Currency choices are confirmed. The business rules below describe the current implementation.

## Confirmed scope

- Build from scratch using the latest stable Laravel release, REST APIs, and MySQL.
- Current framework target: Laravel 13, requiring PHP 8.3 or newer.
- Customer and admin roles; authentication using Laravel Sanctum.
- Accounts, balances, deposits, withdrawals, internal transfers, and transaction history.
- Admins can freeze and unfreeze accounts.
- Start with EGP only; keep the design ready for additional currencies.
- Transfers are allowed only between accounts using the same currency. No currency exchange.

Version reference: https://laravel.com/framework/docs/releases (checked 2026-09-23).

## Implemented rules

### Users and access

- Customers and admins share the users table, distinguished by a role.
- Public registration always creates a customer. Admin access is assigned privately.
- Customers can view and manage only their own accounts and history.
- A customer can send money to another customer's account without gaining access to its balance or history.
- Admins can inspect accounts and history and change account status; they cannot directly edit balances or transaction records.

### Accounts and currencies

- Each account belongs to one customer and uses one currency permanently.
- A customer may open one account per supported currency; each starts with zero balance.
- Each account has a unique public account reference and an active or frozen status.
- Frozen accounts remain readable, but cannot receive or send money, including deposits and withdrawals.
- Currency definitions include a code and decimal precision. Precision cannot change once used.

### Authentication defaults

- Registration accepts name, email, password, and password confirmation; it always creates a customer. Supplying a role is rejected.
- Email addresses are trimmed and normalized to lowercase for registration and login.
- New passwords require at least 12 characters. The bcrypt password limit is 72 bytes; null characters are rejected.
- Registration and login return a bearer token valid for 24 hours. Login again after expiry.
- Logout revokes only the token used for that request, leaving other devices logged in.
- Registration is limited to 5 requests per minute per IP. Login is limited to 5 requests per minute per email/IP pair and 30 per IP overall.
- Authenticated API routes use a 60-request-per-minute limit per user.
- Missing, invalid, or expired credentials return 401; invalid input returns 422; rate limits return 429.
- Admin-only access requires the user's current admin role. A token never bypasses ownership or role checks.
- Customers receive 404 when attempting to view another customer's account or an unrelated transaction.
- Admins can inspect records and freeze/unfreeze accounts, but cannot initiate customer money operations or directly edit transaction history.

### Admin controls

- Create admins privately using `bank:create-admin`, with a hidden password and confirmation. Public registration cannot create admins.
- Existing users cannot be promoted or overwritten through this command.
- Only admins can freeze/unfreeze accounts or read status audit history.
- A status change requires a reason of 3–500 characters and records the admin, previous/new status, and timestamp.
- Status changes and their audit records commit together under an account lock, serializing with money operations.
- Repeating the current status succeeds without another audit row.
- Audit history cannot be edited or deleted through the API or ordinary model operations.

### Money operations

- This MVP uses simulated money and has no external bank or payment-provider connection.
- Customers can simulate deposits to, and withdrawals from, their own active accounts.
- Amounts must be positive and match the currency's decimal precision.
- Store money as integer minor units (for example, EGP 10.25 as 1025 piastres); never calculate money using floating-point values.
- API amounts are decimal strings. Reject excess decimal places and values outside the supported storage range.
- No overdrafts: withdrawals and transfers cannot exceed the available balance.
- Transfers require distinct source and destination accounts. The sender must own the source.
- A transfer and its history succeed together or leave all balances unchanged.
- Concurrent requests must not spend the same funds twice.
- Retrying a successful money request with the same customer-scoped idempotency key must not move money again. Reusing that key with different details is rejected.
- Every successful operation records its reference, actor, affected accounts, currency, amounts, and timestamp.
- Financial history is immutable; no API can edit or delete completed operations.
- No fees, interest, loans, cards, or account deletion in this first MVP.
- Money endpoints require an `Idempotency-Key` header of 1–128 characters: letters, digits, dots, underscores, colons, or hyphens. Keys are case-sensitive and shared across a customer's money operations.
- A new successful operation returns 201. An authorized retry with the same key and equivalent amount returns the original transaction with 200, even if the account was frozen afterward.
- Reusing a successful key for a different operation, amount, source, or destination returns 409. Failed operations do not reserve a key.
- Insufficient funds, frozen accounts, balance overflow, and duplicate account opening return 409. Invalid amounts or transfer destinations return 422.
- Account and transaction lists contain 25 records per page. Customers see only their own accounts and transactions involving those accounts; admins can inspect all records.

## Confirmed currency decisions

1. Initial supported currency: EGP, with two decimal places.
2. Transfers: same-currency only; reject transfers between different currencies.

## Delivery and review

You authorized finishing all remaining files before review. The MVP now includes:

1. Laravel and MySQL configuration and the API foundation.
2. Models, relationships, migrations, factories, and EGP seed data.
3. Authentication, account ownership, and admin access rules.
4. Accounts, deposits, withdrawals, same-currency transfers, and history.
5. Freeze/unfreeze controls, audit history, and private admin creation.
6. Automated tests, concurrency checks, and importable OpenAPI documentation.

The application database is migrated and seeded. Tests use a separate database. README.md provides the file-by-file review guide and run instructions. Changes to these business rules can be made during your review.
