# Google Sign-In setup (LCRC eReview)

To enable **Google Sign-In** on the login page:

## 1. Create OAuth credentials

1. Go to [Google Cloud Console](https://console.cloud.google.com/).
2. Create or select a project.
3. Open **APIs & Services** → **Credentials**.
4. Click **Create Credentials** → **OAuth client ID** (or edit your existing Web client).
5. If asked, configure the **OAuth consent screen** (External, add your app name and support email).
6. Choose **Application type**: **Web application**.
7. Under **Authorized redirect URIs**, click **Add URI** and add the URI **exactly** as the app builds it (no trailing slash, and **without** `.php`):

   | Environment | Authorized redirect URI |
   |---|---|
   | Local (XAMPP folder `Ereview`) | `http://localhost/Ereview/google_callback` |
   | Production | `https://lcrc-ereview.com/google_callback` |
   | Production (www) | `https://www.lcrc-ereview.com/google_callback` |

   Add every host you actually use. Google requires an **exact** string match (scheme, host, path).

8. Optional - under **Authorized JavaScript origins**, you can also add:
   - `http://localhost`
   - `https://lcrc-ereview.com`
9. Click **Save** / **Create**. Copy the **Client ID** and **Client Secret**.

### Error 400: `redirect_uri_mismatch`

This means the `redirect_uri` your app sent to Google is **not** listed (character-for-character) under Authorized redirect URIs.

Common mistakes:
- Using `google_callback.php` instead of `google_callback`
- `http` vs `https`
- `www` vs bare domain
- Wrong folder path (`/Ereview/` locally vs none on production)
- Trailing slash (`/google_callback/`)

After saving in Google Cloud, wait a minute, then try **Google Sign-In** again.

## 2. Configure this app

1. Copy `google_oauth_config.sample.php` to `google_oauth_config.php` in this folder.
2. Open `google_oauth_config.php` and set:
   - `client_id` → your Client ID (ends with `.apps.googleusercontent.com`)
   - `client_secret` → your Client Secret

## 3. Test

1. Open the login page and click **Google Sign-In**.
2. Sign in with Google and approve the app.
3. You must have an existing LCRC eReview account with the **same email**. If the email is not registered, you'll see "No account found for this Google email."

To see the exact redirect URI your server is sending, open `google_auth` while logged out - if config is missing, the login error also prints the URI. Or check the Google "error details" on the 400 page.
