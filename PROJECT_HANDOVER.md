# PrintSync Project Handover

## 1. Project Overview

PrintSync is an order-management application for RPG Print. Customers submit print requests and check their current order status. Staff work on assigned production tasks, cashiers record payments received outside the application, and owners monitor operations and route work. The public home page is served at `/`.

## 2. Technology and Runtime

- PHP 8.5 and Laravel 13 (the installed application reports Laravel 13.35.0).
- Livewire 4 with single-file components for the role workspaces.
- Flux UI 2 and Tailwind CSS 4 for the interface.
- Vite Plus is used for frontend development and production builds.
- MySQL is the configured application database. PHPUnit/Pest tests use SQLite `:memory:` as configured in `phpunit.xml`.
- Pest 5 and Laravel Pint are included for tests and PHP formatting.

Use the installed lockfiles and the application’s runtime information as the source of truth if these versions change.

## 3. Repository Structure

- `routes/web.php` defines the public home page, authenticated dashboard redirect, private design-file route, and role-protected workspaces.
- `app/Models/` contains the Eloquent models; `app/Services/` contains order, payment, inventory, and receipt operations.
- `app/Policies/` defines order, payment, and inventory authorization.
- `resources/views/pages/` contains Livewire page components. Files with the `⚡` prefix are Livewire single-file components.
- `resources/views/welcome.blade.php` is the public landing page.
- `resources/css/app.css` contains the Tailwind v4 entry point and PrintSync light/dark design tokens and shared styles.
- `database/migrations/` defines the existing schema; `database/seeders/` contains database seeders.
- `tests/Feature/` contains the Pest feature tests.

There is no root `README.md` in this project snapshot; this handover is the primary project orientation document.

## 4. Roles, Routes, and Authorization

Authenticated routes are grouped by role in `routes/web.php` and guarded by the role middleware. The dashboard redirect sends each supported role to its own workspace.

| Role | Main workspace | Access boundary |
| --- | --- | --- |
| Customer | `/customer/dashboard`, `/customer/orders` | Can create and view only their own orders and design files. |
| Staff | `/staff/dashboard`, `/staff/orders`, `/staff/inventory` | Can respond to their own task offers, work on accepted assignments, and use staff inventory actions. |
| Cashier | `/cashier/dashboard`, `/cashier/payments` | Can view payment information and record payments through the cashier workflow. |
| Owner | `/owner/dashboard`, `/owner/monitoring` | Can monitor application data, route orders to eligible staff, and manage staff specialties. |

`OrderPolicy`, `PaymentPolicy`, and `InventoryPolicy` enforce the corresponding server-side rules. The design-file route authorizes through `OrderPolicy` and serves a validated stored path from the private local disk; it does not accept a file path from the request.

## 5. Order and Assignment Workflows

- Orders store the customer, submission date, order type, optional description and private design-file path, quantity, optional production service line, and current status.
- The existing `OrderStatus` values are `pending`, `processing` (labelled “In Progress”), `ready_for_pickup`, and `completed`. Assigned staff advance orders one step at a time; assignment acceptance is separate from production status.
- Owners route orders as offers to staff whose saved specialty matches the selected service line. Eligibility includes the defined machine-operator overlaps; staff cannot freely claim arbitrary orders.
- Staff accept or decline offers. Declined assignments are made inactive and excluded from future eligible staff choices for that same order, allowing routing to another eligible staff member.
- Customer, staff, and owner order views show the saved current status. The application does not synthesize status history or progress events.
- The application supports the documented service-line and specialty enums in `app/OrderServiceLine.php` and `app/StaffSpecialtyType.php`; do not invent additional values or transitions without a business decision.

## 6. Payments and Inventory

### Payments

`PaymentService` records a payment received outside PrintSync and summarizes saved payment rows. Supported methods are `cash`, `gcash`, and `bank_transfer`. There is no online payment gateway or automatic charge flow. The payment table does not define a unique-payment-per-order constraint or a balance/settlement rule; do not assume either.

### Inventory

The existing `inventory` table stores a unique material name, decimal quantity (three places), free-form unit, and free-form status. `available` is the status used by existing application code and tests. Stock-in is recorded through `InventoryService` and the `stock_in` table. Low-stock alerts are explicit `low_stock` records created by staff; there is no minimum-quantity column or automatic threshold calculation. Do not derive alerts or deduct inventory from orders.

## 7. Local Setup and Run Commands

1. Install the locked PHP dependencies with `composer install`.
2. Create a local `.env` from `.env.example` if needed, set the intended database connection, and generate an application key with `php artisan key:generate`.
3. Before running any database command, confirm `DB_CONNECTION` and `DB_DATABASE` refer to the intended local/test database. Run `php artisan migrate` only after confirming the target.
4. Install frontend dependencies with `npm install`.
5. Start the application using `composer run dev`, or run the desired Laravel and Vite development processes separately.
6. Build frontend assets with `npm.cmd run build` in Windows PowerShell.

Do not run project setup scripts blindly against a configured database: inspect their database and seeding effects first.

## 8. Testing and Quality Checks

- Run the focused landing-page and inventory-seeder tests:

  ```powershell
  php artisan test --compact tests\Feature\ExampleTest.php tests\Feature\InventorySeederTest.php
  ```

- Run the whole feature/unit suite when broader validation is required:

  ```powershell
  php artisan test --compact
  ```

- Format only changed PHP files when making a narrow PHP change:

  ```powershell
  vendor\bin\pint --format agent database\seeders\InventorySeeder.php database\seeders\DatabaseSeeder.php tests\Feature\ExampleTest.php tests\Feature\InventorySeederTest.php
  ```

- Build the frontend with `npm.cmd run build`.

The focused tests use the in-memory SQLite connection from `phpunit.xml`; they do not seed or modify the configured MySQL database.
Latest focused verification for this handover: 6 tests and 21 assertions passed; Pint completed for the changed PHP files; the frontend build completed successfully. The build emitted a non-blocking notice that optional font-fallback optimization requires `fontaine`.

## 9. Current Seed Data and Safe Seeding

`DatabaseSeeder` retains its existing `Test User` factory record and calls `InventorySeeder`. The inventory seeder provides 14 example materials: Copy Paper 80 gsm, Coated Paper 150 gsm, Cardstock 250 gsm, Glossy Photo Paper A4, Matte Photo Paper A4, Sticker Paper A4, Tarpaulin Flex Banner, Sintra Board 3 mm, Sublimation Fabric, Lamination Film, and black, cyan, magenta, and yellow printer ink. Sample quantities and units are defined in `database/seeders/InventorySeeder.php`; the existing `available` status is used.

`InventorySeeder` uses `firstOrCreate` with the unique material name. It adds missing sample rows, does not delete other inventory, and does not overwrite the quantity, unit, or status of an existing row with the same name. Repeating the seeder therefore does not duplicate materials or reset existing stock.

To run this seeder manually, first change to the project folder and verify the active database connection and database name:

```powershell
php artisan db:seed --class=InventorySeeder
```

Do not run this command automatically or against production without explicit approval. Verification invokes the seeder only from its focused feature test, which runs against SQLite `:memory:`; it does not run the artisan seeding command against the configured MySQL database.

## 10. Known Limitations and Out-of-Scope Behavior

- Inventory has no configured low-stock threshold; the system only displays explicitly recorded alerts.
- Payments are records of in-person receipt, not online transactions. No balance or duplicate-payment business rule is encoded in the schema.
- Orders display their current saved status; there is no status-event history.
- Order submissions do not automatically deduct inventory.
- Production service eligibility depends on the stored staff specialty mappings and current service-line mappings.
- This handover does not authorize changes to authentication, database schema, payment rules, inventory deduction, or role boundaries without an explicit task.

## 11. Mandatory Guidance for Future Assistants

- Inspect the relevant routes, Livewire pages, models, services, policies, migrations, and tests before changing behavior. Follow existing patterns and keep changes limited to the requested feature.
- Preserve existing customer, staff, cashier, and owner behavior and enforce authorization on the server; hiding a control in Blade is not authorization.
- Never invent statuses, workflow transitions, service eligibility, payment/balance rules, inventory thresholds, or financial figures. Ask for clarification when the code and documented business rules do not establish them.
- Protect existing data. Do not run `migrate:fresh`, `migrate:refresh`, `db:wipe`, production seeders, or other destructive/data-changing commands without explicit approval and confirmation of the target database.
- Do not run `InventorySeeder` automatically. Before the documented manual seeding command, verify the database connection and target.
- Do not add packages, migrations, or broad refactors unless the user approves them. Add focused regression tests for behavior changes, run the narrowest applicable tests, format changed PHP files with Pint, and build frontend assets for UI changes.
- Never place credentials, secret values, private customer files, or production data in source control or documentation.
