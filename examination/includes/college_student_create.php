<?php
declare(strict_types=1);

/**
 * Shared professor-admin college student account creation.
 * Used by professor_create_college_student and student_registration.
 */

require_once __DIR__ . '/college_sections.php';

/**
 * @param array<string,mixed> $post
 * @param array<string,mixed>|null $files $_FILES slice or null
 * @return array{
 *   ok: bool,
 *   error?: string,
 *   user_id?: int,
 *   avatar_preview_path?: string,
 *   avatar_use_default?: int,
 *   profile_picture_path?: string
 * }
 */
function college_student_create_from_request(mysqli $conn, array $post, ?array $files = null, array $options = []): array
{
    $initialStatus = strtolower(trim((string) ($options['initial_status'] ?? 'approved')));
    if (!in_array($initialStatus, ['approved', 'pending'], true)) {
        $initialStatus = 'approved';
    }
    $fullName = trim((string) ($post['full_name'] ?? ''));
    $section = trim((string) ($post['section'] ?? ''));
    $studentNumber = trim((string) ($post['student_number'] ?? ''));
    $email = trim((string) ($post['email'] ?? ''));
    $school = trim((string) ($post['school'] ?? ''));
    $password = (string) ($post['password'] ?? '');
    $confirmPassword = (string) ($post['confirm_password'] ?? '');
    $avatarUseDefault = !empty($post['use_default_avatar']) ? 1 : 0;
    $profilePicturePath = '';
    $avatarPreviewPath = '';
    $uploadedAvatar = is_array($files) ? ($files['profile_picture'] ?? null) : null;
    $canonicalSection = college_sections_resolve_active_name($conn, $section);

    if ($fullName === '' || $canonicalSection === null || $school === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Please enter a valid full name, section (from the list), school, and email.'];
    }
    if ($studentNumber !== '' && (strlen($studentNumber) > 32 || !preg_match('/^[A-Za-z0-9_-]+$/', $studentNumber))) {
        return ['ok' => false, 'error' => 'Student number must be at most 32 characters and use only letters, digits, hyphen, or underscore.'];
    }
    if ($password === '' || strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    }
    if ($confirmPassword === '' || $confirmPassword !== $password) {
        return ['ok' => false, 'error' => 'Passwords do not match.'];
    }

    $section = $canonicalSection;

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

    $stmt = mysqli_prepare($conn, 'SELECT user_id, role, email FROM users WHERE email=? LIMIT 1');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not validate email.'];
    }
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $existingRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($existingRow) {
        $existingId = (int) ($existingRow['user_id'] ?? 0);

        return [
            'ok' => false,
            'error' => 'An account with this email already exists (user ID #' . $existingId . '). '
                . 'Ask an administrator to enable College Examination on that existing account instead of creating a duplicate.',
        ];
    }

    if ($studentNumber !== '') {
        $chkSn = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE student_number=? LIMIT 1');
        if (!$chkSn) {
            return ['ok' => false, 'error' => 'Could not validate student number.'];
        }
        mysqli_stmt_bind_param($chkSn, 's', $studentNumber);
        mysqli_stmt_execute($chkSn);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($chkSn))) {
            mysqli_stmt_close($chkSn);

            return ['ok' => false, 'error' => 'That student number is already assigned.'];
        }
        mysqli_stmt_close($chkSn);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
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

    if ($evCol && $ppCol && $uaCol) {
        $ins = mysqli_prepare($conn, "INSERT INTO users (full_name, review_type, school, section, school_other, payment_proof, profile_picture, use_default_avatar, email, password, role, status, email_verified) VALUES (?, 'undergrad', ?, ?, NULL, NULL, ?, ?, ?, ?, 'college_student', ?, 1)");
        mysqli_stmt_bind_param($ins, 'sssissss', $fullName, $school, $section, $profilePicturePath, $avatarUseDefault, $email, $hash, $initialStatus);
    } elseif ($ppCol && $uaCol) {
        $ins = mysqli_prepare($conn, "INSERT INTO users (full_name, review_type, school, section, school_other, payment_proof, profile_picture, use_default_avatar, email, password, role, status) VALUES (?, 'undergrad', ?, ?, NULL, NULL, ?, ?, ?, ?, 'college_student', ?)");
        mysqli_stmt_bind_param($ins, 'sssissss', $fullName, $school, $section, $profilePicturePath, $avatarUseDefault, $email, $hash, $initialStatus);
    } elseif ($evCol) {
        $ins = mysqli_prepare($conn, "INSERT INTO users (full_name, review_type, school, section, school_other, payment_proof, email, password, role, status, email_verified) VALUES (?, 'undergrad', ?, ?, NULL, NULL, ?, ?, 'college_student', ?, 1)");
        mysqli_stmt_bind_param($ins, 'ssssss', $fullName, $school, $section, $email, $hash, $initialStatus);
    } else {
        $ins = mysqli_prepare($conn, "INSERT INTO users (full_name, review_type, school, section, school_other, payment_proof, email, password, role, status) VALUES (?, 'undergrad', ?, ?, NULL, NULL, ?, ?, 'college_student', ?)");
        mysqli_stmt_bind_param($ins, 'ssssss', $fullName, $school, $section, $email, $hash, $initialStatus);
    }

    if (!$ins || !mysqli_stmt_execute($ins)) {
        if ($ins) {
            mysqli_stmt_close($ins);
        }

        return ['ok' => false, 'error' => 'Could not create account.'];
    }

    $newId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($ins);

    if ($newId > 0 && $studentNumber !== '') {
        $updSn = mysqli_prepare($conn, 'UPDATE users SET student_number=? WHERE user_id=?');
        if ($updSn) {
            mysqli_stmt_bind_param($updSn, 'si', $studentNumber, $newId);
            mysqli_stmt_execute($updSn);
            mysqli_stmt_close($updSn);
        }
    }

    return [
        'ok' => true,
        'user_id' => $newId,
        'initial_status' => $initialStatus,
        'avatar_preview_path' => $avatarPreviewPath,
        'avatar_use_default' => $avatarUseDefault,
        'profile_picture_path' => $profilePicturePath,
    ];
}
