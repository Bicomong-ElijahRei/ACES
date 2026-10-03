<?php
/**
 * ============================================================
 * ACES System — Secure File Upload Handler
 * ============================================================
 * Validates and saves uploaded files with:
 *   - Extension whitelist
 *   - MIME type verification (via finfo)
 *   - Size limit
 *   - Safe filename generation
 *   - Directory traversal prevention
 *
 * Usage:
 *   require_once __DIR__ . '/upload.php';
 *   $result = aces_upload('module_file', 'modules');
 *   if ($result['ok']) {
 *       $savedPath = $result['path']; // e.g. "modules/20261003_abc123.pdf"
 *   }
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';

// Allowed extensions and their permitted MIME types
const ACES_UPLOAD_TYPES = [
    'pdf'  => ['application/pdf'],
    'doc'  => ['application/msword'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'ppt'  => ['application/vnd.ms-powerpoint'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    'xls'  => ['application/vnd.ms-excel'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    'csv'  => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
    'txt'  => ['text/plain'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'gif'  => ['image/gif'],
    'mp4'  => ['video/mp4'],
    'zip'  => ['application/zip', 'application/x-zip-compressed'],
    'rar'  => ['application/x-rar-compressed', 'application/vnd.rar'],
    '7z'   => ['application/x-7z-compressed'],
];

// Max size (10 MB)
const ACES_UPLOAD_MAX_SIZE = 10485760;

/**
 * Validate and save an uploaded file.
 *
 * @param string $input_name  The $_FILES key (e.g., 'module_file')
 * @param string $subfolder   Subdirectory under uploads/ (e.g., 'modules')
 * @return array ['ok' => bool, 'path' => string, 'original' => string, 'error' => string]
 */
function aces_upload(string $input_name, string $subfolder = 'modules'): array
{
    // ---- Does the file exist? ----
    if (!isset($_FILES[$input_name])) {
        return ['ok' => false, 'error' => 'No file field in request.'];
    }

    $file = $_FILES[$input_name];

    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file was uploaded.'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed with error code ' . $file['error']];
    }

    // ---- Size check ----
    if ($file['size'] > ACES_UPLOAD_MAX_SIZE) {
        $max_mb = ACES_UPLOAD_MAX_SIZE / 1048576;
        return ['ok' => false, 'error' => "File is too large. Maximum size: {$max_mb} MB."];
    }

    if ($file['size'] === 0) {
        return ['ok' => false, 'error' => 'File is empty.'];
    }

    // ---- Extension check ----
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($ext === '' || !array_key_exists($ext, ACES_UPLOAD_TYPES)) {
        return ['ok' => false, 'error' => 'File type not allowed. Allowed: ' . implode(', ', array_keys(ACES_UPLOAD_TYPES))];
    }

    // ---- MIME type check ----
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    $allowed_mimes = ACES_UPLOAD_TYPES[$ext];

    if (!in_array($mime, $allowed_mimes, true)) {
        return [
            'ok' => false,
            'error' => "File content doesn't match the extension. Detected: {$mime}, expected: " . implode(' or ', $allowed_mimes)
        ];
    }

    // ---- Safe subfolder (prevent directory traversal) ----
    $subfolder = preg_replace('/[^a-z0-9_\-]/i', '', $subfolder);
    if ($subfolder === '') {
        $subfolder = 'modules';
    }

    $upload_dir = __DIR__ . '/../uploads/' . $subfolder . '/';

    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            return ['ok' => false, 'error' => 'Could not create upload directory.'];
        }
    }

    // ---- Generate safe filename ----
    $safe_name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target    = $upload_dir . $safe_name;

    // ---- Move file ----
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['ok' => false, 'error' => 'Could not save the file. Check folder permissions.'];
    }

    // ---- Restrict file permissions (owner read/write only) ----
    @chmod($target, 0644);

    return [
        'ok'       => true,
        'path'     => $subfolder . '/' . $safe_name,
        'original' => $file['name'],
        'size'     => $file['size'],
        'mime'     => $mime,
        'error'    => '',
    ];
}