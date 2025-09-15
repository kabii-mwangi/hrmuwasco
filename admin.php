<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
require_once 'header.php';

// CSRF Token Generation
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Initialize $tab with validation
$allowed_tabs = ['users', 'financial'];
$tab = isset($_GET['tab']) && in_array($_GET['tab'], $allowed_tabs) ? sanitizeInput($_GET['tab']) : 'users';

$user = [
    'first_name' => isset($_SESSION['user_name']) ? explode(' ', $_SESSION['user_name'])[0] : 'User',
    'last_name' => isset($_SESSION['user_name']) ? (explode(' ', $_SESSION['user_name'])[1] ?? '') : '',
    'role' => $_SESSION['user_role'] ?? 'guest',
    'id' => $_SESSION['user_id']
];

function hasPermission(string $requiredRole): bool {
    $userRole = $_SESSION['user_role'] ?? 'guest';
    $roles = [
        'managing_director' => 6,
        'super_admin' => 5,
        'hr_manager' => 4,
        'dept_head' => 3,
        'section_head' => 2,
        'manager' => 1,
        'employee' => 0
    ];
    $userLevel = $roles[$userRole] ?? 0;
    $requiredLevel = $roles[$requiredRole] ?? 0;
    return $userLevel >= $requiredLevel;
}

// Restrict access
if (!(hasPermission('super_admin') || hasPermission('hr_manager'))) {
    header('Location: dashboard.php');
    exit();
}

function formatDate(?string $date): string {
    if (!$date) return 'N/A';
    return (new DateTime($date))->format('M d, Y');
}

function sanitizeInput(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage(string $location, string $message, string $type = 'info'): void {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header("Location: {$location}");
    exit();
}

function getFlashMessage(): ?array {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

function calculateTotalDays(string $start_date, string $end_date): int {
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    return $end->diff($start)->days + 1;
}

function getCurrentFinancialYear(?string $current_date = null, ?mysqli $mysqli = null): array {
    if ($current_date === null) {
        $current_date = date('Y-m-d');
    }
    if ($mysqli !== null) {
        return getCurrentFinancialYearFromDatabase($current_date, $mysqli);
    }
    return calculateFinancialYearByDate($current_date);
}

function getCurrentFinancialYearFromDatabase(string $current_date, mysqli $mysqli): array {
    $stmt = $mysqli->prepare("SELECT * FROM financial_years 
                             WHERE ? BETWEEN start_date AND end_date 
                             AND is_active = 1 
                             ORDER BY start_date DESC 
                             LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $current_date);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            return [
                'id' => $row['id'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'year_name' => $row['year_name'],
                'from_database' => true
            ];
        }
    }
    $stmt = $mysqli->prepare("SELECT * FROM financial_years 
                             WHERE is_active = 1 
                             ORDER BY start_date DESC 
                             LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            return [
                'id' => $row['id'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'year_name' => $row['year_name'],
                'from_database' => true,
                'note' => 'Using most recent financial year from database'
            ];
        }
    }
    return calculateFinancialYearByDate($current_date);
}

function calculateFinancialYearByDate(string $current_date): array {
    $current_year = (int)(new DateTime($current_date))->format('Y');
    $current_month = (int)(new DateTime($current_date))->format('n');
    if ($current_month >= 7) {
        $start_year = $current_year;
        $end_year = $current_year + 1;
    } else {
        $start_year = $current_year - 1;
        $end_year = $current_year;
    }
    return [
        'start_date' => $start_year . '-07-01',
        'end_date' => $end_year . '-06-30',
        'year_name' => $start_year . '/' . substr($end_year, 2),
        'from_database' => false
    ];
}

function getNextFinancialYear(?string $current_date = null, ?mysqli $mysqli = null): array {
    $current_fy = getCurrentFinancialYear($current_date, $mysqli);
    $current_end_year = (int)explode('-', $current_fy['end_date'])[0];
    $next_start_year = $current_end_year;
    $next_end_year = $next_start_year + 1;
    return [
        'start_date' => $next_start_year . '-07-01',
        'end_date' => $next_end_year . '-06-30',
        'year_name' => $next_start_year . '/' . substr($next_end_year, 2)
    ];
}

function canCreateNewFinancialYear(mysqli $mysqli): array {
    $current_date = date('Y-m-d');
    $current_fy = getCurrentFinancialYear($current_date, $mysqli);
    $next_fy = getNextFinancialYear($current_date, $mysqli);
    
    $stmt = $mysqli->prepare("SELECT id FROM financial_years WHERE year_name = ?");
    if (!$stmt) {
        error_log("Prepare failed: " . $mysqli->error);
        return [
            'can_create' => false,
            'reason' => 'Database error: ' . $mysqli->error,
            'next_fy' => null,
            'current_fy' => $current_fy
        ];
    }
    
    $stmt->bind_param("s", $next_fy['year_name']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->fetch_assoc()) {
        return [
            'can_create' => false,
            'reason' => 'Financial year ' . $next_fy['year_name'] . ' already exists.',
            'next_fy' => null,
            'current_fy' => $current_fy
        ];
    }
    
    $current_fy_end = strtotime($current_fy['end_date']);
    $current_timestamp = strtotime($current_date);
    $days_from_fy_end = ($current_timestamp - $current_fy_end) / (60 * 60 * 24);
    
    if ($days_from_fy_end < -30) {
        return [
            'can_create' => false,
            'reason' => 'Too early to create next financial year. You can create it 30 days before the current financial year ends (' . date('M d, Y', $current_fy_end) . ').',
            'next_fy' => null,
            'current_fy' => $current_fy,
            'days_until_creation' => abs($days_from_fy_end + 30)
        ];
    }
    
    if ($days_from_fy_end > 90) {
        return [
            'can_create' => false,
            'reason' => 'Too late to create financial year ' . $next_fy['year_name'] . '. Please contact system administrator.',
            'next_fy' => null,
            'current_fy' => $current_fy
        ];
    }
    
    return [
        'can_create' => true,
        'reason' => 'Ready to create next financial year.',
        'next_fy' => $next_fy,
        'current_fy' => $current_fy,
        'creation_window' => $days_from_fy_end <= 0 ? 'Pre-creation window' : 'Post-deadline creation'
    ];
}

function allocateLeaveToAllEmployees(mysqli $mysqli, int $financial_year_id): int {
    $debug_info = [];
    $allocated_count = 0;
    
    try {
        $mysqli->begin_transaction();
        
        $stmt = $mysqli->prepare("SELECT start_date, year_name FROM financial_years WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare FY query: " . $mysqli->error);
        }
        
        $stmt->bind_param("i", $financial_year_id);
        $stmt->execute();
        $new_fy = $stmt->get_result()->fetch_assoc();
        
        if (!$new_fy) {
            throw new Exception("Financial year with ID {$financial_year_id} not found");
        }
        
        $new_fy_start = $new_fy['start_date'];
        $debug_info[] = "New FY: {$new_fy['year_name']}, Start: {$new_fy_start}";

        $prev_fy_id = null;
        $prev_stmt = $mysqli->prepare("SELECT id, year_name FROM financial_years 
                                      WHERE end_date < ? 
                                      ORDER BY end_date DESC 
                                      LIMIT 1");
        if ($prev_stmt) {
            $prev_stmt->bind_param("s", $new_fy_start);
            $prev_stmt->execute();
            $prev_result = $prev_stmt->get_result();
            if ($row = $prev_result->fetch_assoc()) {
                $prev_fy_id = $row['id'];
                $debug_info[] = "Previous FY found: {$row['year_name']} (ID: {$prev_fy_id})";
            } else {
                $debug_info[] = "No previous financial year found";
            }
        }

        $prev_balances = [];
        if ($prev_fy_id) {
            $balance_stmt = $mysqli->prepare("SELECT employee_id, remaining_days 
                                             FROM employee_leave_balances 
                                             WHERE leave_type_id = 1 
                                               AND financial_year_id = ?");
            if ($balance_stmt) {
                $balance_stmt->bind_param("i", $prev_fy_id);
                $balance_stmt->execute();
                $balance_result = $balance_stmt->get_result();
                while ($row = $balance_result->fetch_assoc()) {
                    $prev_balances[$row['employee_id']] = (float)$row['remaining_days'];
                }
                $debug_info[] = "Previous balances loaded for " . count($prev_balances) . " employees";
            }
        }

        $leave_rules = [
            ['leave_type_id' => 1, 'days' => 30,  'gender' => 'all',    'employment' => 'permanent'],
            ['leave_type_id' => 6, 'days' => 0,  'gender' => 'all',    'employment' => 'all'],
            ['leave_type_id' => 5, 'days' => 10,  'gender' => 'all',    'employment' => 'all'],
            ['leave_type_id' => 2, 'days' => 10,  'gender' => 'all',    'employment' => 'all'],
            ['leave_type_id' => 3, 'days' => 120, 'gender' => 'female', 'employment' => 'all'],
            ['leave_type_id' => 4, 'days' => 10,  'gender' => 'male',   'employment' => 'all'],
            ['leave_type_id' => 7, 'days' => 10,  'gender' => 'all',    'employment' => 'all'],
            ['leave_type_id' => 9, 'days' => 0,  'gender' => 'all',    'employment' => 'all'],
            ['leave_type_id' => 8, 'days' => 0,  'gender' => 'all',    'employment' => 'all'],
        ];

        $employees_query = "SELECT id, gender, employment_type, CONCAT(first_name, ' ', last_name) as full_name 
                           FROM employees 
                           WHERE employee_status = 'active'";
        $employees_result = $mysqli->query($employees_query);
        
        if (!$employees_result) {
            throw new Exception("Failed to fetch employees: " . $mysqli->error);
        }
        
        $employees = $employees_result->fetch_all(MYSQLI_ASSOC);
        $debug_info[] = "Found " . count($employees) . " active employees";
        
        if (count($employees) == 0) {
            throw new Exception("No active employees found in the database");
        }

        $check_stmt = $mysqli->prepare("SELECT id FROM employee_leave_balances 
                                       WHERE employee_id = ? 
                                         AND leave_type_id = ? 
                                         AND financial_year_id = ?");
        
        $insert_stmt = $mysqli->prepare("INSERT INTO employee_leave_balances 
                                        (employee_id, leave_type_id, financial_year_id, allocated_days, used_days, remaining_days, total_days, created_at, updated_at) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        
        if (!$check_stmt || !$insert_stmt) {
            throw new Exception("Failed to prepare statements - Check: " . ($check_stmt ? "OK" : $mysqli->error) . 
                              ", Insert: " . ($insert_stmt ? "OK" : $mysqli->error));
        }

        $employee_count = 0;
        $rule_applications = 0;
        
        foreach ($employees as $employee) {
            $emp_id = (int)$employee['id'];
            $gender = strtolower(trim($employee['gender'] ?? ''));
            $employment = strtolower(trim($employee['employment_type'] ?? ''));
            $employee_count++;
            
            $debug_info[] = "Processing Employee {$employee_count}: {$employee['full_name']} (ID: {$emp_id}, Gender: {$gender}, Employment: {$employment})";

            foreach ($leave_rules as $rule_index => $rule) {
                $rule_applications++;
                
                $gender_ok = $rule['gender'] === 'all' || $rule['gender'] === $gender;
                $employment_ok = $rule['employment'] === 'all' || $rule['employment'] === $employment;

                if (!$gender_ok || !$employment_ok) {
                    $debug_info[] = "  Rule {$rule_index} (LT:{$rule['leave_type_id']}): SKIPPED - Gender: {$gender} vs {$rule['gender']}, Employment: {$employment} vs {$rule['employment']}";
                    continue;
                }

                $check_stmt->bind_param("iii", $emp_id, $rule['leave_type_id'], $financial_year_id);
                if (!$check_stmt->execute()) {
                    $debug_info[] = "  Rule {$rule_index}: Check query failed - " . $check_stmt->error;
                    continue;
                }
                
                $existing = $check_stmt->get_result()->fetch_assoc();
                if ($existing) {
                    $debug_info[] = "  Rule {$rule_index}: ALREADY EXISTS";
                    continue;
                }

                $allocated_days = (float)$rule['days'];
                $used_days = 0.0;
                
                if ($rule['leave_type_id'] == 1 && $employment === 'permanent') {
                    $prev_balance = $prev_balances[$emp_id] ?? 0;
                    $allocated_days = 30.0;
                    $remaining_days = $prev_balance + $allocated_days;
                    $total_days = $remaining_days;
                    $debug_info[] = "  Rule {$rule_index}: Annual leave - Allocated: 30, Carryover: {$prev_balance}, Remaining: {$remaining_days}, Total: {$total_days}";
                } else {
                    $total_days = $allocated_days;
                    $remaining_days = $allocated_days;
                    $debug_info[] = "  Rule {$rule_index}: Standard allocation - {$allocated_days} days";
                }

                $insert_stmt->bind_param("iiidddd", $emp_id, $rule['leave_type_id'], $financial_year_id, 
                                        $allocated_days, $used_days, $remaining_days, $total_days);

                if ($insert_stmt->execute()) {
                    $allocated_count++;
                    $debug_info[] = "  Rule {$rule_index}: SUCCESS - Allocated: {$allocated_days}, Total: {$total_days}, Remaining: {$remaining_days}";
                } else {
                    $debug_info[] = "  Rule {$rule_index}: FAILED - " . $insert_stmt->error;
                    error_log("Insert failed for employee {$emp_id}, leave type {$rule['leave_type_id']}: " . $insert_stmt->error);
                }
            }
        }

        $mysqli->commit();
        $debug_info[] = "SUMMARY: Processed {$employee_count} employees, {$rule_applications} rule applications, {$allocated_count} successful allocations";
        error_log("Leave Allocation Debug Info:\n" . implode("\n", $debug_info));
        
        return $allocated_count;
        
    } catch (Exception $e) {
        if ($mysqli->in_transaction()) {
            $mysqli->rollback();
        }
        $debug_info[] = "ERROR: " . $e->getMessage();
        error_log("Leave Allocation Error:\n" . implode("\n", $debug_info));
        return 0;
    }
}

function allocateLeaveToEmployee(mysqli $mysqli, int $employee_id, int $financial_year_id, ?array $selected_leave_types = null): int {
    $debug_info = [];
    $allocated_count = 0;
    
    try {
        $mysqli->begin_transaction();
        
        $stmt = $mysqli->prepare("SELECT start_date, year_name FROM financial_years WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare FY query: " . $mysqli->error);
        }
        
        $stmt->bind_param("i", $financial_year_id);
        $stmt->execute();
        $new_fy = $stmt->get_result()->fetch_assoc();
        
        if (!$new_fy) {
            throw new Exception("Financial year with ID {$financial_year_id} not found");
        }
        
        $new_fy_start = $new_fy['start_date'];
        $debug_info[] = "New FY: {$new_fy['year_name']}, Start: {$new_fy_start}";

        $prev_fy_id = null;
        $prev_stmt = $mysqli->prepare("SELECT id, year_name FROM financial_years 
                                      WHERE end_date < ? 
                                      ORDER BY end_date DESC 
                                      LIMIT 1");
        if ($prev_stmt) {
            $prev_stmt->bind_param("s", $new_fy_start);
            $prev_stmt->execute();
            $prev_result = $prev_stmt->get_result();
            if ($row = $prev_result->fetch_assoc()) {
                $prev_fy_id = $row['id'];
                $debug_info[] = "Previous FY found: {$row['year_name']} (ID: {$prev_fy_id})";
            } else {
                $debug_info[] = "No previous financial year found";
            }
        }

        $prev_balances = [];
        if ($prev_fy_id) {
            $balance_stmt = $mysqli->prepare("SELECT employee_id, remaining_days 
                                             FROM employee_leave_balances 
                                             WHERE leave_type_id = 1 
                                               AND financial_year_id = ? 
                                               AND employee_id = ?");
            if ($balance_stmt) {
                $balance_stmt->bind_param("ii", $prev_fy_id, $employee_id);
                $balance_stmt->execute();
                $balance_result = $balance_stmt->get_result();
                if ($row = $balance_result->fetch_assoc()) {
                    $prev_balances[$employee_id] = (float)$row['remaining_days'];
                }
                $debug_info[] = "Previous balances loaded for employee ID {$employee_id}";
            }
        }

        $leave_rules = [
            ['leave_type_id' => 1, 'days' => 30,  'gender' => 'all',    'employment' => 'permanent'],
            ['leave_type_id' => 6, 'days' => 0,  'gender' => 'all',    'employment' => 'all'],
            ['leave_type_id' => 5, 'days' => 10,  'gender' => 'all',    'employment' => 'all'],
            ['leave_type_id' => 2, 'days' => 10,  'gender' => 'all',    'employment' => 'all'],
            ['leave_type_id' => 3, 'days' => 120, 'gender' => 'female', 'employment' => 'all'],
            ['leave_type_id' => 4, 'days' => 10,  'gender' => 'male',   'employment' => 'all'],
            ['leave_type_id' => 7, 'days' => 10,  'gender' => 'all',    'employment' => 'all'],
            ['leave_type_id' => 9, 'days' => 0,  'gender' => 'all',    'employment' => 'all'],
            ['leave_type_id' => 8, 'days' => 0,  'gender' => 'all',    'employment' => 'all'],
        ];

        $employee_query = "SELECT id, gender, employment_type, CONCAT(first_name, ' ', last_name) as full_name 
                          FROM employees 
                          WHERE id = ? AND employee_status = 'active'";
        $stmt = $mysqli->prepare($employee_query);
        if (!$stmt) {
            throw new Exception("Failed to prepare employee query: " . $mysqli->error);
        }
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $employee_result = $stmt->get_result();
        $employee = $employee_result->fetch_assoc();
        
        if (!$employee) {
            throw new Exception("Employee with ID {$employee_id} not found or not active");
        }
        
        $emp_id = (int)$employee['id'];
        $gender = strtolower(trim($employee['gender'] ?? ''));
        $employment = strtolower(trim($employee['employment_type'] ?? ''));
        $debug_info[] = "Processing Employee: {$employee['full_name']} (ID: {$emp_id}, Gender: {$gender}, Employment: {$employment})";

        $check_stmt = $mysqli->prepare("SELECT id FROM employee_leave_balances 
                                       WHERE employee_id = ? 
                                         AND leave_type_id = ? 
                                         AND financial_year_id = ?");
        
        $insert_stmt = $mysqli->prepare("INSERT INTO employee_leave_balances 
                                        (employee_id, leave_type_id, financial_year_id, allocated_days, used_days, remaining_days, total_days, created_at, updated_at) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        
        if (!$check_stmt || !$insert_stmt) {
            throw new Exception("Failed to prepare statements - Check: " . ($check_stmt ? "OK" : $mysqli->error) . 
                              ", Insert: " . ($insert_stmt ? "OK" : $mysqli->error));
        }

        $rule_applications = 0;
        $rules_to_process = $selected_leave_types ? array_filter($leave_rules, function($rule) use ($selected_leave_types) {
            return in_array($rule['leave_type_id'], $selected_leave_types);
        }) : $leave_rules;

        foreach ($rules_to_process as $rule_index => $rule) {
            $rule_applications++;
            
            $gender_ok = $rule['gender'] === 'all' || $rule['gender'] === $gender;
            $employment_ok = $rule['employment'] === 'all' || $rule['employment'] === $employment;

            if (!$gender_ok || !$employment_ok) {
                $debug_info[] = "  Rule {$rule_index} (LT:{$rule['leave_type_id']}): SKIPPED - Gender: {$gender} vs {$rule['gender']}, Employment: {$employment} vs {$rule['employment']}";
                continue;
            }

            $check_stmt->bind_param("iii", $emp_id, $rule['leave_type_id'], $financial_year_id);
            if (!$check_stmt->execute()) {
                $debug_info[] = "  Rule {$rule_index}: Check query failed - " . $check_stmt->error;
                continue;
            }
            
            $existing = $check_stmt->get_result()->fetch_assoc();
            if ($existing) {
                $debug_info[] = "  Rule {$rule_index}: ALREADY EXISTS";
                continue;
            }

            $allocated_days = (float)$rule['days'];
            $used_days = 0.0;
            
            if ($rule['leave_type_id'] == 1 && $employment === 'permanent') {
                $prev_balance = $prev_balances[$emp_id] ?? 0;
                $allocated_days = 30.0;
                $remaining_days = $prev_balance + $allocated_days;
                $total_days = $remaining_days;
                $debug_info[] = "  Rule {$rule_index}: Annual leave - Allocated: 30, Carryover: {$prev_balance}, Remaining: {$remaining_days}, Total: {$total_days}";
            } else {
                $total_days = $allocated_days;
                $remaining_days = $allocated_days;
                $debug_info[] = "  Rule {$rule_index}: Standard allocation - {$allocated_days} days";
            }

            $insert_stmt->bind_param("iiidddd", $emp_id, $rule['leave_type_id'], $financial_year_id, 
                                    $allocated_days, $used_days, $remaining_days, $total_days);

            if ($insert_stmt->execute()) {
                $allocated_count++;
                $debug_info[] = "  Rule {$rule_index}: SUCCESS - Allocated: {$allocated_days}, Total: {$total_days}, Remaining: {$remaining_days}";
            } else {
                $debug_info[] = "  Rule {$rule_index}: FAILED - " . $insert_stmt->error;
                error_log("Insert failed for employee {$emp_id}, leave type {$rule['leave_type_id']}: " . $insert_stmt->error);
            }
        }

        $mysqli->commit();
        $debug_info[] = "SUMMARY: Processed 1 employee, {$rule_applications} rule applications, {$allocated_count} successful allocations";
        error_log("Employee Leave Allocation Debug Info:\n" . implode("\n", $debug_info));
        
        return $allocated_count;
        
    } catch (Exception $e) {
        if ($mysqli->in_transaction()) {
            $mysqli->rollback();
        }
        $debug_info[] = "ERROR: " . $e->getMessage();
        error_log("Employee Leave Allocation Error:\n" . implode("\n", $debug_info));
        return 0;
    }
}

$mysqli = getConnection();
$fy_status = canCreateNewFinancialYear($mysqli);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'add_financial_year') {
            if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
                $error = 'Invalid request. Please refresh and try again.';
            } else {
                $fy_status = canCreateNewFinancialYear($mysqli);
                if (!$fy_status['can_create']) {
                    $error = $fy_status['reason'] . (isset($fy_status['days_until_creation']) ? ' (Available in ' . ceil($fy_status['days_until_creation']) . ' days)' : '');
                } else {
                    $next_fy = $fy_status['next_fy'];
                    $start_date = $next_fy['start_date'];
                    $end_date = $next_fy['end_date'];
                    $year_name = $next_fy['year_name'];
                    $total_days = calculateTotalDays($start_date, $end_date);
                    
                    try {
                        $stmt = $mysqli->prepare("INSERT INTO financial_years (start_date, end_date, year_name, total_days, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
                        if (!$stmt) {
                            throw new Exception('Failed to prepare financial year insert: ' . $mysqli->error);
                        }
                        $stmt->bind_param("sssi", $start_date, $end_date, $year_name, $total_days);
                        if ($stmt->execute()) {
                            $financial_year_id = $mysqli->insert_id;
                            $allocated_count = allocateLeaveToAllEmployees($mysqli, $financial_year_id);
                            redirectWithMessage('admin.php?tab=financial', 
                                "Financial year '{$year_name}' created successfully! Leave allocated to {$allocated_count} employee-leave type combinations.", 
                                'success');
                        } else {
                            throw new Exception('Failed to create financial year: ' . $mysqli->error);
                        }
                    } catch (Exception $e) {
                        $error = 'Error creating financial year: ' . $e->getMessage();
                        error_log("Financial year creation error: " . $e->getMessage());
                    }
                }
            }
        } elseif ($action === 'allocate_employee_leave') {
            if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
                $error = 'Invalid request. Please refresh and try again.';
            } else {
                $employee_id = (int)sanitizeInput($_POST['employee_id']);
                $financial_year_id = (int)sanitizeInput($_POST['financial_year_id']);
                $leave_types = isset($_POST['leave_types']) ? array_map('intval', $_POST['leave_types']) : null;
                
                $stmt = $mysqli->prepare("SELECT CONCAT(first_name, ' ', last_name) as full_name FROM employees WHERE id = ? AND employee_status = 'active'");
                $stmt->bind_param("i", $employee_id);
                $stmt->execute();
                $employee = $stmt->get_result()->fetch_assoc();
                
                if (!$employee) {
                    $error = 'Employee not found or not active.';
                } else {
                    $allocated_count = allocateLeaveToEmployee($mysqli, $employee_id, $financial_year_id, $leave_types);
                    if ($allocated_count > 0) {
                        redirectWithMessage('admin.php?tab=financial', 
                            "Leave allocated successfully to {$employee['full_name']} for {$allocated_count} leave type(s).", 
                            'success');
                    } else {
                        $error = 'No leave allocated. Either leave already exists or an error occurred. Check logs for details.';
                    }
                }
            }
        } elseif ($action === 'debug_leave_allocation') {
            if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
                $error = 'Invalid request. Please refresh and try again.';
            } elseif (isset($_POST['fy_id']) && is_numeric($_POST['fy_id'])) {
                $fy_id = (int)$_POST['fy_id'];
                $allocated_count = allocateLeaveToAllEmployees($mysqli, $fy_id);
                $success = "Debug allocation completed. {$allocated_count} allocations made. Check error log for details.";
            } else {
                $error = 'Invalid financial year ID.';
            }
        } elseif ($action === 'edit_user') {
            if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
                $error = 'Invalid request. Please refresh and try again.';
            } else {
                $user_id = (int)sanitizeInput($_POST['id']);
                $stmt = $mysqli->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if (!$result->fetch_assoc()) {
                    $error = 'User not found.';
                } else {
                    try {
                        if (hasPermission('super_admin')) {
                            if (!empty($_POST['password'])) {
                                $password = $_POST['password'];
                                if (strlen($password) < 6) {
                                    $error = 'Password must be at least 6 characters long.';
                                } else {
                                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                                    $stmt = $mysqli->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                                    $stmt->bind_param("si", $hashedPassword, $user_id);
                                    if ($stmt->execute()) {
                                        redirectWithMessage('admin.php?tab=users', 'Password updated successfully!', 'success');
                                    } else {
                                        $error = 'Error updating password: ' . $mysqli->error;
                                    }
                                }
                            } else {
                                $error = 'Password field is required for super admin.';
                            }
                        } elseif (hasPermission('hr_manager')) {
                            $first_name = sanitizeInput($_POST['first_name']);
                            $last_name = sanitizeInput($_POST['last_name']);
                            $email = sanitizeInput($_POST['email']);
                            $role = sanitizeInput($_POST['role']);
                            $phone = sanitizeInput($_POST['phone']);
                            $address = sanitizeInput($_POST['address']);

                            $stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                            $stmt->bind_param("si", $email, $user_id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            if ($result->fetch_assoc()) {
                                $error = 'Email already exists in the system.';
                            } else {
                                $query = "UPDATE users SET first_name = ?, last_name = ?, email = ?, role = ?, phone = ?, address = ?, updated_at = NOW()";
                                $params = [$first_name, $last_name, $email, $role, $phone, $address];
                                $types = "ssssss";

                                if (!empty($_POST['password'])) {
                                    $password = $_POST['password'];
                                    if (strlen($password) < 6) {
                                        $error = 'Password must be at least 6 characters long.';
                                    } else {
                                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                                        $query .= ", password = ?";
                                        $params[] = $hashedPassword;
                                        $types .= "s";
                                    }
                                }

                                $query .= " WHERE id = ?";
                                $params[] = $user_id;
                                $types .= "i";

                                $stmt = $mysqli->prepare($query);
                                $stmt->bind_param($types, ...$params);
                                if ($stmt->execute()) {
                                    redirectWithMessage('admin.php?tab=users', 'User updated successfully!', 'success');
                                } else {
                                    $error = 'Error updating user: ' . $mysqli->error;
                                }
                            }
                        } else {
                            $error = 'Insufficient permissions to edit users.';
                        }
                    } catch (Exception $e) {
                        $error = 'Error updating user: ' . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'delete_user') {
            if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
                $error = 'Invalid request. Please refresh and try again.';
            } else {
                $user_id = (int)sanitizeInput($_POST['id']);
                if ($user_id === $user['id']) {
                    $error = 'Cannot delete your own account.';
                } else {
                    $stmt = $mysqli->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->bind_param("i", $user_id);
                    if ($stmt->execute()) {
                        if ($stmt->affected_rows > 0) {
                            redirectWithMessage('admin.php?tab=users', 'User deleted successfully!', 'success');
                        } else {
                            $error = 'User not found.';
                        }
                    } else {
                        $error = 'Error deleting user: ' . $mysqli->error;
                    }
                }
            }
        }
    }
}

// Get all users (consider pagination for large datasets)
$result = $mysqli->query("SELECT * FROM users ORDER BY first_name, last_name");
$users = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// Get all financial years
$financial_years_result = $mysqli->query("SELECT * FROM financial_years ORDER BY start_date DESC");
$financial_years = $financial_years_result ? $financial_years_result->fetch_all(MYSQLI_ASSOC) : [];

// Get employee count
$employee_count_result = $mysqli->query("SELECT COUNT(*) as count FROM employees WHERE employee_status = 'active'");
$employee_count = $employee_count_result ? $employee_count_result->fetch_assoc()['count'] : 0;

// Get leave types
$leave_types_result = $mysqli->query("SELECT * FROM leave_types ORDER BY id");
$leave_types = $leave_types_result ? $leave_types_result->fetch_all(MYSQLI_ASSOC) : [];

// Get active employees for new hire allocation
$employees_result = $mysqli->query("SELECT id, CONCAT(first_name, ' ', last_name) as full_name FROM employees WHERE employee_status = 'active' ORDER BY first_name, last_name");
$employees = $employees_result ? $employees_result->fetch_all(MYSQLI_ASSOC) : [];

function getRoleBadge(string $role): string {
    switch ($role) {
        case 'super_admin': return 'badge-danger';
        case 'hr_manager': return 'badge-warning';
        case 'dept_head': return 'badge-info';
        case 'section_head': return 'badge-secondary';
        case 'manager': return 'badge-primary';
        default: return 'badge-light';
    }
}
include 'nav_bar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - HR Management System</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Content -->
            <div class="content">
                <?php $flash = getFlashMessage(); if ($flash): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
                        <?php echo htmlspecialchars($flash['message']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                
                <div class="leave-tabs">
                    <?php if (in_array($user['role'], ['super_admin'])): ?>
                    <a href="admin.php?tab=users" class="leave-tab <?php echo $tab === 'users' ? 'active' : ''; ?>">Users</a>
                    <?php endif; ?>
                    <a href="admin.php?tab=financial" class="leave-tab <?php echo $tab === 'financial' ? 'active' : ''; ?>">Financial Year</a>
                </div>

                <?php if ($tab === 'users'): ?>
                <div style="margin-bottom: 20px;">
                    <h2>System Users (<?php echo count($users); ?>)</h2>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="8" class="text-center">No users found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user_row): ?>
                                <tr data-user='<?php echo htmlspecialchars(json_encode($user_row, JSON_HEX_QUOT | JSON_HEX_APOS)); ?>'>
                                    <td><?php echo $user_row['id']; ?></td>
                                    <td><?php echo htmlspecialchars($user_row['first_name'] . ' ' . $user_row['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user_row['email']); ?></td>
                                    <td>
                                        <span class="badge <?php echo getRoleBadge($user_row['role']); ?>">
                                            <?php echo ucwords(str_replace('_', ' ', $user_row['role'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($user_row['phone'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge badge-success">Active</span>
                                    </td>
                                    <td><?php echo formatDate($user_row['created_at']); ?></td>
                                    <td>
                                        <button onclick="showEditUserModal(this.closest('tr').dataset.user)" class="btn btn-sm btn-primary">Edit</button>
                                        <?php if ($user_row['id'] != $user['id']): ?>
                                            <button onclick="confirmDeleteUser('<?php echo $user_row['id']; ?>', '<?php echo htmlspecialchars($user_row['first_name'] . ' ' . $user_row['last_name']); ?>')" class="btn btn-sm btn-danger ml-1">Delete</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php elseif ($tab === 'financial'): ?>
                <div class="tab-content">
                    <h3>Financial Year Management</h3>
                    <p>Current Financial Year: 
                        <?php 
                        $current_fy = getCurrentFinancialYear(null, $mysqli);
                        echo htmlspecialchars($current_fy['year_name']) . " (" . formatDate($current_fy['start_date']) . " - " . formatDate($current_fy['end_date']) . ")";
                        ?></p>
                    
                    <div class="glass-card">
                        <h4>Add New Financial Year</h4>
                        
                        <?php if (!$fy_status['can_create']): ?>
                            <div class="alert alert-info">
                                <strong>Note:</strong> <?php echo $fy_status['reason']; ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="" onsubmit="return confirm('Are you sure you want to create financial year <?php echo $fy_status['can_create'] ? htmlspecialchars($fy_status['next_fy']['year_name']) : ''; ?>? This will allocate leave to all employees.');">
                            <input type="hidden" name="action" value="add_financial_year">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="start_date">Start Date</label>
                                    <input type="date" 
                                           name="start_date" 
                                           id="start_date" 
                                           class="form-control" 
                                           value="<?php echo $fy_status['can_create'] ? $fy_status['next_fy']['start_date'] : ''; ?>"
                                           readonly
                                           required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="end_date">End Date</label>
                                    <input type="date" 
                                           name="end_date" 
                                           id="end_date" 
                                           class="form-control" 
                                           value="<?php echo $fy_status['can_create'] ? $fy_status['next_fy']['end_date'] : ''; ?>"
                                           readonly
                                           required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="calculated_days">Financial Year Details</label>
                                    <input type="text" 
                                           id="calculated_days" 
                                           class="form-control" 
                                           readonly 
                                           value="<?php 
                                               if ($fy_status['can_create']) {
                                                   $days = calculateTotalDays($fy_status['next_fy']['start_date'], $fy_status['next_fy']['end_date']);
                                                   echo $fy_status['next_fy']['year_name'] . " (" . $days . " days)";
                                               } else {
                                                   echo 'Not available';
                                               }
                                           ?>"
                                           placeholder="Will be calculated automatically">
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary" <?php echo !$fy_status['can_create'] ? 'disabled' : ''; ?>>
                                    <?php echo $fy_status['can_create'] ? 'Add New Financial Year' : 'Cannot Add Financial Year'; ?>
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="location.reload()">Refresh Status</button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Allocate Leave to Employee -->
                    <div class="glass-card">
                        <h4>Allocate Leave to Employee</h4>
                        <form method="POST" action="" onsubmit="return confirm('Are you sure you want to allocate leave for this employee?');">
                            <input type="hidden" name="action" value="allocate_employee_leave">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="employee_id">Select Employee</label>
                                    <select name="employee_id" id="employee_id" class="form-control" required>
                                        <option value="">Select an employee</option>
                                        <?php foreach ($employees as $employee): ?>
                                            <option value="<?php echo $employee['id']; ?>">
                                                <?php echo htmlspecialchars($employee['full_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="financial_year_id">Financial Year</label>
                                    <select name="financial_year_id" id="financial_year_id" class="form-control" required>
                                        <option value="">Select financial year</option>
                                        <?php foreach ($financial_years as $fy): ?>
                                            <option value="<?php echo $fy['id']; ?>" <?php echo $fy['id'] == $current_fy['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($fy['year_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label>Leave Types</label>
                                    <div class="checkbox-group">
                                        <label class="checkbox-label">
                                            <input type="checkbox" id="select_all_leave_types" onclick="toggleLeaveTypes()">
                                            Select All Leave Types
                                        </label>
                                        <?php foreach ($leave_types as $leave_type): ?>
                                            <label class="checkbox-label">
                                                <input type="checkbox" name="leave_types[]" value="<?php echo $leave_type['id']; ?>" class="leave-type-checkbox">
                                                <?php echo htmlspecialchars($leave_type['name']); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Allocate Leave</button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Existing Financial Years -->
                    <div class="table-container">
                        <h3>Existing Financial Years</h3>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Year Name</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Total Days</th>
                                    <th>Status</th>
                                    <th>Current Status</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($financial_years)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center">No financial years found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($financial_years as $fy): ?>
                                    <tr>
                                        <td><?php echo $fy['id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($fy['year_name']); ?></strong></td>
                                        <td><?php echo formatDate($fy['start_date']); ?></td>
                                        <td><?php echo formatDate($fy['end_date']); ?></td>
                                        <td><?php echo $fy['total_days']; ?> days</td>
                                        <td>
                                            <span class="badge <?php echo $fy['is_active'] ? 'badge-success' : 'badge-secondary'; ?>">
                                                <?php echo $fy['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $today = date('Y-m-d');
                                            if ($today < $fy['start_date']) {
                                                echo '<span class="badge badge-info">Future</span>';
                                            } elseif ($today >= $fy['start_date'] && $today <= $fy['end_date']) {
                                                echo '<span class="badge badge-success">Current</span>';
                                            } else {
                                                echo '<span class="badge badge-secondary">Past</span>';
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo formatDate($fy['created_at']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit User</h3>
                <span class="close" onclick="hideEditUserModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" id="edit_user_id" name="id">

                <?php if ($user['role'] === 'super_admin'): ?>
                    <div class="form-group">
                        <label for="edit_password">New Password</label>
                        <input type="password" class="form-control" id="edit_password" name="password" required minlength="6">
                        <small class="form-text text-muted">Enter a new password (minimum 6 characters).</small>
                    </div>
                <?php else: ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_first_name">First Name</label>
                            <input type="text" class="form-control" id="edit_first_name" name="first_name" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_last_name">Last Name</label>
                            <input type="text" class="form-control" id="edit_last_name" name="last_name" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_email">Email</label>
                            <input type="email" class="form-control" id="edit_email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_password">New Password</label>
                            <input type="password" class="form-control" id="edit_password" name="password" placeholder="Leave blank to keep current password">
                            <small class="form-text text-muted">Leave blank to keep current password</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_role">Role</label>
                            <select class="form-control" id="edit_role" name="role" required>
                                <option value="">Select Role</option>
                                <option value="super_admin">Super Admin</option>
                                <option value="hr_manager">HR Manager</option>
                                <option value="dept_head">Department Head</option>
                                <option value="section_head">Section Head</option>
                                <option value="manager">Manager</option>
                                <option value="employee">Employee</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_phone">Phone</label>
                            <input type="text" class="form-control" id="edit_phone" name="phone">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_employee_id">Employee ID</label>
                            <input type="text" class="form-control" id="edit_employee_id" readonly>
                            <input type="hidden" name="employee_id" id="edit_employee_id_hidden">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_address">Address</label>
                        <textarea class="form-control" id="edit_address" name="address" rows="3"></textarea>
                    </div>
                <?php endif; ?>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Update User</button>
                    <button type="button" class="btn btn-secondary" onclick="hideEditUserModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showEditUserModal(userJson) {
            const user = JSON.parse(userJson);
            document.getElementById('edit_user_id').value = user.id;
            <?php if ($user['role'] !== 'super_admin'): ?>
                document.getElementById('edit_first_name').value = user.first_name;
                document.getElementById('edit_last_name').value = user.last_name;
                document.getElementById('edit_email').value = user.email;
                document.getElementById('edit_role').value = user.role;
                document.getElementById('edit_phone').value = user.phone || '';
                document.getElementById('edit_address').value = user.address || '';
                document.getElementById('edit_employee_id').value = user.employee_id || '';
                document.getElementById('edit_employee_id_hidden').value = user.employee_id || '';
            <?php endif; ?>
            document.getElementById('edit_password').value = '';
            document.getElementById('editUserModal').style.display = 'block';
        }
        
        function hideEditUserModal() {
            document.getElementById('editUserModal').style.display = 'none';
        }
        
        function confirmDeleteUser(id, name) {
            if (confirm('Are you sure you want to delete user "' + name + '"?\n\nThis action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="delete_user">' +
                                '<input type="hidden" name="id" value="' + id + '">' +
                                '<input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">';
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function toggleLeaveTypes() {
            const selectAll = document.getElementById('select_all_leave_types');
            const checkboxes = document.querySelectorAll('.leave-type-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('editUserModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>