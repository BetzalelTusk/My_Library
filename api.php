<?php
// --- CONFIGURATION ---
$book_csv = 'Mevs_English_Library 2025 - Sheet1.csv';
$trans_csv = 'transactions.csv';
$users_file = 'users.json'; 
$collateral_file = 'collateral.json'; // NEW: File to store encrypted cards
$admin_password = "1234"; 
$n8n_webhook = ""; 

// SECURITY: Change this to a random 32-character string!
$encryption_key = "KUOXiGH({$/QS8sDu*~@j\E-nbW}%I=Y"; 

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Helper: Safely Read CSV
function read_transactions_safe($filename) {
    $rows = [];
    if (file_exists($filename) && ($handle = fopen($filename, "r")) !== FALSE) {
        $header = fgetcsv($handle); 
        while (($data = fgetcsv($handle)) !== FALSE) {
            if(count($data) >= 4) {
                $rows[] = [
                    'Student' => $data[0] ?? 'Unknown', 
                    'Book' => $data[1] ?? 'Unknown', 
                    'Action' => $data[2] ?? '-', 
                    'Date' => $data[3] ?? ''
                ];
            }
        }
        fclose($handle);
    }
    return $rows;
}

// Helper: Encryption (AES-256-CBC)
function encrypt_data($data, $key) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

function decrypt_data($data, $key) {
    list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
    return openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);
}

// File Checks
if (!file_exists($trans_csv)) file_put_contents($trans_csv, "Student,Book,Action,Date\n");
if (!file_exists($users_file)) file_put_contents($users_file, "{}");
if (!file_exists($collateral_file)) file_put_contents($collateral_file, "{}");

// --- ROUTER ---

try {

    // 1. GET CATALOG
    if ($action === 'books' && $method === 'GET') {
        $books_raw = [];
        if (file_exists($book_csv) && ($handle = fopen($book_csv, "r")) !== FALSE) {
            fgetcsv($handle); 
            while (($data = fgetcsv($handle)) !== FALSE) {
                if(isset($data[0]) && $data[0] !== '') { 
                    $copies = (!empty($data[4])) ? (int)$data[4] : 1;
                    $books_raw[] = [
                        'id' => $data[0],
                        'name' => $data[1] ?? 'Unknown',
                        'category' => $data[2] ?? 'Uncategorized',
                        'shelf' => $data[3] ?? '?',
                        'copies' => $copies
                    ];
                }
            }
            fclose($handle);
        }

        $transactions = read_transactions_safe($trans_csv);
        $loans = [];
        foreach ($transactions as $t) {
            $b = $t['Book'];
            if (!isset($loans[$b])) $loans[$b] = 0;
            if ($t['Action'] === 'Borrow') $loans[$b]++;
            if ($t['Action'] === 'Return') $loans[$b]--;
        }

        $catalog = [];
        foreach ($books_raw as $b) {
            $activeLoans = $loans[$b['name']] ?? 0;
            $b['available'] = max(0, $b['copies'] - $activeLoans);
            $b['total'] = $b['copies'];
            $catalog[] = $b;
        }
        echo json_encode($catalog);
        exit;
    }

    // 2. GET ACTIVE USERS
    if ($action === 'students' && $method === 'GET') {
        $transactions = read_transactions_safe($trans_csv);
        $studentLoans = [];
        foreach ($transactions as $t) {
            $s = $t['Student']; $b = $t['Book'];
            if (!isset($studentLoans[$s])) $studentLoans[$s] = [];
            if (!isset($studentLoans[$s][$b])) $studentLoans[$s][$b] = 0;
            if ($t['Action'] === 'Borrow') $studentLoans[$s][$b]++;
            if ($t['Action'] === 'Return') $studentLoans[$s][$b]--;
        }
        $activeStudents = [];
        foreach ($studentLoans as $student => $books) {
            $borrowed = [];
            foreach ($books as $book => $count) { if ($count > 0) $borrowed[] = $book; }
            if (count($borrowed) > 0) $activeStudents[] = ['name' => $student, 'books' => $borrowed];
        }
        echo json_encode($activeStudents);
        exit;
    }

    // 3. CHECK USER STATUS
    if ($action === 'check_user_status' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $email = strtolower(trim($input['email'] ?? ''));
        $users = json_decode(file_get_contents($users_file), true) ?? [];
        
        if (isset($users[$email])) {
            echo json_encode(['status' => 'exists']); 
        } else {
            echo json_encode(['status' => 'new']);
        }
        exit;
    }

    // 4. REGISTER USER
    if ($action === 'register_user' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $email = strtolower(trim($input['email'] ?? ''));
        $pass = $input['password'] ?? '';

        if(!$email || !$pass) { http_response_code(400); echo json_encode(['error'=>'Missing fields']); exit; }

        $users = json_decode(file_get_contents($users_file), true) ?? [];
        $users[$email] = password_hash($pass, PASSWORD_DEFAULT);
        
        file_put_contents($users_file, json_encode($users));
        echo json_encode(['success' => true]);
        exit;
    }

    // 5. USER HISTORY (UPDATED FOR ADMIN BYPASS)
    if ($action === 'user_history' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $email = strtolower(trim($input['email'] ?? ''));
        $password = $input['password'] ?? '';

        // --- ADMIN BYPASS ---
        $isAdmin = ($password === $admin_password);

        if (!$isAdmin) {
            // Normal user check
            $users = json_decode(file_get_contents($users_file), true) ?? [];
            if (isset($users[$email])) {
                if (!password_verify($password, $users[$email])) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Incorrect Password']);
                    exit;
                }
            } else {
                 http_response_code(401);
                 echo json_encode(['error' => 'Account not found']);
                 exit;
            }
        }
        // -------------------------------

        $transactions = read_transactions_safe($trans_csv);
        $userHistory = [];
        $activeBooks = [];

        foreach ($transactions as $t) {
            if (stripos($t['Student'], $email) !== false) {
                $userHistory[] = $t;
                if(!isset($activeBooks[$t['Book']])) $activeBooks[$t['Book']] = 0;
                if($t['Action'] === 'Borrow') $activeBooks[$t['Book']]++;
                if($t['Action'] === 'Return') $activeBooks[$t['Book']]--;
            }
        }

        $current = [];
        foreach($activeBooks as $b => $c) { if($c > 0) $current[] = $b; }

        echo json_encode(['history' => array_reverse($userHistory), 'active' => $current]);
        exit;
    }

    // 6. TRANSACTION
    if ($action === 'transaction' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $student = str_replace('"', '""', $input['student'] ?? 'Unknown');
        $book = str_replace('"', '""', $input['book'] ?? 'Unknown');
        $act = $input['action'] ?? 'Error';
        $date = date("Y-m-d H:i:s");
        
        $line = "\"$student\",\"$book\",\"$act\",\"$date\"\n";
        file_put_contents($trans_csv, $line, FILE_APPEND);

        if (!empty($n8n_webhook)) {
            $payload = json_encode(['student'=>$student, 'book'=>$book, 'action'=>$act, 'timestamp'=>$date]);
            $ch = curl_init($n8n_webhook);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_exec($ch);
            curl_close($ch);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // 7. ADMIN ACTIVE
    if ($action === 'admin_active' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (($input['password'] ?? '') !== $admin_password) { http_response_code(403); exit; }
        
        $transactions = read_transactions_safe($trans_csv);
        $activeMap = [];
        foreach ($transactions as $t) {
            $key = $t['Student'] . '|' . $t['Book'];
            if ($t['Action'] === 'Borrow') $activeMap[$key] = $t['Date'];
            elseif ($t['Action'] === 'Return') unset($activeMap[$key]);
        }
        
        $result = [];
        $now = new DateTime();
        foreach ($activeMap as $key => $dateStr) {
            $parts = explode('|', $key);
            $student = $parts[0] ?? 'Unknown';
            $book = $parts[1] ?? 'Unknown';
            try { $borrowDate = new DateTime($dateStr); } catch (Exception $e) { $borrowDate = $now; }
            $diff = $now->diff($borrowDate)->days;
            $result[] = ['student'=>$student, 'book'=>$book, 'date'=>$dateStr, 'daysHeld'=>$diff, 'isOverdue'=>$diff > 14];
        }
        echo json_encode($result);
        exit;
    }

    // 8. ADMIN HISTORY
    if ($action === 'admin_history' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (($input['password'] ?? '') !== $admin_password) { http_response_code(403); exit; }
        $transactions = read_transactions_safe($trans_csv);
        echo json_encode(array_reverse($transactions));
        exit;
    }

    // 9. SAVE COLLATERAL (Goal 3)
    if ($action === 'save_collateral' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $email = strtolower(trim($input['email'] ?? ''));
        $cardData = $input['cardData'] ?? ''; 

        if (!$email || !$cardData) { http_response_code(400); exit; }

        $encrypted = encrypt_data($cardData, $encryption_key);
        $db = json_decode(file_get_contents($collateral_file), true) ?? [];
        $db[$email] = [
            'data' => $encrypted,
            'updated' => date("Y-m-d H:i:s")
        ];

        file_put_contents($collateral_file, json_encode($db));
        echo json_encode(['success' => true]);
        exit;
    }

    // 10. GET COLLATERAL (Goal 3 - Admin Only)
    if ($action === 'get_collateral' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (($input['password'] ?? '') !== $admin_password) { http_response_code(403); exit; }

        $targetEmail = strtolower(trim($input['email'] ?? ''));
        $db = json_decode(file_get_contents($collateral_file), true) ?? [];

        if (isset($db[$targetEmail])) {
            $decrypted = decrypt_data($db[$targetEmail]['data'], $encryption_key);
            echo json_encode(['found' => true, 'details' => $decrypted, 'date' => $db[$targetEmail]['updated']]);
        } else {
            echo json_encode(['found' => false]);
        }
        exit;
    }

    if ($action === 'admin_reload') { echo json_encode(['success'=>true]); exit; }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
