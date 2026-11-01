# Hatid API development rules

Read CLAUDE.md for the installed Laravel/PHP/PHPUnit/Pint conventions. Bootstrap is complete: PHP 8.4, Composer and Laravel Boost are available inside the Sail container; do not reinstall host PHP or Composer. Sanctum is a runtime dependency, Boost is development-only.

The user explicitly authorized implementation of API authentication for both the rider client and admin. Related dependencies, additive migrations, configuration and meaningful tests are authorized. Frontend/admin application code is out of scope.

## Coding standards
- Keep controllers thin. Put business logic in focused services under app/Services and use explicit input/return types.
- Use Form Requests for validation and API Resources/Resource Collections as response allowlists. Never return raw models, password hashes or token hashes.
- Use readable names, cohesive methods, explicit types and the existing Laravel conventions. Avoid unnecessary abstractions and duplicated business rules.
- Use Sanctum personal access tokens via Authorization: Bearer. No statefulApi middleware, web/session guard fallback or CSRF-cookie login flow. These tokens are database-backed, not self-contained JWTs.
- Enforce role, ownership and token permissions on the server. Public registration can create riders only. Admin provisioning uses the interactive CLI, with no default password or public admin registration.
- Test real Bearer headers, role/ownership boundaries, validation, token expiry/revocation and response serialization. Keep test databases isolated from local development data.
- Run relevant PHPUnit feature tests, Pint and git diff --check. Do not reset data or run migrate:fresh against development data.

## Runtime access
Application path: /home/novel/projects/hatid/api, mounted as /var/www/html in hatid-laravel.test-1. Use Sail in WSL. If wsl.exe execution times out, native Docker exec with --user sail --workdir /var/www/html is available; do not restart WSL or disrupt other projects just to run commands.
