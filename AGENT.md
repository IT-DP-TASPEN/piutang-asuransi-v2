## General code instructions
 
- Don't generate code comments above the methods or code blocks if they are obvious. Don't add docblock comments when defining variables, unless instructed to, like `/** @var \App\Models\User $currentUser */`. Generate comments only for something that needs extra explanation for the reasons why that code was written.
- For new features, you MUST generate Pest automated tests.
- For library documentation, if some library is not available in Laravel Boost 'search-docs', always use context7. Automatically use the Context7 MCP tools to resolve library id and get library docs without me having to explicitly ask.
- Understand the existing implementation before editing.
- Make the smallest correct change.
- Avoid speculative abstractions.
- Do not rewrite unrelated files.
- Do not reformat entire files unless the project tooling does that automatically.
- Prefer explicit, readable code over clever code.
- Avoid hidden side effects in model accessors, casts, observers, and Filament callbacks.
- Never commit secrets, credentials, tokens, production URLs, or private keys.
- Never hardcode user-specific, branch-specific, or environment-specific values unless the existing project already does and the task requires it.
- Do not run destructive commands unless explicitly requested.

Forbidden unless explicitly approved:

```bash
php artisan migrate:fresh
php artisan migrate:refresh
php artisan db:wipe
php artisan schema:dump --prune
rm -rf storage
rm -rf database
rm -rf vendor
rm -rf node_modules
```

---

## First Things To Inspect

Before implementing a task, check the relevant project context:

1. `composer.json`
   - Laravel version
   - Filament version
   - PHP version
   - installed packages
   - scripts for test, lint, format, static analysis

2. `package.json`
   - frontend build tooling
   - Tailwind / Vite setup
   - formatting scripts

3. Existing Laravel structure:
   - `app/Models`
   - `app/Policies`
   - `app/Http/Requests`
   - `app/Actions`
   - `app/Services`
   - `app/Jobs`
   - `database/migrations`
   - `database/factories`
   - `routes`

4. Existing Filament structure:
   - `app/Providers/Filament`
   - `app/Filament/Resources`
   - `app/Filament/Pages`
   - `app/Filament/Widgets`
   - `app/Filament/Clusters`
   - `app/Filament/Resources/*/Pages`
   - `app/Filament/Resources/*/RelationManagers`

5. Existing naming conventions:
   - Resource names
   - page names
   - action names
   - form schema style
   - table column style
   - authorization style
   - whether the project uses Services, Actions, DTOs, or simple controllers/pages

Do not introduce a new architecture unless the task explicitly requires it.

---
 
## PHP instructions

- In PHP, use `match` operator over `switch` whenever possible
- Generate Enums always in the folder `app/Enums`, not in the main `app/` folder, unless instructed differently.
- Always use Enum value as the default in the migration if column values are from the enum. Always casts this column to the enum type in the Model.
- Don't create temporary variables like `$currentUser = auth()->user()` if that variable is used only one time.
- Always use Enum where possible instead of hardcoded string values, if Enum class exists. For example, in Blade files, and in the tests when creating data if field is casted to Enum then use that Enum instead of hardcoding the value.

---
 
## Laravel instructions

### General PHP / Laravel Style

- Follow PSR-12 and Laravel style.
- Use clear method names.
- Add return types where practical.
- Use constructor property promotion where it improves readability.
- Use `readonly` only when it genuinely fits.
- Prefer dependency injection over facades for complex services, but facades are acceptable for common Laravel features.
- Keep controllers, Filament pages, and Livewire components thin.
- Move reusable business logic into Actions or Services when the logic is non-trivial.
- Do not create generic repository classes unless the project already uses repositories consistently.
- Use Laravel conventions before custom patterns.
- Using Services in Controllers: if Service class is used only in ONE method of Controller, inject it directly into that method with type-hinting. If Service class is used in MULTIPLE methods of Controller, initialize it in Constructor.
- **Eloquent Observers** should be registered in Eloquent Models with PHP Attributes, and not in AppServiceProvider. Example: `#[ObservedBy([UserObserver::class])]` with `use Illuminate\Database\Eloquent\Attributes\ObservedBy;` on top
- Aim for "slim" Controllers and put larger logic pieces in Service classes
- Use Laravel helpers instead of `use` section classes. Examples: use `auth()->id()` instead of `Auth::id()` and adding `Auth` in the `use` section. Other examples: use `redirect()->route()` instead of `Redirect::route()`, or `str()->slug()` instead of `Str::slug()`.
- Don't use `whereKey()` or `whereKeyNot()`, use specific fields like `id`. Example: instead of `->whereKeyNot($currentUser->getKey())`, use `->where('id', '!=', $currentUser->id)`.
- Don't add `::query()` when running Eloquent `create()` statements. Example: instead of `User::query()->create()`, use `User::create()`.
- In Livewire projects, don't use Livewire Volt. Only Livewire class components.
- When adding columns in a migration, update the model's `$fillable` array to include those new attributes.
- Never chain multiple migration-creating commands (e.g., `make:model -m`, `make:migration`) with `&&` or `;` — they may get identical timestamps. Run each command separately and wait for completion before running the next.
- Enums: If a PHP Enum exists for a domain concept, always use its cases (or their `->value`) instead of raw strings everywhere — routes, middleware, migrations, seeds, configs, and UI defaults.
- Controllers: Single-method Controllers should use `__invoke()`; multi-method RESTful controllers should use `Route::resource()->only([])`
- Don't create Controllers with just one method which just returns `view()`. Instead, use `Route::view()` with Blade file directly.
- Always use Laravel's @session() directive instead of @if(session()) for displaying flash messages in Blade templates.
- In Blade files always use `@selected()` and `@checked()` directives instead of `selected` and `checked` HTML attributes. Good example: @selected(old('status') === App\Enums\ProjectStatus::Pending->value). Bad example: {{ old('status') === App\Enums\ProjectStatus::Pending->value ? 'selected' : '' }}.

### Eloquent Queries

- Prevent N+1 queries.
- Use `with()`, `withCount()`, `withSum()`, `withExists()`, or eager-loaded relationships where appropriate.
- Select only needed columns when handling large datasets.
- Use chunking / lazy collections for large jobs.
- Use database transactions for multi-step writes that must succeed or fail together.
- Add indexes when introducing frequent filters, joins, or unique constraints.
- Keep reporting-heavy SQL readable and tested.

### Validation

Use the right validation layer:

- Form Request classes for HTTP endpoints.
- Filament field validation for UI-level validation.
- Dedicated domain validation inside Actions / Services for business rules that can be triggered from multiple places.
- Database constraints for invariants that must never be violated.

Do not rely only on frontend / hidden fields.

### Authorization

Use Policies / Gates for domain authorization.

For Filament:

- Ensure Resources respect policies.
- Do not hide buttons only in the UI while leaving actions executable.
- Apply query scoping for role / branch / tenant restrictions.
- Treat table filters and form defaults as UX helpers, not security boundaries.

### Jobs, Queues, and Long-Running Work

Use queued Jobs for slow work such as:

- importing large files
- exporting reports
- calling slow external services
- sending notifications in bulk
- running RPA-like tasks
- processing documents

Jobs should be:

- idempotent where possible
- safe to retry
- explicit about timeout / tries when needed
- careful with database transactions
- clear about failure handling

Do not put long-running work directly inside Filament actions unless it is intentionally synchronous and fast.

### Migrations

Migrations must be safe and reversible where practical.

Rules:

- Do not modify old migrations if they may already be deployed.
- Create a new migration for schema changes.
- Add indexes for common filters and joins.
- Use foreign keys when the existing schema style supports them.
- Avoid destructive column changes without a migration plan.
- For large production tables, avoid blocking operations where possible.

When adding columns:

```php
$table->string('status')->index();
$table->timestamp('approved_at')->nullable();
$table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
```

### Config and Environment

- Use `config/*.php` for configurable behavior.
- Use `.env` only as the source for environment-specific values.
- Never call `env()` outside config files unless the project already does and there is a strong reason.
- Keep production, staging, and local behavior explicit.

---
 
## Testing instructions
 
### Before Writing Tests
 
  1. **Check database schema** - Use `database-schema` tool to understand:
     - Which columns have defaults
     - Which columns are nullable
     - Foreign key relationship names
 
  2. **Verify relationship names** - Read the model file to confirm:
     - Exact relationship method names (not assumed from column names)
     - Return types and related models
 
  3. **Test realistic states** - Don't assume:
     - Empty model = all nulls (check for defaults)
     - `user_id` foreign key = `user()` relationship (could be `author()`, `employer()`, etc.)
     - When testing form submissions that redirect back with errors, assert that old input is preserved using `assertSessionHasOldInput()`.
 
---
 
## Filament Rules

- Keep business rules outside Filament UI classes when they are non-trivial.
- When generating Filament resource, you MUST generate Filament smoke tests to check if the Resource works. When making changes to Filament resource, you MUST run the tests (generate them if they don't exist) and make changes to resource/tests to make the tests pass.
- When generating Filament resource, don't generate View page or Infolist, unless specifically instructed.
- When generating Filament resource/widget, don't create the files by hand, use the appropriate Filament artisan command instead.
- When generating a `Form` or an `Infolist` prefer to use `Section::make` and make each section has `->collapsible()`, `->inlineLabel()` and `->columnSpanFull()` by default, unless stated otherwise.
- When referencing the Filament routes, aim to use `getUrl()` instead of Laravel `route()`. Instead of `route('filament.admin.resources.class-schedules.index')`, use `ClassScheduleResource::getUrl('index')`. Also, specify the exact Resource name, instead of `getResource()`.
- When writing tests with Pest, use syntax `Livewire::test(class)` and not `livewire(class)`, to avoid extra dependency on `pestphp/pest-plugin-livewire`.
- When using Enum class for Eloquent Model field, add Enum `HasLabel`, `HasColor` and `HasIcon` interfaces if aren't added yet instead of specifying values/labels/colors/icons inside of Filament Forms/Tables. **CRITICAL**: Always use the exact return type declarations from the interface definitions - do NOT substitute specific types (e.g., use `string|BackedEnum|Htmlable|null` for `getIcon()`, not `string|Heroicon|null`). When defining a default using enum never add `->value`. Refer to this docs page: https://filamentphp.com/docs/4.x/advanced/enums
- Always use Enum instead of hardcoded string value where possible, if Enum class exists. For example, in the tests, when creating data, if field is casted to Enum, then use that Enum instead of hardcoded string value.
- When adding icons, always use the Filament enum Filament\Support\Icons\Heroicon class instead of string.
- When adding actions that require authorization, use the `->authorize('ability')` method on the action instead of manually calling `Gate::authorize()` or checking `Gate::allows()`. The `authorize()` method handles both authorization enforcement and action visibility automatically.
- In Filament v4, validation rule `unique()` has `ignoreRecord: true` by default, no need to specify it.
- In Filament v4, if you create custom Blade files with Tailwind classes, you need to create a custom theme and specify the folder of those Blade files in theme.css.
- In Filament v4/v5, the `$view` property on Page classes is non-static (`protected string $view`), unlike v3 where it was static. Do NOT declare it as `protected static string $view` - this causes a "Cannot redeclare non static" fatal error.
- **Deprecated v3 methods - do NOT use:**
  - `->form()` on Actions/Filters → use `->schema()` instead
  - `->mutateFormDataUsing()` → use `->mutateDataUsing()` instead
  - `Placeholder::make()` → use `TextEntry::make()->state()` instead (import from `Filament\Infolists\Components\TextEntry`)
  - `->label('')` for hidden labels → use `->hiddenLabel()` instead

### Forms

Forms should be:

- grouped logically
- easy to scan
- validated clearly
- reactive only when necessary
- consistent with existing project layout

Prefer clear sections:

```php
Section::make('Customer Information')
    ->schema([
        TextInput::make('name')
            ->required()
            ->maxLength(255),

        TextInput::make('email')
            ->email()
            ->maxLength(255),
    ])
    ->columns(2);
```

Rules:

- Use `required()`, `maxLength()`, `numeric()`, `date()`, etc. directly where suitable.
- Use `helperText()` for fields that need business explanation.
- Use `disabled()` or `dehydrated(false)` intentionally.
- Do not trust disabled fields for security.
- Avoid excessive reactive callbacks.
- Avoid giant single-section forms.
- Use `Select::relationship()` when appropriate.
- Use searchable selects for large option lists.
- Use preload carefully for small option lists only.
- For money fields, be explicit about formatting and storage unit.

### Tables

Tables should be useful for real admin work.

Default expectations:

- important columns visible by default
- secondary columns toggleable
- relevant columns searchable
- relevant columns sortable
- practical filters
- safe row actions
- destructive actions require confirmation
- bulk actions only when safe and authorized

Example style:

```php
TextColumn::make('name')
    ->searchable()
    ->sortable();

TextColumn::make('created_at')
    ->dateTime()
    ->sortable()
    ->toggleable(isToggledHiddenByDefault: true);
```

For large tables:

- avoid loading expensive computed columns
- avoid per-row database queries
- prefer summarized data via eager loading or aggregate queries
- add indexes for common filters
- avoid default sorting on non-indexed columns for huge tables

### Actions

Actions must be explicit and safe.

For destructive or irreversible actions:

```php
Action::make('approve')
    ->requiresConfirmation()
    ->modalHeading('Approve this record?')
    ->action(fn ($record) => app(ApproveRecordAction::class)->execute($record))
    ->successNotificationTitle('Record approved');
```

Rules:

- Use confirmation modals for destructive / sensitive operations.
- Use clear success and failure notifications.
- Move business logic to Action classes for anything beyond simple field updates.
- Wrap multi-step writes in transactions.
- Re-check authorization inside the action or policy when needed.
- Do not rely only on whether the button is visible.

### Infolists

Use infolists for read-only detail pages where users need structured viewing.

- Group related entries.
- Format dates, money, statuses, and booleans clearly.
- Avoid showing raw IDs unless useful.
- Use badges for statuses when it improves scanning.
- Keep layout consistent with Forms.

### Relation Managers

Use Relation Managers when managing child records from a parent Resource.

Rules:

- Make relationship names match Eloquent relationship methods.
- Avoid duplicate business logic between parent Resource and relation manager.
- Apply authorization to relation actions.
- Keep table columns minimal but useful.
- Be careful with attach / detach on many-to-many relationships.

### Widgets and Dashboards

Widgets should answer business questions, not just display numbers.

Good widgets:

- have clear labels
- show period / filter context
- avoid expensive queries on every request
- cache when appropriate
- use query scopes or service classes for repeated metrics

Avoid:

- heavy dashboard queries without indexes
- business logic duplicated across widgets
- ambiguous metrics

### Navigation

Keep navigation organized:

- use groups for related modules
- use icons consistently
- hide navigation only for UX; use authorization for real access control
- avoid overcrowding the sidebar
- use clusters when the module has many related Resources / Pages

---

## Security Rules

Always consider:

- authentication
- authorization
- mass assignment
- file upload validation
- path traversal
- XSS from HTML rendering
- SQL injection
- unsafe redirects
- leaking internal data
- multi-tenant / branch data boundaries

For file uploads:

- validate mime type and size
- store on configured disks
- avoid exposing private files directly
- generate temporary URLs or controlled download routes when needed
- do not trust original filenames

For HTML display:

- escape by default
- only render raw HTML when content is trusted or sanitized
- be careful with `HtmlString`, `formatStateUsing()`, and custom Blade views

For database queries:

- use bindings, not string concatenation
- avoid raw SQL unless necessary
- when using raw SQL, parameterize inputs

---

## Performance Guidelines

Be careful with:

- large Filament tables
- dashboard widgets
- import / export features
- computed attributes
- relation counts
- global scopes
- repeated authorization checks
- Livewire reactivity

Use:

- pagination
- eager loading
- indexed filters
- query scopes
- queued jobs
- caching for expensive read-only metrics
- database aggregation for reporting

Do not introduce expensive per-row callbacks in tables unless the dataset is guaranteed small.

---

## Code Organization Preferences

Use this order of preference:

1. Existing project pattern
2. Laravel convention
3. Filament convention
4. Small Action / Service class
5. Custom architecture only when justified

Recommended patterns:

```text
app/Actions/{Domain}/{VerbNounAction}.php
app/Services/{Domain}/{Name}Service.php
app/Data or app/DTO only if the project already uses DTOs
```

Examples:

```text
app/Actions/Loans/ApproveLoanAction.php
app/Actions/Documents/GenerateDocumentPreviewAction.php
app/Services/Reports/LiquidityReportService.php
```

Avoid:

- dumping business logic into Filament callbacks
- creating massive Service classes
- creating repositories just to wrap Eloquent
- duplicating queries across widgets/resources/pages
- mixing unrelated module concerns

---

## Database / Reporting Notes

For reporting-style modules:

- keep query intent readable
- prefer named scopes or query objects for repeated filters
- document non-obvious formulas in code comments
- handle decimal precision carefully
- avoid float for money
- store money as integer minor units or decimal consistently with the existing schema
- be explicit with date boundaries
- avoid timezone ambiguity

When working with banking / finance-like data:

- do not casually rename fields that may map to regulatory / core banking terms
- preserve auditability
- avoid destructive updates without logging
- prefer append-only or snapshot-style data for historical reporting
- be careful with rounding and date cutoffs

---

## UI / UX Expectations

Filament UI should be clean and operationally useful.

Prioritize:

- clarity
- fast scanning
- safe actions
- sensible defaults
- useful filters
- minimal clicks for common workflows
- readable labels
- helpful empty states

Avoid:

- overusing badges
- too many columns visible by default
- too many actions per row
- modal-heavy workflows for simple actions
- unclear status names
- showing raw technical values to non-technical users

Use Bahasa Indonesia labels if the existing project uses Bahasa Indonesia. Use English labels if the existing project uses English. Do not mix languages without reason.

---

## Comments and Documentation

Add comments only when they explain why something exists, not what obvious code does.

Good comments:

```php
// Core banking exports this field as text, so normalize it before comparison.
```

Bad comments:

```php
// Set the name.
```

When implementing non-obvious business rules, add short comments or PHPDoc explaining:

- source of the rule
- edge cases
- assumptions
- date / status meaning

---

## Git / Change Management

Before starting:

```bash
git status --short
```

During work:

- keep changes scoped
- do not touch unrelated files
- do not amend existing commits unless explicitly requested
- preserve user changes

Before finishing, when possible:

```bash
git diff --stat
git diff
```

Summarize:

- what changed
- files touched
- tests/checks run
- tests/checks not run and why
- any follow-up risks

---

## Task Completion Checklist

Before returning the final answer, verify:

- [ ] Existing patterns were checked.
- [ ] Change is scoped to the task.
- [ ] No secrets were added.
- [ ] Authorization was considered.
- [ ] Validation was considered.
- [ ] Database changes are safe.
- [ ] Filament UI is usable and consistent.
- [ ] N+1 risks were checked.
- [ ] Tests or relevant checks were run when possible.
- [ ] Any skipped checks are clearly stated.

---

## Preferred Final Response Format

When reporting back, use this structure:

```text
Summary:
- ...

Changed:
- ...

Validation:
- Ran ...
- Could not run ... because ...

Notes:
- ...
```

Keep it concise. Do not over-explain unless the task is risky or the user asks for details.
