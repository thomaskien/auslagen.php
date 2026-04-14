<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('Europe/Berlin');

const DB_FILE = __DIR__ . '/auslagen.sqlite';
const APP_TITLE = 'Auslagen & Kilometer';
const APP_VERSION = '1.0';
const APP_FOOTER = 'auslagen.php v1.0 von Dr. Thomas Kienzle';
const DEFAULT_ADMIN_PASSWORD = 'admin';

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sqlite_available(): bool {
    return extension_loaded('pdo_sqlite') && in_array('sqlite', PDO::getAvailableDrivers(), true);
}

function mb_cut(string $value, int $limit): string {
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit);
    }
    return substr($value, 0, $limit);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!sqlite_available()) {
        throw new RuntimeException('SQLite-Unterstützung fehlt. Bitte auf dem Server das Paket php-sqlite3 bzw. die Erweiterung pdo_sqlite installieren.');
    }
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    init_db($pdo);
    return $pdo;
}

function init_db(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            iban TEXT NOT NULL,
            bic TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE(name, iban)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reimbursements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            doc_id TEXT NOT NULL UNIQUE,
            doc_year INTEGER NOT NULL,
            doc_number INTEGER NOT NULL,
            expense_date TEXT NOT NULL,
            subject TEXT NOT NULL,
            claimant_name TEXT NOT NULL,
            iban TEXT NOT NULL,
            bic TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            km_rate REAL NOT NULL DEFAULT 0,
            total_km REAL NOT NULL DEFAULT 0,
            total_receipts REAL NOT NULL DEFAULT 0,
            total_amount REAL NOT NULL DEFAULT 0,
            transfer_purpose TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reimbursement_id INTEGER NOT NULL,
            item_type TEXT NOT NULL,
            item_date TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            kilometers REAL NOT NULL DEFAULT 0,
            amount REAL NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (reimbursement_id) REFERENCES reimbursements(id) ON DELETE CASCADE
        )
    ");

    $defaults = [
        'km_rate' => '0.30',
        'practice_name' => 'Praxis',
        'transfer_prefix' => 'Erstattung von Auslage Praxis',
        'admin_password_hash' => password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT),
        'counter_year' => date('Y'),
        'next_counter' => '1',
    ];

    foreach ($defaults as $key => $value) {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
        $stmt->execute([$key, $value]);
    }
}

function get_setting(string $key, ?string $default = null): ?string {
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function set_setting(string $key, string $value): void {
    $stmt = db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([$key, $value]);
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(): void {
    $posted = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $posted)) {
        throw new RuntimeException('Ungültiges Formular-Token. Bitte Seite neu laden und erneut versuchen.');
    }
}

function is_admin(): bool {
    return !empty($_SESSION['is_admin']);
}

function require_admin_or_throw(): void {
    if (!is_admin()) {
        throw new RuntimeException('Admin-Anmeldung erforderlich.');
    }
}

function normalize_date_input(?string $date): string {
    $date = trim((string)$date);
    if ($date === '') {
        return '';
    }
    $formats = ['Y-m-d', 'd.m.Y', 'd.m.y'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $date);
        if ($dt && $dt->format($format) === $date) {
            return $dt->format('Y-m-d');
        }
    }
    return '';
}

function parse_decimal($value): float {
    if (is_float($value) || is_int($value)) {
        return (float)$value;
    }
    $value = trim((string)$value);
    if ($value === '') {
        return 0.0;
    }
    $value = str_replace(' ', '', $value);

    if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
        if (strrpos($value, ',') > strrpos($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif (strpos($value, ',') !== false) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } else {
        $value = str_replace(',', '', $value);
    }

    if (!is_numeric($value)) {
        return 0.0;
    }
    return round((float)$value, 2);
}

function format_decimal(float $value, int $decimals = 2): string {
    return number_format($value, $decimals, ',', '.');
}

function format_eur(float $value): string {
    return format_decimal($value, 2) . ' €';
}

function normalize_iban(?string $iban): string {
    $iban = strtoupper((string)$iban);
    $iban = preg_replace('/\s+/', '', $iban) ?? '';
    return $iban;
}

function format_iban(string $iban): string {
    $iban = normalize_iban($iban);
    return trim(chunk_split($iban, 4, ' '));
}

function iban_last4(string $iban): string {
    $iban = normalize_iban($iban);
    return substr($iban, -4);
}

function validate_iban(string $iban): bool {
    $iban = normalize_iban($iban);
    if ($iban === '' || !preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $iban)) {
        return false;
    }
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric = '';
    foreach (str_split($rearranged) as $char) {
        if (ctype_alpha($char)) {
            $numeric .= (string)(ord($char) - 55);
        } else {
            $numeric .= $char;
        }
    }
    $mod = 0;
    $len = strlen($numeric);
    for ($i = 0; $i < $len; $i++) {
        $mod = ($mod * 10 + (int)$numeric[$i]) % 97;
    }
    return $mod === 1;
}

function normalize_bic(?string $bic): string {
    $bic = strtoupper(trim((string)$bic));
    return preg_replace('/\s+/', '', $bic) ?? '';
}

function validate_bic(string $bic): bool {
    if ($bic === '') {
        return true;
    }
    return (bool)preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', $bic);
}

function upsert_payee(string $name, string $iban, string $bic): void {
    $now = date('c');
    $stmt = db()->prepare("
        INSERT INTO payees (name, iban, bic, created_at, updated_at)
        VALUES (:name, :iban, :bic, :created_at, :updated_at)
        ON CONFLICT(name, iban) DO UPDATE SET
            bic = excluded.bic,
            updated_at = excluded.updated_at
    ");
    $stmt->execute([
        ':name' => $name,
        ':iban' => $iban,
        ':bic' => $bic,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function get_payees(): array {
    $stmt = db()->query("
        SELECT name, iban, bic, updated_at
        FROM payees
        ORDER BY updated_at DESC, name ASC
        LIMIT 200
    ");
    return $stmt->fetchAll();
}

function allocate_doc_id(): array {
    $currentYear = (int)date('Y');
    $storedYear = (int)(get_setting('counter_year', (string)$currentYear) ?? $currentYear);
    $nextCounter = (int)(get_setting('next_counter', '1') ?? '1');

    if ($storedYear !== $currentYear) {
        $storedYear = $currentYear;
        $nextCounter = 1;
        set_setting('counter_year', (string)$storedYear);
        set_setting('next_counter', '1');
    }

    $docNumber = max(1, $nextCounter);
    $docId = sprintf('%d-AUSL-%04d', $storedYear, $docNumber);

    set_setting('next_counter', (string)($docNumber + 1));

    return [$docId, $storedYear, $docNumber];
}

function build_transfer_purpose(string $docId): string {
    $prefix = trim((string)get_setting('transfer_prefix', 'Erstattung von Auslage Praxis'));
    if ($prefix === '') {
        $prefix = 'Erstattung von Auslage Praxis';
    }
    return $prefix . '. Beleg: ' . $docId;
}

function build_epc_qr_payload(string $name, string $iban, string $bic, float $amount, string $purpose): string {
    $lines = [
        'BCD',
        '002',
        '1',
        'SCT',
        $bic,
        mb_cut($name, 70),
        normalize_iban($iban),
        'EUR' . number_format($amount, 2, '.', ''),
        '',
        '',
        mb_cut($purpose, 140),
        '',
    ];
    return implode("\n", $lines);
}

function shell_available(string $command): bool {
    if (!function_exists('shell_exec')) {
        return false;
    }
    $result = @shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
    return is_string($result) && trim($result) !== '';
}

function generate_qr_data_uri_local(string $payload): ?string {
    if (!function_exists('shell_exec')) {
        return null;
    }

    if (shell_available('qrencode')) {
        $tmp = tempnam(sys_get_temp_dir(), 'qr_');
        if ($tmp !== false) {
            @shell_exec('qrencode -s 5 -m 1 -o ' . escapeshellarg($tmp) . ' ' . escapeshellarg($payload) . ' 2>/dev/null');
            if (is_file($tmp) && filesize($tmp) > 0) {
                $data = file_get_contents($tmp);
                @unlink($tmp);
                if ($data !== false) {
                    return 'data:image/png;base64,' . base64_encode($data);
                }
            }
            @unlink($tmp);
        }
    }

    if (shell_available('python3')) {
        $payloadB64 = base64_encode($payload);
        $py = <<<'PY'
import base64, io, sys
payload = base64.b64decode(sys.argv[1]).decode('utf-8')
try:
    import qrcode
    img = qrcode.make(payload)
    out = io.BytesIO()
    img.save(out, format='PNG')
    sys.stdout.buffer.write(base64.b64encode(out.getvalue()))
except Exception:
    sys.exit(1)
PY;
        $cmd = 'python3 -c ' . escapeshellarg($py) . ' ' . escapeshellarg($payloadB64) . ' 2>/dev/null';
        $out = @shell_exec($cmd);
        if (is_string($out) && trim($out) !== '') {
            return 'data:image/png;base64,' . trim($out);
        }
    }

    return null;
}

function qr_image_source(string $payload): ?array {
    $local = generate_qr_data_uri_local($payload);
    if ($local !== null) {
        return ['src' => $local, 'mode' => 'local'];
    }
    return null;
}

function can_access_recent_saved_doc(string $docId): bool {
    return isset($_SESSION['last_saved_doc_id'])
        && is_string($_SESSION['last_saved_doc_id'])
        && hash_equals((string)$_SESSION['last_saved_doc_id'], $docId);
}

function save_reimbursement(array $input): string {
    $expenseDate = date('Y-m-d');
    $subject = trim((string)($input['subject'] ?? ''));
    $claimantName = trim((string)($input['claimant_name'] ?? ''));
    $iban = normalize_iban($input['iban'] ?? '');
    $bic = normalize_bic($input['bic'] ?? '');
    $note = trim((string)($input['note'] ?? ''));

    if ($subject === '') {
        throw new RuntimeException('Bitte einen Betreff angeben.');
    }
    if ($claimantName === '') {
        throw new RuntimeException('Bitte einen Namen angeben.');
    }
    if (!validate_iban($iban)) {
        throw new RuntimeException('Bitte eine gültige IBAN angeben.');
    }
    if (!validate_bic($bic)) {
        throw new RuntimeException('Die BIC ist ungültig.');
    }

    $kmRate = (float)(get_setting('km_rate', '0.30') ?? '0.30');

    $types = $input['item_type'] ?? [];
    $dates = $input['item_date'] ?? [];
    $descriptions = $input['item_description'] ?? [];
    $kilometers = $input['item_kilometers'] ?? [];
    $amounts = $input['item_amount'] ?? [];

    $rows = [];
    $totalKm = 0.0;
    $totalReceipts = 0.0;
    $totalAmount = 0.0;

    $count = max(count($types), count($dates), count($descriptions), count($kilometers), count($amounts));

    for ($i = 0; $i < $count; $i++) {
        $type = ($types[$i] ?? 'receipt') === 'km' ? 'km' : 'receipt';
        $itemDate = normalize_date_input($dates[$i] ?? '') ?: $expenseDate;
        $description = trim((string)($descriptions[$i] ?? ''));
        $km = parse_decimal($kilometers[$i] ?? '');
        $amount = parse_decimal($amounts[$i] ?? '');

        $isEmpty = ($description === '') && ($km <= 0) && ($amount <= 0) && (($dates[$i] ?? '') === '');
        if ($isEmpty) {
            continue;
        }

        if ($type === 'km') {
            if ($km <= 0) {
                throw new RuntimeException('Bei Kilometer-Posten muss eine Kilometerzahl größer 0 angegeben werden.');
            }
            $amount = round($km * $kmRate, 2);
            if ($description === '') {
                $description = 'Kilometerpauschale';
            }
            $totalKm += $km;
        } else {
            if ($amount <= 0) {
                throw new RuntimeException('Bei Beleg-Posten muss ein Betrag größer 0 angegeben werden.');
            }
            if ($description === '') {
                $description = 'Beleg';
            }
            $totalReceipts += $amount;
        }

        $totalAmount += $amount;

        $rows[] = [
            'item_type' => $type,
            'item_date' => $itemDate,
            'description' => $description,
            'kilometers' => $type === 'km' ? $km : 0.0,
            'amount' => $amount,
        ];
    }

    if (!$rows) {
        throw new RuntimeException('Bitte mindestens einen Kilometer- oder Beleg-Posten eingeben.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        [$docId, $docYear, $docNumber] = allocate_doc_id();
        $transferPurpose = build_transfer_purpose($docId);
        $now = date('c');

        $stmt = $pdo->prepare("
            INSERT INTO reimbursements
            (
                doc_id, doc_year, doc_number, expense_date, subject, claimant_name, iban, bic, note,
                km_rate, total_km, total_receipts, total_amount, transfer_purpose, created_at, updated_at
            )
            VALUES
            (
                :doc_id, :doc_year, :doc_number, :expense_date, :subject, :claimant_name, :iban, :bic, :note,
                :km_rate, :total_km, :total_receipts, :total_amount, :transfer_purpose, :created_at, :updated_at
            )
        ");
        $stmt->execute([
            ':doc_id' => $docId,
            ':doc_year' => $docYear,
            ':doc_number' => $docNumber,
            ':expense_date' => $expenseDate,
            ':subject' => $subject,
            ':claimant_name' => $claimantName,
            ':iban' => $iban,
            ':bic' => $bic,
            ':note' => $note,
            ':km_rate' => $kmRate,
            ':total_km' => round($totalKm, 2),
            ':total_receipts' => round($totalReceipts, 2),
            ':total_amount' => round($totalAmount, 2),
            ':transfer_purpose' => $transferPurpose,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $reimbursementId = (int)$pdo->lastInsertId();

        $stmtItem = $pdo->prepare("
            INSERT INTO items
            (reimbursement_id, item_type, item_date, description, kilometers, amount, sort_order)
            VALUES
            (:reimbursement_id, :item_type, :item_date, :description, :kilometers, :amount, :sort_order)
        ");

        foreach ($rows as $index => $row) {
            $stmtItem->execute([
                ':reimbursement_id' => $reimbursementId,
                ':item_type' => $row['item_type'],
                ':item_date' => $row['item_date'],
                ':description' => $row['description'],
                ':kilometers' => $row['kilometers'],
                ':amount' => $row['amount'],
                ':sort_order' => $index + 1,
            ]);
        }

        upsert_payee($claimantName, $iban, $bic);

        $pdo->commit();
        return $docId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function find_reimbursement_by_doc_id(string $docId): ?array {
    $stmt = db()->prepare('SELECT * FROM reimbursements WHERE doc_id = ?');
    $stmt->execute([trim($docId)]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $stmtItems = db()->prepare('SELECT * FROM items WHERE reimbursement_id = ? ORDER BY sort_order ASC, id ASC');
    $stmtItems->execute([(int)$row['id']]);
    $row['items'] = $stmtItems->fetchAll();
    return $row;
}

function get_recent_reimbursements(int $limit = 100): array {
    $stmt = db()->prepare('SELECT * FROM reimbursements ORDER BY created_at DESC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function delete_reimbursement(int $id): void {
    $stmt = db()->prepare('DELETE FROM reimbursements WHERE id = ?');
    $stmt->execute([$id]);
}

function wipe_reimbursements(): void {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM items');
        $pdo->exec('DELETE FROM reimbursements');
        $pdo->exec('DELETE FROM payees');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function euro_for_js(float $value): string {
    return number_format($value, 2, '.', '');
}

$flash = '';
$error = '';
$docView = null;
$successNotice = '';
$showSavedView = false;
$adminView = isset($_GET['admin']) || isset($_POST['action']) && str_starts_with((string)$_POST['action'], 'admin_');
$action = $_POST['action'] ?? '';
$showForm = true;

try {
    if ($action === 'create_reimbursement') {
        require_csrf();
        $docId = save_reimbursement($_POST);
        $_SESSION['last_saved_doc_id'] = $docId;
        $_SESSION['success_notice'] = 'Beleg gespeichert, bitte jetzt ausdrucken, Belege beifügen und unterschreiben';
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?saved=1');
        exit;
    }

    if ($action === 'admin_login') {
        $password = (string)($_POST['password'] ?? '');
        $hash = get_setting('admin_password_hash', '');
        if (!$hash || !password_verify($password, $hash)) {
            throw new RuntimeException('Admin-Passwort falsch.');
        }
        $_SESSION['is_admin'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?admin=1');
        exit;
    }

    if ($action === 'admin_logout') {
        require_csrf();
        unset($_SESSION['is_admin']);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    if ($action === 'admin_save_settings') {
        require_admin_or_throw();
        require_csrf();

        $kmRate = parse_decimal($_POST['km_rate'] ?? '');
        $practiceName = trim((string)($_POST['practice_name'] ?? ''));
        $transferPrefix = trim((string)($_POST['transfer_prefix'] ?? ''));
        $counterYear = (int)trim((string)($_POST['counter_year'] ?? date('Y')));
        $nextCounter = (int)trim((string)($_POST['next_counter'] ?? '1'));
        $newPassword = trim((string)($_POST['new_admin_password'] ?? ''));

        if ($kmRate < 0) {
            throw new RuntimeException('Kilometersatz darf nicht negativ sein.');
        }
        if ($practiceName === '') {
            $practiceName = 'Praxis';
        }
        if ($transferPrefix === '') {
            $transferPrefix = 'Erstattung von Auslage Praxis';
        }
        if ($counterYear < 2000 || $counterYear > 2100) {
            throw new RuntimeException('Zählerjahr wirkt ungültig.');
        }
        if ($nextCounter < 1) {
            throw new RuntimeException('Die nächste laufende Nummer muss mindestens 1 sein.');
        }

        set_setting('km_rate', euro_for_js($kmRate));
        set_setting('practice_name', $practiceName);
        set_setting('transfer_prefix', $transferPrefix);
        set_setting('counter_year', (string)$counterYear);
        set_setting('next_counter', (string)$nextCounter);

        if ($newPassword !== '') {
            set_setting('admin_password_hash', password_hash($newPassword, PASSWORD_DEFAULT));
        }

        $flash = 'Admin-Einstellungen gespeichert.';
        $adminView = true;
    }

    if ($action === 'admin_delete_reimbursement') {
        require_admin_or_throw();
        require_csrf();
        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('Ungültiger Eintrag.');
        }
        delete_reimbursement($id);
        $flash = 'Eintrag gelöscht.';
        $adminView = true;
    }

    if ($action === 'admin_wipe_database') {
        require_admin_or_throw();
        require_csrf();
        $confirmation = trim((string)($_POST['wipe_confirmation'] ?? ''));
        if ($confirmation !== 'ALLES LÖSCHEN') {
            throw new RuntimeException('Bitte zur Bestätigung exakt "ALLES LÖSCHEN" eingeben.');
        }
        wipe_reimbursements();
        $counterYear = (int)trim((string)($_POST['counter_year_after_wipe'] ?? date('Y')));
        $nextCounter = (int)trim((string)($_POST['next_counter_after_wipe'] ?? '1'));
        if ($counterYear < 2000 || $counterYear > 2100) {
            $counterYear = (int)date('Y');
        }
        if ($nextCounter < 1) {
            $nextCounter = 1;
        }
        set_setting('counter_year', (string)$counterYear);
        set_setting('next_counter', (string)$nextCounter);
        $flash = 'Datenbank geleert und Startzähler gesetzt.';
        $adminView = true;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $adminView = $adminView || isset($_GET['admin']);
}

if (isset($_GET['saved']) && $_GET['saved'] !== '') {
    $savedDocId = isset($_SESSION['last_saved_doc_id']) ? trim((string)$_SESSION['last_saved_doc_id']) : '';
    if ($savedDocId !== '') {
        $docView = find_reimbursement_by_doc_id($savedDocId);
        if ($docView) {
            $showSavedView = true;
            $showForm = false;
            $successNotice = (string)($_SESSION['success_notice'] ?? 'Beleg gespeichert, bitte jetzt ausdrucken, Belege beifügen und unterschreiben');
        } else {
            $error = 'Der zuletzt gespeicherte Beleg wurde nicht gefunden.';
        }
    } else {
        $error = 'Kein zuletzt gespeicherter Beleg vorhanden.';
    }
}

if (isset($_GET['view']) && $_GET['view'] !== '') {
    $requestedDocId = trim((string)$_GET['view']);
    if (!is_admin()) {
        $error = 'Alte Belege können nur im Admin-Bereich erneut geöffnet werden.';
    } else {
        $docView = find_reimbursement_by_doc_id($requestedDocId);
        if (!$docView) {
            $error = 'Beleg nicht gefunden.';
        }
    }
}

$printMode = false;
if (isset($_GET['print']) && $_GET['print'] !== '') {
    $requestedPrint = trim((string)$_GET['print']);
    if ($requestedPrint === '1' && $showSavedView && $docView) {
        $printMode = true;
    } elseif ($requestedPrint !== '' && is_admin()) {
        $docView = find_reimbursement_by_doc_id($requestedPrint);
        if (!$docView) {
            $error = 'Beleg nicht gefunden.';
        } else {
            $printMode = true;
        }
    } else {
        $error = 'Druckansicht nur für den soeben gespeicherten Beleg oder im Admin-Bereich verfügbar.';
    }
}

$payees = [];
$payeesJson = [];
$currentKmRate = 0.30;
$counterYear = date('Y');
$nextCounter = '1';
$practiceName = 'Praxis';
$transferPrefix = 'Erstattung von Auslage Praxis';
$recentReimbursements = [];

try {
    $payees = get_payees();
    foreach ($payees as $payee) {
        $label = $payee['name'] . ' · …' . iban_last4($payee['iban']);
        $payeesJson[] = [
            'label' => $label,
            'name' => $payee['name'],
            'iban' => format_iban($payee['iban']),
            'bic' => $payee['bic'],
        ];
    }

    $currentKmRate = (float)(get_setting('km_rate', '0.30') ?? '0.30');
    $counterYear = (string)(get_setting('counter_year', date('Y')) ?? date('Y'));
    $nextCounter = (string)(get_setting('next_counter', '1') ?? '1');
    $practiceName = (string)(get_setting('practice_name', 'Praxis') ?? 'Praxis');
    $transferPrefix = (string)(get_setting('transfer_prefix', 'Erstattung von Auslage Praxis') ?? 'Erstattung von Auslage Praxis');
    $recentReimbursements = $adminView && is_admin() ? get_recent_reimbursements(200) : [];
} catch (Throwable $e) {
    if ($error === '') {
        $error = $e->getMessage();
    }
}

$formData = [
    'subject' => '',
    'claimant_name' => '',
    'iban' => '',
    'bic' => '',
    'note' => '',
    'item_type' => [],
    'item_date' => [],
    'item_description' => [],
    'item_kilometers' => [],
    'item_amount' => [],
];

if ($action === 'create_reimbursement') {
    $formData['subject'] = (string)($_POST['subject'] ?? '');
    $formData['claimant_name'] = (string)($_POST['claimant_name'] ?? '');
    $formData['iban'] = (string)($_POST['iban'] ?? '');
    $formData['bic'] = (string)($_POST['bic'] ?? '');
    $formData['note'] = (string)($_POST['note'] ?? '');
    $formData['item_type'] = is_array($_POST['item_type'] ?? null) ? array_values($_POST['item_type']) : [];
    $formData['item_date'] = is_array($_POST['item_date'] ?? null) ? array_values($_POST['item_date']) : [];
    $formData['item_description'] = is_array($_POST['item_description'] ?? null) ? array_values($_POST['item_description']) : [];
    $formData['item_kilometers'] = is_array($_POST['item_kilometers'] ?? null) ? array_values($_POST['item_kilometers']) : [];
    $formData['item_amount'] = is_array($_POST['item_amount'] ?? null) ? array_values($_POST['item_amount']) : [];
}

if (!$showForm && !$showSavedView && !$adminView) {
    $showForm = true;
}

$formItemsForJs = [];
$formCount = max(
    count($formData['item_type']),
    count($formData['item_date']),
    count($formData['item_description']),
    count($formData['item_kilometers']),
    count($formData['item_amount'])
);
for ($i = 0; $i < $formCount; $i++) {
    $formItemsForJs[] = [
        'type' => (($formData['item_type'][$i] ?? 'receipt') === 'km') ? 'km' : 'receipt',
        'date' => (string)($formData['item_date'][$i] ?? ''),
        'description' => (string)($formData['item_description'][$i] ?? ''),
        'kilometers' => (string)($formData['item_kilometers'][$i] ?? ''),
        'amount' => (string)($formData['item_amount'][$i] ?? ''),
    ];
}

function render_print_view(array $doc, string $error = ''): void {
    $qrPayload = build_epc_qr_payload($doc['claimant_name'], $doc['iban'], $doc['bic'], (float)$doc['total_amount'], $doc['transfer_purpose']);
    $qr = qr_image_source($qrPayload);
    ?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title><?= h($doc['doc_id']) ?> – Ausdruck</title>
<style>
    :root { --border:#222; --light:#f4f4f4; --muted:#666; }
    * { box-sizing:border-box; }
    body { margin:0; font-family:Arial, Helvetica, sans-serif; color:#111; background:#fff; }
    .page { max-width:980px; margin:0 auto; padding:20px 24px 16px; }
    .head { display:grid; grid-template-columns:minmax(0, 1fr) 150px; gap:18px; align-items:start; }
    .title h1 { margin:0 0 6px; font-size:28px; }
    .subtitle { color:#333; font-size:14px; margin-top:4px; }
    .box { border:1px solid var(--border); padding:10px 12px; border-radius:8px; }
    .meta { display:grid; grid-template-columns:minmax(0, 1fr); gap:10px; margin-top:14px; max-width:620px; }
    .label { font-size:12px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
    .value { font-size:16px; margin-top:4px; }
    .items { width:100%; border-collapse:collapse; margin-top:16px; }
    .items th, .items td { border:1px solid #333; padding:7px 8px; vertical-align:top; }
    .items th { background:var(--light); text-align:left; }
    .totals { margin-top:14px; margin-left:auto; width:340px; border-collapse:collapse; }
    .totals td { padding:7px 10px; border:1px solid #333; }
    .totals tr:last-child td { font-weight:bold; font-size:18px; }
    .signature { margin-top:24px; display:grid; grid-template-columns:1fr 1fr; gap:28px; }
    .sigbox { padding-top:48px; border-bottom:1px solid #111; font-size:14px; }
    .warning { margin-top:8px; color:#7b3f00; font-size:12px; line-height:1.4; }
    .muted { color:#666; }
    .print-actions { margin-top:18px; display:flex; gap:10px; }
    .button { display:inline-block; padding:10px 14px; border:1px solid #222; border-radius:6px; color:#111; text-decoration:none; }
    .qrbox { border:1px solid var(--border); border-radius:8px; padding:8px; min-height:150px; display:flex; align-items:center; justify-content:center; }
    .footer { margin-top:20px; font-size:12px; color:#666; text-align:right; }
    @media print {
        .print-actions { display:none; }
        .page { max-width:none; padding:10mm 12mm 8mm; }
    }
</style>
</head>
<body>
<div class="page">
    <?php if ($error !== ''): ?>
        <div class="box" style="margin-bottom:12px; color:#8a1f1f;"><?= h($error) ?></div>
    <?php endif; ?>
    <div class="head">
        <div class="title">
            <h1>Auslagen- / Kilometerabrechnung</h1>
            <div class="subtitle">Beleg-ID: <strong><?= h($doc['doc_id']) ?></strong></div>
            <div class="subtitle">Erstellt am <?= h(date('d.m.Y H:i', strtotime($doc['created_at']))) ?></div>
            <div class="subtitle">Überweisungsbetreff: <?= h($doc['transfer_purpose']) ?></div>
        </div>
        <div class="qrbox" style="text-align:center;">
            <?php if ($qr): ?>
                <img src="<?= h($qr['src']) ?>" alt="QR-Code für Überweisung" style="width:128px; height:128px; max-width:100%;">
            <?php else: ?>
                <div class="warning">Kein lokaler QR-Code verfügbar. Bitte lokal <code>qrencode</code> installieren.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="meta">
        <div class="box">
            <div class="label">Mitarbeiter/in</div>
            <div class="value"><?= h($doc['claimant_name']) ?></div>
        </div>
        <div class="box">
            <div class="label">Betreff</div>
            <div class="value"><?= h($doc['subject']) ?></div>
        </div>
        <div class="box">
            <div class="label">IBAN</div>
            <div class="value"><?= h(format_iban($doc['iban'])) ?><?= $doc['bic'] !== '' ? '<br>' . h($doc['bic']) : '' ?></div>
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width:120px;">Datum</th>
                <th style="width:110px;">Typ</th>
                <th>Beschreibung</th>
                <th style="width:110px; text-align:right;">Kilometer</th>
                <th style="width:130px; text-align:right;">Betrag</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($doc['items'] as $item): ?>
            <tr>
                <td><?= h(date('d.m.Y', strtotime($item['item_date']))) ?></td>
                <td><?= $item['item_type'] === 'km' ? 'Kilometer' : 'Beleg' ?></td>
                <td><?= h($item['description']) ?></td>
                <td style="text-align:right;"><?= $item['item_type'] === 'km' ? h(format_decimal((float)$item['kilometers'], 2)) : '–' ?></td>
                <td style="text-align:right;"><?= h(format_eur((float)$item['amount'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Belegsumme</td><td style="text-align:right;"><?= h(format_eur((float)$doc['total_receipts'])) ?></td></tr>
        <tr><td>Kilometer gesamt</td><td style="text-align:right;"><?= h(format_decimal((float)$doc['total_km'], 2)) ?> km</td></tr>
        <tr><td>Kilometersatz</td><td style="text-align:right;"><?= h(format_eur((float)$doc['km_rate'])) ?> / km</td></tr>
        <tr><td>Gesamtsumme</td><td style="text-align:right;"><?= h(format_eur((float)$doc['total_amount'])) ?></td></tr>
    </table>

    <?php if (trim((string)$doc['note']) !== ''): ?>
        <div class="box" style="margin-top:14px;">
            <div class="label">Notiz</div>
            <div class="value"><?= nl2br(h($doc['note'])) ?></div>
        </div>
    <?php endif; ?>

    <div class="signature">
        <div>
            <div class="sigbox"></div>
            <div class="muted">Unterschrift Mitarbeiter/in</div>
        </div>
        <div>
            <div class="sigbox"></div>
            <div class="muted">Freigabe / Buchhaltung</div>
        </div>
    </div>

    <div class="footer"><?= h(APP_FOOTER) ?></div>

    <div class="print-actions">
        <a class="button" href="#" onclick="window.print(); return false;">Drucken</a>
        <a class="button" href="<?= h(strtok($_SERVER['REQUEST_URI'], '?')) ?>">Zurück</a>
    </div>
</div>
</body>
</html>
<?php
}

if ($printMode && $docView) {
    render_print_view($docView, $error);
    exit;
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_TITLE) ?></title>
<style>
    :root {
        --bg:#f5f7fb;
        --card:#ffffff;
        --text:#18212b;
        --muted:#5b6878;
        --line:#d9e0ea;
        --accent:#1f5fbf;
        --danger:#b42318;
        --ok:#067647;
        --soft:#eef4ff;
    }
    * { box-sizing:border-box; }
    body { margin:0; font-family:Arial, Helvetica, sans-serif; background:var(--bg); color:var(--text); }
    .wrap { max-width:1180px; margin:0 auto; padding:24px 18px 40px; }
    .header { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:20px; }
    .header h1 { margin:0 0 6px; font-size:30px; }
    .sub { color:var(--muted); font-size:14px; line-height:1.45; }
    .grid-main { display:grid; grid-template-columns:minmax(0, 1.5fr) minmax(320px, 0.9fr); gap:18px; }
    .card { background:var(--card); border:1px solid var(--line); border-radius:14px; box-shadow:0 10px 25px rgba(17,24,39,.04); overflow:hidden; }
    .card-head { padding:16px 18px; border-bottom:1px solid var(--line); background:#fafcff; font-weight:bold; }
    .card-body { padding:18px; }
    .form-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px; }
    .field { display:flex; flex-direction:column; gap:6px; }
    .field.full { grid-column:1 / -1; }
    label { font-size:13px; color:var(--muted); font-weight:bold; }
    input[type=text], input[type=password], input[type=date], textarea, select { width:100%; border:1px solid #c9d3df; border-radius:10px; padding:11px 12px; font-size:15px; background:#fff; }
    textarea { min-height:92px; resize:vertical; }
    .inline-help { font-size:12px; color:var(--muted); }
    .toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
    .button, button { appearance:none; border:none; background:var(--accent); color:#fff; border-radius:10px; padding:11px 14px; font-size:14px; font-weight:bold; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:8px; }
    .button.secondary, button.secondary { background:#eef2f7; color:#1b2734; border:1px solid #c8d1dc; }
    .button.danger, button.danger { background:var(--danger); color:#fff; }
    .items-table { width:100%; border-collapse:collapse; margin-top:10px; }
    .items-table th, .items-table td { border:1px solid var(--line); padding:8px; vertical-align:top; }
    .items-table th { background:#f8fbff; font-size:13px; text-align:left; }
    .items-table input, .items-table select { width:100%; min-width:0; padding:9px 10px; font-size:14px; }
    .summary-box { margin-top:16px; background:var(--soft); border:1px solid #cfe0ff; border-radius:12px; padding:12px 14px; display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:10px; }
    .summary-item .k { font-size:12px; color:var(--muted); text-transform:uppercase; letter-spacing:.03em; }
    .summary-item .v { margin-top:4px; font-size:19px; font-weight:bold; }
    .flash, .error { margin-bottom:16px; padding:12px 14px; border-radius:12px; font-size:14px; }
    .flash { background:#ecfdf3; border:1px solid #a6f4c5; color:#067647; }
    .error { background:#fef3f2; border:1px solid #fecdca; color:#b42318; }
    .mini-table { width:100%; border-collapse:collapse; }
    .mini-table th, .mini-table td { padding:10px 8px; border-bottom:1px solid var(--line); text-align:left; font-size:14px; vertical-align:top; }
    .mini-table th { color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
    .badge { display:inline-block; padding:4px 8px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:12px; font-weight:bold; }
    .doc-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:14px; }
    .doc-boxes { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; margin-top:14px; }
    .doc-box { padding:12px; border:1px solid var(--line); border-radius:12px; background:#fbfcfe; }
    .doc-box .k { color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
    .doc-box .v { margin-top:4px; font-size:15px; line-height:1.4; }
    .login-box { max-width:420px; margin:0 auto; }
    .danger-zone { margin-top:20px; border:1px solid #f1b5b5; background:#fff5f5; border-radius:12px; padding:14px; }
    .muted { color:var(--muted); }
    .small { font-size:12px; }
    .app-footer { margin-top:24px; text-align:right; color:var(--muted); font-size:12px; }
    @media (max-width: 980px) {
        .grid-main { grid-template-columns:1fr; }
        .form-grid, .doc-boxes, .summary-box { grid-template-columns:1fr; }
        .header { flex-direction:column; }
        .items-table { font-size:13px; display:block; overflow:auto; }
    }
</style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <div>
            <h1><?= h(APP_TITLE) ?></h1>
            <div class="sub">
                Einfache Erfassung von Auslagen und Kilometerabrechnungen mit Beleg-ID, SQLite-Archiv
                und lokal erzeugtem Banking-QR.
            </div>
        </div>
        <div class="toolbar">
            <?php if (!$adminView): ?>
                <a class="button secondary" href="?admin=1">Admin</a>
            <?php else: ?>
                <a class="button secondary" href="<?= h(strtok($_SERVER['REQUEST_URI'], '?')) ?>">Zur Erfassung</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flash !== ''): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

    <?php if ($adminView): ?>
        <?php if (!is_admin()): ?>
            <div class="card login-box">
                <div class="card-head">Admin-Anmeldung</div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="admin_login">
                        <div class="field">
                            <label for="password">Passwort</label>
                            <input id="password" type="password" name="password" autofocus required>
                        </div>
                        <div class="inline-help" style="margin-top:10px;">
                            Standard beim ersten Start: <strong><?= h(DEFAULT_ADMIN_PASSWORD) ?></strong> – danach bitte im Admin-Menü ändern.
                        </div>
                        <div class="toolbar" style="margin-top:16px;">
                            <button type="submit">Anmelden</button>
                            <a class="button secondary" href="<?= h(strtok($_SERVER['REQUEST_URI'], '?')) ?>">Abbrechen</a>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="grid-main">
                <div>
                    <div class="card">
                        <div class="card-head">Admin-Einstellungen</div>
                        <div class="card-body">
                            <form method="post">
                                <input type="hidden" name="action" value="admin_save_settings">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <div class="form-grid">
                                    <div class="field">
                                        <label for="km_rate">Kilometersatz in € pro km</label>
                                        <input id="km_rate" type="text" name="km_rate" value="<?= h(format_decimal($currentKmRate, 2)) ?>">
                                    </div>
                                    <div class="field">
                                        <label for="practice_name">Praxisname</label>
                                        <input id="practice_name" type="text" name="practice_name" value="<?= h($practiceName) ?>">
                                    </div>
                                    <div class="field full">
                                        <label for="transfer_prefix">Text vor der Beleg-ID im Überweisungsbetreff</label>
                                        <input id="transfer_prefix" type="text" name="transfer_prefix" value="<?= h($transferPrefix) ?>">
                                        <div class="inline-help">Beispiel ergibt: <?= h($transferPrefix) ?>. Beleg: <?= h($counterYear) ?>-AUSL-<?= sprintf('%04d', (int)$nextCounter) ?></div>
                                    </div>
                                    <div class="field">
                                        <label for="counter_year">Zählerjahr</label>
                                        <input id="counter_year" type="text" name="counter_year" value="<?= h($counterYear) ?>">
                                    </div>
                                    <div class="field">
                                        <label for="next_counter">Nächste laufende Nummer</label>
                                        <input id="next_counter" type="text" name="next_counter" value="<?= h($nextCounter) ?>">
                                    </div>
                                    <div class="field full">
                                        <label for="new_admin_password">Neues Admin-Passwort</label>
                                        <input id="new_admin_password" type="password" name="new_admin_password" placeholder="leer lassen = unverändert">
                                    </div>
                                </div>
                                <div class="toolbar" style="margin-top:16px;">
                                    <button type="submit">Speichern</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card" style="margin-top:18px;">
                        <div class="card-head">Bisherige Vorgänge</div>
                        <div class="card-body" style="overflow:auto;">
                            <table class="mini-table">
                                <thead>
                                    <tr>
                                        <th>Beleg-ID</th>
                                        <th>Datum</th>
                                        <th>Name</th>
                                        <th>Betreff</th>
                                        <th>Summe</th>
                                        <th>Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!$recentReimbursements): ?>
                                    <tr><td colspan="6" class="muted">Noch keine Einträge vorhanden.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recentReimbursements as $row): ?>
                                        <tr>
                                            <td><strong><?= h($row['doc_id']) ?></strong></td>
                                            <td><?= h(date('d.m.Y', strtotime($row['expense_date']))) ?></td>
                                            <td><?= h($row['claimant_name']) ?></td>
                                            <td><?= h($row['subject']) ?></td>
                                            <td><?= h(format_eur((float)$row['total_amount'])) ?></td>
                                            <td>
                                                <div class="toolbar">
                                                    <a class="button secondary" href="?admin=1&amp;view=<?= rawurlencode($row['doc_id']) ?>">Ansehen</a>
                                                    <a class="button secondary" href="?admin=1&amp;print=<?= rawurlencode($row['doc_id']) ?>" target="_blank" rel="noopener">Drucken</a>
                                                    <form method="post" onsubmit="return confirm('Eintrag wirklich löschen?');">
                                                        <input type="hidden" name="action" value="admin_delete_reimbursement">
                                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                        <button type="submit" class="danger">Löschen</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div>
                    <?php if ($docView): ?>
                        <div class="card" style="margin-bottom:18px;">
                            <div class="card-head">Ausgewählter Beleg <span class="badge"><?= h($docView['doc_id']) ?></span></div>
                            <div class="card-body">
                                <div class="doc-boxes">
                                        <div class="doc-box"><div class="k">Betreff</div><div class="v"><?= h($docView['subject']) ?></div></div>
                                    <div class="doc-box"><div class="k">Name</div><div class="v"><?= h($docView['claimant_name']) ?></div></div>
                                    <div class="doc-box"><div class="k">Gesamtsumme</div><div class="v"><?= h(format_eur((float)$docView['total_amount'])) ?></div></div>
                                </div>
                                <div class="doc-actions">
                                    <a class="button secondary" href="?admin=1&amp;print=<?= rawurlencode($docView['doc_id']) ?>" target="_blank" rel="noopener">Druckansicht</a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="card">
                        <div class="card-head">Admin-Sitzung</div>
                        <div class="card-body">
                            <form method="post">
                                <input type="hidden" name="action" value="admin_logout">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <button type="submit" class="secondary">Abmelden</button>
                            </form>

                            <div class="danger-zone">
                                <strong>Gefahrenbereich</strong>
                                <div class="small muted" style="margin-top:6px;">
                                    Löscht alle Vorgänge, alle Posten und die gespeicherten Empfänger-Vorschläge.
                                    Der Startzähler wird direkt danach neu gesetzt.
                                </div>
                                <form method="post" style="margin-top:14px;" onsubmit="return confirm('Wirklich die komplette Datenbank leeren?');">
                                    <input type="hidden" name="action" value="admin_wipe_database">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <div class="field">
                                        <label for="wipe_confirmation">Zur Bestätigung exakt eingeben: ALLES LÖSCHEN</label>
                                        <input id="wipe_confirmation" type="text" name="wipe_confirmation">
                                    </div>
                                    <div class="form-grid" style="margin-top:12px;">
                                        <div class="field">
                                            <label for="counter_year_after_wipe">Zählerjahr nach Löschung</label>
                                            <input id="counter_year_after_wipe" type="text" name="counter_year_after_wipe" value="<?= h($counterYear) ?>">
                                        </div>
                                        <div class="field">
                                            <label for="next_counter_after_wipe">Nächste Nummer nach Löschung</label>
                                            <input id="next_counter_after_wipe" type="text" name="next_counter_after_wipe" value="1">
                                        </div>
                                    </div>
                                    <div class="toolbar" style="margin-top:14px;">
                                        <button type="submit" class="danger">Komplett leeren</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="card" style="margin-top:18px;">
                        <div class="card-head">Hinweise</div>
                        <div class="card-body">
                            <div class="sub">
                                Die QR-Ausgabe bleibt vollständig lokal. Empfohlen ist <code>qrencode</code> auf dem Server.
                                Wenn lokal kein QR-Generator vorhanden ist, wird kein QR-Code ausgegeben.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="grid-main">
            <div>
                <?php if ($showSavedView && $docView): ?>
                    <?php $qr = qr_image_source(build_epc_qr_payload($docView['claimant_name'], $docView['iban'], $docView['bic'], (float)$docView['total_amount'], $docView['transfer_purpose'])); ?>
                    <div class="card">
                        <div class="card-head">Beleg erfolgreich gespeichert</div>
                        <div class="card-body">
                            <?php if ($successNotice !== ''): ?>
                                <div class="flash" style="font-size:18px; font-weight:bold;"><?= h($successNotice) ?></div>
                            <?php endif; ?>
                            <div class="doc-boxes">
                                <div class="doc-box"><div class="k">Beleg-ID</div><div class="v"><strong><?= h($docView['doc_id']) ?></strong></div></div>
                                <div class="doc-box"><div class="k">Betreff</div><div class="v"><?= h($docView['subject']) ?></div></div>
                                <div class="doc-box"><div class="k">Name</div><div class="v"><?= h($docView['claimant_name']) ?></div></div>
                                <div class="doc-box"><div class="k">IBAN</div><div class="v"><?= h(format_iban($docView['iban'])) ?></div></div>
                                <div class="doc-box"><div class="k">Überweisungsbetreff</div><div class="v"><?= h($docView['transfer_purpose']) ?></div></div>
                            </div>

                            <table class="mini-table" style="margin-top:16px;">
                                <thead>
                                    <tr>
                                        <th>Datum</th>
                                        <th>Typ</th>
                                        <th>Beschreibung</th>
                                        <th>Kilometer</th>
                                        <th>Betrag</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($docView['items'] as $item): ?>
                                        <tr>
                                            <td><?= h(date('d.m.Y', strtotime($item['item_date']))) ?></td>
                                            <td><?= $item['item_type'] === 'km' ? 'Kilometer' : 'Beleg' ?></td>
                                            <td><?= h($item['description']) ?></td>
                                            <td><?= $item['item_type'] === 'km' ? h(format_decimal((float)$item['kilometers'], 2)) . ' km' : '–' ?></td>
                                            <td><?= h(format_eur((float)$item['amount'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <div class="doc-actions">
                                <a class="button" href="?saved=1&amp;print=1" target="_blank" rel="noopener">Druckansicht</a>
                                <a class="button secondary" href="<?= h(strtok($_SERVER['REQUEST_URI'], '?')) ?>">Neue Auslage erfassen</a>
                            </div>

                            <div class="doc-actions" style="margin-top:16px; align-items:center;">
                                <div class="doc-box" style="text-align:center; max-width:180px;">
                                    <?php if ($qr): ?>
                                        <img src="<?= h($qr['src']) ?>" alt="QR-Code" style="width:140px; height:140px; max-width:100%;">
                                    <?php else: ?>
                                        <div class="small muted">Kein lokaler QR-Code verfügbar. Bitte <code>qrencode</code> installieren.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-head">Neue Auslage / Kilometerabrechnung erfassen</div>
                        <div class="card-body">
                            <form method="post" id="reimbursement-form" autocomplete="off">
                                <input type="hidden" name="action" value="create_reimbursement">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

                                <div class="form-grid">
                                    <div class="field">
                                        <label for="subject">Betreff</label>
                                        <input id="subject" type="text" name="subject" value="<?= h($formData['subject']) ?>" placeholder="Hausbesuch Patient#: 1234" required>
                                    </div>
                                    <div class="field full">
                                        <label for="payee_selector">Bisher verwendeter Empfänger</label>
                                        <input id="payee_selector" type="text" list="payee-list" placeholder="Nachname, Vorname · …1234 auswählen oder leer lassen">
                                        <datalist id="payee-list">
                                            <?php foreach ($payeesJson as $payee): ?>
                                                <option value="<?= h($payee['label']) ?>"></option>
                                            <?php endforeach; ?>
                                        </datalist>
                                    </div>
                                    <div class="field">
                                        <label for="claimant_name">Name Mitarbeiter/in</label>
                                        <input id="claimant_name" type="text" name="claimant_name" value="<?= h($formData['claimant_name']) ?>" placeholder="Vorname Nachname (wie bei der Bank gespeichert)" required>
                                    </div>
                                    <div class="field">
                                        <label for="iban">IBAN</label>
                                        <input id="iban" type="text" name="iban" value="<?= h($formData['iban']) ?>" placeholder="DE12 3456 7890 1234 5678 90" required>
                                    </div>
                                    <div class="field">
                                        <label for="bic">BIC (optional)</label>
                                        <input id="bic" type="text" name="bic" value="<?= h($formData['bic']) ?>" placeholder="z. B. GENODE...">
                                    </div>
                                    <div class="field full">
                                        <label for="note">Notiz (optional)</label>
                                        <textarea id="note" name="note" placeholder="z. B. Anlass, Strecke, besondere Hinweise"><?= h($formData['note']) ?></textarea>
                                    </div>
                                </div>

                                <div style="margin-top:18px;">
                                    <div class="toolbar" style="justify-content:space-between;">
                                        <div>
                                            <strong>Posten</strong>
                                            <div class="inline-help">Beleg(e) und Kilometer können gemeinsam in einem Vorgang erfasst werden.</div>
                                        </div>
                                        <div class="toolbar">
                                            <button class="secondary" type="button" id="add-receipt">+ Beleg</button>
                                            <button class="secondary" type="button" id="add-km">+ Kilometer</button>
                                        </div>
                                    </div>

                                    <table class="items-table" id="items-table">
                                        <thead>
                                            <tr>
                                                <th style="width:110px;">Typ</th>
                                                <th style="width:130px;">Datum</th>
                                                <th>Beschreibung</th>
                                                <th style="width:120px;">Kilometer</th>
                                                <th style="width:120px;">Betrag in €</th>
                                                <th style="width:90px;">Aktion</th>
                                            </tr>
                                        </thead>
                                        <tbody id="items-body"></tbody>
                                    </table>
                                </div>

                                <div class="summary-box">
                                    <div class="summary-item"><div class="k">Belegsumme</div><div class="v" id="sum-receipts">0,00 €</div></div>
                                    <div class="summary-item"><div class="k">Kilometer</div><div class="v" id="sum-km">0,00 km</div></div>
                                    <div class="summary-item"><div class="k">Kilometersatz</div><div class="v" id="km-rate-label"><?= h(format_eur($currentKmRate)) ?></div></div>
                                    <div class="summary-item"><div class="k">Gesamtsumme</div><div class="v" id="sum-total">0,00 €</div></div>
                                </div>

                                <div class="toolbar" style="margin-top:18px;"><button type="submit">Vorgang speichern</button></div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div>
                <div class="card" style="margin-top:18px;">
                    <div class="card-head">Aktueller Kilometersatz</div>
                    <div class="card-body">
                        <div style="font-size:28px; font-weight:bold;"><?= h(format_eur($currentKmRate)) ?> <span class="muted" style="font-size:16px; font-weight:normal;">pro km</span></div>
                    </div>
                </div>

                <div class="card" style="margin-top:18px;">
                    <div class="card-head">Zuletzt verwendete Empfänger</div>
                    <div class="card-body">
                        <table class="mini-table">
                            <thead><tr><th>Name</th><th>IBAN</th></tr></thead>
                            <tbody>
                                <?php if (!$payees): ?>
                                    <tr><td colspan="2" class="muted">Noch keine gespeicherten Empfänger vorhanden.</td></tr>
                                <?php else: ?>
                                    <?php foreach (array_slice($payees, 0, 10) as $payee): ?>
                                        <tr><td><?= h($payee['name']) ?></td><td>…<?= h(iban_last4($payee['iban'])) ?></td></tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card" style="margin-top:18px;">
                    <div class="card-head">Hinweise</div>
                    <div class="card-body">
                        <div class="sub">
                            Kilometer sind optional; auch reine Beleg-Erstattungen funktionieren. Jede Erstattung erhält automatisch eine eindeutige Beleg-ID.
                            Frühere Belege können nur im Admin-Bereich erneut geöffnet oder gedruckt werden.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<div class="wrap"><div class="app-footer"><?= h(APP_FOOTER) ?></div></div>

<script>
const KM_RATE = <?= json_encode(euro_for_js($currentKmRate)) ?>;
const PAYEES = <?= json_encode($payeesJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const FORM_ITEMS = <?= json_encode($formItemsForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const itemsBody = document.getElementById('items-body');

function parseLocaleNumber(value) {
    let cleaned = String(value || '').trim().replace(/\s+/g, '');
    if (cleaned.includes(',') && cleaned.includes('.')) {
        if (cleaned.lastIndexOf(',') > cleaned.lastIndexOf('.')) {
            cleaned = cleaned.replace(/\./g, '').replace(',', '.');
        } else {
            cleaned = cleaned.replace(/,/g, '');
        }
    } else if (cleaned.includes(',')) {
        cleaned = cleaned.replace(/\./g, '').replace(',', '.');
    } else {
        cleaned = cleaned.replace(/,/g, '');
    }
    const number = parseFloat(cleaned);
    return isNaN(number) ? 0 : number;
}
function formatEuro(value) {
    return value.toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';
}
function formatKm(value) {
    return value.toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' km';
}
function escapeAttr(value) {
    return String(value ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function rowTemplate(type, dateValue='', descriptionValue='', kmValue='', amountValue='') {
    if (!itemsBody) return;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <select name="item_type[]" class="item-type">
                <option value="receipt"${type === 'receipt' ? ' selected' : ''}>Beleg</option>
                <option value="km"${type === 'km' ? ' selected' : ''}>Kilometer</option>
            </select>
        </td>
        <td><input type="date" name="item_date[]" value="${escapeAttr(dateValue)}"></td>
        <td><input type="text" name="item_description[]" value="${escapeAttr(descriptionValue)}" placeholder="${type === 'km' ? 'z. B. Fahrt Praxis → Hausbesuch' : 'z. B. Material / Parkticket / Porto'}"></td>
        <td><input type="text" name="item_kilometers[]" class="item-km" value="${escapeAttr(kmValue)}" placeholder="0,00"></td>
        <td><input type="text" name="item_amount[]" class="item-amount" value="${escapeAttr(amountValue)}" placeholder="0,00"></td>
        <td><button type="button" class="secondary remove-row">Entfernen</button></td>
    `;
    itemsBody.appendChild(tr);
    bindRow(tr);
    recalc();
}
function bindRow(tr) {
    const typeSelect = tr.querySelector('.item-type');
    const kmInput = tr.querySelector('.item-km');
    const amountInput = tr.querySelector('.item-amount');
    const descInput = tr.querySelector('input[name="item_description[]"]');
    const removeButton = tr.querySelector('.remove-row');

    function syncType() {
        const type = typeSelect.value;
        if (type === 'km') {
            kmInput.readOnly = false;
            amountInput.readOnly = true;
            if (!descInput.value.trim()) descInput.placeholder = 'z. B. Fahrt Praxis → Hausbesuch';
        } else {
            kmInput.readOnly = true;
            kmInput.value = '';
            amountInput.readOnly = false;
            if (!descInput.value.trim()) descInput.placeholder = 'z. B. Material / Parkticket / Porto';
        }
        recalc();
    }

    typeSelect.addEventListener('change', syncType);
    kmInput.addEventListener('input', recalc);
    amountInput.addEventListener('input', recalc);
    removeButton.addEventListener('click', () => { tr.remove(); recalc(); });
    syncType();
}
function recalc() {
    let totalKm = 0;
    let receiptSum = 0;
    let total = 0;
    if (!itemsBody) return;
    [...itemsBody.querySelectorAll('tr')].forEach(tr => {
        const type = tr.querySelector('.item-type').value;
        const kmInput = tr.querySelector('.item-km');
        const amountInput = tr.querySelector('.item-amount');
        if (type === 'km') {
            const km = parseLocaleNumber(kmInput.value);
            const amount = Math.round(km * parseFloat(KM_RATE) * 100) / 100;
            amountInput.value = km > 0 ? amount.toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2}) : '';
            totalKm += km;
            total += amount;
        } else {
            const amount = parseLocaleNumber(amountInput.value);
            receiptSum += amount;
            total += amount;
        }
    });
    const elKm = document.getElementById('sum-km');
    const elReceipts = document.getElementById('sum-receipts');
    const elTotal = document.getElementById('sum-total');
    if (elKm) elKm.textContent = formatKm(totalKm);
    if (elReceipts) elReceipts.textContent = formatEuro(receiptSum);
    if (elTotal) elTotal.textContent = formatEuro(total);
}
document.getElementById('add-receipt')?.addEventListener('click', () => rowTemplate('receipt', ''));
document.getElementById('add-km')?.addEventListener('click', () => rowTemplate('km', ''));

document.getElementById('payee_selector')?.addEventListener('change', function () {
    const found = PAYEES.find(p => p.label === this.value);
    if (!found) return;
    const name = document.getElementById('claimant_name');
    const iban = document.getElementById('iban');
    const bic = document.getElementById('bic');
    if (name) name.value = found.name || '';
    if (iban) iban.value = found.iban || '';
    if (bic) bic.value = found.bic || '';
});

if (itemsBody) {
    if (FORM_ITEMS.length > 0) {
        FORM_ITEMS.forEach(item => rowTemplate(item.type || 'receipt', item.date || '', item.description || '', item.kilometers || '', item.amount || ''));
    }
    if (itemsBody.children.length === 0) {
        rowTemplate('receipt', '');
    }
}
</script>
</body>
</html>
