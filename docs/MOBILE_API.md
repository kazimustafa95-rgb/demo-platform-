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
