# Global Instruction for Codex

You are working on an existing empty Laravel + Filament v5 project.

Build an internal banking application for **Insurance Receivables Recording and CKPN Calculation**.

Use clean Laravel architecture. Do not put business logic directly inside Filament Resources, Controllers, or Models unless it is simple relationship/accessor logic.

Use:

* Laravel 13
* Filament v5
* MySQL-compatible database
* `bezhansalleh/filament-shield` for role and permission management
* Laravel HTTP Client for external API integration
* Decimal-safe handling for money
* Master/reference tables instead of PHP enums for business statuses and CKPN parameters

Important principles:

* Do not hardcode CKPN business rules inside controllers/resources.
* Do not hardcode claim status IDs. Use stable `code` fields from master tables.
* Do not use floating point for money in database/domain logic.
* API edge may format/cast values only when required by external API.
* Use migrations, seeders, models, relationships, policies, Filament Resources, services, actions, and tests where appropriate.
* After each phase, summarize files created/changed, assumptions made, and commands to run.
* Run formatting/tests if available.
* Do not proceed to the next phase unless this phase is complete and consistent.

---

# Phase 1 Prompt — Foundation, Branch Master, Shield, and Reference Tables

Implement the project foundation.

## Goals

Create:

1. Branch office master
2. Insurance company master
3. Claim status master
4. CKPN age bucket master
5. CKPN calculation rule master
6. User branch relationship
7. Filament Shield role/permission setup
8. Initial seeders
9. Filament Resources for master data

## Requirements

Install and configure:

```bash
composer require bezhansalleh/filament-shield
```

Use Filament Shield for roles and permissions.

## User Model Update

Update users table with:

* `branch_office_id` nullable foreign key
* optional `branch_code` string nullable as snapshot/helper if needed

Relationship:

* User belongs to BranchOffice

Rules:

* Branch users should have branch office assigned.
* Central users may have branch office nullable.

## Master Table: Branch Offices

Create table `branch_offices`.

Fields:

* `id`
* `branch_code` string length 3 unique
* `branch_name` string
* `is_active` boolean default true
* timestamps

Seed initial branch codes:

* 001
* 002
* 003
* 004
* 005
* 006
* 007
* 008

Use placeholder names:

* Cabang 001
* Cabang 002
* etc.

Do not invent real branch names.

Create Filament Resource:

* searchable by branch code and branch name
* filter active/inactive
* editable by permitted users only

## Master Table: Insurance Companies

Create table `insurance_companies`.

Fields:

* `id`
* `code` string nullable
* `name` string
* `ckpn_weight` decimal(8,4)
* `sla_description` text nullable
* `is_active` boolean default true
* timestamps

Seed:

* SDI = 0
* ASEI = 0
* BPJS TK = 0
* HEKSA = 0.5
* GIB RELIANCE = 0.5
* AA PIALANG = 0.5
* MPM = 0.5
* ABB = 50
* NASIONAL LIFE = 50
* TASPEN LIFE = 100

Store as percent values:

* 0.5 means 0.5%
* 50 means 50%
* 100 means 100%

Create Filament Resource.

## Master Table: Claim Statuses

Create table `claim_statuses`.

Fields:

* `id`
* `code` string unique
* `name` string
* `ckpn_weight` decimal(8,4)
* `is_default` boolean default false
* `is_terminal` boolean default false
* `is_active` boolean default true
* timestamps

Seed:

1. `on_process`

* name: `On proses`
* ckpn_weight: 0
* is_default: true

2. `approved`

* name: `Approved`
* ckpn_weight: 0

3. `reject_loss`

* name: `Reject Loss`
* ckpn_weight: 100
* is_terminal: true

4. `reject_installment_heir`

* name: `Reject - Cicil Ahli Waris`
* ckpn_weight: 0.5

Important:

* Claim status is mandatory for insurance receivables.
* New records must default to the row with `code = on_process`.
* Do not use PHP enums for claim statuses.

Create Filament Resource.

## Master Table: CKPN Age Buckets

Create table `ckpn_age_buckets`.

Fields:

* `id`
* `name`
* `min_days` integer nullable
* `max_days` integer nullable
* `ckpn_weight` decimal(8,4)
* `is_active` boolean default true
* timestamps

Seed:

1. `1 - 6 bulan`

* min_days: 0
* max_days: 180
* ckpn_weight: 0

2. `7 - 12 bulan`

* min_days: 181
* max_days: 365
* ckpn_weight: 0.5

3. `> 12 bulan`

* min_days: 366
* max_days: null
* ckpn_weight: 100

Create Filament Resource.

## Master Table: CKPN Calculation Rules

Create table `ckpn_calculation_rules`.

Fields:

* `id`
* `code` string unique
* `name` string
* `strategy_class` string nullable
* `description` text nullable
* `is_active` boolean default false
* timestamps

Seed one active rule:

* code: `average_three_factors_with_reject_loss_override`
* name: `Average Three Factors With Reject Loss Override`
* strategy_class: `App\Services\Ckpn\Strategies\AverageThreeFactorsWithRejectLossOverrideStrategy`
* is_active: true

Create Filament Resource.

## Roles

Create Shield-compatible role seeder for:

* `super_admin`
* `branch_maker`
* `branch_approver`
* `it_user`
* `accounting_maker`
* `accounting_approver`
* `business_maker`
* `business_approver`
* `auditor`

Set up permissions reasonably:

* super_admin can manage all
* auditor can view only
* master data should only be managed by super_admin or specific privileged role
* branch roles should not manage global master data

## Deliverables

Implement:

* migrations
* models
* relationships
* seeders
* Filament Resources
* Shield setup
* basic policies/permissions
* branch relationship on User

After finishing:

* list created/changed files
* list commands to run
* mention assumptions
* do not implement insurance receivables yet

---

# Phase 2 Prompt — Insurance Receivable Core, Documents, API Client Foundation, Inquiry, and Branch Validation

Continue from Phase 1.

Do not redesign Phase 1 unless necessary. Preserve existing structure.

## Goals

Implement:

1. Main `insurance_receivables` table
2. Document upload table
3. API integration log table
4. Core banking API client foundation
5. Signature generator
6. Loan Inquiry API integration
7. Branch code validation
8. Filament Resource for insurance receivables
9. Basic tests for signature generation and branch validation

## Main Table: Insurance Receivables

Create table `insurance_receivables`.

Fields:

* `id`
* `branch_office_id` foreign key
* `branch_code` string length 3 snapshot
* `cif_no` nullable string
* `cif_no_alt` nullable string
* `loan_account_number` string
* `alt_number` nullable string
* `customer_name` nullable string
* `date_of_death` date
* `insurance_company_id` foreign key
* `claim_status_id` foreign key mandatory
* `credit_limit` decimal(20,2) nullable
* `loan_outstanding` decimal(20,2) nullable
* `collectability` string nullable
* `dpd` integer nullable
* `product_id` nullable string
* `product_name` nullable string
* `start_period` date nullable
* `end_period` date nullable
* `receivable_formation_date` date nullable
* `receivable_amount` decimal(20,2) nullable
* `workflow_status` string default `draft`
* `stage` string nullable
* `created_by` foreign key nullable to users
* `submitted_at` timestamp nullable
* `approved_at` timestamp nullable
* timestamps
* soft deletes

Rules:

* Default `claim_status_id` must resolve to claim status with `code = on_process`.
* `branch_code` must be stored as snapshot.
* `loan_outstanding` should be decimal-safe.
* `receivable_amount` can initially be based on `loan_outstanding`.

Relationships:

* belongsTo BranchOffice
* belongsTo InsuranceCompany
* belongsTo ClaimStatus
* belongsTo creator User
* hasMany Documents

## Document Uploads

Create table `insurance_receivable_documents`.

Fields:

* `id`
* `insurance_receivable_id`
* `document_type` string
* `file_path` string
* `original_filename` string nullable
* `mime_type` string nullable
* `uploaded_by` foreign key nullable to users
* timestamps

Create Filament relation manager for documents.

Keep document type flexible. Do not over-engineer required document validation yet.

## API Integration Logs

Create table `api_integration_logs`.

Fields:

* `id`
* `service_name` string
* `endpoint` string
* `method` string
* `request_headers` json nullable
* `request_body` longText/json nullable
* `response_status` integer nullable
* `response_body` longText/json nullable
* `response_code` string nullable
* `response_description` string nullable
* `is_success` boolean default false
* `error_message` text nullable
* `related_type` string nullable
* `related_id` unsignedBigInteger nullable
* `requested_by` foreign key nullable to users
* `requested_at` timestamp nullable
* timestamps

Important:

* Never log API secret.
* Mask Signature header if stored.
* Store enough raw request/response data for audit/debugging.

Create model and Filament Resource for API logs. API logs should be view-only for most roles.

## Config

Create `config/core_banking.php`.

Use env:

* `CORE_BANKING_BASE_URL=http://172.22.80.18:17000`
* `CORE_BANKING_SIGNATURE_SECRET=`

Never hardcode the secret.

## Signature Generator

Create:

* `App\Services\CoreBanking\CoreBankingSignatureGenerator`

Signature rule:

* Generate HMAC-SHA256 hex over the exact raw JSON body string.
* The same exact body string must be sent to API.

Method example:

```php
public function generate(string $rawBody): string
```

Use:

```php
hash_hmac('sha256', $rawBody, config('core_banking.signature_secret'))
```

Add tests proving:

* same raw body produces same signature
* different JSON string formatting produces different signature
* generator uses raw body string, not array serialization hidden inside method

## Core Banking Client

Create:

* `App\Services\CoreBanking\CoreBankingClient`

Responsibilities:

* serialize payload once
* sign exact raw body
* send exact same body with Laravel HTTP Client using `withBody`
* log API request/response into `api_integration_logs`
* mask signature in stored headers
* return structured response object/array

Headers:

* Accept: application/json
* Content-Type: application/json
* Signature: generated signature

## API Inquiry

Endpoint:

```text
POST /inquiry/detail/loan
```

Body:

```json
{
"accountNumber": "3010001000054745"
}
```

Important response fields:

* `responseCode`
* `description`
* `data.branchCode`
* `data.loanOutStanding`
* `data.accountNumber`
* `data.altNumber`
* `data.cifNo`
* `data.cifNoAlt`
* `data.customerName`
* `data.collectability`
* `data.dpd`
* `data.productID`
* `data.productName`
* `data.startPeriod`
* `data.endPeriod`
* `data.creditLimit`

Create Action:

* `App\Actions\InsuranceReceivable\PerformLoanInquiryAction`

Responsibilities:

1. Call Core Banking Inquiry API.
2. Validate responseCode is `00`.
3. Validate authenticated user's branch code equals `data.branchCode`.
4. If mismatch, throw validation exception with clear message.
5. Map response fields into insurance receivable.
6. Store snapshot fields.
7. Log API response.

Branch validation:

* Branch users can only create records for their own branch.
* Central users may be allowed broader access depending on role/permission.
* For this phase, implement strict validation for branch users.

## Filament Resource: Insurance Receivables

Create Filament Resource.

Form:

* loan account number
* CIF optional
* date of death
* insurance company
* document relation manager
* read-only mapped inquiry fields after inquiry
* claim status shown but defaults to On proses

Actions:

* `Run Inquiry`
* Save draft

Table:

* branch code
* loan account number
* customer name
* insurance company
* claim status
* loan outstanding
* workflow status
* created date

Filters:

* branch
* insurance company
* claim status
* workflow status

Scoping:

* Branch roles only see own branch records.
* Central roles can see all depending on permission.

## Tests

Add tests for:

1. Signature generation
2. Branch code validation success
3. Branch code validation failure
4. Default claim status is On proses

Use HTTP fake for API inquiry tests.

## Deliverables

Implement:

* migrations
* models
* relationships
* API client
* signature generator
* API logs
* insurance receivable resource
* document upload relation manager
* inquiry action
* tests

After finishing:

* summarize files
* commands to run
* assumptions
* do not implement approval workflow or early termination yet

---

# Phase 3 Prompt — Generic Approval Workflow, Stage 1 Approval, Accounting Validation, and Early Termination API

Continue from Phase 2.

Do not redesign earlier phases unless required. Preserve existing architecture.

## Goals

Implement:

1. Generic approval workflow foundation
2. Approval actions in Filament
3. Stage 1 branch claim submission approval
4. Accounting receivable validation foundation
5. IT collectability update foundation
6. Early Termination API integration
7. Idempotent `trxReference`
8. Tests for approval and early termination payload/signing

## Approval Workflow Tables

Create generic approval tables.

### `approval_requests`

Fields:

* `id`
* `approvable_type`
* `approvable_id`
* `workflow_code` string
* `status` string default `draft`
* `submitted_by` foreign key nullable to users
* `submitted_at` timestamp nullable
* `final_approved_at` timestamp nullable
* timestamps

Statuses:

* draft
* submitted
* approved
* rejected
* returned
* cancelled

### `approval_steps`

Fields:

* `id`
* `approval_request_id`
* `step_order` integer
* `role_name` string nullable
* `assigned_user_id` foreign key nullable to users
* `status` string default `pending`
* `acted_by` foreign key nullable to users
* `acted_at` timestamp nullable
* `notes` text nullable
* timestamps

Step statuses:

* pending
* approved
* rejected
* returned
* skipped

### `approval_logs`

Fields:

* `id`
* `approval_request_id`
* `actor_id` foreign key nullable to users
* `action` string
* `notes` text nullable
* `metadata` json nullable
* timestamps

Models and relationships:

* ApprovalRequest morphTo approvable
* ApprovalRequest hasMany steps/logs
* ApprovalStep belongsTo request
* ApprovalLog belongsTo request

## Approval Service

Create:

* `App\Services\Approval\ApprovalService`

Responsibilities:

* create approval request
* submit
* approve current step
* reject
* return/revise
* finalize when all steps are approved
* write approval logs
* run inside DB transactions

Do not make approval logic resource-specific.

## Workflows

Support workflow codes:

* `claim_submission_branch`
* `accounting_receivable_validation`
* `claim_status_update`
* `monthly_ckpn_workpaper`
* `ckpn_journal_approval`
* `ckpn_adjustment`

For this phase, implement actual use for:

* `claim_submission_branch`
* `accounting_receivable_validation`

## Stage 1 Flow

Branch maker creates insurance receivable draft.

Branch maker submits for approval:

* create approval request with workflow `claim_submission_branch`
* status becomes `submitted`

Branch approver approves:

* insurance receivable can move to next stage/status

Suggested workflow statuses on `insurance_receivables`:

* `draft`
* `submitted`
* `branch_approved`
* `returned`
* `rejected`
* `accounting_validation`
* `receivable_formed`
* `early_termination_executed`

Keep the status strings centralized as constants if useful, but do not use database enum.

## IT Collectability Update

IT can update/change `collectability`.

Implement foundation:

* Filament action available only to IT/super_admin permission
* record old and new collectability in audit log or model activity table
* do not allow uncontrolled mass assignment

A simple table may be created:

`insurance_receivable_field_change_logs`

* `id`
* `insurance_receivable_id`
* `field_name`
* `old_value`
* `new_value`
* `changed_by`
* `reason` nullable text
* timestamps

## Accounting Validation

Accounting validates receivable formation.

Create fields/table as needed for accounting validation.

At minimum:

* set `receivable_formation_date`
* set `receivable_amount`, default from `loan_outstanding`
* move status to `receivable_formed`
* create internal journal foundation record if needed

Create optional table:

`receivable_formation_journals`

* `id`
* `insurance_receivable_id`
* `journal_date`
* `amount` decimal(20,2)
* `debit_account` nullable string
* `credit_account` nullable string
* `description` nullable text
* `status` string default `draft`
* `created_by`
* `approved_by` nullable
* `approved_at` nullable
* timestamps

Keep journal account mapping configurable/fillable. Do not invent final COA.

## Early Termination API

Add method to CoreBankingClient:

```php
earlyTerminateLoan(array $payload, ?Model $related = null): array
```

Endpoint:

```text
POST /loan/earlytermination/
```

Payload rules:

```json
{
"trxReference": "PA-ET{YYYYMMDDHHMMSS}",
"accountNumber": "3010010000000068",
"altNumber": "",
"principalPaid": 230929055,
"interestPaid": 0,
"penaltyPaid": 0,
"principalWaive": 230929055,
"interestWaive": 0,
"description": "Pelunasan Debitur MD",
"branchCode": "001"
}
```

Rules:

* `trxReference` format: `PA-ET{YYYYMMDDHHMMSS}`
* Generate `trxReference` once and store it before calling API.
* Reuse same `trxReference` on retry.
* `accountNumber` comes from insurance receivable loan account number or inquiry snapshot.
* `altNumber` comes from inquiry response `altNumber`.
* `principalPaid` comes from Inquiry `loanOutStanding`.
* `principalWaive` comes from Inquiry `loanOutStanding`.
* `interestPaid`, `penaltyPaid`, `interestWaive` are zero.
* `description` is exactly `Pelunasan Debitur MD`.
* `branchCode` comes from Inquiry response / stored branch code.

Money handling:

* Keep monetary values decimal-safe in DB/domain.
* If external API requires JSON number, cast only at API payload edge.
* Avoid floating point elsewhere.

## Early Termination Storage

Add fields to insurance receivables or create table.

Recommended table:

`early_termination_transactions`

* `id`
* `insurance_receivable_id`
* `trx_reference` string unique
* `request_payload` json/text nullable
* `response_payload` json/text nullable
* `response_code` string nullable
* `response_description` string nullable
* `transaction_id` string nullable
* `journal_id` string nullable
* `core_trx_reference` string nullable
* `alternate_number` string nullable
* `status` string nullable
* `executed_by` foreign key nullable to users
* `executed_at` timestamp nullable
* timestamps

Response fields to capture:

* transactionId
* journalId
* trxReference
* alternateNumber
* status
* accountNumber
* cifNo
* customerName
* branchCode
* principalPaid
* interestPaid
* penaltyPaid
* interestWaive
* penaltyWaive
* repaymentAccBalance

## Filament Actions

On InsuranceReceivableResource:

* Submit for approval
* Approve
* Reject
* Return
* Update collectability
* Accounting validate receivable formation
* Execute early termination

Protect actions using Shield permissions.

Dangerous API execute actions must not be available to all users.

## Tests

Add tests:

1. approval request creation
2. approval flow finalizes after required steps
3. rejected/returned flow works
4. early termination payload uses stored loanOutstanding and branchCode
5. trxReference is generated once and reused
6. early termination API signs exact body sent

Use HTTP fake for API tests.

## Deliverables

Implement:

* approval tables/models/service
* approval Filament actions
* IT collectability update foundation
* accounting validation foundation
* early termination API
* early termination transaction storage
* tests

After finishing:

* summarize files
* commands
* assumptions
* do not implement claim status update or CKPN workpaper yet

---

# Phase 4 Prompt — Claim Status Update Workflow and Insurance Cover Letter Foundation

Continue from Phase 3.

Do not redesign earlier phases unless required.

## Goals

Implement:

1. Claim status change request module
2. Maker/approval flow for claim status updates
3. Approved status update applies to insurance receivable
4. Supporting document upload foundation
5. Insurance cover letter / surat pengantar foundation
6. Filament Resource and actions
7. Tests for status update approval

## Claim Status Change Requests

Create table `claim_status_change_requests`.

Fields:

* `id`
* `insurance_receivable_id`
* `from_claim_status_id`
* `to_claim_status_id`
* `reason` text nullable
* `supporting_document_path` nullable
* `requested_by` foreign key nullable to users
* `approved_by` foreign key nullable to users
* `approved_at` timestamp nullable
* `status` string default `draft`
* timestamps

Rules:

* Do not directly overwrite `insurance_receivables.claim_status_id` when maker submits request.
* Status changes only apply after approval.
* Allowed target statuses are loaded from `claim_statuses` master table.
* Current status must be captured as `from_claim_status_id`.
* Use generic approval workflow with code `claim_status_update`.

Status request statuses:

* draft
* submitted
* approved
* rejected
* returned
* cancelled

## Approval Flow

Business maker creates claim status change request.

Business maker submits.

Business approver approves.

After final approval:

* update `insurance_receivables.claim_status_id` to `to_claim_status_id`
* set request approved fields
* create approval logs
* do all in database transaction

## Filament Resource

Create Filament Resource:

* Claim Status Change Requests

Form:

* insurance receivable selector
* current claim status read-only
* target claim status
* reason
* supporting document upload

Table:

* request number/id
* branch code
* loan account
* customer name
* from status
* to status
* request status
* requested by
* created date

Actions:

* submit
* approve
* reject
* return

Also add relation/view from InsuranceReceivableResource showing status change history.

## Insurance Cover Letter Foundation

Create basic foundation for surat pengantar to insurance company.

Do not over-engineer document templates yet.

Create table:

`insurance_cover_letters`

* `id`
* `insurance_receivable_id`
* `letter_number` string nullable
* `letter_date` date nullable
* `insurance_company_id`
* `recipient_name` nullable string
* `subject` nullable string
* `body` longText nullable
* `generated_file_path` nullable string
* `status` string default `draft`
* `created_by` foreign key nullable to users
* timestamps

Create Filament Resource or relation manager.

Provide action foundation:

* Generate draft letter body from receivable data
* Save as draft

Do not require PDF generation yet unless easy. A basic printable view or stored body is enough for this phase.

## Access Control

Use Shield permissions:

* business_maker can create status change requests
* business_approver can approve status change requests
* auditor can view
* super_admin can manage all

Branch/central scoping:

* Business/accounting central roles can see all.
* Branch roles should only see own branch records.

## Tests

Add tests:

1. submitting claim status request does not update insurance receivable immediately
2. approving claim status request updates insurance receivable
3. rejected request does not update insurance receivable
4. status request uses master claim statuses, not hardcoded enum
5. cover letter draft can be created from insurance receivable

## Deliverables

Implement:

* migration/model/resource for claim status change requests
* approval integration
* insurance cover letter foundation
* relation/history views
* permissions
* tests

After finishing:

* summarize files
* commands
* assumptions
* do not implement CKPN monthly workpaper yet

---

# Phase 5 Prompt — CKPN Calculation Engine, Monthly Workpaper, Snapshot, Adjustment, and Tests

Continue from Phase 4.

Do not redesign earlier phases unless required.

## Goals

Implement:

1. CKPN calculation service and strategy
2. Monthly CKPN workpaper module
3. Workpaper item snapshot
4. CKPN adjustment foundation
5. Approval flow for workpaper/adjustment
6. Filament Resources
7. Tests for CKPN calculation

## CKPN Calculation Rule V1

Implement current CKPN rule in dedicated strategy.

Rule:

```text
IF receivable_age > 12 months AND claim_status = reject_loss:
final_ckpn_rate = 100%
insurance company factor is ignored

ELSE:
final_ckpn_rate = (
    insurance_company_weight
    + receivable_age_weight
    + claim_status_weight
) / 3
```

Notes:

* Claim status is mandatory.
* Default status is `On proses`.
* Divisor is always 3.
* Percent values are stored as percent numbers:

* 0.5 means 0.5%
* 50 means 50%
* 100 means 100%
* CKPN amount = receivable amount x final rate / 100.
* Use decimal-safe calculation.
* Do not put this rule inside Filament Resource.

## Suggested Classes

Create:

* `App\Services\Ckpn\Contracts\CkpnCalculationStrategy`
* `App\Services\Ckpn\CkpnCalculationService`
* `App\Services\Ckpn\Strategies\AverageThreeFactorsWithRejectLossOverrideStrategy`
* `App\Data\CkpnCalculationInput`
* `App\Data\CkpnCalculationResult`

Result should include:

* insurance company weight
* age weight
* claim status weight
* final CKPN rate
* CKPN amount
* calculation explanation
* applied rule code
* age in days
* selected age bucket id/name

Active strategy should be resolved from `ckpn_calculation_rules` active row.

## Monthly Workpaper Tables

Create table `ckpn_workpapers`.

Fields:

* `id`
* `period` date
* `branch_office_id` nullable foreign key
* `status` string default `draft`
* `created_by` foreign key nullable to users
* `approved_by` foreign key nullable to users
* `approved_at` timestamp nullable
* `total_receivable_amount` decimal(20,2) default 0
* `total_ckpn_amount` decimal(20,2) default 0
* timestamps

Create table `ckpn_workpaper_items`.

Fields:

* `id`
* `ckpn_workpaper_id`
* `insurance_receivable_id`
* `branch_code` string nullable
* `cif_no` string nullable
* `loan_account_number` string nullable
* `customer_name` string nullable
* `insurance_company_name` string nullable
* `claim_status_name` string nullable
* `receivable_formation_date` date nullable
* `receivable_amount` decimal(20,2)
* `age_days` integer
* `age_bucket_name` string nullable
* `insurance_company_weight` decimal(8,4)
* `age_weight` decimal(8,4)
* `claim_status_weight` decimal(8,4)
* `final_ckpn_rate` decimal(8,4)
* `ckpn_amount` decimal(20,2)
* `calculation_rule_code` string
* `calculation_explanation` text nullable
* `snapshot` json nullable
* timestamps

Important:

* Store snapshot values at calculation time.
* Historical workpaper must not change when master data changes later.
* Workpaper items are generated from eligible outstanding insurance receivables.

## Eligible Receivables

For now, include insurance receivables where:

* `receivable_formation_date` is not null
* `receivable_amount` is greater than 0
* workflow status is not rejected/cancelled
* not soft-deleted

Keep eligibility logic in dedicated query/action so it can be changed later.

Create:

* `App\Actions\Ckpn\GenerateMonthlyCkpnWorkpaperAction`
* `App\Actions\Ckpn\RecalculateCkpnWorkpaperAction`

## Workpaper Flow

1. Business creates monthly workpaper.
2. System generates items and calculates CKPN.
3. Business submits workpaper.
4. Accounting reviews/approves using approval workflow `monthly_ckpn_workpaper`.
5. After approval, workpaper becomes locked from recalculation unless explicitly reopened by privileged role.

Statuses:

* draft
* generated
* submitted
* approved
* rejected
* returned
* locked

## CKPN Adjustment

Create table `ckpn_adjustments`.

Fields:

* `id`
* `insurance_receivable_id`
* `ckpn_workpaper_id` nullable
* `ckpn_workpaper_item_id` nullable
* `adjustment_type` string
* `original_rate` decimal(8,4) nullable
* `adjusted_rate` decimal(8,4) nullable
* `original_amount` decimal(20,2) nullable
* `adjusted_amount` decimal(20,2) nullable
* `reason` text
* `status` string default `draft`
* `requested_by` foreign key nullable to users
* `approved_by` foreign key nullable to users
* `approved_at` timestamp nullable
* timestamps

Rules:

* Adjustment requires approval using workflow `ckpn_adjustment`.
* Adjustment must store reason.
* Approved adjustment should update workpaper item adjusted values or create a clearly traceable adjustment record.
* Do not silently overwrite original calculated values without audit trail.

## Filament Resources

Create:

* CKPN Workpapers Resource
* CKPN Workpaper Items relation manager
* CKPN Adjustments Resource

Actions:

* Generate items
* Recalculate draft workpaper
* Submit
* Approve
* Reject
* Return
* Create adjustment

Tables:

* period
* branch
* status
* total receivable
* total CKPN
* created by
* approved by

Filters:

* period
* branch
* status

## Tests

Add tests:

1. age bucket selection 0-180 days
2. age bucket selection 181-365 days
3. age bucket selection >365 days
4. normal average-three-factor calculation
5. reject_loss + age >12 months returns 100%
6. reject_loss + age <=12 months uses average rule
7. workpaper item stores snapshot
8. changing master weight after workpaper generation does not mutate existing item
9. adjustment requires reason and approval

## Deliverables

Implement:

* CKPN calculation service/strategy/data objects
* workpaper tables/models/resources
* workpaper generation action
* adjustment module
* approval integration
* tests

After finishing:

* summarize files
* commands
* assumptions
* do not implement GL-to-GL yet

---

# Phase 6 Prompt — SAKEP Export Foundation, CKPN Journal, GL-to-GL API, Final Audit, and Hardening

Continue from Phase 5.

Do not redesign earlier phases unless required.

## Goals

Implement:

1. CKPN journal foundation
2. SAKEP upload/overlay export foundation
3. GL-to-GL API integration
4. API response storage
5. Final permission hardening
6. Export/report foundation
7. Additional tests

## CKPN Journal Foundation

Create table `ckpn_journals`.

Fields:

* `id`
* `ckpn_workpaper_id`
* `branch_office_id` nullable foreign key
* `journal_date` date
* `total_amount` decimal(20,2)
* `debit_account` string nullable
* `credit_account` string nullable
* `debit_narrative` text nullable
* `credit_narrative` text nullable
* `description` text nullable
* `status` string default `draft`
* `created_by` foreign key nullable
* `approved_by` foreign key nullable
* `approved_at` timestamp nullable
* timestamps

Rules:

* Journal is created from approved CKPN workpaper.
* Account mapping is not final. Keep fields configurable/fillable.
* Do not invent final COA values.
* Journal approval uses workflow `ckpn_journal_approval`.

## SAKEP Export Foundation

Create export foundation for CKPN workpaper / overlay file.

Do not over-engineer unknown exact format.

Create:

* export action from approved CKPN workpaper
* generated file record table if useful:

`generated_exports`

* `id`
* `exportable_type`
* `exportable_id`
* `export_type` string
* `file_path` string nullable
* `status` string
* `generated_by` foreign key nullable
* `generated_at` timestamp nullable
* `metadata` json nullable
* timestamps

Export format can be CSV/XLSX foundation depending on available packages.
If no package exists, create CSV foundation first.

Include columns based on known report needs:

* no
* cif
* loan account number
* customer name
* plafond/credit limit
* date of realization/start period
* tenor/term
* maturity/end period
* date of death
* bade/loan outstanding
* receivable formation date
* insurance company
* claim status
* CKPN rate
* CKPN amount
* maker/checker/approver placeholders if needed

## GL-to-GL API Integration

Add CoreBankingClient method:

```php
transferGlToGl(array $payload, ?Model $related = null): array
```

Endpoint:

```text
POST /trx/transfer/gl-to-gl
```

Payload foundation:

```json
{
"referenceNumber": "092511010003",
"trxType": "Deprc-Buildings",
"termType": "",
"termId": "FINCLOUD",
"receiptNumber": "012511010003",
"debitAccount": "",
"creditAccount": "",
"amount": "3077644.00",
"fee": "0",
"creditFee": "0",
"branchCode": "001",
"debitNarrative": "Penyusutan Debit",
"creditNarrative": "Penyusutan Kredit",
"customerId": "127369366000",
"dateTime": "20251130000000",
"description": "001.Penyusutan_Asset. Gedung",
"debitFee": "0",
"destAccount": "",
"currency": "IDR",
"srcAccType": "10",
"totalBill": "",
"type": "G2"
}
```

Important:

* `amount` must use decimal point string format, e.g. `"3077644.00"`.
* Do not use comma decimal format.
* Response shape is currently unknown.
* Store raw response in API logs and GL transaction table.
* Signature uses exact raw JSON body string, same as previous APIs.

Create table:

`gl_to_gl_transactions`

* `id`
* `ckpn_journal_id` nullable
* `ckpn_workpaper_id` nullable
* `reference_number` string nullable
* `receipt_number` string nullable
* `request_payload` json/text nullable
* `response_payload` json/text nullable
* `response_code` string nullable
* `response_description` string nullable
* `status` string nullable
* `executed_by` foreign key nullable
* `executed_at` timestamp nullable
* timestamps

## Payload Builder

Create:

* `App\Services\CoreBanking\PayloadBuilders\GlToGlPayloadBuilder`

Responsibilities:

* build payload from CKPN journal/workpaper
* format amount using decimal point string
* format dateTime as `YYYYMMDDHHMMSS`
* leave configurable account/narrative fields
* do not hardcode final COA

## Filament Actions

Add actions:

* Create CKPN Journal from approved workpaper
* Submit journal for approval
* Approve journal
* Generate SAKEP/export file
* Execute GL-to-GL

Protect all dangerous actions using Shield permissions.

Rules:

* GL-to-GL can only execute after journal approval.
* GL-to-GL should be idempotent as much as possible using stored reference number/receipt number.
* Do not regenerate references on retry.

## Reports

Add basic report/export views:

* Insurance receivables list
* Monthly CKPN workpaper
* API logs
* CKPN journal list
* GL-to-GL transaction list

## Hardening

Review:

* branch scoping
* permissions
* dangerous actions
* API secret handling
* masked Signature logging
* decimal money handling
* approval logs
* historical CKPN snapshots

## Tests

Add tests:

1. GL-to-GL amount uses decimal point string
2. GL-to-GL signs exact body sent
3. GL-to-GL stores raw unknown response
4. journal cannot execute GL-to-GL before approval
5. export can be generated from approved workpaper
6. branch user cannot access other branch records
7. auditor cannot execute write/API actions

## Deliverables

Implement:

* CKPN journal foundation
* generated export foundation
* GL-to-GL API integration
* GL transaction table
* payload builder
* Filament actions
* report/export foundation
* hardening review
* tests

After finishing:

* summarize files
* commands
* assumptions
* list remaining TODOs requiring business confirmation
