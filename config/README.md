# Config (LCRC eReview)

After **cloning or pulling** the repo, config files that contain secrets are not included. Do one of the following:

## Quick setup (recommended)

1. **Create config files from samples**  
   In the project root, run:
   ```bash
   php setup_config.php
   ```
   Or open in a browser once: `http://localhost/Ereview/setup_config.php`

2. **Edit the created files** with your credentials:
   - **Google Sign-In:** `config/google_oauth_config.php` — see [README_GOOGLE_OAUTH.md](README_GOOGLE_OAUTH.md)
   - **Email (forgot password, registration verification):** `config/mail_config.php` — see [README_MAIL.md](README_MAIL.md)

## Manual setup

- Copy `google_oauth_config.sample.php` → `google_oauth_config.php` and add your Google OAuth Client ID and Secret.
- Copy `mail_config.sample.php` → `mail_config.php` and add your Gmail SMTP (App Password).

Without these, **Google Sign-In** will show “not set up”, and **forgot password** / **registration verification emails** will not be sent (PHP `mail()` is used as fallback and often does not work on localhost).
