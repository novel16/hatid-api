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

## Simplicity and review
- The user is learning from and reviewing this implementation. Prefer a compact, cohesive structure and avoid unnecessary files or abstractions.
- Keep related code in an existing class when its responsibility remains clear. Create separate files only for an actual responsibility, meaningful reuse, complexity or framework convention. Preserve the requested service, Form Request and Resource boundaries and all security behavior.
- Do not add speculative repositories/interfaces, one-method forwarding layers or a class for each trivial helper. Do not collapse unrelated concerns into a giant service just to reduce file count.
- Explain new files and provide a short reading order for one endpoint at a time, including validation, authorization, service logic, response and relevant tests. Separate source changes from tests/migrations/configuration in review summaries.
- The authorized Ponytail auth refactor uses one AuthController, one AuthRequest and three focused services. Reuse User::normalizeEmail(), AuthRequest::newPasswordRules() and the controller's authenticate() helper. AuthRequest selects validation by controller action; update that match and its regression cases if an action changes. Use TokenResource::collection() for standard pagination instead of restoring a forwarding collection class.

## Runtime access
Application path: /home/novel/projects/hatid/api, mounted as /var/www/html in hatid-laravel.test-1. Use Sail in WSL. If wsl.exe execution times out, native Docker exec with --user sail --workdir /var/www/html is available; do not restart WSL or disrupt other projects just to run commands.
