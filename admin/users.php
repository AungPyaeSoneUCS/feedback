<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = $LANG['users_title'] ?? 'Users';
$activeMenu = 'users';

function isValidEmail($email)
{
    return preg_match('/^[a-zA-Z0-9._]+@(ucsh\.edu\.mm|gmail\.com)$/', $email);
}

function isValidPassword($password)
{
    return strlen($password) >= 6 && preg_match('/^[a-zA-Z0-9@]+$/', $password);
}

function isValidName($name)
{
    $name = trim($name);
    if (strlen($name) < 2 || strlen($name) > 100)
        return false;
    return preg_match('/^(?:(?:Dr|Prof|U|Daw)\.\s)?[A-Za-z]+(?: [A-Za-z]+)*$/i', $name);
}

function isValidUsername($username)
{
    return preg_match('/^[a-z0-9_]{4,30}$/', $username);
}

function formatName($name)
{
    $name = trim($name);
    $name = ucwords(strtolower($name));
    $name = preg_replace('/\b(Dr|Prof|U|Daw)\b\.(\s)/', '$1.$2', $name);
    return $name;
}

// ─── Template Download ─────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'download_template' && isset($_SESSION['csrf_token'])) {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="student_import_template.xlsx"');
    // Build minimal .xlsx template with headers
    $tmp = tempnam(sys_get_temp_dir(), 'tpl');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbooks.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="Students" sheetId="1" r:id="rId1"/></sheets>
</workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>');
    $headers = ['Name', 'Username', 'Email', 'Password', 'Roll No'];
    $shared = '';
    foreach ($headers as $h)
        $shared .= '<si><t>' . htmlspecialchars($h) . '</t></si>';
    $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="5" uniqueCount="5">' . $shared . '</sst>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts><font><sz val="11"/><name val="Calibri"/></font></fonts>
  <fills><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>
  <borders><border><left/><right/><top/><bottom/><diagonal/></border></borders>
  <cellXfs><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellXfs>
</styleSheet>');
    $rows = '<row r="1">';
    for ($c = 0; $c < 5; $c++) {
        $col = chr(65 + $c);
        $rows .= '<c r="' . $col . '1" t="s"><v>' . $c . '</v></c>';
    }
    $rows .= '</row>';
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>' . $rows . '</sheetData>
</worksheet>');
    $zip->close();
    readfile($tmp);
    unlink($tmp);
    exit;
}

// ─── Failed Import File Download ───────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'download_failed_import' && isset($_SESSION['csrf_token'])) {
    $failedFile = $_SESSION['failed_import_file'] ?? null;
    if ($failedFile && file_exists($failedFile)) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="failed_import_report.xlsx"');
        header('Content-Length: ' . filesize($failedFile));
        readfile($failedFile);
        unlink($failedFile);
        unset($_SESSION['failed_import_file']);
        exit;
    }
    // File not found, redirect back
    header('Location: users.php');
    exit;
}

// ─── Helper: Build .xlsx from array data ───────────────────────
function buildXlsx(array $headers, array $dataRows): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="Failed Records" sheetId="1" r:id="rId1"/></sheets>
</workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts><font><sz val="11"/><name val="Calibri"/></font></fonts>
  <fills><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>
  <borders><border><left/><right/><top/><bottom/><diagonal/></border></borders>
  <cellXfs><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellXfs>
</styleSheet>');

    // Collect all unique strings for shared strings table
    $allStrings = [];
    $stringIndex = [];
    foreach ($headers as $h) {
        if (!isset($stringIndex[$h])) {
            $stringIndex[$h] = count($allStrings);
            $allStrings[] = $h;
        }
    }
    foreach ($dataRows as $row) {
        foreach ($row as $val) {
            $val = (string) $val;
            if (!isset($stringIndex[$val])) {
                $stringIndex[$val] = count($allStrings);
                $allStrings[] = $val;
            }
        }
    }

    $sharedXml = '';
    foreach ($allStrings as $s) {
        $sharedXml .= '<si><t>' . htmlspecialchars($s, ENT_XML1, 'UTF-8') . '</t></si>';
    }
    $totalStrings = count($allStrings);
    $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $totalStrings . '" uniqueCount="' . $totalStrings . '">' . $sharedXml . '</sst>');

    // Build sheet rows
    $sheetRows = '<row r="1">';
    foreach ($headers as $c => $h) {
        $col = chr(65 + $c);
        $sheetRows .= '<c r="' . $col . '1" t="s"><v>' . $stringIndex[$h] . '</v></c>';
    }
    $sheetRows .= '</row>';

    foreach ($dataRows as $rIdx => $row) {
        $rowNum = $rIdx + 2;
        $sheetRows .= '<row r="' . $rowNum . '">';
        foreach ($row as $c => $val) {
            $col = chr(65 + $c);
            $val = (string) $val;
            $sheetRows .= '<c r="' . $col . $rowNum . '" t="s"><v>' . $stringIndex[$val] . '</v></c>';
        }
        $sheetRows .= '</row>';
    }

    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>' . $sheetRows . '</sheetData>
</worksheet>');
    $zip->close();
    return $tmp;
}

// ─── Import Handler ────────────────────────────────────────────
$importResults = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf() && ($_POST['action'] ?? '') === 'import_students') {
    $allowed = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'];
    $file = $_FILES['import_file'] ?? null;
    $importErrors = [];

    // A new import invalidates any failed-record file left from an earlier attempt.
    if (!empty($_SESSION['failed_import_file'])) {
        if (file_exists($_SESSION['failed_import_file'])) {
            @unlink($_SESSION['failed_import_file']);
        }
        unset($_SESSION['failed_import_file']);
    }

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $importErrors[] = $LANG['import_upload_failed'] ?? 'File upload failed. Please try again.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls'])) {
            $importErrors[] = $LANG['import_invalid_format'] ?? 'Only .xlsx and .xls files are allowed.';
        } elseif ($ext === 'xls') {
            $importErrors[] = $LANG['import_xls_not_supported'] ?? '.xls format is not supported. Please save as .xlsx and try again.';
        }
    }

    if (!$importErrors) {
        // Read .xlsx file
        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            $importErrors[] = $LANG['import_read_error'] ?? 'Unable to read the Excel file. It may be corrupted.';
        } else {
            // Load shared strings
            $sharedStrings = [];
            if ($zip->locateName('xl/sharedStrings.xml')) {
                $xml = simplexml_load_string($zip->getFromName('xl/sharedStrings.xml'));
                if ($xml) {
                    foreach ($xml->si as $si) {
                        $sharedStrings[] = (string) ($si->t ?? $si->r->t ?? '');
                    }
                }
            }

            // Load sheet data
            $sheetData = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();

            if (!$sheetData) {
                $importErrors[] = $LANG['import_no_worksheet'] ?? 'No worksheet found in the Excel file.';
            } else {
                $xml = simplexml_load_string($sheetData);
                if (!$xml) {
                    $importErrors[] = $LANG['import_parse_error'] ?? 'Unable to parse worksheet data.';
                } else {
                    $rows = [];
                    foreach ($xml->sheetData->row as $row) {
                        $rowData = [];
                        $maxCol = 0;
                        foreach ($row->c as $cell) {
                            $ref = (string) $cell['r'];
                            preg_match('/([A-Z]+)/', $ref, $m);
                            $colIdx = 0;
                            foreach (str_split($m[1]) as $ch) {
                                $colIdx = $colIdx * 26 + (ord($ch) - 64);
                            }
                            $colIdx--;
                            $val = (string) ($cell->v ?? '');
                            if ((string) ($cell['t'] ?? '') === 's' && isset($sharedStrings[(int) $val])) {
                                $val = $sharedStrings[(int) $val];
                            }
                            $rowData[$colIdx] = trim($val);
                            if ($colIdx > $maxCol)
                                $maxCol = $colIdx;
                        }
                        $rows[] = $rowData;
                    }

                    // ─── Phase 1: Template Header Validation ────────────
                    $expectedHeaders = ['Name', 'Username', 'Email', 'Password', 'Roll No'];
                    $templateErrors = [];

                    if (empty($rows)) {
                        $templateErrors[] = $LANG['import_empty_template'] ?? 'The uploaded file is empty. No header row found.';
                    } else {
                        $headerRow = $rows[0];
                        $actualHeaders = [];
                        for ($i = 0; $i < max(count($expectedHeaders), count($headerRow)); $i++) {
                            $actualHeaders[$i] = trim($headerRow[$i] ?? '');
                        }

                        // Check column count
                        $actualCount = count(array_filter($actualHeaders, fn($h) => $h !== ''));
                        $expectedCount = count($expectedHeaders);

                        if ($actualCount === 0) {
                            $templateErrors[] = $LANG['import_no_headers'] ?? 'No column headers found in the first row. Please use the import template.';
                        } else {
                            // Compare each expected column exactly, including case and position.
                            for ($i = 0; $i < $expectedCount; $i++) {
                                $expected = $expectedHeaders[$i];
                                $actual = $actualHeaders[$i] ?? '';

                                if ($actual === '') {
                                    $templateErrors[] = sprintf($LANG['import_missing_column'] ?? "Missing column '%s'.", $expected);
                                } elseif ($actual !== $expected) {
                                    $matchingPosition = array_search($expected, $actualHeaders, true);
                                    if ($matchingPosition !== false) {
                                        $templateErrors[] = sprintf($LANG['import_incorrect_order'] ?? "Incorrect column order: '%s' must be column %d.", $expected, $i + 1);
                                    } else {
                                        $templateErrors[] = sprintf($LANG['import_incorrect_name'] ?? "Incorrect column name '%s'; expected '%s' at column %d.", $actual, $expected, $i + 1);
                                    }
                                }
                            }

                            // Check for extra columns
                            for ($i = $expectedCount; $i < count($actualHeaders); $i++) {
                                if ($actualHeaders[$i] !== '') {
                                    $templateErrors[] = sprintf($LANG['import_extra_column'] ?? "Unexpected extra column '%s'.", $actualHeaders[$i]);
                                }
                            }
                        }
                    }

                    // If template is invalid, stop — do NOT generate a failed file
                    if ($templateErrors) {
                        $importErrors = $templateErrors;
                    } else {
                        // ─── Phase 2: Per-Row Validation ────────────────
                        $dataRows = array_slice($rows, 1);

                        $totalRows = 0;
                        $imported = 0;
                        $skipped = 0;
                        $skipDetails = [];

                        // Track duplicates within the file itself
                        $seenUsernames = [];
                        $seenEmails = [];
                        $seenRollNos = [];

                        foreach ($dataRows as $rowIdx => $rowData) {
                            $excelRow = $rowIdx + 2; // +2 because 0-indexed + header
                            $name = $rowData[0] ?? '';
                            $originalRow = array_map(static fn($index) => $rowData[$index] ?? '', range(0, 4));
                            $username = strtolower(trim($originalRow[1]));
                            $email = strtolower(trim($originalRow[2]));
                            $password = $rowData[3] ?? '';
                            $rollNo = trim($rowData[4] ?? '');

                            // Skip completely empty rows
                            if (!$name && !$username && !$email && !$password && !$rollNo) {
                                continue;
                            }

                            $totalRows++;

                            $rowErrors = [];

                            // Required field checks
                            if (!$name)
                                $rowErrors[] = $LANG['import_required_name'] ?? 'Required field is empty: Name';
                            if (!$username)
                                $rowErrors[] = $LANG['import_required_username'] ?? 'Required field is empty: Username';
                            if (!$email)
                                $rowErrors[] = $LANG['import_required_email'] ?? 'Required field is empty: Email';
                            if (!$password)
                                $rowErrors[] = $LANG['import_required_password'] ?? 'Required field is empty: Password';
                            if (!$rollNo)
                                $rowErrors[] = $LANG['import_required_roll'] ?? 'Required field is empty: Roll No';

                            // Format validation
                            if ($name && !isValidName($name))
                                $rowErrors[] = $LANG['import_invalid_name'] ?? 'Invalid name format';
                            if ($username && !isValidUsername($username))
                                $rowErrors[] = $LANG['import_invalid_username'] ?? 'Invalid username format';
                            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL))
                                $rowErrors[] = $LANG['val_email_invalid'] ?? 'Invalid email address. Email must use either @ucsh.edu.mm or @gmail.com.';
                            elseif ($email && !preg_match('/@(ucsh\.edu\.mm|gmail\.com)$/', $email))
                                $rowErrors[] = $LANG['val_email_invalid'] ?? 'Invalid email address. Email must use either @ucsh.edu.mm or @gmail.com.';
                            if ($password && !isValidPassword($password))
                                $rowErrors[] = $LANG['import_invalid_password'] ?? 'Invalid password format';
                            if ($rollNo && !preg_match('/^[A-Za-z0-9\-\/]+$/', $rollNo))
                                $rowErrors[] = $LANG['import_invalid_roll'] ?? 'Invalid roll number format';

                            // Check for duplicates within the file
                            if ($username) {
                                if (isset($seenUsernames[$username]))
                                    $rowErrors[] = $LANG['import_duplicate_username_file'] ?? 'Duplicate username in file';
                                else
                                    $seenUsernames[$username] = $excelRow;
                            }
                            if ($email) {
                                if (isset($seenEmails[$email]))
                                    $rowErrors[] = $LANG['import_duplicate_email_file'] ?? 'Duplicate email in file';
                                else
                                    $seenEmails[$email] = $excelRow;
                            }
                            if ($rollNo) {
                                if (isset($seenRollNos[$rollNo]))
                                    $rowErrors[] = $LANG['import_duplicate_roll_file'] ?? 'Duplicate roll number in file';
                                else
                                    $seenRollNos[$rollNo] = $excelRow;
                            }

                            // Check DB duplicates (always run, even if format errors exist)
                            if ($username && !isset($seenUsernames[$username]) || $username && $seenUsernames[$username] === $excelRow) {
                                $chk = $conn->prepare("SELECT id FROM users WHERE username = ?");
                                $chk->bind_param('s', $username);
                                $chk->execute();
                                if ($chk->get_result()->num_rows > 0)
                                    $rowErrors[] = $LANG['import_username_exists'] ?? 'Username already exists';
                                $chk->close();
                            }
                            if ($email && !isset($seenEmails[$email]) || $email && $seenEmails[$email] === $excelRow) {
                                $chk = $conn->prepare("SELECT id FROM users WHERE email = ?");
                                $chk->bind_param('s', $email);
                                $chk->execute();
                                if ($chk->get_result()->num_rows > 0)
                                    $rowErrors[] = $LANG['import_email_exists'] ?? 'Email already exists';
                                $chk->close();
                            }
                            if ($rollNo && !isset($seenRollNos[$rollNo]) || $rollNo && $seenRollNos[$rollNo] === $excelRow) {
                                $chk = $conn->prepare("SELECT id FROM students WHERE roll_no = ?");
                                $chk->bind_param('s', $rollNo);
                                $chk->execute();
                                if ($chk->get_result()->num_rows > 0)
                                    $rowErrors[] = $LANG['import_roll_exists'] ?? 'Roll number already exists';
                                $chk->close();
                            }

                            if ($rowErrors) {
                                $skipped++;
                                $skipDetails[] = ['values' => $originalRow, 'reasons' => array_values(array_unique($rowErrors))];
                                continue;
                            }

                            // Insert with transaction
                            $conn->begin_transaction();
                            try {
                                $hash = password_hash($password, PASSWORD_DEFAULT);
                                $stmt = $conn->prepare("INSERT INTO users (name, username, email, password, role, profile_image, created_at, updated_at) VALUES (?, ?, ?, ?, 'student', NULL, NOW(), NOW())");
                                $formattedName = formatName($name);
                                $stmt->bind_param('ssss', $formattedName, $username, $email, $hash);
                                $stmt->execute();
                                $userId = $stmt->insert_id;
                                $stmt->close();

                                $stmt2 = $conn->prepare("INSERT INTO students (user_id, roll_no, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
                                $stmt2->bind_param('is', $userId, $rollNo);
                                $stmt2->execute();
                                $stmt2->close();

                                $conn->commit();
                                $imported++;
                            } catch (\Exception $e) {
                                $conn->rollback();
                                $skipped++;
                                $skipDetails[] = ['values' => $originalRow, 'reasons' => [$LANG['import_record_failed'] ?? 'Unable to import record']];
                            }
                        }

                        // ─── Phase 3: Generate Failed Import .xlsx ──────
                        // Only include original template columns (no Reason) so users can fix and re-upload directly
                        if (!empty($skipDetails)) {
                            $failedHeaders = ['Name', 'Username', 'Email', 'Password', 'Roll No'];
                            $failedRows = [];
                            foreach ($skipDetails as $d) {
                                $failedRows[] = $d['values'];
                            }
                            $failedFile = buildXlsx($failedHeaders, $failedRows);
                            $_SESSION['failed_import_file'] = $failedFile;
                        }

                        $importResults = [
                            'total' => $totalRows,
                            'imported' => $imported,
                            'skipped' => $skipped,
                            'details' => $skipDetails,
                        ];
                    }
                }
            }
        }
    }

    if ($importErrors) {
        $importResults = ['error' => $importErrors];
    }

    $_SESSION['import_results'] = $importResults;
    header('Location: users.php');
    exit;
}

// Read import results from session
$importResults = $_SESSION['import_results'] ?? null;
unset($_SESSION['import_results']);

// Clean up old failed import file if no results to display
if (!$importResults && !empty($_SESSION['failed_import_file'])) {
    if (file_exists($_SESSION['failed_import_file'])) {
        @unlink($_SESSION['failed_import_file']);
    }
    unset($_SESSION['failed_import_file']);
}

// ─── POST Handlers ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf() && ($_POST['action'] ?? '') !== 'import_students') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $rawName = clean($_POST['name'] ?? '');
        $username = strtolower(clean($_POST['username'] ?? ''));
        $email = strtolower(clean($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $role = in_array($_POST['role'], ['admin', 'teacher', 'student']) ? $_POST['role'] : 'student';
        $name = formatName($rawName);

        $errors = [];
        if (!$rawName || !$username || !$email || !$password || !$confirm) {
            $errors[] = 'required';
        }
        if ($rawName && !isValidName($rawName)) {
            $errors[] = 'name_invalid';
        }
        if ($username && !isValidUsername($username)) {
            $errors[] = 'username_invalid';
        }
        if ($email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'email_invalid';
            } elseif ($role !== 'admin' && !preg_match('/@(ucsh\.edu\.mm|gmail\.com)$/', $email)) {
                $errors[] = 'email_domain';
            } elseif ($role !== 'admin' && !isValidEmail($email)) {
                $errors[] = 'email_invalid';
            }
        }
        if ($password && !isValidPassword($password)) {
            $errors[] = 'password';
        }
        if ($password && $password !== $confirm) {
            $errors[] = 'confirm';
        }

        $openModal = null;
        if ($errors) {
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_values'] = ['name' => $name, 'username' => $username, 'email' => $email, 'role' => $role];
            $openModal = 'addModal';
        } else {
            $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $check->bind_param('s', $email);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $_SESSION['form_errors'] = ['email_exists'];
                $_SESSION['form_values'] = ['name' => $name, 'username' => $username, 'email' => $email, 'role' => $role];
                $openModal = 'addModal';
            } else {
                $check2 = $conn->prepare("SELECT id FROM users WHERE username = ?");
                $check2->bind_param('s', $username);
                $check2->execute();
                if ($check2->get_result()->num_rows > 0) {
                    $_SESSION['form_errors'] = ['username_exists'];
                    $_SESSION['form_values'] = ['name' => $name, 'username' => $username, 'email' => $email, 'role' => $role];
                    $openModal = 'addModal';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO users (name,username,email,password,role) VALUES (?,?,?,?,?)");
                    $stmt->bind_param('sssss', $name, $username, $email, $hash, $role);
                    if ($stmt->execute()) {
                        setFlash('success', $LANG['flash_admin_user_created_successfully'] ?? 'User created successfully.');
                    } else {
                        setFlash('error', $LANG['flash_admin_failed_to_create_user'] ?? 'Failed to create user.');
                    }
                    $stmt->close();
                }
                $check2->close();
            }
            $check->close();
        }

        if ($openModal) {
            $_SESSION['reopen_modal'] = $openModal;
            header('Location: users.php');
            exit;
        }
    }

    if ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $rawName = clean($_POST['name'] ?? '');
        $username = strtolower(clean($_POST['username'] ?? ''));
        $email = strtolower(clean($_POST['email'] ?? ''));
        $role = in_array($_POST['role'], ['admin', 'teacher', 'student']) ? $_POST['role'] : 'student';
        $password = $_POST['password'] ?? '';
        $name = formatName($rawName);

        $errors = [];
        if (!$id || !$rawName || !$email) {
            $errors[] = 'required';
        }
        if ($rawName && !isValidName($rawName)) {
            $errors[] = 'name_invalid';
        }
        if ($username && !isValidUsername($username)) {
            $errors[] = 'username_invalid';
        }
        if ($email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'email_invalid';
            } elseif (!preg_match('/@(ucsh\.edu\.mm|gmail\.com)$/', $email)) {
                $errors[] = 'email_domain';
            } elseif (!isValidEmail($email)) {
                $errors[] = 'email_invalid';
            }
        }
        if ($password && !isValidPassword($password)) {
            $errors[] = 'password';
        }

        if ($errors) {
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_values'] = ['id' => $id, 'name' => $name, 'username' => $username, 'email' => $email, 'role' => $role];
            $_SESSION['reopen_modal'] = 'editModal';
            header('Location: users.php');
            exit;
        } else {
            $existing = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
            $existing->bind_param('i', $id);
            $existing->execute();
            $old = $existing->get_result()->fetch_assoc();
            $existing->close();

            $uniqueErrors = [];
            if ($old && $old['email'] !== $email) {
                $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $chk->bind_param('si', $email, $id);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0)
                    $uniqueErrors[] = 'email_exists';
                $chk->close();
            }
            if ($old && $old['username'] !== $username) {
                $chk = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $chk->bind_param('si', $username, $id);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0)
                    $uniqueErrors[] = 'username_exists';
                $chk->close();
            }

            if ($uniqueErrors) {
                $_SESSION['form_errors'] = $uniqueErrors;
                $_SESSION['form_values'] = ['id' => $id, 'name' => $name, 'username' => $username, 'email' => $email, 'role' => $role];
                $_SESSION['reopen_modal'] = 'editModal';
                header('Location: users.php');
                exit;
            }

            if ($password) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET name=?,username=?,email=?,password=?,role=? WHERE id=?");
                $stmt->bind_param('sssssi', $name, $username, $email, $hash, $role, $id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET name=?,username=?,email=?,role=? WHERE id=?");
                $stmt->bind_param('ssssi', $name, $username, $email, $role, $id);
            }
            $stmt->execute() ? setFlash('success', $LANG['flash_admin_user_updated'] ?? 'User updated.') : setFlash('error', $LANG['flash_admin_failed_to_update'] ?? 'Failed to update.');
            $stmt->close();
            header('Location: users.php');
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id && $id !== (int) $_SESSION['user_id']) {
            $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute() ? setFlash('success', $LANG['flash_admin_user_deleted'] ?? 'User deleted.') : setFlash('error', $LANG['flash_admin_cannot_delete'] ?? 'Cannot delete.');
            $stmt->close();
        } else {
            setFlash('error', $LANG['flash_admin_you_cannot_delete_your_own_account'] ?? 'You cannot delete your own account.');
        }
    }
    header('Location: users.php');
    exit;
}

// ─── Read inline errors from session ──────────────────────────
$formErrors = $_SESSION['form_errors'] ?? [];
$formValues = $_SESSION['form_values'] ?? [];
$reopenModal = $_SESSION['reopen_modal'] ?? null;
unset($_SESSION['form_errors'], $_SESSION['form_values'], $_SESSION['reopen_modal']);

$nameError = in_array('name_invalid', $formErrors);
$usernameError = in_array('username_invalid', $formErrors);
$usernameExistsErr = in_array('username_exists', $formErrors);
$emailDomainErr = in_array('email_domain', $formErrors);
$emailInvalidErr = in_array('email_invalid', $formErrors);
$emailExistsErr = in_array('email_exists', $formErrors);
$passwordError = in_array('password', $formErrors);
$confirmError = in_array('confirm', $formErrors);
$requiredError = in_array('required', $formErrors);
$hasErrors = !empty($formErrors);

$addNameErr = ($reopenModal === 'addModal') && $nameError;
$addUsernameErr = ($reopenModal === 'addModal') && ($usernameError || $usernameExistsErr);
$addEmailErr = ($reopenModal === 'addModal') && ($emailDomainErr || $emailInvalidErr || $emailExistsErr);
$addPasswordErr = ($reopenModal === 'addModal') && $passwordError;
$addConfirmErr = ($reopenModal === 'addModal') && $confirmError;
$editNameErr = ($reopenModal === 'editModal') && $nameError;
$editUsernameErr = ($reopenModal === 'editModal') && ($usernameError || $usernameExistsErr);
$editEmailErr = ($reopenModal === 'editModal') && ($emailDomainErr || $emailInvalidErr || $emailExistsErr);
$editPasswordErr = ($reopenModal === 'editModal') && $passwordError;

$borderRed = 'border-red-500 focus:border-red-500 focus:ring-2 focus:ring-red-500/20';

// ─── Fetch ────────────────────────────────────────────────────
$search = clean($_GET['search'] ?? '');
$roleF = clean($_GET['role'] ?? '');
$perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 10)));
$page = max(1, (int) ($_GET['page'] ?? 1));

$whereParts = [];
$params = [];
$types = '';

if ($search) {
    $whereParts[] = "(name LIKE ? OR email LIKE ? OR username LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s]);
    $types .= 'sss';
}
if ($roleF && in_array($roleF, ['admin', 'teacher', 'student'])) {
    $whereParts[] = "role = ?";
    $params[] = $roleF;
    $types .= 's';
}
$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$cntStmt = $conn->prepare("SELECT COUNT(*) AS c FROM users $where");
if ($types)
    $cntStmt->bind_param($types, ...$params);
$cntStmt->execute();
$total = (int) $cntStmt->get_result()->fetch_assoc()['c'];
$cntStmt->close();

$pg = paginate($total, $perPage, $page);
$off = $pg['offset'];

$dataStmt = $conn->prepare("SELECT * FROM users $where ORDER BY id DESC LIMIT ? OFFSET ?");
$p2 = array_merge($params, [$perPage, $off]);
$t2 = $types . 'ii';
$dataStmt->bind_param($t2, ...$p2);
$dataStmt->execute();
$rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dataStmt->close();

$qs = http_build_query(array_filter(['search' => $search, 'role' => $roleF]));

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
    <div>
        <!-- <h2 class="text-xl font-bold text-slate-800"><?= $LANG['users_title'] ?? 'Users' ?></h2> -->
        <p class="text-sm text-slate-500 "><?= $LANG['users_subtitle'] ?? 'Manage all system users' ?></p>
    </div>
    <div class="flex items-center gap-3">
        <button onclick="openModal('addModal')"
            class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-indigo-600/20 transition-all hover:-translate-y-0.5">
            <?= iconSvg('plus', 'w-4 h-4') ?>
            <?= $LANG['add_user'] ?? 'Add New User' ?>
        </button>
        <button onclick="openModal('importModal')"
            class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-indigo-600/20 transition-all hover:-translate-y-0.5">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                stroke="currentColor" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
            </svg>
            Import Students (Excel)
        </button>

    </div>
</div>

<?php renderFlash() ?>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-center gap-3">
        <form method="GET" id="userSearchForm" class="flex flex-wrap items-center gap-2 flex-1">
            <div class="relative">
                <span
                    class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= iconSvg('search', 'w-4 h-4') ?></span>
                <input type="text" name="search" value="<?= e($search) ?>"
                    placeholder="<?= $LANG['search_users'] ?? 'Search users...' ?>"
                    class="pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl w-48 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
            </div>
            <select name="role" onchange="this.form.submit()"
                class="border border-slate-200 rounded-xl px-3 py-2 text-sm text-slate-600 focus:border-cyan-500 outline-none bg-white">
                <option value=""><?= $LANG['all_roles'] ?? 'All Roles' ?></option>
                <option value="admin" <?= $roleF === 'admin' ? 'selected' : '' ?>><?= $LANG['admin_role'] ?? 'Admin' ?>
                </option>
                <option value="teacher" <?= $roleF === 'teacher' ? 'selected' : '' ?>>
                    <?= $LANG['teacher_role'] ?? 'Teacher' ?>
                </option>
                <option value="student" <?= $roleF === 'student' ? 'selected' : '' ?>>
                    <?= $LANG['student_role'] ?? 'Student' ?>
                </option>
            </select>
            <button type="submit"
                class="px-3 py-2 text-sm bg-indigo-600 text-white rounded-xl hover:bg-indigo-700"><?= $LANG["search"] ?? "Search" ?></button>
            <?php if ($search || $roleF): ?><a href="users.php"
                    class="px-3 py-2 text-sm text-white border border-slate-200 rounded-xl hover:bg-red-700 bg-red-500"><?= $LANG['clear'] ?? 'Clear' ?></a><?php endif ?>
        </form>
        <span class="text-xs text-slate-400"><?= $LANG['total'] ?? 'Total' ?> <?= $total ?>
            <?= $total !== 1 ? ($LANG['records'] ?? 'records') : ($LANG['record'] ?? 'record') ?></span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-slate-200 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">#</th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['col_name'] ?? 'Name' ?>
                    </th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['col_username'] ?? 'Username' ?>
                    </th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['col_email'] ?? 'Email' ?>
                    </th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['col_role'] ?? 'Role' ?>
                    </th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['col_created'] ?? 'Created' ?>
                    </th>
                    <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['col_actions'] ?? 'Actions' ?>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if ($rows):
                    foreach ($rows as $i => $row): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 text-sm text-slate-400"><?= $pg['offset'] + $i + 1 ?></td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2">
                                    <div
                                        class="w-7 h-7 rounded-full bg-cyan-100 flex items-center justify-center text-xs font-semibold text-cyan-700 flex-shrink-0">
                                        <?= e(avatarInitials($row['name'])) ?>
                                    </div>
                                    <span class="text-sm font-medium text-slate-800"><?= e($row['name']) ?></span>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-sm text-slate-600 font-mono"><?= e($row['username']) ?></td>
                            <td class="px-5 py-3 text-sm text-slate-600"><?= e($row['email']) ?></td>
                            <td class="px-5 py-3"><?= badgeRole($row['role']) ?></td>
                            <td class="px-5 py-3 text-sm text-slate-400"><?= formatDate($row['created_at']) ?></td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button onclick="openEdit(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-cyan-700 bg-cyan-50 hover:bg-cyan-100 rounded-lg">
                                        <?= iconSvg('edit', 'w-3.5 h-3.5') ?>         <?= $LANG['edit'] ?? 'Edit' ?>
                                    </button>
                                    <?php if ($row['id'] != $_SESSION['user_id']): ?>
                                        <button onclick="openDelete(<?= $row['id'] ?>,'<?= addslashes(e($row['name'])) ?>')"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
                                            <?= iconSvg('trash', 'w-3.5 h-3.5') ?>             <?= $LANG['delete'] ?? 'Delete' ?>
                                        </button>
                                    <?php endif ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-16 text-slate-400">
                            <?= iconSvg('users', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                            <p class="text-sm"><?= $LANG['no_users_found'] ?? 'No users found.' ?></p>
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100">
        <?= paginationLinks($pg, 'users.php' . ($qs ? "?$qs" : ''), $perPage) ?>
    </div>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG['add_user_modal'] ?? 'Add User' ?></h3>
            <button onclick="closeModal('addModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="action" value="add">
            <div class="px-6 py-5 grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['full_name'] ?? 'Full Name' ?>
                        <span class="text-red-500">*</span></label>
                    <input type="text" name="name" id="add_name" required
                        placeholder="<?= $LANG["full_name_placeholder"] ?? "John Doe" ?>"
                        value="<?= e($reopenModal === 'addModal' ? ($formValues['name'] ?? '') : '') ?>"
                        class="w-full border <?= $addNameErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 text-sm outline-none">
                    <?php if ($addNameErr): ?>
                        <p class="text-red-500 text-xs mt-1.5">
                            <?= $LANG['val_name_invalid'] ?? 'Full Name may contain only letters, single spaces, and a period (.) for titles such as Dr. or Prof.' ?>
                        </p>
                    <?php endif ?>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['username'] ?? 'Username' ?>
                        <span class="text-red-500">*</span></label>
                    <input type="text" name="username" id="add_username" required
                        placeholder="<?= $LANG["username_placeholder"] ?? "john_doe" ?>"
                        value="<?= e($reopenModal === 'addModal' ? ($formValues['username'] ?? '') : '') ?>"
                        class="w-full border <?= $addUsernameErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 text-sm outline-none">
                    <?php if ($addUsernameErr): ?>
                        <p class="text-red-500 text-xs mt-1.5">
                            <?php if ($usernameExistsErr): ?>
                                <?= $LANG['val_username_taken'] ?? 'This username is already taken.' ?>
                            <?php else: ?>
                                <?= $LANG['val_username_invalid'] ?? 'Username must be 4-30 characters. Only lowercase letters, numbers, and underscore allowed.' ?>
                            <?php endif ?>
                        </p>
                    <?php endif ?>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['role'] ?? 'Role' ?> <span
                            class="text-red-500">*</span></label>
                    <select name="role" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value="student" <?= ($reopenModal === 'addModal' && ($formValues['role'] ?? '') === 'student') ? 'selected' : '' ?>><?= $LANG['student_role'] ?? 'Student' ?></option>
                        <option value="teacher" <?= ($reopenModal === 'addModal' && ($formValues['role'] ?? '') === 'teacher') ? 'selected' : '' ?>><?= $LANG['teacher_role'] ?? 'Teacher' ?></option>
                        <option value="admin" <?= ($reopenModal === 'addModal' && ($formValues['role'] ?? '') === 'admin') ? 'selected' : '' ?>><?= $LANG['admin_role'] ?? 'Admin' ?></option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['email'] ?? 'Email' ?> <span
                            class="text-red-500">*</span></label>
                    <input type="email" name="email" id="add_email" required
                        placeholder="<?= $LANG["email_placeholder"] ?? "name@ucsh.edu.mm" ?>"
                        value="<?= e($reopenModal === 'addModal' ? ($formValues['email'] ?? '') : '') ?>"
                        class="w-full border <?= $addEmailErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 text-sm outline-none">
                    <?php if ($addEmailErr): ?>
                        <p class="text-red-500 text-xs mt-1.5">
                            <?php if ($emailExistsErr): ?>
                                <?= $LANG['val_email_taken'] ?? 'This email address is already registered.' ?>
                            <?php elseif ($emailDomainErr): ?>
                                <?= $LANG['val_email_domain'] ?? 'Invalid email address. Email must use either @ucsh.edu.mm or @gmail.com.' ?>
                            <?php else: ?>
                                <?= $LANG['val_email_invalid'] ?? 'Invalid email address. Email must use either @ucsh.edu.mm or @gmail.com.' ?>
                            <?php endif ?>
                        </p>
                    <?php endif ?>
                </div>
                <div class="col-span-2">
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['password_field'] ?? 'Password' ?>
                        <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="password" name="password" id="add_password" required minlength="6"
                            placeholder="Letters, numbers, or @"
                            class="w-full border <?= $addPasswordErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 pr-10 text-sm outline-none">
                        <button type="button" onclick="togglePassword('add_password',this)"
                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600"><?= iconSvg('eye', 'w-4 h-4') ?></button>
                    </div>
                    <?php if ($addPasswordErr): ?>
                        <p class="text-red-500 text-xs mt-1.5"><?= $LANG['val_password_invalid'] ?? 'Password must be at least 6 characters. Only letters,
                            numbers, and @ are allowed.' ?></p>
                    <?php endif ?>
                </div>
                <div class="col-span-2">
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['confirm_password'] ?? 'Confirm Password' ?>
                        <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="password" name="confirm_password" id="add_confirm_password" required minlength="6"
                            placeholder="Re-enter password"
                            class="w-full border <?= $addConfirmErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 pr-10 text-sm outline-none">
                        <button type="button" onclick="togglePassword('add_confirm_password',this)"
                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600"><?= iconSvg('eye', 'w-4 h-4') ?></button>
                    </div>
                    <?php if ($addConfirmErr): ?>
                        <p class="text-red-500 text-xs mt-1.5">
                            <?= $LANG['val_passwords_mismatch'] ?? 'Passwords do not match.' ?>
                        </p>
                    <?php endif ?>
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('addModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG['create_user'] ?? 'Create User' ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG['edit_user_modal'] ?? 'Edit User' ?></h3>
            <button onclick="closeModal('editModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id"
                id="edit_id" value="<?= e($reopenModal === 'editModal' ? ($formValues['id'] ?? '') : '') ?>">
            <div class="px-6 py-5 grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['full_name'] ?? 'Full Name' ?></label>
                    <input type="text" name="name" id="edit_name" required
                        value="<?= e($reopenModal === 'editModal' ? ($formValues['name'] ?? '') : '') ?>"
                        class="w-full border <?= $editNameErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 text-sm outline-none">
                    <?php if ($editNameErr): ?>
                        <p class="text-red-500 text-xs mt-1.5">
                            <?= $LANG['val_name_invalid'] ?? 'Full Name may contain only letters, single spaces, and a period (.) for titles such as Dr. or Prof.' ?>
                        </p>
                    <?php endif ?>
                </div>
                <div>
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['username'] ?? 'Username' ?></label>
                    <input type="text" name="username" id="edit_username" required
                        value="<?= e($reopenModal === 'editModal' ? ($formValues['username'] ?? '') : '') ?>"
                        class="w-full border <?= $editUsernameErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 text-sm outline-none">
                    <?php if ($editUsernameErr): ?>
                        <p class="text-red-500 text-xs mt-1.5">
                            <?php if ($usernameExistsErr): ?>
                                <?= $LANG['val_username_taken'] ?? 'This username is already taken.' ?>
                            <?php else: ?>
                                <?= $LANG['val_username_invalid'] ?? 'Username must be 4-30 characters. Only lowercase letters, numbers, and underscore allowed.' ?>
                            <?php endif ?>
                        </p>
                    <?php endif ?>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['role'] ?? 'Role' ?></label>
                    <select name="role" id="edit_role"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value="student" <?= ($reopenModal === 'editModal' && ($formValues['role'] ?? '') === 'student') ? 'selected' : '' ?>><?= $LANG['student_role'] ?? 'Student' ?></option>
                        <option value="teacher" <?= ($reopenModal === 'editModal' && ($formValues['role'] ?? '') === 'teacher') ? 'selected' : '' ?>><?= $LANG['teacher_role'] ?? 'Teacher' ?></option>
                        <option value="admin" <?= ($reopenModal === 'editModal' && ($formValues['role'] ?? '') === 'admin') ? 'selected' : '' ?>><?= $LANG['admin_role'] ?? 'Admin' ?></option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['email'] ?? 'Email' ?></label>
                    <input type="email" name="email" id="edit_email" required
                        value="<?= e($reopenModal === 'editModal' ? ($formValues['email'] ?? '') : '') ?>"
                        class="w-full border <?= $editEmailErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 text-sm outline-none">
                    <?php if ($editEmailErr): ?>
                        <p class="text-red-500 text-xs mt-1.5">
                            <?php if ($emailExistsErr): ?>
                                <?= $LANG['val_email_taken'] ?? 'This email address is already registered.' ?>
                            <?php elseif ($emailDomainErr): ?>
                                <?= $LANG['val_email_domain'] ?? 'Invalid email address. Email must use either @ucsh.edu.mm or @gmail.com.' ?>
                            <?php else: ?>
                                <?= $LANG['val_email_invalid'] ?? 'Invalid email address. Email must use either @ucsh.edu.mm or @gmail.com.' ?>
                            <?php endif ?>
                        </p>
                    <?php endif ?>
                </div>
                <div class="col-span-2">
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['new_password'] ?? 'New Password' ?>
                        <span
                            class="text-xs text-slate-400">(<?= $LANG['new_password_hint'] ?? 'leave blank to keep current' ?>)</span></label>
                    <div class="relative">
                        <input type="password" name="password" id="edit_password" placeholder="Letters, numbers, or @"
                            class="w-full border <?= $editPasswordErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 pr-10 text-sm outline-none">
                        <button type="button" onclick="togglePassword('edit_password',this)"
                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600"><?= iconSvg('eye', 'w-4 h-4') ?></button>
                    </div>
                    <?php if ($editPasswordErr): ?>
                        <p class="text-red-500 text-xs mt-1.5"><?= $LANG['val_password_invalid'] ?? 'Password must be at least 6 characters. Only letters,
                            numbers, and @ are allowed.' ?></p>
                    <?php endif ?>
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('editModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG['save_changes_btn'] ?? 'Save Changes' ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-box">
        <div class="px-6 py-6 text-center">
            <div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
                <?= iconSvg('trash', 'w-7 h-7 text-red-600') ?>
            </div>
            <h3 class="text-lg font-semibold text-slate-800"><?= $LANG['delete_user_modal'] ?? 'Delete User' ?></h3>
            <p class="text-sm text-slate-500 mt-2"><strong id="delete_name"
                    class="text-slate-700"></strong><?= $LANG['delete_user_confirm'] ?? 'Delete user' ?> ?

            </p>
        </div>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id"
                id="delete_id">
            <div class="flex gap-3 px-6 pb-6">
                <button type="button" onclick="closeModal('deleteModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl"><?= $LANG['delete'] ?? 'Delete' ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Import Students Modal -->
<div id="importModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG['import_students_excel'] ?? 'Import Students (Excel)' ?>
            </h3>
            <button onclick="closeModal('importModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="importForm">
            <?= csrfField() ?><input type="hidden" name="action" value="import_students">
            <div class="px-6 py-5 space-y-4">
                <p class="text-sm text-slate-500">Upload an Excel file (.xlsx) with student data. The file must have
                    these columns in order:</p>
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-3">
                    <p class="text-xs font-mono text-slate-600">Name &nbsp;|&nbsp; Username &nbsp;|&nbsp; Email
                        &nbsp;|&nbsp; Password &nbsp;|&nbsp; Roll No</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Select Excel File <span
                            class="text-red-500">*</span></label>
                    <input type="file" name="import_file" id="import_file" accept=".xlsx,.xls" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100">
                </div>
                <!-- <div class="flex items-center gap-2">
                    <a href="users.php?action=download_template&csrf_token=<?= csrfToken() ?>"
                        class="inline-flex items-center gap-1.5 text-xs font-medium text-cyan-700 bg-cyan-50 hover:bg-cyan-100 px-3 py-1.5 rounded-lg transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg>
                        Download Excel Template
                    </a>
                </div> -->
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('importModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit" id="importBtn"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl inline-flex items-center justify-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                        stroke="currentColor" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                    </svg>
                    Import Students
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Import Results Modal -->
<?php if ($importResults): ?>
    <div id="importResultModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
        data-modal-backdrop>
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl modal-box max-h-[85vh] flex flex-col">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 shrink-0">
                <h3 class="font-semibold text-slate-800"><?= $LANG['import_results'] ?? 'Import Results' ?></h3>
                <button onclick="closeModal('importResultModal')"
                    class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
            </div>
            <div class="px-6 py-5 overflow-y-auto">
                <?php if (!empty($importResults['error'])): ?>
                    <div class="bg-red-50 border border-red-200 rounded-xl p-4">
                        <div class="flex items-start gap-3">
                            <div class="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-red-600">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-red-700 mb-2"><?= $LANG['import_invalid_template'] ?? 'Import Failed - Invalid Template' ?></p>
                                <?php foreach ($importResults['error'] as $idx => $err): ?>
                                    <?php if ($idx === 0): ?>
                                        <p class="text-sm font-medium text-red-700 mb-1"><?= e($err) ?></p>
                                    <?php else: ?>
                                        <p class="text-sm text-red-600 pl-2 border-l-2 border-red-200 mb-0.5"><?= e($err) ?></p>
                                    <?php endif ?>
                                <?php endforeach ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
                        <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 text-center">
                            <p class="text-2xl font-black text-slate-700"><?= $importResults['total'] ?></p>
                            <p class="text-xs font-bold text-slate-500 uppercase mt-1"><?= $LANG['total'] ?? 'Total' ?></p>
                        </div>
                        <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-center">
                            <p class="text-2xl font-black text-emerald-600"><?= $importResults['imported'] ?></p>
                            <p class="text-xs font-bold text-emerald-500 uppercase mt-1"><?= $LANG['import_successfully_imported'] ?? 'Successfully Imported' ?></p>
                        </div>
                        <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-center">
                            <p class="text-2xl font-black text-red-600"><?= $importResults['skipped'] ?></p>
                            <p class="text-xs font-bold text-red-500 uppercase mt-1"><?= $LANG['import_failed_count'] ?? 'Failed' ?></p>
                        </div>
                    </div>
                    <?php if (!empty($importResults['details'])): ?>
                        <div class="flex items-center justify-between mb-3">
                            <p class="text-xs font-bold text-slate-500 uppercase tracking-wider"><?= $LANG['failed_records'] ?? 'Failed Records' ?></p>
                            <a href="users.php?action=download_failed_import"
                                class="inline-flex items-center gap-1.5 text-xs font-medium text-white bg-red-600 hover:bg-red-700 px-3 py-1.5 rounded-lg transition-colors shadow-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                    stroke="currentColor" class="w-3.5 h-3.5">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                </svg>
                                <?= $LANG['download_failed_records'] ?? 'Download Failed Records' ?>
                            </a>
                        </div>
                        <div class="border border-slate-200 rounded-lg overflow-x-auto max-h-80">
                            <table class="w-full min-w-[900px] text-sm">
                                <thead class="bg-slate-100 sticky top-0">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-semibold text-slate-600"><?= $LANG['col_name'] ?? 'Name' ?></th>
                                        <th class="px-3 py-2 text-left font-semibold text-slate-600"><?= $LANG['col_username'] ?? 'Username' ?></th>
                                        <th class="px-3 py-2 text-left font-semibold text-slate-600"><?= $LANG['col_email'] ?? 'Email' ?></th>
                                        <th class="px-3 py-2 text-left font-semibold text-slate-600"><?= $LANG['col_password'] ?? 'Password' ?></th>
                                        <th class="px-3 py-2 text-left font-semibold text-slate-600"><?= $LANG['col_roll_no'] ?? 'Roll No' ?></th>
                                        <th class="px-3 py-2 text-left font-semibold text-slate-600 min-w-72"><?= $LANG['col_reason'] ?? 'Reason' ?></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    <?php foreach ($importResults['details'] as $d): ?>
                                        <tr class="align-top hover:bg-slate-50">
                                            <?php foreach ($d['values'] as $value): ?>
                                                <td class="px-3 py-2 text-slate-700 break-words"><?= e($value) ?></td>
                                            <?php endforeach ?>
                                            <td class="px-3 py-2 text-red-600 break-words"><?= e(implode('; ', $d['reasons'])) ?></td>
                                        </tr>
                                    <?php endforeach ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif ?>
                <?php endif ?>
            </div>
            <div class="flex justify-end px-6 py-4 border-t border-slate-100 shrink-0">
                <button onclick="closeModal('importResultModal')"
                    class="px-5 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG['close'] ?? 'Close' ?></button>
            </div>
        </div>
    </div>
<?php endif ?>

<script>
    (function () { var s = document.createElement('style'); s.textContent = 'input[type="password"]::-ms-reveal,input[type="password"]::-ms-clear{display:none}'; document.head.appendChild(s) })();
    function togglePassword(id, btn) {
        var inp = document.getElementById(id);
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        btn.innerHTML = show
            ? '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>'
            : '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178zM15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>';
    }
    var msgs = {
        name_invalid: <?= json_encode($LANG['val_name_invalid'] ?? 'Full Name may contain only letters, single spaces, and a period (.) for titles such as Dr. or Prof.') ?>,
        username_invalid: <?= json_encode($LANG['val_username_invalid'] ?? 'Username must be 4-30 characters. Only lowercase letters, numbers, and underscore allowed.') ?>,
        email_invalid: <?= json_encode($LANG['val_email_invalid'] ?? 'Invalid email address. Email must use either @ucsh.edu.mm or @gmail.com.') ?>,
        email_domain: <?= json_encode($LANG['val_email_domain'] ?? 'Invalid email address. Email must use either @ucsh.edu.mm or @gmail.com.') ?>
    };
    function validateName(name) {
        name = name.trim();
        if (name.length < 2 || name.length > 100) return msgs.name_invalid;
        if (!/^(?:(?:Dr|Prof|U|Daw)\.\s)?[A-Za-z]+(?: [A-Za-z]+)*$/i.test(name)) return msgs.name_invalid;
        return '';
    }
    function validateUsername(username) {
        if (!/^[a-z0-9_]{4,30}$/.test(username)) return msgs.username_invalid;
        return '';
    }
    function validateEmail(email, role) {
        if (role === 'admin') {
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return msgs.email_invalid;
        } else {
            if (!/^[a-zA-Z0-9._]+@(ucsh\.edu\.mm|gmail\.com)$/.test(email)) return msgs.email_invalid;
        }
        return '';
    }
    document.addEventListener('DOMContentLoaded', function () {
        var addForm = document.querySelector('#addModal form');
        if (addForm) {
            addForm.addEventListener('submit', function (e) {
                var name = document.getElementById('add_name');
                var username = document.getElementById('add_username');
                var email = document.getElementById('add_email');
                var role = addForm.querySelector('select[name="role"]').value;
                var err = validateName(name.value);
                if (err) { alert(err); name.focus(); e.preventDefault(); return; }
                username.value = username.value.toLowerCase();
                err = validateUsername(username.value);
                if (err) { alert(err); username.focus(); e.preventDefault(); return; }
                email.value = email.value.toLowerCase();
                err = validateEmail(email.value, role);
                if (err) { alert(err); email.focus(); e.preventDefault(); return; }
            });
        }
        var editForm = document.querySelector('#editModal form');
        if (editForm) {
            editForm.addEventListener('submit', function (e) {
                var name = document.getElementById('edit_name');
                var username = document.getElementById('edit_username');
                var email = document.getElementById('edit_email');
                var err = validateName(name.value);
                if (err) { alert(err); name.focus(); e.preventDefault(); return; }
                username.value = username.value.toLowerCase();
                err = validateUsername(username.value);
                if (err) { alert(err); username.focus(); e.preventDefault(); return; }
                email.value = email.value.toLowerCase();
                err = validateEmail(email.value);
                if (err) { alert(err); email.focus(); e.preventDefault(); return; }
            });
        }
    });
    function openEdit(u) {
        document.getElementById('edit_id').value = u.id;
        document.getElementById('edit_name').value = u.name;
        document.getElementById('edit_username').value = u.username;
        document.getElementById('edit_email').value = u.email;
        document.getElementById('edit_role').value = u.role;
        openModal('editModal');
    }
    function openDelete(id, name) {
        document.getElementById('delete_id').value = id;
        document.getElementById('delete_name').textContent = name;
        openModal('deleteModal');
    }
    <?php if ($reopenModal): ?>
        document.addEventListener('DOMContentLoaded', function () {
            openModal('<?= $reopenModal ?>');
        });
    <?php endif ?>
    <?php if ($importResults): ?>
        document.addEventListener('DOMContentLoaded', function () {
            openModal('importResultModal');
        });
    <?php endif ?>
    var importForm = document.getElementById('importForm');
    if (importForm) {
        importForm.addEventListener('submit', function () {
            var btn = document.getElementById('importBtn');
            btn.disabled = true;
            btn.innerHTML = '<svg class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Importing...';
        });
    }

</script>

<?php include '../includes/admin_footer.php'; ?>
