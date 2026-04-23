# DEMOS PM Demo Brief

## Project Overview

DEMOS is a civic participation platform built on Laravel. It combines:

- a mobile/API experience for citizens,
- an admin panel for operations and moderation,
- background sync jobs for legislative data,
- district-based participation and insights,
- content management and platform settings.

The main goal of the platform is to let verified users discover real legislation, vote on bills, propose amendments, support citizen proposals, and participate in a structured, moderated civic workflow.

## Core User Flow

### 1. Registration and Login

Users register through the mobile/API auth flow.

- Registration accepts name, email, phone, and password.
- A verification code is sent by email.
- Users verify the code to activate access.
- Login returns a Sanctum bearer token for protected API calls.
- Suspended users are blocked from participation and login where applicable.

### 2. Onboarding and Constituent Verification

After email verification, users complete location verification.

- Users can enter address fields manually or send latitude/longitude.
- The system geocodes the address using Google Maps.
- It resolves federal and state legislative districts using Google Civic APIs first.
- If Google data is incomplete, it falls back to OpenStates location lookup.
- When district detection succeeds, the user is marked as a verified constituent.

This is an important business rule because voting, amendment submission, proposal submission, and support actions all require a verified constituent.

### 3. Bill Discovery

Users can browse bills through the API.

- Bills can be filtered by jurisdiction and status.
- Bills include federal and state legislation.
- Each bill stores title, summary, introduced date, voting deadline, official vote date, sponsors, committees, and related documents.

### 4. Bill Detail and Participation

When a user opens a bill:

- the system shows the bill information,
- top user-generated amendments are shown,
- constituent vote totals are calculated,
- percentages are shown,
- official representative votes are attached when synced,
- the API indicates whether voting is still open.

Users can then:

- vote `in_favor`, `against`, or `abstain`,
- optionally add a short comment,
- update or remove their vote while voting is open.

### 5. District Insights

For a verified user, the platform can calculate district-specific insights on a bill.

- It limits the analysis to verified participants from the user's district.
- It resolves district population and registered voter counts.
- It calculates turnout, vote proportions, and confidence intervals.
- This gives the product a stronger policy and analytics layer beyond simple raw vote counts.

### 6. Amendment Workflow

Users can submit amendment proposals on bills.

- Only verified constituents can propose amendments.
- Amendments are validated to stay within the defined word-count rule.
- Users can support amendments from other users.
- Support counts are tracked.
- When support reaches the admin-configured threshold, the amendment is marked as threshold reached.
- Amendments can also be hidden through moderation or auto-hide logic.

The platform also imports official amendments from external legislative sources, so the system contains both:

- official amendments from upstream feeds,
- citizen-generated amendments from platform users.

### 7. Citizen Proposal Workflow

Users can also create independent citizen proposals.

- Only verified constituents can submit them.
- Proposals include title, content, category, and jurisdiction focus.
- The system checks for similarity against existing legislation to reduce duplicates.
- Users can support proposals.
- Support thresholds are configurable through admin settings.
- Threshold-reaching proposals are flagged for visibility and escalation.

### 8. Reporting and Moderation

Users can report amendments or citizen proposals.

- Report reasons are structured.
- Duplicate reports by the same user are prevented.
- If report counts hit the configured threshold, content is auto-hidden.

This creates a moderation safety layer before manual admin review.

### 9. User Self-Service APIs

Authenticated users can also access:

- their profile,
- their voting history,
- their supported amendments and proposals,
- their own submissions,
- their matched representatives based on district.

## Admin Flow

The system includes a Filament admin panel with role-based access.

Admin users can manage:

- Bills
- Amendments
- Citizen Proposals
- Representatives
- Reports
- Managed Content
- Settings

### Admin Dashboard

The dashboard gives quick totals for:

- bills,
- amendments,
- citizen proposals,
- reports,
- representatives.

It also includes report status tracking, such as:

- pending,
- reviewed,
- dismissed,
- action taken.

### Moderation Actions

Admins can:

- mark reports as reviewed,
- dismiss reports,
- hide content,
- restore content,
- delete content,
- suspend an author,
- restore an author.

This means moderation is not only a database status update; it supports real operational actions on both the content and the user account.

### Managed Content

Admins can publish content for the product experience, including:

- FAQs,
- guidelines,
- announcements.

Content can be audience-targeted and ordered for display.

### Settings Management

Admins can configure platform behavior without changing code, including:

- amendment support threshold,
- proposal support threshold,
- duplicate detection sensitivity,
- voting deadline hours,
- auto-hide report threshold,
- feature flags,
- maintenance mode,
- support and contact emails.

## Data Sync and Background Processing

The platform has a strong backend sync layer for keeping legislative data current.

### Federal Sync

Federal jobs pull data from Congress.gov, including:

- bills,
- bill details,
- amendments,
- amendment details,
- representatives,
- representative details,
- official voting results.

These jobs enrich records with:

- summaries,
- text links,
- committees,
- sponsors,
- related documents,
- official vote dates,
- voting deadlines.

### State Sync

State jobs use OpenStates to sync:

- state bills,
- state bill details,
- state representatives,
- state-level official amendment history.

### Scheduled Operations

The scheduler currently runs:

- hourly federal sync,
- daily state-inclusive sync,
- Horizon snapshots,
- community engagement maintenance.

### Community Maintenance

A maintenance job recalculates:

- support counts,
- threshold flags,
- auto-hide behavior for reported content.

This helps keep engagement data accurate even if counts drift.

## Technical Architecture

The project is built with:

- Laravel 12
- Sanctum for API tokens
- Fortify and Jetstream for auth foundations
- Filament for admin operations
- Horizon for queue visibility
- Spatie Permission for admin role control

It uses background jobs and scheduled commands heavily, which is good for scale and for upstream API reliability.

## Current Project Status

### What Is Already Working Well

The strongest completed areas are:

- authentication and onboarding,
- district-aware verification,
- bill browsing and voting,
- amendment and citizen proposal participation,
- reporting and moderation,
- admin CRUD and operational controls,
- federal and state sync foundations,
- representative and vote persistence,
- managed content and settings,
- district-scoped insights.

### What Is Still Pending or Needs Hardening

According to the roadmap and current implementation, the main remaining areas are:

- stronger duplicate detection against full bill text,
- more complete scheduled orchestration and resilience,
- full identity verification provider integration,
- analytics endpoints,
- notification events,
- share/email workflows,
- broader authorization, abuse, and security hardening,
- expanded test coverage for all edge cases.

## Confidence and Validation

The automated test suite is in good shape.

- `41` tests passed.
- `1` scaffold example test failed because the root route now redirects to login or dashboard instead of returning a plain `200` response.

That failure is not a core business-flow failure. It is a stale default example test caused by the app's intended redirect behavior.

## PM Demo Summary

If you want one short summary for the meeting, use this:

"DEMOS is now a working civic participation platform where users can register, verify email, verify district, view real federal and state bills, vote on legislation, propose amendments, support community proposals, report inappropriate content, and see district-based participation insights. On the operations side, admins can manage legislation, representatives, reports, content, and platform settings through the admin panel. The system also syncs legislative data and official voting information from external public sources through background jobs. The core backend and admin workflows are in place, while the next phase focuses on analytics, notifications, identity verification integration, and additional hardening."
