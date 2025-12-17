<?php
// --- CONFIGURATION ---
$book_csv = 'Google_Sheets_Updated.csv';
$trans_csv = 'transactions.csv';
$users_file = 'users.json'; 
$collateral_file = 'collateral.json'; 
$admin_password = "1234"; 
$n8n_webhook = "https://n8n.srv1091500.hstgr.cloud/webhook/library-transaction"; 

// SECURITY: Change this to a random 32-character string!
$encryption_key = "eNQ>4'Ns&n`qxj9N!'BQRL>KZ^HK[)]h"; 

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// --- SECURITY HELPERS ---

function sanitize_input($data) {
    if (is_array($data)) return array_map('sanitize_input', $data);
    $data = trim($data);
    // CRITICAL FIX: Changed from strip_tags to htmlspecialchars to preserve <emails>
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    // Prevent Excel Injection
    if (in_array(substr($data, 0, 1), ['=', '+', '-', '@'])) $data = "'" . $data;
    return $data;
}

function encrypt_data($data, $key) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

function decrypt_data($data, $key) {
    list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
    return openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);
}

function read_transactions_safe($filename) {
    $rows = [];
    if (file_exists($filename) && ($handle = fopen($filename, "r")) !== FALSE) {
        fgetcsv($handle); 
        while (($data = fgetcsv($handle)) !== FALSE) {
            if(count($data) >= 4) {
                // We decode entities when reading back so logic works (e.g. < becomes < again)
                $rows[] = [
                    'Student' => htmlspecialchars_decode($data[0] ?? 'Unknown'), 
                    'Book' => htmlspecialchars_decode($data[1] ?? 'Unknown'), 
                    'Action' => htmlspecialchars_decode($data[2] ?? '-'), 
                    'Date' => htmlspecialchars_decode($data[3] ?? '')
                ];
            }
        }
        fclose($handle);
    }
    return $rows;
}

if (!file_exists($trans_csv)) file_put_contents($trans_csv, "Student,Book,Action,Date\n");
if (!file_exists($users_file)) file_put_contents($users_file, "{}");
if (!file_exists($collateral_file)) file_put_contents($collateral_file, "{}");

try {
    $raw_input = json_decode(file_get_contents('php://input'), true);
    // Apply sanitization to input
    $input = $raw_input ? sanitize_input($raw_input) : [];

    // 1. GET CATALOG
    if ($action === 'books' && $method === 'GET') {
        $books_raw = [];
        if (file_exists($book_csv) && ($handle = fopen($book_csv, "r")) !== FALSE) {
            fgetcsv($handle); 
            while (($data = fgetcsv($handle)) !== FALSE) {
                if(isset($data[0]) && $data[0] !== '') { 
                    $copies = (!empty($data[4])) ? (int)$data[4] : 1;
                    $books_raw[] = [
                        'id' => htmlspecialchars($data[0]),
                        'name' => htmlspecialchars($data[1] ?? 'Unknown'),
                        'category' => htmlspecialchars($data[2] ?? 'Uncategorized'),
                        'shelf' => htmlspecialchars($data[3] ?? '?'),
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
            // Decode name for matching against transactions
            $realName = htmlspecialchars_decode($b['name']);
            $activeLoans = $loans[$realName] ?? 0;
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
        $email = strtolower($input['email'] ?? '');
        $users = json_decode(file_get_contents($users_file), true) ?? [];
        if (isset($users[$email])) {
            $userData = $users[$email];
            $name = is_array($userData) ? ($userData['name'] ?? 'Unknown') : 'Unknown';
            echo json_encode(['status' => 'exists', 'name' => $name]); 
        } else {
            echo json_encode(['status' => 'new']);
        }
        exit;
    }

    // 4. REGISTER USER
    if ($action === 'register_user' && $method === 'POST') {
        $email = strtolower($input['email'] ?? '');
        $pass = $input['password'] ?? '';
        $name = $input['name'] ?? '';
        $studentId = $input['student_id'] ?? '';

        if(!$email || !$pass || !$name) { http_response_code(400); echo json_encode(['error'=>'Missing fields']); exit; }

        $users = json_decode(file_get_contents($users_file), true) ?? [];
        $users[$email] = ['hash' => password_hash($pass, PASSWORD_DEFAULT), 'name' => $name, 'student_id' => $studentId];
        
        file_put_contents($users_file, json_encode($users));
        echo json_encode(['success' => true]);
        exit;
    }

    // 5. USER HISTORY
    if ($action === 'user_history' && $method === 'POST') {
        $email = strtolower($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $isAdmin = ($password === $admin_password);

        if (empty($email)) { echo json_encode(['history' => [], 'active' => []]); exit; }

        $users = json_decode(file_get_contents($users_file), true) ?? [];
        $targetName = '';

        if (!$isAdmin) {
            if (isset($users[$email])) {
                $userData = $users[$email];
                $hash = is_array($userData) ? $userData['hash'] : $userData; 
                if (!password_verify($password, $hash)) { http_response_code(403); echo json_encode(['error' => 'Incorrect Password']); exit; }
            } else { http_response_code(401); echo json_encode(['error' => 'Account not found']); exit; }
        }

        if(isset($users[$email])) {
            $userData = $users[$email];
            $targetName = is_array($userData) ? strtolower($userData['name'] ?? '') : '';
        }

        $transactions = read_transactions_safe($trans_csv);
        $userHistory = [];
        $activeBooks = [];

        foreach ($transactions as $t) {
            $rawStudent = strtolower($t['Student']);
            $isMatch = false;

            if (!empty($email) && strpos($rawStudent, $email) !== false) $isMatch = true;
            elseif (!empty($targetName) && strpos($rawStudent, $targetName) !== false) $isMatch = true;
            
            if ($isMatch) {
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
        // Use Raw input for logic, sanitize for storage
        $rawStudentInput = $raw_input['student'] ?? 'Unknown';
        $rawBookInput = $raw_input['book'] ?? 'Unknown';
        
        $act = $input['action'] ?? 'Error';
        $date = date("Y-m-d H:i:s");
        
        $email = strtolower(trim($rawStudentInput));
        $users = json_decode(file_get_contents($users_file), true) ?? [];
        $realName = 'Student';

        if(filter_var($email, FILTER_VALIDATE_EMAIL) && isset($users[$email])) {
             $userData = $users[$email];
             $realName = is_array($userData) ? ($userData['name'] ?? 'Unknown') : 'Student';
             $student = "$realName <$email>"; 
        } else {
             $student = $rawStudentInput;
        }

        // Sanitize for CSV storage
        $safeStudent = sanitize_input($student);
        $safeBook = sanitize_input($rawBookInput);
        $safeAct = sanitize_input($act);
        $safeDate = sanitize_input($date);

        $line = "\"$safeStudent\",\"$safeBook\",\"$safeAct\",\"$safeDate\"\n";
        file_put_contents($trans_csv, $line, FILE_APPEND);

        if (!empty($n8n_webhook)) {
            $dueDate = date('Y-m-d', strtotime($date . ' + 14 days')); 
            $payload = json_encode(['student_name' => $realName, 'student_email' => $email, 'book_title' => $rawBookInput, 'action' => $act, 'transaction_date' => $date, 'due_date' => $dueDate]);
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
        if (($input['password'] ?? '') !== $admin_password) { http_response_code(403); exit; }
        
        $transactions = read_transactions_safe($trans_csv);
        $collateralDB = json_decode(file_get_contents($collateral_file), true) ?? [];

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
            $studentStr = $parts[0] ?? 'Unknown';
            $book = $parts[1] ?? 'Unknown';
            
            $email = '';
            $name = $studentStr;

            if (preg_match('/(.*) <(.*)>/', $studentStr, $matches)) {
                $name = trim($matches[1]);
                $email = strtolower(trim($matches[2]));
            }

            try { $borrowDate = new DateTime($dateStr); } catch (Exception $e) { $borrowDate = $now; }
            $diff = $now->diff($borrowDate)->days;
            $daysLeft = 14 - $diff;
            $isOverdue = $daysLeft < 0;
            $hasCollateral = !empty($email) && isset($collateralDB[$email]);

            $result[] = [
                'student' => $name, 
                'email' => $email,
                'book' => $book, 
                'date' => $dateStr, 
                'daysHeld' => $diff,
                'daysLeft' => $daysLeft,
                'isOverdue' => $isOverdue,
                'hasCollateral' => $hasCollateral
            ];
        }
        echo json_encode($result);
        exit;
    }

    // 8. ADMIN HISTORY
    if ($action === 'admin_history' && $method === 'POST') {
        if (($input['password'] ?? '') !== $admin_password) { http_response_code(403); exit; }
        $transactions = read_transactions_safe($trans_csv);
        echo json_encode(array_reverse($transactions));
        exit;
    }

    // 9. SAVE COLLATERAL
    if ($action === 'save_collateral' && $method === 'POST') {
        $email = strtolower($input['email'] ?? '');
        $rawCard = $raw_input['cardData'] ?? ''; 

        if (!$email || !$rawCard) { http_response_code(400); exit; }

        $encrypted = encrypt_data($rawCard, $encryption_key);
        $db = json_decode(file_get_contents($collateral_file), true) ?? [];
        $db[$email] = ['data' => $encrypted, 'updated' => date("Y-m-d H:i:s")];

        file_put_contents($collateral_file, json_encode($db));
        echo json_encode(['success' => true]);
        exit;
    }

    // 10. GET COLLATERAL
    if ($action === 'get_collateral' && $method === 'POST') {
        if (($input['password'] ?? '') !== $admin_password) { http_response_code(403); exit; }
        $targetEmail = strtolower($input['email'] ?? '');
        $db = json_decode(file_get_contents($collateral_file), true) ?? [];
        if (isset($db[$targetEmail])) {
            $decrypted = decrypt_data($db[$targetEmail]['data'], $encryption_key);
            echo json_encode(['found' => true, 'details' => $decrypted, 'date' => $db[$targetEmail]['updated']]);
        } else { echo json_encode(['found' => false]); }
        exit;
    }

    // 11. LINK STUDENT ID
    if ($action === 'link_student_id' && $method === 'POST') {
        $email = strtolower($input['email'] ?? '');
        $sid = $input['student_id'] ?? '';
        if (!$email || !$sid) { http_response_code(400); exit; }
        $users = json_decode(file_get_contents($users_file), true) ?? [];
        if(isset($users[$email])) {
            $userData = $users[$email];
            if(is_array($userData)) {
                $users[$email]['student_id'] = $sid;
                file_put_contents($users_file, json_encode($users));
                echo json_encode(['success' => true]);
            }
        }
        exit;
    }

    // 12. LOOKUP STUDENT ID
    if ($action === 'lookup_student_id' && $method === 'POST') {
        $sid = $input['student_id'] ?? '';
        if (!$sid) { echo json_encode(['found'=>false]); exit; }
        $users = json_decode(file_get_contents($users_file), true) ?? [];
        foreach($users as $email => $data) {
            if(is_array($data) && isset($data['student_id']) && $data['student_id'] === $sid) {
                echo json_encode(['found'=>true, 'email'=>$email, 'name'=>$data['name']]);
                exit;
            }
        }
        echo json_encode(['found'=>false]);
        exit;
    }

    if ($action === 'admin_reload') { echo json_encode(['success'=>true]); exit; }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']); 
}
?>
