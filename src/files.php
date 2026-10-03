<?php
declare(strict_types=1);
/*
 * File uploads. Files are stored OUTSIDE the web root with random names and can only be fetched through
 * download.php, which checks who is asking. Only JPG, PNG and PDF are accepted (checked by file signature).
 */
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

function storage_file(string $stored): string
{
    return rtrim($GLOBALS['config']['storage_dir'], '/\\') . DIRECTORY_SEPARATOR . $stored;
}

/** Detects the real type from the first bytes; returns [mime, extension] or null. */
function sniff_type(string $path): ?array
{
    $h = (string) file_get_contents($path, false, null, 0, 8);
    if (str_starts_with($h, "\xFF\xD8\xFF")) return ['image/jpeg', 'jpg'];
    if (str_starts_with($h, "\x89PNG\r\n\x1a\n")) return ['image/png', 'png'];
    if (str_starts_with($h, '%PDF')) return ['application/pdf', 'pdf'];
    return null;
}

function has_upload(string $field): bool
{
    return isset($_FILES[$field]) && is_array($_FILES[$field]) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

/** Checks an uploaded file without saving it. Throws UserError when it is not acceptable. */
function check_upload(string $field, bool $imagesOnly = false): void
{
    if (!has_upload($field)) return;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new UserError(in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'The file is too large (maximum 5 MB)' : 'The file could not be uploaded, please try again');
    }
    if ($f['size'] > MAX_UPLOAD_BYTES) throw new UserError('The file is too large (maximum 5 MB)');
    if (!is_uploaded_file($f['tmp_name'])) throw new UserError('The file could not be uploaded, please try again');
    $t = sniff_type($f['tmp_name']);
    if (!$t || ($imagesOnly && $t[0] === 'application/pdf')) {
        throw new UserError($imagesOnly ? 'Please upload a JPG or PNG photo' : 'Only JPG, PNG or PDF files are allowed');
    }
}

/** Saves $_FILES[$field] and returns the new document id, or null if no file was chosen. */
function save_upload(string $field, int $districtId, string $ownerType, ?int $ownerId, string $category,
                     ?string $title = null, ?string $expires = null, bool $imagesOnly = false, ?int $uploader = -1): ?int
{
    if (!has_upload($field)) return null;
    check_upload($field, $imagesOnly);
    $f = $_FILES[$field];
    [$mime, $ext] = sniff_type($f['tmp_name']);
    $dir = rtrim($GLOBALS['config']['storage_dir'], '/\\');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Cannot create the upload folder');
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], storage_file($stored))) throw new RuntimeException('Cannot save the uploaded file');
    $name = preg_replace('/[^\w.\- ]+/u', '_', basename((string) $f['name']));
    $by = $uploader === -1 ? (current_user()['id'] ?? null) : $uploader;
    q('INSERT INTO documents (district_id, owner_type, owner_id, category, title, original_name, stored_name, mime, size_bytes, expires_on, uploaded_by)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [$districtId, $ownerType, $ownerId, $category, $title, mb_substr($name, 0, 255), $stored, $mime, (int) $f['size'], $expires, $by]);
    return last_id();
}

function doc_link(?int $docId, string $label = 'View'): string
{
    return $docId ? '<a href="download.php?id=' . (int) $docId . '" target="_blank" rel="noopener">' . e($label) . '</a>' : '–';
}
function doc_thumb(?int $docId): string
{
    if (!$docId) return '–';
    return '<a href="download.php?id=' . (int) $docId . '" target="_blank" rel="noopener"><img class="thumb" loading="lazy" alt="Photo" src="download.php?id=' . (int) $docId . '"></a>';
}

/** Who may open an uploaded file. */
function can_view_document(array $u, array $d): bool
{
    if ($u['role'] === 'STATE_ADMIN') return false;
    if ((int) $u['district_id'] !== (int) $d['district_id']) return false;
    if ($u['role'] === 'DISTRICT_COLLECTOR') return true;
    if ($d['uploaded_by'] !== null && (int) $d['uploaded_by'] === (int) $u['id']) return true;
    $id = (int) $d['owner_id'];
    $inScope = function (string $table) use ($u, $id): bool {
        $row = q_one("SELECT ward_id, village_id FROM $table WHERE id = ?", [$id]);
        if (!$row) return false;
        return can_manage_area($u, $row['ward_id'] === null ? null : (int) $row['ward_id'], $row['village_id'] === null ? null : (int) $row['village_id']);
    };
    return match ($d['owner_type']) {
        'FACILITY' => $inScope('facilities'),
        'WASTE' => $inScope('waste_entries'),
        'COMPLAINT' => $inScope('complaints') || (bool) q_val('SELECT 1 FROM complaints WHERE id = ? AND (user_id = ? OR assigned_to_user_id = ?)', [$id, $u['id'], $u['id']]),
        'ACTION' => (bool) q_val('SELECT 1 FROM actions WHERE id = ? AND (responsible_user_id = ? OR supporting_user_id = ? OR created_by = ?)', [$id, $u['id'], $u['id'], $u['id']]),
        'INSPECTION' => (bool) q_val('SELECT 1 FROM inspections WHERE id = ? AND inspector_user_id = ?', [$id, $u['id']]),
        'MEETING' => in_array($u['role'], ['MC', 'SARPANCH'], true),
        default => false,
    };
}
