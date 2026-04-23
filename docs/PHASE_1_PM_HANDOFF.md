# DEMOS Phase 1 PM Handoff

Last verified from code and tests on April 23, 2026.

## 1. What The Project Is

DEMOS is a civic participation platform built on Laravel. Phase 1 already delivers four working layers:

1. A mobile/API layer for citizen-facing actions.
2. An admin panel for operations, moderation, and settings.
3. A sync layer that imports federal and state legislative data.
4. A maintenance layer that recalculates support thresholds, hiding rules, and operational status.

The project is designed so citizens can interact with real legislation, while admins can moderate activity and the backend keeps legislative records current through scheduled jobs.

## 2. Main Product Surfaces

### Citizen side

- Mobile/API authentication
- Email verification by code
- Location and district verification
- Bill discovery and bill detail
- Citizen voting on bills
- District-level bill insights
- Citizen amendment submission and support
- Citizen proposal submission and support
- Reporting inappropriate content
- Self-service history and representative lookup
- Managed content feed for FAQs, guidelines, and announcements

### Admin side

- Admin dashboard widgets
- Bills management
- Amendments moderation
- Citizen proposal moderation
- Reports review and enforcement
- Representatives management
- Managed content publishing
- Platform settings management

### Backend operations

- Federal bill sync
- Federal amendment sync
- Federal representative sync
- Official federal vote sync
- State bill sync
- State representative sync
- Community engagement maintenance
- Scheduled automation through Laravel scheduler

## 3. How The User Journey Works

### Flow 1: Registration

Endpoint: `POST /api/auth/register`

What happens:

- User submits name, email, phone number, password, and password confirmation.
- Input is normalized:
  - `full_name` is accepted as fallback for `name`
  - `phone` is accepted as fallback for `phone_number`
  - email is lowercased
- Laravel user creation runs through the Fortify user creation action.
- A 6-digit email verification code is generated, hashed, stored, and emailed to the user.
- Response returns:
  - `verification_required = true`
  - verification expiry time
  - resend cooldown seconds
  - `next_step = verify_email`
  - a mobile-ready user profile payload

Business rule:

- Registration alone does not grant participation access.

### Flow 2: Email verification

Endpoint: `POST /api/auth/verify-email-code`

What happens:

- User submits email, 6-digit code, and device name.
- System checks:
  - user exists
  - user is not already verified
  - code matches hashed stored value
  - code is not expired
- On success:
  - `email_verified_at` is stored
  - stored verification code fields are cleared
  - a Sanctum token is created for the provided device
  - the next onboarding step is returned

Possible outcomes:

- Invalid or expired code returns `422`
- Suspended user returns `423`
- Successful verification returns bearer token and `next_step`

### Flow 3: Verification code resend

Endpoint: `POST /api/auth/resend-verification-code`

What happens:

- User provides email.
- System enforces resend cooldown.
- If cooldown is still active, it returns `429` with remaining seconds.
- If cooldown has elapsed, a new verification code is generated and emailed.

### Flow 4: Login

Endpoint: `POST /api/auth/login`

What happens:

- User submits email, password, and device name.
- Credentials are verified.
- If email is not yet verified:
  - login is blocked
  - verification instructions are returned again
  - resend availability is included
- If account is suspended:
  - login is blocked with suspension metadata
- If valid:
  - Sanctum token is returned
  - `next_step` is returned as either `select_location` or `complete`

### Flow 5: Password recovery

Endpoints:

- `POST /api/auth/forgot-password`
- `POST /api/auth/reset-password`

What happens:

- Forgot password sends Laravel password reset link email.
- Reset password accepts token, email, password, and password confirmation.
- On successful reset:
  - password is updated
  - all previous tokens are deleted

### Flow 6: Logout

Endpoint: `POST /api/auth/logout`

What happens:

- If a current access token exists, only that token is deleted.
- Otherwise all user tokens are revoked.

## 4. Constituent Verification Flow

### Flow 7: Location verification and onboarding completion

Endpoints:

- `POST /api/user/location`
- `POST /api/user/verify-location`

Both routes point to the same logic.

The user can verify location in two ways:

1. Manual address entry
2. Latitude/longitude auto-detect

#### 7A. Manual address path

- User submits country, state, district/locality, street address, and zip/postal code.
- System builds a full address string.
- Google Geocoding converts the address into coordinates.

#### 7B. Coordinate path

- User submits latitude and longitude.
- Google reverse geocoding fills address fields when possible.

#### 7C. District resolution path

After coordinates are known, the system attempts district resolution in this order:

1. Google Civic `divisionsByAddress`
2. Google Civic `representatives`
3. OpenStates location lookup fallback

What must be resolved:

- federal district
- state district

If both are found:

- user location fields are stored
- `federal_district` and `state_district` are stored
- `verified_at` is set
- `is_verified` is set to true
- response returns `next_step = complete`

If district lookup fails:

- API returns `422`
- partial district data is included
- diagnostics show whether Google only matched partially
- diagnostics also show whether OpenStates quota is exhausted

Important business rule:

Participation depends on verified constituent status, not simple account creation.

## 5. Verified Constituent Rule

A user is treated as a verified constituent when all of the following are true:

- email is verified
- location has been completed
- account is not suspended
- identity is verified, or legacy `is_verified` is true

That rule gates:

- bill voting
- amendment submission
- amendment support
- citizen proposal submission
- citizen proposal support

## 6. Bill Discovery And Participation

### Flow 8: Bill list

Endpoint: `GET /api/bills`

Capabilities:

- list federal and state bills
- filter by jurisdiction code
- filter by status
- filter to only bills with open deadlines
- paginated response

### Flow 9: Bill detail

Endpoint: `GET /api/bills/{bill}`

What the response includes:

- bill with jurisdiction
- top 3 user-generated amendments ordered by support count
- current authenticated user's vote if logged in
- constituent vote totals:
  - `in_favor`
  - `against`
  - `abstain`
- constituent percentages
- official representative votes synced from public feeds
- `voting_open` boolean

### Flow 10: Vote on a bill

Endpoint: `POST /api/bills/{bill}/vote`

Allowed values:

- `in_favor`
- `against`
- `abstain`

Optional:

- comment

Validation and rules:

- user must not be suspended
- user must be a verified constituent
- bill voting must still be open
- comment cannot exceed 50 words

Implementation details:

- `updateOrCreate` is used, so a second vote updates the existing vote instead of duplicating it

### Flow 11: Remove vote

Endpoint: `DELETE /api/bills/{bill}/vote`

Rules:

- suspended users are blocked
- vote can only be removed while bill voting is still open

### Flow 12: District insights

Endpoint: `GET /api/bills/{bill}/insights`

What happens:

- user must complete location verification first
- system determines whether the bill belongs to federal or state jurisdiction
- system resolves the correct district context for the viewer
- only verified users from that district are counted
- district population data is loaded from `district_populations`

What the response includes:

- district identity
- registered voter count
- verified participant count
- turnout rate
- vote totals
- vote proportions
- confidence interval metadata
- margin of error

This gives the platform an analytics layer beyond raw vote totals.

## 7. Amendment Workflow

### Flow 13: Amendment list for a bill

Endpoint: `GET /api/bills/{bill}/amendments`

What it returns:

- only user-generated amendments
- only non-hidden amendments
- optional category filter
- optional popularity ordering
- paginated results

### Flow 14: Amendment detail

Endpoint: `GET /api/amendments/{amendment}`

What it returns:

- only citizen amendments are exposed through this endpoint
- official imported amendments are not returned here
- includes bill and proposer
- includes whether current user already supports the amendment

### Flow 15: Submit amendment

Endpoint: `POST /api/bills/{bill}/amendments`

Rules:

- user must not be suspended
- user must be a verified constituent
- amendments close once the bill has an official vote date in the past
- amendment text must be 50 to 70 words
- category must be at least 2 characters

What is stored:

- `source = user`
- proposer user id
- bill id
- amendment text
- category
- support count initialized to 0

### Flow 16: Support amendment

Endpoint: `POST /api/amendments/{amendment}/support`

Rules:

- only citizen amendments can be supported
- user must not be suspended
- user must be a verified constituent
- duplicate supports are blocked

What happens:

- support row is created
- amendment `support_count` increments
- if count reaches configured threshold:
  - `threshold_reached = true`
  - `threshold_reached_at` is saved

Default threshold from seeded settings:

- `amendment_threshold = 1000`

### Flow 17: Remove amendment support

Endpoint: `DELETE /api/amendments/{amendment}/support`

What happens:

- support record is deleted if it exists
- counter decrements only when greater than zero
- support count cannot go negative

## 8. Citizen Proposal Workflow

### Flow 18: Citizen proposal list

Endpoint: `GET /api/citizen-proposals`

Capabilities:

- returns only non-hidden proposals
- can filter by category
- can filter by jurisdiction focus
- can sort by popularity
- paginated results

### Flow 19: Citizen proposal detail

Endpoint: `GET /api/citizen-proposals/{proposal}`

What it returns:

- proposer information
- whether current user already supports it

### Flow 20: Submit citizen proposal

Endpoint: `POST /api/citizen-proposals`

Rules:

- user must not be suspended
- user must be a verified constituent
- title required, 5 to 255 characters
- content required, 30 to 5000 characters
- category required
- jurisdiction focus must be:
  - `federal`
  - `state`
  - or valid two-letter state code

Duplicate detection behavior:

- system loads bills matching the proposal's focus area
- it compares proposal content against bill title + summary text
- if similarity meets configured threshold, proposal is rejected
- response tells the user the proposal appears too close to an existing bill and suggests amendment submission instead

Default threshold from seeded settings:

- `duplicate_threshold = 90`

Known limitation:

- duplicate detection currently checks against bill title + summary, not full bill text

### Flow 21: Support citizen proposal

Endpoint: `POST /api/citizen-proposals/{proposal}/support`

Rules:

- user must not be suspended
- user must be a verified constituent
- duplicate support is blocked

What happens:

- support row is created
- `support_count` increments
- threshold flags are updated when limit is reached

Default threshold from seeded settings:

- `proposal_threshold = 5000`

### Flow 22: Remove citizen proposal support

Endpoint: `DELETE /api/citizen-proposals/{proposal}/support`

What happens:

- support record is removed if present
- counter decrements only when greater than zero

## 9. Reporting And Moderation Input Flow

### Flow 23: Submit report

Endpoint: `POST /api/report`

Supported report targets:

- amendment
- proposal

Supported reasons:

- spam
- offensive
- joke
- duplicate
- other

Special rule:

- if reason is `other`, description must be at least 10 characters

Moderation protections:

- same user cannot report the same content twice
- missing content returns `404`
- suspended users cannot report

Auto-hide behavior:

- after report count reaches configured threshold, content is immediately hidden

Default threshold from seeded settings:

- `auto_hide_report_count = 10`

## 10. Managed Content Flow

### Flow 24: Public content feed

Endpoint: `GET /api/content`

What it serves:

- FAQs
- guidelines
- announcements

Rules:

- only published content is returned
- publication date must be in the past or null
- results are ordered by display order, published date, then title

Optional filters:

- `type`
- `audience`

If `type` is provided:

- response returns a flat `items` array

If `type` is not provided:

- response groups content into:
  - `faqs`
  - `guidelines`
  - `announcements`

## 11. User Self-Service Flow

### Flow 25: Profile read

Endpoint: `GET /api/user`

Returns authenticated user payload.

### Flow 26: Profile update

Endpoint: `PUT /api/user`

Currently allows:

- name update
- notification preferences update

### Flow 27: Vote history

Endpoint: `GET /api/user/votes`

Returns paginated personal vote history with related bills.

### Flow 28: Support history

Endpoint: `GET /api/user/supports`

Returns:

- supported amendments
- supported citizen proposals

### Flow 29: Submission history

Endpoint: `GET /api/user/submissions`

Returns:

- user amendments
- user citizen proposals

### Flow 30: Matched representatives

Endpoint: `GET /api/user/representatives`

What happens:

- user must already have district data
- federal representatives are matched by federal district
- state representatives are matched by state district and jurisdiction code
- if needed, OpenStates geo lookup helps infer the state code for matching

## 12. Web And Admin Entry Flow

### Flow 31: Root route and dashboard behavior

Web routes:

- `GET /`
- `GET /dashboard`

Behavior:

- unauthenticated visitor at `/` is redirected to admin login
- authenticated admin is redirected to Filament admin dashboard
- authenticated non-admin goes to `/dashboard`

### Flow 32: Admin access control

Admin panel path:

- `/admin`

Rule:

- only users with `admin` role and no active suspension can access the panel

## 13. Admin Operations Flow

### Flow 33: Admin dashboard widgets

The dashboard shows:

- total bills and active bills
- total amendments and threshold-reached amendments
- total citizen proposals and threshold-reached proposals
- total reports and pending reports
- total representatives and covered chambers
- report breakdown by:
  - pending
  - reviewed
  - dismissed
  - action taken

### Flow 34: Bills management

Admin can:

- create, edit, and delete bills
- assign jurisdiction
- manage status
- manage introduced date
- manage official vote date
- manage voting deadline
- manage official bill text URL

### Flow 35: Amendments management

Admin can:

- review user amendments
- edit amendment details
- hide amendment
- approve and restore amendment
- suspend proposer
- restore proposer
- delete amendment

### Flow 36: Citizen proposals management

Admin can:

- review user proposals
- edit proposal details
- hide proposal
- approve and restore proposal
- suspend proposer
- restore proposer
- delete proposal

### Flow 37: Reports management

Admin can:

- mark report reviewed
- dismiss report
- hide reported content
- approve and restore hidden content
- delete reported content
- suspend author
- restore author

This is the main moderation control center.

### Flow 38: Representatives management

Admin can:

- browse synced representatives
- edit identity and chamber data
- update jurisdiction assignment
- manage contact metadata
- manage committee assignments
- store historical years in office

### Flow 39: Managed content management

Admin can:

- create FAQs
- create guidelines
- create announcements
- assign target audience
- control display order
- publish immediately or schedule publication time

### Flow 40: Settings management

Admin can change platform behavior without code deployment.

Current managed settings include:

- platform name
- contact email
- support email
- amendment threshold
- proposal threshold
- duplicate threshold
- voting deadline hours
- proposal active days
- auto-hide report count
- feature flag for amendments
- feature flag for citizen proposals
- maintenance mode

## 14. Legislative Sync And Data Refresh Flow

### Flow 41: Manual sync command

Console command:

- `php artisan demos:sync-federal`

Options:

- `--now` to run synchronously
- `--with-state` to include state jobs
- `--limit=` for small test runs

Queued mode dispatches:

- `SyncFederalBills`
- `SyncFederalAmendments`
- `SyncFederalRepresentatives`
- `SyncVotingResults`
- `MaintainCommunityEngagement`
- plus state sync jobs when `--with-state` is used

Note:

- inline `--now` currently runs state jobs directly, while several federal inline calls remain commented out
- queued mode is the full operational path

### Flow 42: Federal bills sync

What it does:

- loads federal jurisdiction
- fetches bills from Congress.gov
- creates or updates bill shell records
- maps bill status
- dispatches detailed bill sync when key bill metadata is missing

### Flow 43: Federal bill detail sync

What it enriches:

- title
- summary
- introduced date
- sponsors
- committees
- official vote date
- voting deadline based on configured hours
- amendments history
- related document links
- bill text URL

It also dispatches official amendment detail sync for related congressional amendments.

### Flow 44: Federal amendments sync

What it does:

- fetches congressional amendments from Congress.gov
- uses last synced timestamp stored in settings
- dispatches amendment detail jobs
- stores new last synced timestamp

### Flow 45: Federal amendment detail sync

What it stores:

- official amendment identity
- chamber
- sponsor data
- latest action
- text URL
- metadata collections
- amendment text fallback
- linked bill

Imported official amendments are stored with:

- `source = congress_gov`
- `category = official`

### Flow 46: Federal representatives sync

What it does:

- imports members from Congress.gov
- stores name, party, chamber, district, photo, years in office
- queues detail sync when contact info or committees are missing

### Flow 47: Federal representative detail sync

What it enriches:

- website
- phone
- office address
- zip code
- committee assignments
- party history normalization

### Flow 48: Official federal vote sync

What it does:

- reads House roll-call vote feeds
- matches vote events back to bills or amendments
- creates official representative vote records
- updates official vote dates on bills
- recalculates voting deadlines from official vote dates

Official votes are stored separately from citizen votes in the `votes` table.

### Flow 49: State bills sync

What it does:

- loops through all seeded state jurisdictions
- fetches bills from OpenStates
- creates or updates state bill records
- derives status from passage date or latest action
- sets official vote date when present
- computes voting deadline using configured hours
- dispatches bill detail sync when enrichment is needed

### Flow 50: State bill detail sync

What it enriches:

- summaries
- sponsors
- committees
- bill text URL
- related documents
- amendment history

It also creates official state amendments from amendment-like history entries using:

- `source = openstates`
- `category = official`

### Flow 51: State representatives sync

What it does:

- imports legislators from OpenStates for each state
- stores party, chamber, district, photo, contact information, committees, and service years

### Flow 52: Community maintenance

Job:

- `MaintainCommunityEngagement`

What it recalculates:

- amendment support counts
- proposal support counts
- threshold flags
- threshold reached timestamps
- auto-hide state based on report counts

This job acts as a reconciliation layer so counters stay accurate even if live counts drift.

## 15. Scheduled Automation

Scheduler configuration currently runs:

- `demos:sync-federal` every hour
- `demos:sync-federal --with-state` daily at 02:00
- `horizon:snapshot` every five minutes
- community engagement maintenance every fifteen minutes

This means the system already has recurring operational refresh behavior, not just manual sync tools.

## 16. Seeded Platform Defaults

Seeded baseline setup includes:

- federal jurisdiction (`US`)
- all state jurisdictions with real USPS state codes
- default platform settings
- roles:
  - `admin`
  - `user`
- default admin account:
  - `admin@demos.local`

## 17. Important Validation And Safety Rules

The most important enforced rules in Phase 1 are:

- email verification required before normal participation
- district verification required before civic participation
- suspended accounts cannot participate
- bill vote comment max 50 words
- amendment text must be 50 to 70 words
- citizen proposal jurisdiction focus must be valid
- duplicate report by same user is blocked
- `other` reports need extra explanation
- support counters do not decrement below zero
- auto-hide can trigger after enough reports

## 18. What Is Working Right Now

The strongest completed areas are:

- mobile registration, email verification, login, logout, and password reset
- location verification with Google Civic plus OpenStates fallback
- verified constituent gating for participation
- bill browsing, bill detail, and voting
- district-scoped bill insights
- amendment submission and support
- citizen proposal submission and support
- reporting and moderation pathways
- admin CRUD and moderation actions
- managed content publishing
- platform settings management
- federal and state sync foundations
- representative persistence and official vote persistence
- scheduled background execution

## 19. Remaining Gaps And Next-Phase Work

Items still pending or needing hardening:

- duplicate detection should compare against full bill text, not only title + summary
- full production-grade sync resilience and orchestration can be expanded further
- external identity verification provider integration is still not wired in
- analytics endpoints are still missing
- notification and sharing workflows are still missing
- authorization, abuse prevention, and broader security hardening can be expanded

## 20. Current Validation Status

I ran the test suite on April 23, 2026 with:

`php artisan test`

Result:

- 41 tests passed
- 1 test failed
- 244 assertions executed

The only failing test is the default scaffold test in `tests/Feature/ExampleTest.php`.

Reason:

- it expects `GET /` to return HTTP `200`
- the real application intentionally redirects `/` to admin login, admin dashboard, or user dashboard
- current app behavior returns HTTP `302`, which matches the implemented routing flow

There was also a PHPUnit cache write warning for `.phpunit.result.cache`, which is an environment/write-permission issue and not a business-flow failure.

## 21. Short PM Summary

If you need one concise explanation in the meeting, use this:

"Phase 1 of DEMOS is already functioning as a working civic participation backend and admin platform. Citizens can register, verify email, verify their legislative districts, browse federal and state bills, vote on legislation, submit amendment ideas, submit citizen proposals, support community content, report inappropriate submissions, and view district-based participation insights. Admins can manage legislation, representatives, reports, content, and configurable platform settings through the admin panel. Behind the scenes, the platform syncs federal and state legislative data, official amendments, representatives, and official voting results through queued jobs and scheduled tasks. The next phase is mainly about hardening, analytics, notifications, and external identity-verification integration rather than building the core workflow from scratch." 
