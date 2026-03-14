# LCRC eReview — GitHub setup (step-by-step)

Use this guide to put the project on GitHub so your team can access and edit it on their own devices.

---

## Part 1: Prepare your computer (one-time)

### Step 1.1 — Install Git

1. Go to **https://git-scm.com/download/win** and download **Git for Windows**.
2. Run the installer. Default options are fine; keep **“Git from the command line and also from 3rd-party software”**.
3. Close and reopen any terminals (or restart the computer).
4. Check that Git works: open **Command Prompt** or **PowerShell** and run:
   ```bash
   git --version
   ```
   You should see something like `git version 2.x.x`.

### Step 1.2 — Configure your name and email (one-time per machine)

In Command Prompt or PowerShell, run (use your real name and GitHub email):

```bash
git config --global user.name "Your Full Name"
git config --global user.email "your-email@example.com"
```

Use the **same email** as your GitHub account.

---

## Part 2: Put LCRC eReview on GitHub

### Step 2.1 — Create a new repository on GitHub

1. Log in to **https://github.com**.
2. Click the **“+”** (top right) → **“New repository”**.
3. Fill in:
   - **Repository name:** e.g. `Ereview` or `LCRC-eReview`
   - **Description:** e.g. `LCRC eReview web system`
   - **Visibility:** **Private** (recommended) or **Public**
   - **Do not** check “Add a README” or “Add .gitignore” (we already have files).
4. Click **“Create repository”**.
5. Leave the browser tab open; you’ll need the repository URL (e.g. `https://github.com/YourUsername/Ereview.git`).

### Step 2.2 — Turn your project folder into a Git repo and push

Open **Command Prompt** or **PowerShell** and run these commands **one at a time**. Replace `YOUR_USERNAME` and `YOUR_REPO` with your GitHub username and repo name (e.g. `LC-RC/Ereview`).

```bash
cd c:\xampp\htdocs\Ereview
```

```bash
git init
```

```bash
git add .
```

```bash
git status
```
(Check that the right files are listed; config with secrets should be ignored if you use `.gitignore`.)

```bash
git commit -m "Initial commit: LCRC eReview web system"
```

```bash
git branch -M main
```

```bash
git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPO.git
```
Example: `git remote add origin https://github.com/LC-RC/Ereview.git`

```bash
git push -u origin main
```

- If GitHub asks you to sign in, use **GitHub’s browser sign-in** or a **Personal Access Token (PAT)** instead of a password.
- To create a PAT: GitHub → **Settings** → **Developer settings** → **Personal access tokens** → **Tokens (classic)** → **Generate new token**. Give it **repo** scope and use it as the password when `git push` asks.

After this, your project is on GitHub and the **main** branch is set.

---

## Part 3: Let others access and edit (collaborators)

### Step 3.1 — Add collaborators (you do this once per person)

1. On GitHub, open your repository (e.g. `https://github.com/LC-RC/Ereview`).
2. Click **“Settings”** (repo menu).
3. In the left sidebar, click **“Collaborators”** (or **“Collaborators and teams”**).
4. Click **“Add people”**.
5. Enter their **GitHub username** or **email**, choose their access (usually **Write** so they can push), then send the invite.
6. They accept the invite from their email or GitHub notifications.

After they accept, they can clone and push to the repo (within the permissions you gave).

---

## Part 4: How collaborators work on their own devices

Each teammate does this on **their** computer (one-time setup per machine if they don’t have Git yet: Part 1.1 and 1.2).

### Step 4.1 — Clone the repository

They open Command Prompt or PowerShell and run (replace with your repo URL):

```bash
cd c:\xampp\htdocs
git clone https://github.com/YOUR_USERNAME/YOUR_REPO.git Ereview
```

Example:

```bash
cd c:\xampp\htdocs
git clone https://github.com/LC-RC/Ereview.git Ereview
```

That creates `c:\xampp\htdocs\Ereview` with the full project. They open that folder in their editor (e.g. Cursor/VS Code) or point XAMPP to it.

### Step 4.2 — Daily workflow (get latest, edit, push)

**Get the latest changes (before starting work):**

```bash
cd c:\xampp\htdocs\Ereview
git pull
```

**After editing and testing:**

```bash
git add .
git status
git commit -m "Short description of what you changed"
git push
```

**You (and others) get their changes with:**

```bash
git pull
```

So: **pull** → edit → **add** → **commit** → **push**. Everyone stays in sync.

---

## Part 5: You (owner) getting updates after others push

Whenever someone else has pushed:

```bash
cd c:\xampp\htdocs\Ereview
git pull
```

Do this at least once at the start of your day or before editing, so you don’t overwrite their work.

---

## Quick reference

| Goal                         | Command (in `c:\xampp\htdocs\Ereview`)   |
|-----------------------------|------------------------------------------|
| Get latest from GitHub     | `git pull`                               |
| See status                 | `git status`                             |
| Stage all changes          | `git add .`                              |
| Commit                     | `git commit -m "Your message"`           |
| Send your commits to GitHub| `git push`                               |

---

## Troubleshooting

- **“Git is not recognized”**  
  Install Git (Part 1.1) and restart the terminal (or PC).

- **“Permission denied” or “Authentication failed” on push**  
  Use a **Personal Access Token** as the password, or set up **SSH keys** with GitHub.

- **“Updates were rejected” on push**  
  Someone else pushed first. Run `git pull` (fix conflicts if Git says so), then `git push` again.

- **Conflicts when pulling**  
  Git will mark conflicted files. Open them, remove the conflict markers (`<<<<<<<`, `=======`, `>>>>>>>`), keep the correct code, then:
  ```bash
  git add .
  git commit -m "Resolve merge conflicts"
  git push
  ```

---

## Optional: Same repo, different branch per person

If you want each person to use a separate branch (e.g. `maria`, `john`) and merge later:

- Create branch: `git checkout -b maria`
- Push it: `git push -u origin maria`
- Merge into `main` later on GitHub (Pull request) or with `git checkout main`, `git merge maria`, `git push`.

You can stick to **everyone on `main`** and **pull** before **push** until you’re comfortable with branches.

---

Once Git is installed and you’ve run the Part 2 commands with your repo URL, your LCRC eReview project will be on GitHub and your team can follow Part 4 to clone and edit on their own devices.
