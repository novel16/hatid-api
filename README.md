# Hatid API

Laravel 13.39 / PHP 8.4 / Sanctum 4.3.3. This API serves the rider PWA and the separate admin app.

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
| GET | /tokens?page=1&per_page=15 | Owned, unexpired tokens; maximum 100 per page |
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

## Configuration and recovery

See .env.example. Defaults are explicit and configurable:

- FRONTEND_URL=http://localhost:3000
- ADMIN_URL=http://localhost:3001
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

Verified after the 2 November 2026 refactor: 57 tests / 317 assertions passed, including all original assertions and seven added required-field cases. Pint, optimized Composer autoload generation with strict PSR checks, route inspection and live missing-token/login-validation checks passed. All 17 endpoints retain their paths, route names, response contracts and security middleware.

The repository's AGENTS.md records the ongoing coding standards. Laravel Boost was installed as a development dependency to complete the repository's required agent bootstrap; it is not part of the authentication runtime.
