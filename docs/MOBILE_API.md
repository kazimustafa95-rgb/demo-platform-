# Mobile API Handoff

Import [docs/mobile-api.postman_collection.json](./mobile-api.postman_collection.json) into Postman for the mobile team.

## Auth flow

1. Call `POST /api/auth/register` or `POST /api/auth/login`.
2. The collection saves the returned bearer token into `auth_token` automatically.
3. Protected requests reuse that token.
4. Call `POST /api/auth/logout` to revoke the current token.

## Notes

- Run `php artisan migrate` so the new `personal_access_tokens` table exists.
- Update the collection `base_url` variable if your local URL is not `http://localhost`.
- Voting, supporting, and submitting content still require a user with `is_verified = true`.
- For Firebase push notifications, set `FIREBASE_PROJECT_ID` and `FIREBASE_CREDENTIALS_PATH` in your environment before using the notification test endpoint or real device pushes.

## Bills filters

`GET /api/bills` supports these query params:

- `jurisdiction=US` or any two-letter state code for exact jurisdiction filtering
- `jurisdiction=federal` or `jurisdiction=state` for the segmented bill type filter
- `jurisdiction_type=federal|state` as an explicit alias for the same type filter
- `search=term` with `q` and `query` accepted as aliases to search bill number, title, summary, and sponsors
- existing `status` and `deadline` filters continue to work

## Bill details

`GET /api/bills/{bill}` now includes these extra `bill` fields:

- `ai_summary_plain`
- `ai_bill_impact`

Both are plain-text fields capped at 500 characters each. To backfill them for all stored bills after setting `OPENAI_API_KEY`, run `php artisan demos:generate-bill-ai`.

For user-submitted amendments returned inside `bill.amendments`, the API also includes `support_threshold`, which is the admin-configured amendment support threshold.

## Amendments

`POST /api/bills/{bill}/amendments` now accepts:

- `title`
- `amendment_text`
- `category`

The API sets `submitted_at` automatically and returns `username` plus `submitted_date` on amendment payloads for mobile display.

`GET /api/amendments` lists all public user-submitted amendments with pagination and these optional filters:

- `jurisdiction=federal|state|US|CA`
- `jurisdiction_type=federal|state`
- `status=active|passed|failed` with comma-separated values supported
- `category=...` with comma-separated values supported
- `popular=1` to sort by support count
- `per_page=1..100`

Each amendment item includes its related `bill`, nested `bill.jurisdiction`, `username`, `submitted_date`, `support_threshold`, derived `status`, and `user_supported`.

## Citizen Proposals

`GET /api/citizen-proposals` lists all public citizen proposals with pagination and these optional filters:

- `jurisdiction=federal|state|CA`
- `jurisdiction_type=federal|state`
- `jurisdiction_focus=federal|state|CA`
- `status=active|passed|failed` with comma-separated values supported
- `category=...` with comma-separated values supported
- `search=term` with `q` and `query` aliases
- `popular=1`
- `per_page=1..100`

Each proposal item includes `username`, `submitted_date`, `support_threshold`, derived `status`, derived `jurisdiction_type`, `expires_at`, `days_left`, and `user_supported`.

`POST /api/citizen-proposals` now accepts either:

- `problem_statement` and `proposed_solution`

or the legacy:

- `content`

along with:

- `title`
- `category`
- `jurisdiction_focus`

The create response now returns the same enriched proposal payload used by the list endpoint.

## User Profile

`GET /api/user` now returns a mobile-friendly profile payload that includes:

- top-level identity and onboarding fields
- top-level `email_preferences`
- top-level `push_notification_preferences`
- `profile_information.full_name`
- `profile_information.email_address`
- `profile_information.phone_number`
- `profile_information.profile_photo_url`
- `profile_information.residential_address`
- `verification_status`
- top-level `profile_photo_url` and `profile_image_url`

`PUT /api/user` now supports these editable profile fields:

- `name` or `full_name`
- `email`
- `phone_number` or `phone`
- `address` or `street_address`
- `country`
- `state`
- `district`
- `zip_code` or `postal_code`
- `notification_preferences` for backward-compatible generic settings storage
- `remove_profile_image=true` to clear the current image

For profile image uploads, use `POST /api/user` with `multipart/form-data` and send the file as `profile_image`. Raw multipart `PUT` uploads are not reliable in PHP, so `PUT /api/user` should be used for normal JSON/text profile updates.

Changing the email clears `email_verified_at`, so the updated profile will show `email_verified = false` until the new email is verified again. Location verification still uses `POST /api/user/location`.

## Account Settings

`GET /api/user/privacy-settings` returns the mobile Privacy Settings page payload with:

- `title`
- `summary`
- `sections[policies]` linking to published privacy content pages
- `sections[controls]` linking to email preferences, notification preferences, and delete account
- `status` with the current verification flags

`GET /api/user/email-preferences` returns the mobile email-preferences payload with:

- `summary`
- `preferences.account_updates`
- `preferences.security_alerts`
- `preferences.reminders`
- `preferences.promotions`
- `preferences.newsletter`
- `sections` with per-toggle title, description, and enabled state

`PUT /api/user/email-preferences` accepts any of these boolean fields:

- `account_updates`
- `security_alerts`
- `reminders`
- `promotions`
- `newsletter`

The response returns the full normalized email-preferences payload after saving.

`DELETE /api/user` soft-deletes the authenticated user account and revokes all Sanctum tokens. Send either `password` or `current_password` in the request body to confirm the delete action.

`GET /api/content/pages/{slug}` returns a single published managed content page by slug. This is intended for pages like `privacy-policy` and `terms-of-service`, which can now be created and edited by admin from Managed Content with a direct page slug.

## Push Notification Preferences

`GET /api/user/notification-preferences` returns the mobile push-notification settings payload with:

- `title`
- `summary`
- `preferences.bill_updates`
- `preferences.significance_alerts`
- `preferences.weekly_digest`
- `preferences.proposal_activity`
- `preferences.representative_updates`
- `items` with title, description, and enabled state for each toggle

`PUT /api/user/notification-preferences` accepts any of these boolean fields:

- `bill_updates`
- `significance_alerts`
- `weekly_digest`
- `proposal_activity`
- `representative_updates`

The response returns the full normalized push-notification preferences payload after saving.

`POST /api/user/notification-devices` registers or updates the current device's FCM token. Send:

- `device_token`
- `platform` as `ios`, `android`, or `web`
- optional `device_name`
- optional `app_version`
- optional `notifications_enabled`

`DELETE /api/user/notification-devices` removes a previously registered FCM token for the authenticated user.

`POST /api/user/notification-test` sends a Firebase Cloud Messaging test push to the authenticated user's registered devices. Send:

- `type` as one of the supported push preference keys
- optional `title`
- optional `body`
- optional `device_token` to target one device only

The test endpoint only sends when that notification type is currently enabled for the user.
