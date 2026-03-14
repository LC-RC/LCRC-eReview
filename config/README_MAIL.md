# Email / SMTP configuration (password reset)

Password reset emails are sent via **Gmail SMTP** when configured below.

## 1. Use your Gmail account

Edit **`config/mail_config.php`** and set:

- **`smtp_username`** – your full Gmail address (e.g. `monzalesvinceivan@gmail.com`)
- **`smtp_password`** – your **Gmail App Password** (see step 2)
- **`from_email`** – same as `smtp_username` (Gmail requires this)

## 2. Create a Gmail App Password

You cannot use your normal Gmail password. You must use an **App Password**:

1. Turn on **2-Step Verification** for your Google account:  
   [https://myaccount.google.com/signinoptions/two-step-verification](https://myaccount.google.com/signinoptions/two-step-verification)

2. Create an **App Password**:  
   [https://myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords)  
   - Choose “Mail” and “Other (Custom name)” → e.g. “LCRC eReview”
   - Copy the **16-character password** (no spaces)

3. Paste that 16-character password into **`smtp_password`** in `config/mail_config.php`.

## 3. Test

Open **Forgot password**, enter your email, and submit. The reset email should arrive at that address (check Inbox and Spam).

If it still does not send, ensure:

- 2-Step Verification is ON
- You are using the **App Password**, not your normal Gmail password
- `config/mail_config.php` has no typos and is saved
