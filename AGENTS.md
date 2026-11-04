# Hatid API development rules

Read CLAUDE.md for the installed Laravel/PHP/PHPUnit/Pint conventions. The active project is now the native Windows copy: PHP8.4.27 from C:/xampp/php and Composer2.8.10 are installed and verified. Preserve that installation. Sanctum is a runtime dependency, Boost is development-only.

The user explicitly authorized implementation of API authentication for both the rider client and admin. Related dependencies, additive migrations, configuration and meaningful tests are authorized. Frontend/admin application code is out of scope.

The latest customer/seller feature request additionally authorizes their API and rider-frontend implementation, additive directory migrations and relevant tests. The user confirmed a private directory per rider. Keep server ownership checks on all parent/child records, real data separate from demo workflows and GPS writes gated by actual delivery evidence. Admin UI and unrelated business APIs remain outside this stage.

## Required skills, simplicity and comments

- Always use the installed Ponytail skill for every feature and other coding change, including fixes and refactors. Follow its workflow before implementation.
- Use the relevant Superpowers skills when needed, such as for planning, debugging, testing or verification. Keep the workflow proportionate to the task and within the user's authorized scope.
- Avoid over-engineering and over-architecture. Add abstractions, layers, dependencies or files only for a demonstrated requirement or meaningful reuse; do not build speculative flexibility.
- Prefer clear names, straightforward control flow, guard clauses and small cohesive functions. Avoid deep nesting, clever shortcuts or complex generic code when a simple, readable implementation works. Do not sacrifice readability just to shorten code.
- Reuse existing helpers/components and native or framework features before introducing custom solutions. Preserve validation, authorization, security, accessibility, data integrity and meaningful tests when simplifying.
- Add a brief purpose comment or docblock before every function and method, including small helpers and callbacks. Use the language's usual style, including PHPDoc for PHP. Explain intent or the business rule, keep comments accurate, and avoid narrating each line of code.
- Apply the comment rule to every new or modified function/method. Do not perform a broad comment-only rewrite of untouched code unless the user requests it.

## Device resource limits

- The user's device has 8 GB RAM and an 11th-generation Intel Core i5. Run tests, builds and page compilation sequentially, with only one heavy operation active at a time. Never launch multiple suites/builds/compilers together.
- Keep checks focused on the change, and do not repeat passing suites without a new reason. Start the Next.js apps one at a time; reuse an existing valid production build for previews when possible.
- If a task-owned process stalls, stop it safely before trying another heavy operation. Preserve unrelated processes and follow the project runtime rules for any WSL/Docker recovery.

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
Local client URLs are http://localhost:3011 for the rider frontend and http://localhost:3012 for admin. Keep clients.php, CORS origins, .env.example and local environment URLs aligned; password reset links use these client URLs. Bearer auth remains cookie-free.

Active application path: C:/Users/Novel/Desktop/laravel projects/hatid/api. Use native Windows PHP/Composer from this copy and quote paths containing spaces. The previous /home/novel/projects/hatid/api is a legacy copy, not the active source directory. Preserve its database/data until migration is verified; do not restart WSL or disrupt other projects just to run commands.

Native readiness check on3 November: Composer platform requirements passed, Laravel13.39.0 booted, and80 tests/549 assertions passed using isolated SQLite memory. The subsequent explicit frontend/API fix request authorized local runtime repairs. The user chose a new empty XAMPP hatid database and will create their own account; six normal additive migrations were applied without importing/seeding users or touching the legacy Docker data. Native API is http://127.0.0.1:8000, APP_URL matches that address, and PHP_CLI_SERVER_WORKERS=1 avoids the old multi-worker warning. Preserve the user's local database credentials. API startup is in their existing VS Code terminal; avoid starting a duplicate server.
