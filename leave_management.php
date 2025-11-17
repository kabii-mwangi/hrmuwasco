<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ob_start();

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
            || $_SERVER['SERVER_PORT'] == 443
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

if (session_status() == PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,  
        'httponly' => true,
        'samesite' => 'Lax'  
    ]);
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'auth.php';
require_once 'auth_check.php';
require_once 'config.php';
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$conn = getConnection();

// Get current user from session
$user = [
    'first_name' => isset($_SESSION['user_name']) ? explode(' ', $_SESSION['user_name'])[0] : 'User',
    'last_name' => isset($_SESSION['user_name']) ? (explode(' ', $_SESSION['user_name'])[1] ?? '') : '',
    'role' => $_SESSION['user_role'] ?? 'guest',
    'id' => $_SESSION['user_id']
];

// Function to get current financial year ID (shared)
function getCurrentFinancialYearId($conn) {
    $fyStmt = $conn->prepare("SELECT id FROM financial_years WHERE end_date >= CURDATE() ORDER BY id DESC LIMIT 1");
    $fyStmt->execute();
    $fyResult = $fyStmt->get_result();
    $fy = $fyResult->fetch_assoc();
    
    if (!$fy) {
        // Fallback to latest financial year
        $fyStmt = $conn->prepare("SELECT id FROM financial_years ORDER BY id DESC LIMIT 1");
        $fyStmt->execute();
        $fyResult = $fyStmt->get_result();
        $fy = $fyResult->fetch_assoc();
        if (!$fy) {
            throw new Exception("No financial year record found.");
        }
    }
    return $fy['id'];
}

// Function to send leave notification emails
function sendLeaveNotification($applicationId, $conn, $type = 'confirmation') {
    require 'email_config.php';
    
    // Get application details
    $emailStmt = $conn->prepare("
        SELECT la.*, e.first_name, e.last_name, e.surname, e.email, lt.name as leave_type_name,
               d.name as department_name, s.name as section_name, ss.name as subsection_name,
               sh.email as section_head_email, sh.first_name as section_head_first, sh.last_name as section_head_last, sh.surname as section_head_surname,
               dh.email as dept_head_email, dh.first_name as dept_head_first, dh.last_name as dept_head_last, dh.surname as dept_head_surname,
               ssh.email as subsection_head_email, ssh.first_name as subsection_head_first, ssh.last_name as subsection_head_last, ssh.surname as subsection_head_surname,
               md.email as md_email, md.first_name as md_first, md.last_name as md_last
        FROM leave_applications la
        JOIN employees e ON la.employee_id = e.id
        JOIN leave_types lt ON la.leave_type_id = lt.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN sections s ON e.section_id = s.id
        LEFT JOIN subsections ss ON e.subsection_id = ss.id
        LEFT JOIN employees sh ON la.section_head_emp_id = sh.id
        LEFT JOIN employees dh ON la.dept_head_emp_id = dh.id
        LEFT JOIN employees ssh ON la.subsection_head_emp_id = ssh.id
        LEFT JOIN employees md ON md.id = (
            SELECT e2.id FROM employees e2
            JOIN users u2 ON u2.employee_id = e2.employee_id
            WHERE u2.role = 'managing_director' LIMIT 1
        )
        WHERE la.id = ?
    ");
    $emailStmt->bind_param("i", $applicationId);
    $emailStmt->execute();
    $emailResult = $emailStmt->get_result();
    
    if ($emailData = $emailResult->fetch_assoc()) {
        $employee_name = $emailData['first_name'] . ' ' . $emailData['last_name'];
        $leave_type = $emailData['leave_type_name'];
        $start_date = date('M d, Y', strtotime($emailData['start_date']));
        $end_date = date('M d, Y', strtotime($emailData['end_date']));
        $days = $emailData['days_requested'];
        $status = $emailData['status'];
        
        try {
            $mail = new PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_ENCRYPTION;
            $mail->Port = SMTP_PORT;
            
            $mail->setFrom(EMAIL_FROM, EMAIL_FROM_NAME);
            $mail->isHTML(true);
            
            $leave_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . 
                        "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']) . 
                        "/leave_management.php";
            
            if ($type === 'confirmation') {
                // Confirmation email to employee
                $mail->addAddress($emailData['email'], $employee_name);
                $mail->Subject = 'Leave Application Submitted Successfully';
                
                $mail->Body = "
                    <html>
                    <body style='font-family: Arial, sans-serif; line-height: 1.6;'>
                        <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 5px;'>
                            <h2 style='color: #2c3e50;'>Leave Application Confirmation</h2>
                            <p>Dear $employee_name,</p>
                            <p>Your leave application has been submitted successfully with the following details:</p>
                            <ul>
                                <li><strong>Leave Type:</strong> $leave_type</li>
                                <li><strong>Start Date:</strong> $start_date</li>
                                <li><strong>End Date:</strong> $end_date</li>
                                <li><strong>Days Requested:</strong> $days</li>
                                <li><strong>Status:</strong> " . getStatusDisplayName($status) . "</li>
                            </ul>
                            <p>You can track the status of your application in the HR Management System.</p>
                            <div style='text-align: center; margin: 25px 0;'>
                                <a href='$leave_url' style='background-color: #4CAF50; color: white; padding: 12px 24px; text-align: center; text-decoration: none; display: inline-block; border-radius: 5px; font-weight: bold;'>View Application</a>
                            </div>
                            <p>If you have any questions, please contact HR.</p>
                            <br>
                            <p>Best regards,<br>HR Management Team</p>
                            <hr style='border: none; border-top: 1px solid #e0e0e0; margin: 20px 0;'>
                            <p style='font-size: 12px; color: #7f8c8d;'>
                                This is an automated notification. Please do not reply to this email.
                            </p>
                        </div>
                    </body>
                    </html>
                ";
                
                $mail->AltBody = "Dear $employee_name,\n\nYour leave application has been submitted successfully.\n\nDetails:\n- Leave Type: $leave_type\n- Start Date: $start_date\n- End Date: $end_date\n- Days Requested: $days\n- Status: " . getStatusDisplayName($status) . "\n\nYou can track the status of your application at: $leave_url\n\nIf you have any questions, please contact HR.\n\nBest regards,\nHR Management Team";
            } else {
                // Notification to approver
                $approver_email = null;
                $approver_name = '';
                
                switch ($status) {
                    case 'pending_subsection_head':
                        $approver_email = $emailData['subsection_head_email'] ?? null;
                        $approver_name = ($emailData['subsection_head_first'] ?? '') . ' ' . ($emailData['subsection_head_last'] ?? '');
                        break;
                    case 'pending_section_head':
                        $approver_email = $emailData['section_head_email'];
                        $approver_name = $emailData['section_head_first'] . ' ' . $emailData['section_head_last'];
                        break;
                    case 'pending_dept_head':
                        $approver_email = $emailData['dept_head_email'];
                        $approver_name = $emailData['dept_head_first'] . ' ' . $emailData['dept_head_last'];
                        break;
                    case 'pending_managing_director':
                        $approver_email = $emailData['md_email'];
                        $approver_name = $emailData['md_first'] . ' ' . $emailData['md_last'];
                        break;
                }
                
                if ($approver_email && $approver_name) {
                    $mail->addAddress($approver_email, $approver_name);
                    $mail->Subject = 'New Leave Application Awaiting Your Approval';
                    
                    $mail->Body = "
                        <html>
                        <body style='font-family: Arial, sans-serif; line-height: 1.6;'>
                            <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 5px;'>
                                <h2 style='color: #2c3e50;'>New Leave Application Notification</h2>
                                <p>Dear $approver_name,</p>
                                <p>A new leave application from $employee_name requires your approval:</p>
                                <ul>
                                    <li><strong>Employee:</strong> $employee_name</li>
                                    <li><strong>Leave Type:</strong> $leave_type</li>
                                    <li><strong>Start Date:</strong> $start_date</li>
                                    <li><strong>End Date:</strong> $end_date</li>
                                    <li><strong>Days Requested:</strong> $days</li>
                                    <li><strong>Department:</strong> " . ($emailData['department_name'] ?? 'N/A') . "</li>
                                    <li><strong>Section:</strong> " . ($emailData['section_name'] ?? 'N/A') . "</li>
                                </ul>
                                <p>Please review and approve or reject this application in the HR Management System.</p>
                                <div style='text-align: center; margin: 25px 0;'>
                                    <a href='$leave_url' style='background-color: #4CAF50; color: white; padding: 12px 24px; text-align: center; text-decoration: none; display: inline-block; border-radius: 5px; font-weight: bold;'>Review Application</a>
                                </div>
                                <p>If you have any questions, please contact HR.</p>
                                <br>
                                <p>Best regards,<br>HR Management Team</p>
                                <hr style='border: none; border-top: 1px solid #e0e0e0; margin: 20px 0;'>
                                <p style='font-size: 12px; color: #7f8c8d;'>
                                    This is an automated notification. Please do not reply to this email.
                                </p>
                            </div>
                        </body>
                        </html>
                    ";
                    
                    $mail->AltBody = "Dear $approver_name,\n\nA new leave application from $employee_name requires your approval.\n\nDetails:\n- Employee: $employee_name\n- Leave Type: $leave_type\n- Start Date: $start_date\n- End Date: $end_date\n- Days Requested: $days\n- Department: " . ($emailData['department_name'] ?? 'N/A') . "\n- Section: " . ($emailData['section_name'] ?? 'N/A') . "\n\nPlease review at: $leave_url\n\nIf you have any questions, please contact HR.\n\nBest regards,\nHR Management Team";
                } else {
                    error_log("No valid approver email found for application ID: $applicationId, status: $status");
                    return false;
                }
            }
            
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Email could not be sent for application ID: $applicationId. Mailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }
    return false;
}

function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return false;
}

function calculateBusinessDays($startDate, $endDate, $conn, $includeWeekends = false) {
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $days = 0;

    // Get holidays from database
    $holidayQuery = "SELECT date FROM holidays WHERE date BETWEEN ? AND ?";
    $stmt = $conn->prepare($holidayQuery);
    $stmt->bind_param("ss", $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $holidays = [];
    while ($row = $result->fetch_assoc()) {
        $holidays[] = $row['date'];
    }

    // Check if leave type counts weekends
    $typeId = $_POST['leave_type_id'] ?? null;
    if ($typeId) {
        $typeStmt = $conn->prepare("SELECT counts_weekends FROM leave_types WHERE id = ?");
        $typeStmt->bind_param("i", $typeId);
        $typeStmt->execute();
        $typeResult = $typeStmt->get_result();
        $type = $typeResult->fetch_assoc();

        $includeWeekends = ($type['counts_weekends'] == 1);
    }

    $current = clone $start;
    while ($current <= $end) {
        $dayOfWeek = $current->format('N'); // 1 = Monday, 7 = Sunday
        $currentDate = $current->format('Y-m-d');

        // Skip weekends if not included
        if (!$includeWeekends && ($dayOfWeek == 6 || $dayOfWeek == 7)) {
            $current->add(new DateInterval('P1D'));
            continue;
        }

        // Skip holidays
        if (!in_array($currentDate, $holidays)) {
            $days++;
        }

        $current->add(new DateInterval('P1D'));
    }

    return $days;
}

function getLeaveTypeDetails($leaveTypeId, $conn) {
    $query = "SELECT * FROM leave_types WHERE id = ? AND is_active = 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $leaveTypeId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getLeaveTypeBalance($employeeId, $leaveTypeId, $conn) {
    // First, get leave type details
    $leaveType = getLeaveTypeDetails($leaveTypeId, $conn);
    if (!$leaveType) {
        return [
            'allocated' => 0, 
            'used' => 0, 
            'remaining' => 0,
            'total_days' => 0,
            'leave_type_id' => $leaveTypeId,
            'leave_type_name' => 'Unknown',
            'counts_weekends' => 0,
            'deducted_from_annual' => 0
        ];
    }

    // Get current financial year ID
    $current_fy_id = getCurrentFinancialYearId($conn);
    
    // Check employee_leave_balances table - Use total_days for allocation
    $query = "SELECT total_days, allocated_days as allocated, used_days as used, remaining_days as remaining
              FROM employee_leave_balances 
              WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("SQL Error in getLeaveTypeBalance: " . $conn->error);
        return [
            'total_days' => 0,
            'allocated' => 0,
            'used' => 0,
            'remaining' => 0,
            'leave_type_id' => $leaveTypeId,
            'leave_type_name' => $leaveType['name'],
            'counts_weekends' => $leaveType['counts_weekends'] ?? 0,
            'deducted_from_annual' => $leaveType['deducted_from_annual'] ?? 0
        ];
    }

    $stmt->bind_param("iii", $employeeId, $leaveTypeId, $current_fy_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        return [
            'total_days' => (int)$row['total_days'],
            'allocated' => (float)$row['allocated'],
            'used' => (float)$row['used'],
            'remaining' => (float)$row['remaining'],
            'leave_type_id' => $leaveTypeId,
            'leave_type_name' => $leaveType['name'],
            'counts_weekends' => $leaveType['counts_weekends'] ?? 0,
            'deducted_from_annual' => $leaveType['deducted_from_annual'] ?? 0
        ];
    }

    return [
        'total_days' => 0,
        'allocated' => 0,
        'used' => 0,
        'remaining' => 0,
        'leave_type_id' => $leaveTypeId,
        'leave_type_name' => $leaveType['name'],
        'counts_weekends' => $leaveType['counts_weekends'] ?? 0,
        'deducted_from_annual' => $leaveType['deducted_from_annual'] ?? 0
    ];
}

function getAnnualLeaveTypeId($conn) {
    $stmt = $conn->prepare("SELECT id FROM leave_types WHERE name LIKE '%annual%' LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['id'];
    }
    return 1; // Default to ID 1 if not found
}

function getAnnualLeaveBalance($employeeId, $conn) {
    $annualTypeId = getAnnualLeaveTypeId($conn);
    return getLeaveTypeBalance($employeeId, $annualTypeId, $conn);
}

function calculateLeaveDeduction($employeeId, $leaveTypeId, $requestedDays, $conn) {
    $leaveType = getLeaveTypeDetails($leaveTypeId, $conn);
    
    $deductionPlan = [
        'primary_deduction' => 0,
        'annual_deduction' => 0,
        'unpaid_days' => 0,
        'warnings' => [],
        'is_valid' => true,
        'total_days' => $requestedDays
    ];

    if (!$leaveType) {
        $deductionPlan['is_valid'] = false;
        $deductionPlan['warnings'][] = "Invalid leave type selected.";
        return $deductionPlan;
    }

    // Handle special case for "claim a day" (id: 9)
    if ($leaveTypeId == 9) { // claim a day
        $deductionPlan['is_valid'] = true;
        $deductionPlan['warnings'][] = "✅ This will add {$requestedDays} days to your annual leave upon approval.";
        $deductionPlan['add_to_annual'] = $requestedDays;
        return $deductionPlan;
    }

    // Handle special case for "leave of absence" (id: 8)
    if ($leaveTypeId == 8) { // leave of absence
        $deductionPlan['is_valid'] = true;
        $deductionPlan['warnings'][] = "ℹ️ You will be absent for {$requestedDays} days.";
        $deductionPlan['unpaid_days'] = $requestedDays;
        return $deductionPlan;
    }

    // Check for unlimited leave types using total_days == 0
    $balance = getLeaveTypeBalance($employeeId, $leaveTypeId, $conn);
    if ($balance['total_days'] == 0) {
        $deductionPlan['warnings'][] = "Unlimited leave type—no balance deduction required.";
        $deductionPlan['is_valid'] = true;
        return $deductionPlan;
    }

    // Get the actual leave balance
    $availablePrimaryBalance = $balance['remaining'];

    if ($requestedDays <= $availablePrimaryBalance) {
        // Sufficient balance in primary leave type
        $deductionPlan['primary_deduction'] = $requestedDays;
        $deductionPlan['warnings'][] = "Will be deducted from {$leaveType['name']} balance.";
    } else {
        // Insufficient balance in primary leave type
        $primaryUsed = min($availablePrimaryBalance, $requestedDays);
        $remainingDays = $requestedDays - $primaryUsed;

        $deductionPlan['primary_deduction'] = $primaryUsed;
        
        if ($primaryUsed > 0) {
            $deductionPlan['warnings'][] = "{$primaryUsed} days from {$leaveType['name']}.";
        }

        // Check if fallback to annual leave is allowed
        if ($leaveType['deducted_from_annual'] == 1 && $remainingDays > 0) {
            $annualBalance = getAnnualLeaveBalance($employeeId, $conn);
            $availableAnnualBalance = $annualBalance['remaining'];
            
            $annualUsed = min($availableAnnualBalance, $remainingDays);
            $deductionPlan['annual_deduction'] = $annualUsed;
            $remainingDays -= $annualUsed;
            
            if ($annualUsed > 0) {
                $deductionPlan['warnings'][] = "{$annualUsed} days from Annual Leave.";
            }
        }

        // Remaining days become unpaid
        if ($remainingDays > 0) {
            $deductionPlan['unpaid_days'] = $remainingDays;
            $deductionPlan['warnings'][] = "{$remainingDays} days will be unpaid.";
        }
    }

    return $deductionPlan;
}

function logLeaveTransaction($applicationId, $employeeId, $leaveTypeId, $days, $deductionPlan, $conn) {
    $transactionData = [
        'primary_leave_type' => $leaveTypeId,
        'primary_days' => $deductionPlan['primary_deduction'],
        'annual_days' => $deductionPlan['annual_deduction'],
        'unpaid_days' => $deductionPlan['unpaid_days'],
        'warnings' => implode('; ', $deductionPlan['warnings'])
    ];

    $query = "INSERT INTO leave_transactions 
              (application_id, employee_id, transaction_date, transaction_type, details) 
              VALUES (?, ?, NOW(), 'deduction', ?)";
    $stmt = $conn->prepare($query);
    $details = json_encode($transactionData);
    $stmt->bind_param("iis", $applicationId, $employeeId, $details);
    return $stmt->execute();
}

function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input ?? '')));
}

function getStatusDisplayName($status) {
    switch ($status) {
        case 'approved': return 'Approved';
        case 'rejected': return 'Rejected';
        case 'pending': return 'Pending';
        case 'pending_subsection_head': return 'Pending Subsection Head Approval';
        case 'pending_section_head': return 'Pending Section Head Approval';
        case 'pending_dept_head': return 'Pending Department Head Approval';
        case 'pending_managing_director': return 'Pending Managing Director Approval';
        case 'pending_hr': return 'Pending HR Approval';
        case 'pending_bod_chair': return 'Pending BOD Chair Approval';
        default: return ucfirst($status);
    }
}

// Check if user is currently on leave
function isCurrentlyOnLeave($employeeId, $conn) {
    $today = date('Y-m-d');
    
    $query = "SELECT id, start_date, end_date, status, leave_type_id 
              FROM leave_applications 
              WHERE employee_id = ? 
              AND ? BETWEEN start_date AND end_date
              AND status = 'approved'";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $employeeId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

// Check if user has pending applications
function hasPendingApplications($employeeId, $conn) {
    $query = "SELECT id, start_date, end_date, status, leave_type_id 
              FROM leave_applications 
              WHERE employee_id = ? 
              AND status IN ('pending_subsection_head', 'pending_section_head', 'pending_dept_head', 'pending_managing_director', 'pending_hr', 'pending_bod_chair')";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $pendingApplications = [];
    while ($row = $result->fetch_assoc()) {
        $pendingApplications[] = $row;
    }
    
    return $pendingApplications;
}

// Check for overlapping leave applications
function hasOverlappingLeave($employeeId, $startDate, $endDate, $conn, $excludeApplicationId = null) {
    $query = "SELECT id, start_date, end_date, status, leave_type_id 
              FROM leave_applications 
              WHERE employee_id = ? 
              AND ((start_date BETWEEN ? AND ?) 
                   OR (end_date BETWEEN ? AND ?) 
                   OR (? BETWEEN start_date AND end_date) 
                   OR (? BETWEEN start_date AND end_date))
              AND status IN ('pending_subsection_head', 'pending_section_head', 'pending_dept_head', 'pending_managing_director', 'pending_hr', 'pending_bod_chair', 'approved')";
    
    $params = [$employeeId, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate];
    $types = "issssss";
    
    if ($excludeApplicationId) {
        $query .= " AND id != ?";
        $params[] = $excludeApplicationId;
        $types .= "i";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $overlappingLeaves = [];
    while ($row = $result->fetch_assoc()) {
        $overlappingLeaves[] = $row;
    }
    
    return $overlappingLeaves;
}

// Function to determine initial workflow status
function determineInitialWorkflowStatus($targetEmployeeId, $applicantUserId, $conn) {
    // Get target employee details
    $targetQuery = "SELECT e.*, u.role as user_role 
                   FROM employees e 
                   LEFT JOIN users u ON u.employee_id = e.employee_id 
                   WHERE e.id = ?";
    $stmt = $conn->prepare($targetQuery);
    $stmt->bind_param("i", $targetEmployeeId);
    $stmt->execute();
    $targetEmployee = $stmt->get_result()->fetch_assoc();
    
    if (!$targetEmployee) {
        return 'pending_hr';
    }
    
    // Get applicant's role
    $applicantQuery = "SELECT role FROM users WHERE id = ?";
    $stmt = $conn->prepare($applicantQuery);
    $stmt->bind_param("i", $applicantUserId);
    $stmt->execute();
    $applicantData = $stmt->get_result()->fetch_assoc();
    $applicantRole = $applicantData['role'] ?? 'employee';
    
    // Get managers for the target employee
    $managersQuery = "SELECT
        e.subsection_id, e.section_id, e.department_id,
        (SELECT e2.id FROM employees e2 
         JOIN users u2 ON u2.employee_id = e2.employee_id 
         WHERE e2.subsection_id = e.subsection_id 
         AND u2.role = 'sub_section_head' LIMIT 1) as subsection_head_emp_id,
        (SELECT e3.id FROM employees e3 
         JOIN users u3 ON u3.employee_id = e3.employee_id 
         WHERE e3.section_id = e.section_id 
         AND u3.role = 'section_head' LIMIT 1) as section_head_emp_id,
        (SELECT e4.id FROM employees e4 
         JOIN users u4 ON u4.employee_id = e4.employee_id 
         WHERE e4.department_id = e.department_id 
         AND u4.role = 'dept_head' LIMIT 1) as dept_head_emp_id
        FROM employees e WHERE e.id = ?";
    $stmt = $conn->prepare($managersQuery);
    $stmt->bind_param("i", $targetEmployeeId);
    $stmt->execute();
    $managers = $stmt->get_result()->fetch_assoc();
    
    $targetRole = $targetEmployee['user_role'] ?? 'employee';
    $isSelfApplication = ($targetEmployeeId == getEmployeeIdFromUserId($applicantUserId, $conn));
    
    // HR Manager applying for themselves - auto-approve
    if ($targetRole === 'hr_manager' && $isSelfApplication) {
        return 'approved';
    }
    
    // Managing Director applying for themselves
    if ($targetRole === 'managing_director' && $isSelfApplication) {
        return 'pending_bod_chair';
    }
    
    // Department Head or Manager applying for themselves
    if (($targetRole === 'dept_head' || $targetRole === 'manager') && $isSelfApplication) {
        return 'pending_managing_director';
    }
    
    // Section Head applying for themselves
    if ($targetRole === 'section_head' && $isSelfApplication) {
        return 'pending_dept_head';
    }
    
    // Subsection Head applying for themselves
    if ($targetRole === 'sub_section_head' && $isSelfApplication) {
        return 'pending_section_head';
    }
    
    // Workflow based on target employee's role (not self-application or regular employee/officer)
    
    // Officer with subsection
    if ($targetRole === 'officer' && $managers['subsection_id'] && $managers['subsection_head_emp_id']) {
        return 'pending_subsection_head';
    }
    
    // Officer without subsection (but has section)
    if ($targetRole === 'officer' && !$managers['subsection_id'] && $managers['section_id'] && $managers['section_head_emp_id']) {
        return 'pending_section_head';
    }
    
    // Subsection Head (when someone else is applying for them)
    if ($targetRole === 'sub_section_head' && !$isSelfApplication) {
        return 'pending_section_head';
    }
    
    // Section Head (when someone else is applying for them)
    if ($targetRole === 'section_head' && !$isSelfApplication) {
        return 'pending_dept_head';
    }
    
    // Department Head or Manager (when someone else is applying for them)
    if (($targetRole === 'dept_head' || $targetRole === 'manager') && !$isSelfApplication) {
        return 'pending_managing_director';
    }

    // HR Manager (when someone else is applying for them)
    if (($targetRole === 'hr_manager' ) && !$isSelfApplication) {
        return 'pending_managing_director';
    }
    
    // Managing Director (when someone else is applying for them)
    if ($targetRole === 'managing_director' && !$isSelfApplication) {
        return 'pending_bod_chair';
    }
    
    // Default workflow for regular employees (no specific role)
    // Check if employee has subsection
    if ($managers['subsection_id'] && $managers['subsection_head_emp_id']) {
        return 'pending_subsection_head';
    }
    // Check if employee has section (no subsection)
    elseif ($managers['section_id'] && $managers['section_head_emp_id']) {
        return 'pending_section_head';
    }
    // Check if employee has department (no section)
    elseif ($managers['department_id'] && $managers['dept_head_emp_id']) {
        return 'pending_managing_director';
    }
    // Default to HR
    else {
        return 'pending_hr';
    }
}

// Helper function to get employee ID from user ID
function getEmployeeIdFromUserId($userId, $conn) {
    $query = "SELECT e.id FROM employees e 
              JOIN users u ON u.employee_id = e.employee_id 
              WHERE u.id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['id'];
    }
    return null;
}

// Get user's employee record for auto-filling
$userEmployeeQuery = "SELECT e.* FROM employees e 
                      LEFT JOIN users u ON u.employee_id = e.employee_id 
                      WHERE u.id = ?";
$stmt = $conn->prepare($userEmployeeQuery);
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$userEmployee = $stmt->get_result()->fetch_assoc();

// Initialize variables
$success = '';
$error = '';
$employees = [];

// Fetch employees based on role
try {
    if (in_array($user['role'], ['hr_manager', 'super_admin', 'managing_director'])) {
        // HR Manager, Super Admin, and Managing Director can see all employees
        $employeesQuery = "SELECT e.*, d.name as department_name, s.name as section_name, ss.name as subsection_name 
                          FROM employees e 
                          LEFT JOIN departments d ON e.department_id = d.id 
                          LEFT JOIN sections s ON e.section_id = s.id 
                          LEFT JOIN subsections ss ON e.subsection_id = ss.id
                          ORDER BY e.first_name, e.last_name";
        $employees = $conn->query($employeesQuery)->fetch_all(MYSQLI_ASSOC);
    } elseif ($user['role'] === 'dept_head' && $userEmployee) {
        // Department Head can see employees in their department
        $employeesQuery = "SELECT e.*, d.name as department_name, s.name as section_name, ss.name as subsection_name 
                          FROM employees e 
                          LEFT JOIN departments d ON e.department_id = d.id 
                          LEFT JOIN sections s ON e.section_id = s.id 
                          LEFT JOIN subsections ss ON e.subsection_id = ss.id
                          WHERE e.department_id = ?
                          ORDER BY e.first_name, e.last_name";
        $stmt = $conn->prepare($employeesQuery);
        $stmt->bind_param("i", $userEmployee['department_id']);
        $stmt->execute();
        $employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } elseif ($user['role'] === 'section_head' && $userEmployee) {
        // Section Head can see employees in their section
        $employeesQuery = "SELECT e.*, d.name as department_name, s.name as section_name, ss.name as subsection_name 
                          FROM employees e 
                          LEFT JOIN departments d ON e.department_id = d.id 
                          LEFT JOIN sections s ON e.section_id = s.id 
                          LEFT JOIN subsections ss ON e.subsection_id = ss.id
                          WHERE e.section_id = ?
                          ORDER BY e.first_name, e.last_name";
        $stmt = $conn->prepare($employeesQuery);
        $stmt->bind_param("i", $userEmployee['section_id']);
        $stmt->execute();
        $employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } elseif ($user['role'] === 'sub_section_head' && $userEmployee) {
        // Subsection Head can see employees in their subsection
        $employeesQuery = "SELECT e.*, d.name as department_name, s.name as section_name, ss.name as subsection_name 
                          FROM employees e 
                          LEFT JOIN departments d ON e.department_id = d.id 
                          LEFT JOIN sections s ON e.section_id = s.id 
                          LEFT JOIN subsections ss ON e.subsection_id = ss.id
                          WHERE e.subsection_id = ?
                          ORDER BY e.first_name, e.last_name";
        $stmt = $conn->prepare($employeesQuery);
        $stmt->bind_param("i", $userEmployee['subsection_id']);
        $stmt->execute();
        $employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

} catch (Exception $e) {
    $error = "Error fetching data: " . $e->getMessage();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid security token. Please refresh and try again.";
    } else {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'apply_leave':
                // Force self for non-HR roles
                if (!in_array($user['role'], ['hr_manager', 'super_admin', 'managing_director'])) {
                    $employeeId = $userEmployee['id'] ?? 0;
                } else {
                    $employeeId = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : ($userEmployee['id'] ?? 0);
                }
                $leaveTypeId = (int)$_POST['leave_type_id'];
                $startDate = $_POST['start_date'];
                $endDate = $_POST['end_date'];
                $reason = sanitizeInput($_POST['reason']);

                // Validate required fields
                if (!$employeeId || !$leaveTypeId || !$startDate || !$endDate) {
                    $error = "Please fill in all required fields.";
                    break;
                }

                // Validate leave application
                $currentLeave = isCurrentlyOnLeave($employeeId, $conn);
                if ($currentLeave) {
                    $error = "Cannot apply for leave: Employee is currently on approved leave from " . 
                            date('M d, Y', strtotime($currentLeave['start_date'])) . " to " . 
                            date('M d, Y', strtotime($currentLeave['end_date'])) . ".";
                    break;
                }

                $pendingApplications = hasPendingApplications($employeeId, $conn);
                if (!empty($pendingApplications)) {
                    $pendingDates = [];
                    foreach ($pendingApplications as $pending) {
                        $pendingDates[] = date('M d, Y', strtotime($pending['start_date'])) . " to " . 
                                         date('M d, Y', strtotime($pending['end_date']));
                    }
                    $error = "Cannot apply for leave: Employee has " . count($pendingApplications) . 
                            " pending leave application(s): " . implode("; ", $pendingDates) . ". Please wait for existing applications to be processed.";
                    break;
                }

                $overlappingLeaves = hasOverlappingLeave($employeeId, $startDate, $endDate, $conn);
                if (!empty($overlappingLeaves)) {
                    $overlappingDates = [];
                    foreach ($overlappingLeaves as $overlap) {
                        $overlappingDates[] = date('M d, Y', strtotime($overlap['start_date'])) . " to " . 
                                             date('M d, Y', strtotime($overlap['end_date'])) . " (" . 
                                             getStatusDisplayName($overlap['status']) . ")";
                    }
                    $error = "Cannot apply for leave: Date range conflicts with existing leave: " . 
                            implode("; ", $overlappingDates) . ". Please choose different dates.";
                    break;
                }

                // Get leave type details for calculation
                $leaveType = getLeaveTypeDetails($leaveTypeId, $conn);
                if (!$leaveType) {
                    $error = "Invalid leave type selected.";
                    break;
                }

                // Calculate days based on leave type settings
                $days = calculateBusinessDays($startDate, $endDate, $conn, $leaveType['counts_weekends'] == 0);
                
                // Check for annual leave with less than 15 days
                if ($leaveTypeId == 1 && $days < 15) {
                    $error = "Annual leave requires at least 15 days. Please apply for Short Leave instead.";
                    break;
                }

                // Calculate deduction plan
                $deductionPlan = calculateLeaveDeduction($employeeId, $leaveTypeId, $days, $conn);
                if (!$deductionPlan['is_valid']) {
                    $error = implode(' ', $deductionPlan['warnings']);
                    break;
                }

               // CORRECT INSERT WITH MANUAL ID GENERATION
try {
    $conn->begin_transaction();

    // Get the next available ID manually
    $idQuery = "SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM leave_applications";
    $idResult = $conn->query($idQuery);
    $nextId = $idResult->fetch_assoc()['next_id'];

    // Get the managers for this employee
    $getManagersQuery = "SELECT
        e.section_id, e.department_id, e.subsection_id,
        (SELECT e2.id FROM employees e2 
         JOIN users u2 ON u2.employee_id = e2.employee_id 
         WHERE e2.subsection_id = e.subsection_id 
         AND u2.role = 'sub_section_head' LIMIT 1) as subsection_head_emp_id,
        (SELECT e3.id FROM employees e3 
         JOIN users u3 ON u3.employee_id = e3.employee_id 
         WHERE e3.section_id = e.section_id 
         AND u3.role = 'section_head' LIMIT 1) as section_head_emp_id,
        (SELECT e4.id FROM employees e4 
         JOIN users u4 ON u4.employee_id = e4.employee_id 
         WHERE e4.department_id = e.department_id 
         AND u4.role = 'dept_head' LIMIT 1) as dept_head_emp_id
        FROM employees e WHERE e.id = ?";
    $stmt = $conn->prepare($getManagersQuery);
    $stmt->bind_param("i", $employeeId);
    $stmt->execute();
    $managersResult = $stmt->get_result();
    $managers = $managersResult->fetch_assoc();

    $subsectionHeadEmpId = $managers['subsection_head_emp_id'] ?? null;
    $sectionHeadEmpId = $managers['section_head_emp_id'] ?? null;
    $deptHeadEmpId = $managers['dept_head_emp_id'] ?? null;

    // Determine initial status
    $initialStatus = determineInitialWorkflowStatus($employeeId, $user['id'], $conn);

    // Validate and fallback statuses
    if ($initialStatus === 'pending_subsection_head' && !$subsectionHeadEmpId) {
        $initialStatus = 'pending_section_head';
    } elseif ($initialStatus === 'pending_section_head' && !$sectionHeadEmpId) {
        $initialStatus = 'pending_dept_head';
    } elseif ($initialStatus === 'pending_dept_head' && !$deptHeadEmpId) {
        $initialStatus = 'pending_managing_director';
    }

    // MODIFIED INSERT STATEMENT WITH MANUAL ID
    $insertQuery = "INSERT INTO leave_applications 
                   (id, employee_id, leave_type_id, start_date, end_date, days_requested, reason, 
                    status, applied_at, subsection_head_emp_id, section_head_emp_id, dept_head_emp_id,
                    primary_days, annual_days, unpaid_days, applied_by_user_id) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($insertQuery);
    
    // Prepare parameters WITH ID
    $subsectionHeadEmpId = $subsectionHeadEmpId !== null ? $subsectionHeadEmpId : null;
    $sectionHeadEmpId = $sectionHeadEmpId !== null ? $sectionHeadEmpId : null;
    $deptHeadEmpId = $deptHeadEmpId !== null ? $deptHeadEmpId : null;

    $params = [
        $nextId, // Manually generated ID
        $employeeId,
        $leaveTypeId,
        $startDate,
        $endDate,
        $days,
        $reason,
        $initialStatus,
        $subsectionHeadEmpId,
        $sectionHeadEmpId,
        $deptHeadEmpId,
        $deductionPlan['primary_deduction'],
        $deductionPlan['annual_deduction'],
        $deductionPlan['unpaid_days'],
        $user['id']
    ];

    // 16 parameters for 16 placeholders (added ID)
    $types = 'iiisssisiiidddi';

    // Debug logging
    error_log("Insert Parameters Count: " . count($params));
    error_log("Types String Length: " . strlen($types));
    error_log("Next ID: " . $nextId);

    if ($stmt->bind_param($types, ...$params)) {
        if ($stmt->execute()) {
            $applicationId = $nextId; // Use our manually generated ID
            error_log("SUCCESS: Application inserted with ID: " . $applicationId);

            // Handle auto-approval for HR Manager
            if ($initialStatus === 'approved') {
                $updateStatusStmt = $conn->prepare("UPDATE leave_applications SET status = 'approved' WHERE id = ?");
                $updateStatusStmt->bind_param("i", $applicationId);
                $updateStatusStmt->execute();

                // Update leave balances
                if ($deductionPlan['primary_deduction'] > 0) {
                    $updatePrimaryQuery = "UPDATE employee_leave_balances 
                                          SET used_days = used_days + ?, 
                                              remaining_days = remaining_days - ?
                                          WHERE employee_id = ? 
                                          AND leave_type_id = ? 
                                          AND financial_year_id = ?";
                    $stmt = $conn->prepare($updatePrimaryQuery);
                    $current_fy_id = getCurrentFinancialYearId($conn);
                    $stmt->bind_param("ddiii", 
                        $deductionPlan['primary_deduction'], 
                        $deductionPlan['primary_deduction'],
                        $employeeId, 
                        $leaveTypeId, 
                        $current_fy_id
                    );
                    $stmt->execute();
                }
                
                if (isset($deductionPlan['add_to_annual']) && $deductionPlan['add_to_annual'] > 0) {
                    $annualTypeId = getAnnualLeaveTypeId($conn);
                    $updateAnnualQuery = "UPDATE employee_leave_balances 
                                         SET total_days = total_days + ?, 
                                             remaining_days = remaining_days + ?
                                         WHERE employee_id = ? 
                                         AND leave_type_id = ? 
                                         AND financial_year_id = ?";
                    $stmt = $conn->prepare($updateAnnualQuery);
                    $current_fy_id = getCurrentFinancialYearId($conn);
                    $stmt->bind_param("ddiii", 
                        $deductionPlan['add_to_annual'], 
                        $deductionPlan['add_to_annual'],
                        $employeeId, 
                        $annualTypeId, 
                        $current_fy_id
                    );
                    $stmt->execute();
                } elseif ($deductionPlan['annual_deduction'] > 0) {
                    $annualTypeId = getAnnualLeaveTypeId($conn);
                    $updateAnnualQuery = "UPDATE employee_leave_balances 
                                         SET used_days = used_days + ?, 
                                             remaining_days = remaining_days - ?
                                         WHERE employee_id = ? 
                                         AND leave_type_id = ? 
                                         AND financial_year_id = ?";
                    $stmt = $conn->prepare($updateAnnualQuery);
                    $current_fy_id = getCurrentFinancialYearId($conn);
                    $stmt->bind_param("ddiii", 
                        $deductionPlan['annual_deduction'], 
                        $deductionPlan['annual_deduction'],
                        $employeeId, 
                        $annualTypeId, 
                        $current_fy_id
                    );
                    $stmt->execute();
                }
            }

            // Log the transaction
            logLeaveTransaction($applicationId, $employeeId, $leaveTypeId, $days, $deductionPlan, $conn);

            // Log to leave_history
            $historyStmt = $conn->prepare("INSERT INTO leave_history 
                                          (leave_application_id, action, performed_by, comments, performed_at) 
                                          VALUES (?, ?, ?, ?, NOW())");
            $historyAction = ($initialStatus === 'approved') ? 'auto-approved' : 'applied';
            $comment = ($initialStatus === 'approved') 
                ? "Leave application auto-approved (HR Manager self-approval) for $days days" 
                : "Leave application submitted for $days days";
            $historyStmt->bind_param("isis", $applicationId, $historyAction, $user['id'], $comment);
            $historyStmt->execute();

            // Send emails
            $employeeEmailSent = sendLeaveNotification($applicationId, $conn, 'confirmation');
            $approverEmailSent = true;
            if ($initialStatus !== 'approved') {
                $approverEmailSent = sendLeaveNotification($applicationId, $conn, 'approver');
            }

            $conn->commit();
            
            if ($initialStatus === 'approved') {
                $_SESSION['flash_message'] = "Leave application submitted and auto-approved successfully!";
                $_SESSION['flash_type'] = "success";
            } else {
                $_SESSION['flash_message'] = "Leave application submitted successfully!" . 
                           ($employeeEmailSent ? " Confirmation email sent." : "") . 
                           ($approverEmailSent ? " Approver notified." : "");
                $_SESSION['flash_type'] = "success";
            }
            
            // Clear form
            $_POST = [];
            
        } else {
            throw new Exception("Execute failed: " . $stmt->error);
        }
    } else {
        throw new Exception("Bind failed: " . $stmt->error);
    }
} catch (Exception $e) {
    $conn->rollback();
    $error = "Database error: " . $e->getMessage();
    error_log("Leave application error: " . $e->getMessage());
}
                break;
                
        } // END SWITCH
    } // END ELSE (CSRF check)
} // END POST check

// Include header and navigation
include 'header.php';
include 'nav_bar.php';
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply Leave - HR Management System</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <div class="main-content">
        <!-- Header -->
        <div class="leave-tabs">
            <a href="leave_management.php" class="leave-tab active">Apply Leave</a>
            <?php if (in_array($user['role'], ['hr_manager', 'dept_head', 'section_head', 'subsection_head', 'manager', 'managing_director','super_admin'])): ?>
            <a href="manage.php" class="leave-tab">Manage Leave</a>
            <?php endif; ?>
            <?php if(in_array($user['role'], ['hr_manager', 'super_admin', 'manager','managing_director'])): ?>
            <a href="history.php" class="leave-tab">Leave History</a>
            <a href="holidays.php" class="leave-tab">Holidays</a>
            <?php endif; ?>
            <a href="profile.php" class="leave-tab ">My Leave Profile</a>
        </div>
<!-- Apply Leave Tab -->
<div class="tab-content">
    <h3>Apply for Leave</h3>
    
    <?php if ($flash = getFlashMessage()): ?>
        <div class="alert alert-<?php echo $flash['type']; ?>">
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($userEmployee || !empty($employees)): ?>
        <form method="POST" action="" id="leaveApplicationForm">
            <input type="hidden" name="action" value="apply_leave">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="employee_id">Employee</label>
                    <select id="employee_id" name="employee_id" class="form-control" required>
                        <option value="">Select Employee</option>
                        <?php if (!empty($employees)): ?>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>">
                                    <?php 
                                    $locationInfo = (!empty($emp['subsection_name']) 
                                        ? ' - ' . $emp['subsection_name'] 
                                        : (!empty($emp['section_name']) 
                                            ? ' - ' . $emp['section_name'] 
                                            : (!empty($emp['department_name']) 
                                                ? ' - ' . $emp['department_name'] 
                                                : '')
                                        )
                                    );
                                    echo htmlspecialchars(
                                        $emp['employee_id'] . ' - ' .
                                        $emp['first_name'] . ' ' .
                                        $emp['last_name'] . ' ' .
                                        ($emp['surname'] ?? '') . ' (' .
                                        ($emp['designation'] ?? '') . ')' .
                                        $locationInfo
                                    ); 
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <!-- For regular employees (not in $employees array) -->
                            <?php if ($userEmployee): ?>
                                <option value="<?php echo $userEmployee['id']; ?>" selected>
                                    <?php 
                                    $locationInfo = (!empty($userEmployee['subsection_name']) 
                                        ? ' - ' . $userEmployee['subsection_name'] 
                                        : (!empty($userEmployee['section_name']) 
                                            ? ' - ' . $userEmployee['section_name'] 
                                            : (!empty($userEmployee['department_name']) 
                                                ? ' - ' . $userEmployee['department_name'] 
                                                : '')
                                        )
                                    );
                                    echo htmlspecialchars(
                                        $userEmployee['employee_id'] . ' - ' .
                                        $userEmployee['first_name'] . ' ' .
                                        $userEmployee['last_name'] . ' ' .
                                        ($userEmployee['surname'] ?? '') . ' (' .
                                        ($userEmployee['designation'] ?? '') . ')' .
                                        $locationInfo
                                    ); 
                                    ?>
                                </option>
                            <?php endif; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="leave_type_id">Leave Type</label>
                    <select name="leave_type_id" id="leave_type_id" class="form-control" required>
                        <option value="">Select Leave Type</option>
                        <!-- Options populated by JS -->
                    </select>
                </div>

                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" name="start_date" id="start_date" class="form-control" required 
                           value="<?php echo isset($_POST['start_date']) ? htmlspecialchars($_POST['start_date']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" name="end_date" id="end_date" class="form-control" required
                           value="<?php echo isset($_POST['end_date']) ? htmlspecialchars($_POST['end_date']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="calculated_days">Calculated Days</label>
                    <input type="text" id="calculated_days" class="form-control" readonly>
                </div>
            </div>

            <!-- Enhanced Deduction Preview -->
            <div id="deduction_preview" class="deduction-preview">
                <h5>Leave Deduction Preview</h5>
                <div id="deduction_details"></div>
            </div>

            <div class="form-group">
                <label for="reason">Reason for Leave</label>
                <textarea name="reason" id="reason" class="form-control" rows="3" ><?php echo isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : ''; ?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="submit_btn">Submit Application</button>
                <button type="reset" class="btn btn-secondary">Reset Form</button>
            </div>
        </form>
        
        <!-- Employee Status Overview - MOVED BELOW THE FORM -->
        <div id="employee_status_card" class="employee-status-card">
            <h5>Employee Leave Status</h5>
            <div id="employee_status_content"></div>
        </div>
        
        <!-- Validation Section - ALSO BELOW THE FORM -->
        <div id="validation_section" class="validation-section">
            <h5>Leave Application Validation</h5>
            <div id="validation_checks"></div>
        </div>
    <?php else: ?>
        <div class="alert alert-warning">
            Your user account is not linked to an employee record. Please contact HR to resolve this issue.
        </div>
    <?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    const leaveTypeInput = document.getElementById('leave_type_id');
    const employeeInput = document.getElementById('employee_id');
    const calculatedDays = document.getElementById('calculated_days');
    const deductionPreview = document.getElementById('deduction_preview');
    const deductionDetails = document.getElementById('deduction_details');
    const validationSection = document.getElementById('validation_section');
    const validationChecks = document.getElementById('validation_checks');
    const employeeStatusCard = document.getElementById('employee_status_card');
    const employeeStatusContent = document.getElementById('employee_status_content');
    const submitBtn = document.getElementById('submit_btn');
    const today = new Date().toISOString().split('T')[0];

    // Initialize correctly for all roles
    <?php if (!empty($employees)): ?>
        // HR/Manager: auto-select first employee
        const firstEmpId = <?php echo json_encode($employees[0]['id'] ?? ''); ?>;
        if (firstEmpId) {
            employeeInput.value = firstEmpId;
            loadLeaveTypesForEmployee(firstEmpId);
            loadEmployeeStatus(firstEmpId);
        }
    <?php elseif ($userEmployee): ?>
        // Regular user: self
        employeeInput.value = <?php echo $userEmployee['id'] ?? ''; ?>;
        loadLeaveTypesForEmployee(<?php echo $userEmployee['id'] ?? ''; ?>);
        loadEmployeeStatus(<?php echo $userEmployee['id'] ?? ''; ?>);
    <?php endif; ?>

    employeeInput.addEventListener('change', function() {
        const employeeId = this.value;
        if (employeeId) {
            loadLeaveTypesForEmployee(employeeId);
            loadEmployeeStatus(employeeId);
        } else {
            leaveTypeInput.innerHTML = '<option value="">Select Leave Type</option>';
            deductionPreview.classList.add('d-none');
            validationSection.classList.add('d-none');
            employeeStatusCard.classList.add('d-none');
        }
    });

    // Load employee leave status
    function loadEmployeeStatus(employeeId) {
        fetch('get_employee_leave_status.php?employee_id=' + encodeURIComponent(employeeId))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    employeeStatusCard.classList.add('d-none');
                    return;
                }

                let statusHTML = '';
                
                // Employee info
                if (data.employee_info) {
                    statusHTML += `
                        <div class="status-item">
                            <span><strong>Employee:</strong></span>
                            <span>${data.employee_info.first_name} ${data.employee_info.last_name} (${data.employee_info.employee_id})</span>
                        </div>
                        <div class="status-item">
                            <span><strong>Department:</strong></span>
                            <span>${data.employee_info.department || 'N/A'}</span>
                        </div>
                        <div class="status-item">
                            <span><strong>Section:</strong></span>
                            <span>${data.employee_info.section || 'N/A'}</span>
                        </div>
                    `;
                }

                // Current leave status
                if (data.current_leave) {
                    statusHTML += `
                        <div class="status-item">
                            <span><strong>Current Status:</strong></span>
                            <span class="status-badge badge-danger">ON LEAVE</span>
                        </div>
                        <div class="status-item">
                            <span><strong>Leave Period:</strong></span>
                            <span>${new Date(data.current_leave.start_date).toLocaleDateString()} to ${new Date(data.current_leave.end_date).toLocaleDateString()}</span>
                        </div>
                    `;
                } else {
                    statusHTML += `
                        <div class="status-item">
                            <span><strong>Current Status:</strong></span>
                            <span class="status-badge badge-success">AVAILABLE</span>
                        </div>
                    `;
                }

                // Pending applications
                if (data.pending_applications && data.pending_applications.length > 0) {
                    statusHTML += `
                        <div class="status-item">
                            <span><strong>Pending Applications:</strong></span>
                            <span class="status-badge badge-warning">${data.pending_applications.length}</span>
                        </div>
                    `;
                } else {
                    statusHTML += `
                        <div class="status-item">
                            <span><strong>Pending Applications:</strong></span>
                            <span class="status-badge badge-success">NONE</span>
                        </div>
                    `;
                }

                employeeStatusContent.innerHTML = statusHTML;
                employeeStatusCard.classList.remove('d-none');
            })
            .catch(error => {
                console.error('Error loading employee status:', error);
                employeeStatusCard.classList.add('d-none');
            });
    }

    function loadLeaveTypesForEmployee(employeeId) {
        fetch('get_employee_leave_types.php?employee_id=' + encodeURIComponent(employeeId))
            .then(response => response.json())
            .then(data => {
                leaveTypeInput.innerHTML = '<option value="">Select Leave Type</option>';
                if (data.length === 0) {
                    leaveTypeInput.innerHTML += '<option value="">No leave types allocated</option>';
                } else {
                    data.forEach(type => {
                        const option = document.createElement('option');
                        option.value = type.leave_type_id;
                        option.textContent = `${type.leave_type_name} (total_days: ${type.total_days} days)`;
                        option.dataset.totalDays = type.total_days;
                        option.dataset.countsWeekends = type.counts_weekends;
                        option.dataset.fallback = type.deducted_from_annual;
                        option.dataset.remaining = type.remaining_days;
                        leaveTypeInput.appendChild(option);
                    });
                }
                // Store for JS calculations
                window.currentEmployeeLeaveBalances = data;
                // Reset date fields and preview
                startDateInput.value = '';
                endDateInput.value = '';
                calculatedDays.value = '';
                deductionPreview.classList.add('d-none');
                validationSection.classList.add('d-none');
            })
            .catch(error => {
                console.error('Error loading leave types:', error);
                leaveTypeInput.innerHTML = '<option value="">Error loading leave types</option>';
            });
    }

    function calculateDays() {
        if (!startDateInput.value || !endDateInput.value || !leaveTypeInput.value) {
            calculatedDays.value = '';
            deductionPreview.classList.add('d-none');
            validationSection.classList.add('d-none');
            return;
        }

        const start = new Date(startDateInput.value);
        const end = new Date(endDateInput.value);
        if (end < start) {
            calculatedDays.value = 'Invalid date range';
            deductionPreview.classList.add('d-none');
            validationSection.classList.add('d-none');
            return;
        }

        const leaveTypeId = parseInt(leaveTypeInput.value);
        const selectedOption = leaveTypeInput.options[leaveTypeInput.selectedIndex];
        const countsWeekends = selectedOption.dataset.countsWeekends === '1';

        let diffDays = 0;
        let current = new Date(start);
        while (current <= end) {
            const dayOfWeek = current.getDay();
            if (countsWeekends || (dayOfWeek !== 0 && dayOfWeek !== 6)) {
                diffDays++;
            }
            current.setDate(current.getDate() + 1);
        }

        calculatedDays.value = diffDays + ' days';
        calculateDeduction(leaveTypeId, diffDays);
        validateLeaveApplication();
    }

    // Function to validate leave application
    function validateLeaveApplication() {
        const employeeId = employeeInput.value;
        const startDate = startDateInput.value;
        const endDate = endDateInput.value;

        if (!employeeId || !startDate || !endDate) {
            validationSection.classList.add('d-none');
            return;
        }

        // Show loading state
        validationChecks.innerHTML = '<div class="validation-check">Checking availability...</div>';
        validationSection.classList.remove('d-none');

        // Create form data
        const formData = new FormData();
        formData.append('employee_id', employeeId);
        formData.append('start_date', startDate);
        formData.append('end_date', endDate);

        fetch('validate_leave_application.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.error) {
                throw new Error(data.error);
            }

            let allValid = true;
            let validationHTML = '';

            // Check current leave status
            if (data.current_leave) {
                validationHTML += `
                    <div class="validation-check error">
                        <span class="validation-icon">❌</span>
                        <strong>Currently on Leave:</strong> Employee is on approved leave from ${data.current_leave.start_date} to ${data.current_leave.end_date}
                    </div>
                `;
                allValid = false;
            } else {
                validationHTML += `
                    <div class="validation-check success">
                        <span class="validation-icon">✅</span>
                        <strong>Available:</strong> Employee is not currently on leave
                    </div>
                `;
            }

            // Check pending applications
            if (data.pending_applications && data.pending_applications.length > 0) {
                const pendingDates = data.pending_applications.map(app => 
                    `${new Date(app.start_date).toLocaleDateString()} to ${new Date(app.end_date).toLocaleDateString()}`
                ).join('; ');
                
                validationHTML += `
                    <div class="validation-check error">
                        <span class="validation-icon">❌</span>
                        <strong>Pending Applications:</strong> Employee has ${data.pending_applications.length} pending leave application(s)
                        <br><small>${pendingDates}</small>
                    </div>
                `;
                allValid = false;
            } else {
                validationHTML += `
                    <div class="validation-check success">
                        <span class="validation-icon">✅</span>
                        <strong>No Pending Applications:</strong> No pending leave applications found
                    </div>
                `;
            }

            // Check overlapping leave
            if (data.overlapping_leaves && data.overlapping_leaves.length > 0) {
                const overlappingDates = data.overlapping_leaves.map(leave => 
                    `${new Date(leave.start_date).toLocaleDateString()} to ${new Date(leave.end_date).toLocaleDateString()} (${leave.status})`
                ).join('; ');
                
                validationHTML += `
                    <div class="validation-check error">
                        <span class="validation-icon">❌</span>
                        <strong>Date Conflict:</strong> Overlapping with ${data.overlapping_leaves.length} existing leave application(s)
                        <br><small>${overlappingDates}</small>
                    </div>
                `;
                allValid = false;
            } else {
                validationHTML += `
                    <div class="validation-check success">
                        <span class="validation-icon">✅</span>
                        <strong>Date Available:</strong> No overlapping leave found
                    </div>
                `;
            }

            validationChecks.innerHTML = validationHTML;
            updateSubmitButton(allValid);

        })
        .catch(error => {
            console.error('Validation error:', error);
            validationChecks.innerHTML = `
                <div class="validation-check warning">
                    <span class="validation-icon">⚠️</span>
                    <strong>Validation Temporarily Unavailable:</strong> Could not check leave availability. You can still submit the application.
                </div>
            `;
            updateSubmitButton(true);
        });
    }

    // Helper function to update submit button state
    function updateSubmitButton(isValid) {
        if (isValid) {
            submitBtn.disabled = false;
            submitBtn.className = 'btn btn-primary';
            submitBtn.textContent = 'Submit Application';
        } else {
            submitBtn.disabled = true;
            submitBtn.className = 'btn btn-secondary';
            submitBtn.textContent = 'Cannot Submit - Validation Failed';
        }
    }

    function calculateDeduction(leaveTypeId, requestedDays) {
        // Special cases: Claim a Day (9), Leave of Absence (8)
        if (leaveTypeId == 9) {
            showDeductionPreview(`✅ This will add ${requestedDays} days to annual leave upon approval.`, [], requestedDays, 0, 0);
            return;
        }
        if (leaveTypeId == 8) {
            showDeductionPreview(`ℹ️ Absent for ${requestedDays} days.`, [], 0, 0, requestedDays);
            return;
        }

        // Special validation for Annual Leave (ID 1) - must be at least 15 days
        if (leaveTypeId == 1 && requestedDays < 15) {
            let deductionHtml = `
                <div class="alert alert-warning">
                    <strong>Short Leave Recommended</strong><br>
                    You've selected ${requestedDays} days of Annual Leave, which is less than the 15-day minimum. 
                    Consider applying for <strong>Short Leave</strong> instead for better leave management.
                </div>
                <div class="text-center mt-2">
                    <button type="button" class="btn btn-info btn-sm" id="switchToShortLeave">
                        Switch to Short Leave
                    </button>
                </div>
            `;
            deductionDetails.innerHTML = deductionHtml;
            deductionPreview.classList.remove('d-none');
            
            // Disable submit button
            submitBtn.disabled = true;
            submitBtn.innerHTML = 'Please use Short Leave for less than 15 days';
            submitBtn.className = 'btn btn-secondary';
            
            // Add event listener for the switch button
            setTimeout(() => {
                const switchBtn = document.getElementById('switchToShortLeave');
                if (switchBtn) {
                    switchBtn.addEventListener('click', function() {
                        // Find short leave option (ID 6)
                        const shortLeaveOption = Array.from(leaveTypeInput.options).find(
                            option => option.value == 6
                        );
                        if (shortLeaveOption) {
                            leaveTypeInput.value = 6;
                            calculateDays();
                        }
                    });
                }
            }, 100);
            
            return;
        }

        const balances = window.currentEmployeeLeaveBalances || [];
        const leaveBalance = balances.find(lb => lb.leave_type_id == leaveTypeId);
        const annualBalance = balances.find(lb => lb.leave_type_id == 1);

        const totalDays = leaveBalance ? leaveBalance.total_days : 0;
        if (totalDays === 0) {
            showDeductionPreview('Unlimited leave type—no balance deduction required.', ['Unlimited leave type'], 0, 0, 0);
            return;
        }

        const availablePrimary = leaveBalance ? parseFloat(leaveBalance.remaining_days) : 0;
        let primaryDeduction = 0, annualDeduction = 0, unpaidDays = 0;
        let warnings = [];

        // Check maximum days using total_days
        if (totalDays > 0 && requestedDays > totalDays) {
            warnings.push(`⚠️ Requested days (${requestedDays}) exceed total allocated (${totalDays}).`);
        }

        if (requestedDays <= availablePrimary) {
            primaryDeduction = requestedDays;
            warnings.push(`✅ Will be deducted from ${leaveBalance.leave_type_name} balance.`);
        } else {
            primaryDeduction = availablePrimary;
            let remaining = requestedDays - primaryDeduction;
            if (leaveBalance.deducted_from_annual === 1 && annualBalance) {
                const availableAnnual = parseFloat(annualBalance.remaining_days);
                if (availableAnnual >= remaining) {
                    annualDeduction = remaining;
                    warnings.push(`⚠️ ${primaryDeduction} from ${leaveBalance.leave_type_name}, ${annualDeduction} from Annual.`);
                } else {
                    annualDeduction = availableAnnual;
                    unpaidDays = remaining - annualDeduction;
                    warnings.push(`❌ Insufficient balance. ${unpaidDays} days unpaid.`);
                }
            } else {
                unpaidDays = remaining;
                warnings.push(`❌ ${unpaidDays} days will be unpaid.`);
            }
        }

        // Add note for HR users on balance checks
        if ('<?php echo $user["role"]; ?>' !== 'hr_manager') {
            warnings.push('Note: Final approval checks balance availability.');
        }

        showDeductionPreview('', warnings, primaryDeduction, annualDeduction, unpaidDays, requestedDays);
    }

    function showDeductionPreview(message, warnings, primary, annual, unpaid, requested = 0) {
        let html = '';
        if (requested > 0) {
            html += `<div class="deduction-item"><span>Requested Days:</span><span>${requested}</span></div>`;
        }
        if (primary > 0) {
            html += `<div class="deduction-item"><span>Primary Deduction:</span><span>${primary} days</span></div>`;
        }
        if (annual > 0) {
            html += `<div class="deduction-item"><span>Annual Deduction:</span><span>${annual} days</span></div>`;
        }
        if (unpaid > 0) {
            html += `<div class="deduction-item unpaid-days"><span>Unpaid Days:</span><span>${unpaid} days</span></div>`;
        }
        if (message) {
            html += `<div class="info-text">${message}</div>`;
        }
        warnings.forEach(w => {
            const cls = w.includes('❌') ? 'unpaid-warning' : w.includes('⚠️') ? 'warning-text' : 'info-text';
            html += `<div class="${cls}">${w}</div>`;
        });

        deductionDetails.innerHTML = html;
        deductionPreview.classList.remove('d-none');

        // Update button (only if validation passes)
        const isCurrentlyDisabled = submitBtn.disabled;
        if (!isCurrentlyDisabled) {
            if (unpaid > 0) {
                submitBtn.className = 'btn btn-warning';
                submitBtn.textContent = 'Submit (Includes Unpaid Leave)';
            } else {
                submitBtn.className = 'btn btn-primary';
                submitBtn.textContent = 'Submit Application';
            }
        }
    }

    // Event listeners
    [startDateInput, endDateInput, leaveTypeInput].forEach(el => 
        el.addEventListener('change', calculateDays)
    );

    // Set min dates (allow past for Claim a Day)
    leaveTypeInput.addEventListener('change', function() {
        const isClaim = this.value == '9';
        startDateInput.min = isClaim ? '' : today;
        endDateInput.min = isClaim ? '' : (startDateInput.value || today);
    });

    startDateInput.addEventListener('change', function() {
        if (leaveTypeInput.value != '9') {
            endDateInput.min = this.value;
        }
    });
});
</script>

<?php ob_end_flush(); ?>
</body>
</html>
