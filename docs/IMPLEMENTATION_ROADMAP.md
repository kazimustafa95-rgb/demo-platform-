# DEMOS Implementation Roadmap

## Phase 1 - Critical Platform Readiness
- [x] Register API routes in app bootstrap so `/api/*` endpoints are reachable.
- [x] Repair federal sync command inline mode (`--now`) to actually run bill sync.
- [x] Add optional state sync support to the sync command (`--with-state`).
- [x] Trigger voting-results sync job from the command flow.
- [x] Fix state jurisdiction seeding to use real USPS codes.
- [x] Use configured voting deadline hours setting when computing bill cutoff.

## Phase 2 - Core Requirement Rule Alignment
- [x] Enforce amendment proposal length at 50-70 words.
- [x] Enforce bill vote comment max length at 50 words.
- [x] Prevent support counters from decrementing below zero on unsupport.
- [x] Improve state district/state code parsing for representative lookup.
- [ ] Upgrade duplicate detection to compare against full bill text (not title+summary only).

## Phase 3 - Admin Operations Buildout
- [x] Build Filament resources for Bills, Amendments, Citizen Proposals, Reports, Representatives, Settings.
- [ ] Add moderation actions: approve/restore, delete, ban/suspend.
- [ ] Add threshold and voting settings management in admin UI.
- [ ] Add content management pages for FAQs/guidelines/announcements.

## Phase 4 - Data & Integrations
- [ ] Add scheduled sync orchestration for federal/state bills and official votes.
- [ ] Integrate Google Civic for district/official lookup fallback and reconciliation.
- [ ] Add identity verification provider integration (Persona/iDenfy/etc.).
- [ ] Add bill-source resilience (retries, dead-letter, idempotency, API error tracking).

## Phase 5 - Product Surface (Web/Mobile API Completeness)
- [ ] Implement dashboard/feed endpoints for "active/trending" sections.
- [ ] Add analytics endpoints for user/bill/amendment/proposal engagement.
- [ ] Add notification events (threshold reached, vote closed, official result posted).
- [ ] Add share/email templates when thresholds are reached.

## Phase 6 - Quality, Security, and Release
- [ ] Add feature tests for all API flows and edge cases.
- [ ] Add policy/authorization tests for admin/user boundaries.
- [ ] Add rate limiting and abuse controls for voting/support/reporting.
- [ ] Add security hardening checklist (PII handling, encryption checks, audit logs).

## Notes
- Section 3 (Citizen Proposals) is still marked phase 2 in your business doc, but backend groundwork already exists.
- The highest immediate risk before frontend/mobile work is API reachability + data integrity.