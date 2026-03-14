# Frontend: Tailwind CSS + Alpine.js

This project uses **Tailwind CSS** and **Alpine.js** for the UI. **Bootstrap has been fully removed**; all app and public pages now use Tailwind + Alpine.

## How it works

- **Tailwind CSS**: Utility-first CSS. Brand colors and shadows are in `tailwind.config.js`.
- **Alpine.js**: Lightweight JS for modals, toggles, and interactivity (no jQuery/Bootstrap JS).
- **Bootstrap Icons**: Still loaded from CDN for icons (`bi bi-*` classes).

## No build step required (default)

The app works **without Node/npm**. The includes (`includes/head_public.php` and `includes/head_app.php`) use the **Tailwind Play CDN** when `assets/css/tailwind.css` does not exist. You can develop and run the site as-is.

## Optional: build Tailwind for production

For a smaller CSS file and faster load, you can build Tailwind yourself:

1. **Install Node.js** (LTS) from [nodejs.org](https://nodejs.org) if you haven’t already.

2. **Install dependencies and build:**
   ```bash
   cd "C:\Users\Kathleen Lizeth\Downloads\DEV SYSTEM\Ereview"
   npm install
   npm run build
   ```
   This creates `assets/css/tailwind.css`.

3. **Use the built file:**  
   Once `assets/css/tailwind.css` exists, the includes will link to it instead of the CDN.

4. **While developing (optional):**
   ```bash
   npm run watch
   ```
   Rebuilds CSS when you change PHP files that use Tailwind classes.

## Files involved

| File | Purpose |
|------|--------|
| `tailwind.config.js` | Tailwind config (content paths, brand colors, fonts, shadows). |
| `src/input.css` | Tailwind directives and custom component classes (e.g. `.btn-brand`). |
| `assets/css/tailwind.css` | Built output (after `npm run build`). |
| `includes/head_public.php` | Head for landing page (Tailwind CDN or built CSS + Alpine + Icons). |
| `includes/head_app.php` | Head for admin/student app pages (same as above). |
| `package.json` | Scripts: `build`, `watch`. |

## Migrated pages (Tailwind + Alpine)

All of the following use `includes/head_app.php` or `includes/head_public.php`, Tailwind for layout/components, and Alpine.js for modals and toggles where needed.

- **Public:** `index.php` (landing), `registration.php` (form + “Other” school toggle with Alpine)
- **Viewer:** `handout_viewer.php` (minimal Tailwind + Bootstrap Icons; no Alpine)
- **Admin:** `admin_dashboard.php`, `admin_sidebar.php`, `admin_students.php`, `admin_subjects.php`, `admin_lessons.php`, `admin_videos.php`, `admin_handouts.php`, `admin_quizzes.php`, `admin_quiz_questions.php`, `admin_student_view.php`, `admin_materials.php`
- **Student:** `student_dashboard.php`, `student_sidebar.php`, `student_subjects.php`, `student_lessons.php`, `student_lesson.php`, `student_subject.php`, `student_quizzes.php`, `student_take_quiz.php`, `student_handouts.php`, `student_videos.php`

Note: `admin_payment_proof.php` is a file-serving script (no HTML UI). No Bootstrap dependency remains.

## Adding or changing pages

To add a new page or restyle an existing one:

1. Use `<?php require_once __DIR__ . '/includes/head_app.php'; ?>` (or `head_public.php` for public pages) and do not load Bootstrap.
2. Include `admin_sidebar.php` or `student_sidebar.php` after `<body>` when the page is part of the app.
3. Use Tailwind utility classes (see existing pages for patterns):
   - `container` → `max-w-6xl mx-auto px-4`
   - `row` / `col-*` → `grid` / `grid-cols-*`
   - Cards → `bg-white rounded-xl shadow-card border border-gray-100 p-5`
   - Buttons → `px-4 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition`
   - Alerts → `p-4 rounded-xl bg-green-50 border border-green-200 text-green-800`
4. Modals and toggles: use Alpine.js (`x-data`, `x-show`, `@click`, `x-model`, or `Alpine.store()` for cross-component state).

## Brand colors (Tailwind)

Use these in class names:

- `brand-navy`, `brand-navy-dark`, `brand-gold`, `brand-gold-light`
- `primary`, `primary-dark`

Example: `text-brand-navy`, `bg-brand-gold`, `border-primary`.
