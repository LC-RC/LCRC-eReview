# Google Sign-In setup (LCRC eReview)

To enable **Continue with Google** on the login page:

## 1. Create OAuth credentials

1. Go to [Google Cloud Console](https://console.cloud.google.com/).
2. Create or select a project.
3. Open **APIs & Services** → **Credentials**.
4. Click **Create Credentials** → **OAuth client ID**.
5. If asked, configure the **OAuth consent screen** (External, add your app name and support email).
6. Choose **Application type**: **Web application**.
7. Under **Authorized redirect URIs**, click **Add URI** and add exactly:
   - Local: `http://localhost/Ereview/google_callback.php`
   - Or with your domain: `https://yourdomain.com/Ereview/google_callback.php`
8. Click **Create**. Copy the **Client ID** and **Client Secret**.

## 2. Configure this app

1. Copy `google_oauth_config.sample.php` to `google_oauth_config.php` in this folder.
2. Open `google_oauth_config.php` and set:
   - `client_id` → your Client ID (ends with `.apps.googleusercontent.com`)
   - `client_secret` → your Client Secret

## 3. Test

1. Open the login page and click **Continue with Google**.
2. Sign in with Google and approve the app.
3. You must have an existing LCRC eReview account with the **same email** and that account must be **verified** (via the registration email verification flow). If the email is not registered, you’ll see “No account found for this Google email. Please register first.”

The redirect URI in Google Console must match your app URL exactly (including `/Ereview/` if you use that path).
