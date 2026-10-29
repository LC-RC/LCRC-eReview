<?php
declare(strict_types=1);

/**
 * Shared professor-admin college student account creation.
 * Public registration uses college_public_register_from_request (pending + verify).
 */

require_once __DIR__ . '/college_sections.php';
require_once __DIR__ . '/college_student_number.php';
require_once __DIR__ . '/college_schools.php';
require_once __DIR__ . '/college_auth_credentials.php';

function college_password_meets_policy(string $password): bool
{
    return strlen($password) >= 8
        && (bool) preg_match('/[A-Z]/', $password)
        && (bool) preg_match('/[a-z]/', $password)
        && (bool) preg_match('/[0-9]/', $password)
        && (bool) preg_match('/[^A-Za-z0-9]/', $password);
}

function college_password_policy_error(): string
{
    return 'Password must be at least 8 characters and include uppercase, lowercase, a number, and a symbol.';
}

/**
 * Validate public/staff registration fields and store avatar if needed.
 * Ignores client-submitted student numbers.
 *
 * @return array<string,mixed>
 */
function college_student_prepare_registration_input(mysqli $conn, array $post, ?array $files = null): array
{
    $fullName = trim((string) ($post['full_name'] ?? ''));
    $section = trim((string) ($post['section'] ?? ''));
    $email = strtolower(trim((string) ($post['email'] ?? '')));
    $schoolId = (int) ($post['school_id'] ?? 0);
    $resolvedSchool = college_schools_resolve_active_by_id($conn, $schoolId);
    $password = (string) ($post['password'] ?? '');
    $confirmPassword = (string) ($post['confirm_password'] ?? '');
    $avatarUseDefault = !empty($post['use_default_avatar']) ? 1 : 0;
    $profilePicturePath = '';
    $avatarPreviewPath = '';
    $uploadedAvatar = is_array($files) ? ($files['profile_picture'] ?? null) : null;
    $canonicalSection = college_sections_resolve_active_name($conn, $section);

    if ($fullName === '' || $canonicalSection === null || $resolvedSchool === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $schoolErr = $resolvedSchool === null
            ? ' Select an active school from the list.'
            : '';
        $sectionErr = $canonicalSection === null ? ' Select a section from the list.' : '';

        return ['ok' => false, 'error' => 'Please enter a valid full name, section, school, and email.' . $sectionErr . $schoolErr];
    }
    $school = (string) $resolvedSchool['school_name'];
    if (!college_password_meets_policy($password)) {
        return ['ok' => false, 'error' => college_password_policy_error()];
    }
    if ($confirmPassword === '' || $confirmPassword !== $password) {
        return ['ok' => false, 'error' => 'Passwords do not match.'];
    }

    if ($avatarUseDefault !== 1 && is_array($uploadedAvatar) && !empty($uploadedAvatar['name'])) {
        $errCode = (int) ($uploadedAvatar['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errCode !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Could not upload profile picture.'];
        }
        $tmpFile = (string) ($uploadedAvatar['tmp_name'] ?? '');
        $origName = (string) ($uploadedAvatar['name'] ?? '');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (!in_array($ext, $allowedExt, true)) {
            return ['ok' => false, 'error' => 'Profile picture must be JPG, PNG, WEBP, or GIF.'];
        }
        if (!is_uploaded_file($tmpFile)) {
            return ['ok' => false, 'error' => 'Invalid profile picture upload.'];
        }
        $size = (int) ($uploadedAvatar['size'] ?? 0);
        if ($size <= 0 || $size > (4 * 1024 * 1024)) {
            return ['ok' => false, 'error' => 'Profile picture must be up to 4MB.'];
        }
        $uploadDirAbs = dirname(__DIR__, 2) . '/uploads/profile_pictures';
        if (!is_dir($uploadDirAbs)) {
            @mkdir($uploadDirAbs, 0775, true);
        }
        if (!is_dir($uploadDirAbs) || !is_writable($uploadDirAbs)) {
            return ['ok' => false, 'error' => 'Profile picture folder is not writable.'];
        }
        $fileBase = 'college_student_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5));
        $destAbs = $uploadDirAbs . '/' . $fileBase . '.' . $ext;
        if (!@move_uploaded_file($tmpFile, $destAbs)) {
            return ['ok' => false, 'error' => 'Failed to save profile picture.'];
        }
        $profilePicturePath = 'uploads/profile_pictures/' . basename($destAbs);
        $avatarPreviewPath = $profilePicturePath;
    } elseif ($avatarUseDefault !== 1) {
        return ['ok' => false, 'error' => 'Please upload a profile picture or enable default avatar.'];
    }

    return [
        'ok' => true,
        'full_name' => $fullName,
        'section' => $canonicalSection,
        'email' => $email,
        'school' => $school,
        'school_id' => (int) $resolvedSchool['school_id'],
        'password' => $password,
        'use_default_avatar' => $avatarUseDefault,
        'profile_picture_path' => $profilePicturePath,
        'avatar_preview_path' => $avatarPreviewPath,
    ];
}

/**
 * Staff create: approved college_student with server-allocated YYYY-NNN. Skips email token.
 *
 * @param array<string,mixed> $post
 * @param array<string,mixed>|null $files
 * @return array<string,mixed>
 */
function college_student_create_from_request(mysqli $conn, array $post, ?array $files = null, array $options = []): array
{
    $initialStatus = strtolower(trim((string) ($options['initial_status'] ?? 'approved')));
    if ($initialStatus !== 'approved') {
        $initialStatus = 'approved';
    }
    $prepared = college_student_prepare_registration_input($conn, $post, $files);
    if (empty($prepared['ok'])) {
        return [
            'ok' => false,
            'error' => (string) ($prepared['error'] ?? 'Could not create account.'),
            'avatar_preview_path' => (string) ($prepared['avatar_preview_path'] ?? ''),
        ];
    }
    if (!college_student_number_tables_ready($conn)) {
        return ['ok' => false, 'error' => 'College student number sequence is not installed. Apply migration 049 before creating college students.'];
    }

    $fullName = (string) $prepared['full_name'];
    $section = (string) $prepared['section'];
    $email = (string) $prepared['email'];
    $school = (string) $prepared['school'];
    $schoolId = (int) ($prepared['school_id'] ?? 0);
    $hash = password_hash((string) $prepared['password'], PASSWORD_DEFAULT);
    $lmsHash = college_auth_unusable_lms_password_hash();
    $profilePicturePath = (string) $prepared['profile_picture_path'];
    $avatarUseDefault = (int) $prepared['use_default_avatar'];
    $avatarPreviewPath = (string) $prepared['avatar_preview_path'];

    $last = ['ok' => false, 'error' => 'Could not create account.'];
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $last = college_student_create_staff_once(
            $conn,
            $fullName,
            $school,
            $section,
            $profilePicturePath,
            $avatarUseDefault,
            $email,
            $lmsHash,
            $hash,
            $initialStatus,
            $schoolId
        );
        if (!empty($last['ok'])) {
            $last['avatar_preview_path'] = $avatarPreviewPath;
            $last['avatar_use_default'] = $avatarUseDefault;
            $last['profile_picture_path'] = $profilePicturePath;
            $last['initial_status'] = $initialStatus;

            return $last;
        }
        $errno = (int) ($last['errno'] ?? 0);
        if ($errno === 1213 || $errno === 1205) {
            continue;
        }

        return $last;
    }

    return $last;
}

/**
 * @return array<string,mixed>
 */
function college_student_create_staff_once(
    mysqli $conn,
    string $fullName,
    string $school,
    string $section,
    string $profilePicturePath,
    int $avatarUseDefault,
    string $email,
    string $lmsHash,
    string $collegeHash,
    string $initialStatus,
    int $schoolId = 0
): array {
    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'Could not create account.'];
    }
    $lock = mysqli_prepare($conn, 'SELECT user_id, role FROM users WHERE email=? LIMIT 1 FOR UPDATE');
    if (!$lock) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => 'Could not validate email.'];
    }
    mysqli_stmt_bind_param($lock, 's', $email);
    if (!mysqli_stmt_execute($lock)) {
        mysqli_stmt_close($lock);
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => 'Could not validate email.'];
    }
    $existingRow = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
    mysqli_stmt_close($lock);
    if ($existingRow) {
        mysqli_rollback($conn);
        $existingId = (int) ($existingRow['user_id'] ?? 0);

        return [
            'ok' => false,
            'error' => 'An account with this email already exists (user ID #' . $existingId . '). '
                . 'Ask an administrator to enable College Examination on that existing account instead of creating a duplicate.',
        ];
    }

    $alloc = college_student_number_allocate_in_transaction($conn);
    if (empty($alloc['ok'])) {
        $errno = (int) ($alloc['errno'] ?? 0);
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => (string) ($alloc['error'] ?? 'Could not allocate student number.'), 'errno' => $errno];
    }
    $studentNumber = (string) $alloc['student_number'];

    $hasEv = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'email_verified'");
    $evCol = $hasEv && mysqli_fetch_assoc($hasEv);
    if ($hasEv) {
        mysqli_free_result($hasEv);
    }
    $hasPp = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'profile_picture'");
    $ppCol = $hasPp && mysqli_fetch_assoc($hasPp);
    if ($hasPp) {
        mysqli_free_result($hasPp);
    }
    $hasUa = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'use_default_avatar'");
    $uaCol = $hasUa && mysqli_fetch_assoc($hasUa);
    if ($hasUa) {
        mysqli_free_result($hasUa);
    }

    if (!$evCol) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => 'Required email_verified column is missing.'];
    }

    if ($ppCol && $uaCol) {
        $ins = mysqli_prepare(
            $conn,
            "INSERT INTO users (full_name, review_type, school, section, school_other, payment_proof, profile_picture, use_default_avatar, email, password, role, status, email_verified, student_number)
             VALUES (?, 'undergrad', ?, ?, NULL, NULL, ?, ?, ?, ?, 'college_student', ?, 1, ?)"
        );
        if (!$ins) {
            mysqli_rollback($conn);

            return ['ok' => false, 'error' => 'Could not create account.'];
        }
        mysqli_stmt_bind_param($ins, 'ssssissss', $fullName, $school, $section, $profilePicturePath, $avatarUseDefault, $email, $lmsHash, $initialStatus, $studentNumber);
    } else {
        $ins = mysqli_prepare(
            $conn,
            "INSERT INTO users (full_name, review_type, school, section, school_other, payment_proof, email, password, role, status, email_verified, student_number)
             VALUES (?, 'undergrad', ?, ?, NULL, NULL, ?, ?, 'college_student', ?, 1, ?)"
        );
        if (!$ins) {
            mysqli_rollback($conn);

            return ['ok' => false, 'error' => 'Could not create account.'];
        }
        mysqli_stmt_bind_param($ins, 'sssssss', $fullName, $school, $section, $email, $lmsHash, $initialStatus, $studentNumber);
    }

    if (!mysqli_stmt_execute($ins)) {
        $errno = mysqli_errno($conn);
        mysqli_stmt_close($ins);
        mysqli_rollback($conn);
        if ($errno === 1062) {
            return ['ok' => false, 'error' => 'Could not create account.', 'errno' => $errno];
        }

        return ['ok' => false, 'error' => 'Could not create account.', 'errno' => $errno];
    }
    $newId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($ins);
    if ($newId <= 0 || $collegeHash === '' || !college_auth_credentials_put($conn, $newId, $collegeHash)) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => 'Could not create account.'];
    }
    if ($schoolId > 0) {
        college_schools_apply_user_fk($conn, $newId, $schoolId);
    }
    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => 'Could not create account.'];
    }

    return [
        'ok' => true,
        'user_id' => $newId,
        'student_number' => $studentNumber,
    ];
}
