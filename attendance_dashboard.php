<?php
ob_start();
require_once 'config.php';
require_once 'auth.php';

// Check permissions
if (!hasPermission('hr_manager')) {
    header("Location: attendance.php");
    exit;
}

$pageTitle = "Attendance Dashboard - MUWASCO HR";
require_once 'header.php';
require_once 'nav_bar.php';

// Get filter parameters
$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_office = $_GET['office'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$absent_date = $_GET['absent_date'] ?? $filter_date; // Separate date for absent filtering

// Pagination parameters
$attendance_page = isset($_GET['attendance_page']) ? max(1, intval($_GET['attendance_page'])) : 1;
$absent_page = isset($_GET['absent_page']) ? max(1, intval($_GET['absent_page'])) : 1;
$records_per_page = 20;

// Get all offices for filter
$offices_query = "SELECT id, name FROM offices ORDER BY name";
$offices_result = $conn->query($offices_query);

// Handle deduction action
if (isset($_POST['deduct_leave']) && hasPermission('hr_manager')) {
    $employee_id = intval($_POST['employee_id']);
    $deduction_date = $_POST['deduction_date'];
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // Verify CSRF token
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
        $_SESSION['flash_message'] = "Security token invalid. Please try again.";
        $_SESSION['flash_type'] = "danger";
        header("Location: attendance_dashboard.php?" . http_build_query($_GET));
        exit();
    }
    
    try {
        $conn->begin_transaction();
        
        // Check if deduction already exists for this employee and date
        $check_query = "SELECT id FROM absent_deductions WHERE employee_id = ? AND deduction_date = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("is", $employee_id, $deduction_date);
        $check_stmt->execute();
        $existing_deduction = $check_stmt->get_result()->fetch_assoc();
        
        if ($existing_deduction) {
            throw new Exception("Deduction already processed for this employee on selected date.");
        }
        
        // Get employee details
        $employee_query = "SELECT e.id, e.employee_id, e.first_name, e.last_name 
                          FROM employees e WHERE e.id = ?";
        $employee_stmt = $conn->prepare($employee_query);
        $employee_stmt->bind_param("i", $employee_id);
        $employee_stmt->execute();
        $employee = $employee_stmt->get_result()->fetch_assoc();
        
        if (!$employee) {
            throw new Exception("Employee not found.");
        }
        
        // Get annual leave type ID (assuming id=1 is annual leave)
        $leave_type_query = "SELECT id FROM leave_types WHERE name LIKE '%annual%' OR name LIKE '%Annual%' LIMIT 1";
        $leave_type_result = $conn->query($leave_type_query);
        $annual_leave_type = $leave_type_result->fetch_assoc();
        
        if (!$annual_leave_type) {
            throw new Exception("Annual leave type not found in system.");
        }
        
        $annual_leave_type_id = $annual_leave_type['id'];
        
        // Ensure leave balance exists for annual leave
        $balance_check_query = "
            SELECT id FROM employee_leave_balances 
            WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = (
                SELECT id FROM financial_years WHERE is_active = 1 LIMIT 1
            )
        ";
        $balance_stmt = $conn->prepare($balance_check_query);
        $balance_stmt->bind_param("ii", $employee_id, $annual_leave_type_id);
        $balance_stmt->execute();
        
        if ($balance_stmt->get_result()->num_rows === 0) {
            // Create balance record if it doesn't exist
            $financial_year_query = "SELECT id FROM financial_years WHERE is_active = 1 LIMIT 1";
            $fy_result = $conn->query($financial_year_query);
            $financial_year = $fy_result->fetch_assoc();
            
            if (!$financial_year) {
                throw new Exception("No active financial year found.");
            }
            
            $default_days_query = "SELECT default_days FROM leave_types WHERE id = ?";
            $default_stmt = $conn->prepare($default_days_query);
            $default_stmt->bind_param("i", $annual_leave_type_id);
            $default_stmt->execute();
            $leave_type = $default_stmt->get_result()->fetch_assoc();
            
            $allocated_days = $leave_type['default_days'] ?? 21; // Default to 21 days if not set
            
            $create_balance_query = "
                INSERT INTO employee_leave_balances 
                (employee_id, leave_type_id, financial_year_id, allocated_days, used_days, remaining_days, total_days, created_at, updated_at)
                VALUES (?, ?, ?, ?, 0, ?, ?, NOW(), NOW())
            ";
            $create_stmt = $conn->prepare($create_balance_query);
            $initial_remaining = $allocated_days;
            $initial_total = $allocated_days;
            $create_stmt->bind_param("iiiddd", $employee_id, $annual_leave_type_id, $financial_year['id'], $allocated_days, $initial_remaining, $initial_total);
            $create_stmt->execute();
        }
        
        // Update leave balance - deduct 1 day from annual leave
        $update_balance_query = "
            UPDATE employee_leave_balances 
            SET used_days = used_days + 1,
                total_days = total_days - 1,
                updated_at = NOW()
            WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = (
                SELECT id FROM financial_years WHERE is_active = 1 LIMIT 1
            )
        ";
        $update_stmt = $conn->prepare($update_balance_query);
        $update_stmt->bind_param("ii", $employee_id, $annual_leave_type_id);
        $update_stmt->execute();
        
        if ($update_stmt->affected_rows === 0) {
            throw new Exception("Failed to update leave balance.");
        }
        
        // Record the deduction
        $deduction_query = "
            INSERT INTO absent_deductions 
            (employee_id, deduction_date, leave_type_id, days_deducted, deducted_by, deducted_at, reason)
            VALUES (?, ?, ?, 1, ?, NOW(), 'Absence deduction - Unauthorized absence')
        ";
        $deduction_stmt = $conn->prepare($deduction_query);
        $deduction_stmt->bind_param("isii", $employee_id, $deduction_date, $annual_leave_type_id, $_SESSION['user_id']);
        $deduction_stmt->execute();
        
        $conn->commit();
        
        $_SESSION['flash_message'] = "Successfully deducted 1 annual leave day from " . $employee['first_name'] . " " . $employee['last_name'] . " for absence on " . date('M j, Y', strtotime($deduction_date));
        $_SESSION['flash_type'] = "success";
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['flash_message'] = "Error processing deduction: " . $e->getMessage();
        $_SESSION['flash_type'] = "danger";
    }
    
    header("Location: attendance_dashboard.php?" . http_build_query($_GET));
    exit();
}

// Get total count for attendance records (for pagination)
$attendance_count_query = "
    SELECT COUNT(DISTINCT a.id) as total_count
    FROM attendance a
    INNER JOIN employees e ON a.employee_id = e.id
    INNER JOIN offices o ON a.office_id = o.id
    WHERE DATE(a.clock_in) = ?
";

if ($filter_office !== 'all') {
    $attendance_count_query .= " AND a.office_id = " . intval($filter_office);
}

if ($filter_status !== 'all') {
    $attendance_count_query .= " AND a.status = '" . $conn->real_escape_string($filter_status) . "'";
}

$count_stmt = $conn->prepare($attendance_count_query);
$count_stmt->bind_param("s", $filter_date);
$count_stmt->execute();
$attendance_count_result = $count_stmt->get_result()->fetch_assoc();
$total_attendance_records = $attendance_count_result['total_count'] ?? 0;
$count_stmt->close();

// Calculate pagination for attendance records
$total_attendance_pages = ceil($total_attendance_records / $records_per_page);
$attendance_offset = ($attendance_page - 1) * $records_per_page;

// Get today's attendance with real-time status (with pagination)
$attendance_query = "
    SELECT 
        a.id,
        a.clock_in,
        a.clock_out,
        a.lat,
        a.lng,
        a.accuracy,
        a.status,
        e.id as employee_id,
        e.employee_id as emp_number,
        e.first_name,
        e.last_name,
        e.email,
        e.phone,
        o.name as office_name,
        o.latitude as office_lat,
        o.longitude as office_lng,
        TIMESTAMPDIFF(SECOND, a.clock_in, COALESCE(a.clock_out, NOW())) as duration_seconds
    FROM attendance a
    INNER JOIN employees e ON a.employee_id = e.id
    INNER JOIN offices o ON a.office_id = o.id
    WHERE DATE(a.clock_in) = ?
";

if ($filter_office !== 'all') {
    $attendance_query .= " AND a.office_id = " . intval($filter_office);
}

if ($filter_status !== 'all') {
    $attendance_query .= " AND a.status = '" . $conn->real_escape_string($filter_status) . "'";
}

$attendance_query .= " ORDER BY a.clock_in DESC LIMIT ? OFFSET ?";

$stmt = $conn->prepare($attendance_query);
$stmt->bind_param("sii", $filter_date, $records_per_page, $attendance_offset);
$stmt->execute();
$attendance_records = $stmt->get_result();
$stmt->close();

// Get statistics
$stats_query = "
    SELECT 
        COUNT(DISTINCT a.employee_id) as total_employees,
        COUNT(CASE WHEN a.status = 'clocked_in' THEN 1 END) as currently_in,
        COUNT(CASE WHEN a.status = 'clocked_out' THEN 1 END) as clocked_out,
        AVG(CASE WHEN a.clock_out IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE, a.clock_in, a.clock_out) / 60.0 
            END) as avg_hours,
        COUNT(CASE WHEN a.accuracy > 50 THEN 1 END) as low_accuracy_count
    FROM attendance a
    WHERE DATE(a.clock_in) = ?
";

if ($filter_office !== 'all') {
    $stats_query .= " AND a.office_id = " . intval($filter_office);
}

$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bind_param("s", $filter_date);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

// Get employees on leave for the selected date
$on_leave_query = "
    SELECT DISTINCT e.id as employee_id
    FROM leave_applications la
    INNER JOIN employees e ON la.employee_id = e.id
    WHERE la.status = 'approved'
    AND ? BETWEEN la.start_date AND la.end_date
";

if ($filter_office !== 'all') {
    $on_leave_query .= " AND e.office_id = " . intval($filter_office);
}

$on_leave_stmt = $conn->prepare($on_leave_query);
$on_leave_stmt->bind_param("s", $absent_date);
$on_leave_stmt->execute();
$on_leave_result = $on_leave_stmt->get_result();
$on_leave_employees = [];
while($row = $on_leave_result->fetch_assoc()) {
    $on_leave_employees[] = $row['employee_id'];
}
$on_leave_stmt->close();

// Get employees who already have deductions for the absent date
$deducted_employees_query = "
    SELECT DISTINCT employee_id 
    FROM absent_deductions 
    WHERE deduction_date = ?
";
$deducted_stmt = $conn->prepare($deducted_employees_query);
$deducted_stmt->bind_param("s", $absent_date);
$deducted_stmt->execute();
$deducted_result = $deducted_stmt->get_result();
$deducted_employees = [];
while($row = $deducted_result->fetch_assoc()) {
    $deducted_employees[] = $row['employee_id'];
}
$deducted_stmt->close();

// Get total count for absent employees (for pagination)
$absent_count_query = "
    SELECT COUNT(*) as total_count
    FROM employees e
    INNER JOIN offices o ON e.office_id = o.id
    WHERE e.employee_status = 'active'
    AND e.id NOT IN (
        SELECT DISTINCT employee_id 
        FROM attendance 
        WHERE DATE(clock_in) = ?
    )
";

// Exclude employees who are on leave
if (!empty($on_leave_employees)) {
    $absent_count_query .= " AND e.id NOT IN (" . implode(',', array_map('intval', $on_leave_employees)) . ")";
}

if ($filter_office !== 'all') {
    $absent_count_query .= " AND e.office_id = " . intval($filter_office);
}

$absent_count_stmt = $conn->prepare($absent_count_query);
$absent_count_stmt->bind_param("s", $absent_date);
$absent_count_stmt->execute();
$absent_count_result = $absent_count_stmt->get_result()->fetch_assoc();
$total_absent_records = $absent_count_result['total_count'] ?? 0;
$absent_count_stmt->close();

// Calculate pagination for absent employees
$total_absent_pages = ceil($total_absent_records / $records_per_page);
$absent_offset = ($absent_page - 1) * $records_per_page;

// Get absent employees for the selected date - EXCLUDING THOSE ON LEAVE (with pagination)
$absent_query = "
    SELECT 
        e.id,
        e.employee_id as emp_number,
        e.first_name,
        e.last_name,
        e.email,
        e.phone,
        o.name as office_name,
        d.name as department_name,
        CASE WHEN ld.id IS NOT NULL THEN 1 ELSE 0 END as deduction_processed
    FROM employees e
    INNER JOIN offices o ON e.office_id = o.id
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN absent_deductions ld ON e.id = ld.employee_id AND ld.deduction_date = ?
    WHERE e.employee_status = 'active'
    AND e.id NOT IN (
        SELECT DISTINCT employee_id 
        FROM attendance 
        WHERE DATE(clock_in) = ?
    )
";

// Exclude employees who are on leave
if (!empty($on_leave_employees)) {
    $absent_query .= " AND e.id NOT IN (" . implode(',', array_map('intval', $on_leave_employees)) . ")";
}

if ($filter_office !== 'all') {
    $absent_query .= " AND e.office_id = " . intval($filter_office);
}

$absent_query .= " ORDER BY o.name, e.first_name, e.last_name LIMIT ? OFFSET ?";

$absent_stmt = $conn->prepare($absent_query);
$absent_stmt->bind_param("ssii", $absent_date, $absent_date, $records_per_page, $absent_offset);
$absent_stmt->execute();
$absent_employees = $absent_stmt->get_result();
$absent_stmt->close();

// Get total active employees
$total_employees_query = "
    SELECT COUNT(*) as total_active 
    FROM employees 
    WHERE employee_status = 'active'
";
if ($filter_office !== 'all') {
    $total_employees_query .= " AND office_id = " . intval($filter_office);
}

$total_result = $conn->query($total_employees_query);
$total_employees_data = $total_result->fetch_assoc();
$total_employees = $total_employees_data['total_active'] ?? 0;

// Update statistics
$stats['absent_employees'] = $total_absent_records; // Use total count for accurate stats
$stats['total_active'] = $total_employees;
$stats['on_leave'] = count($on_leave_employees);

// Calculate present percentage
$present_percentage = $total_employees > 0 ? round(($stats['total_employees'] / $total_employees) * 100, 1) : 0;

// Get office-wise breakdown
$office_stats_query = "
    SELECT 
        o.name as office_name,
        COUNT(DISTINCT a.employee_id) as total_employees,
        COUNT(CASE WHEN a.status = 'clocked_in' THEN 1 END) as currently_in,
        AVG(CASE WHEN a.clock_out IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE, a.clock_in, a.clock_out) / 60.0 
            END) as avg_hours
    FROM attendance a
    INNER JOIN offices o ON a.office_id = o.id
    WHERE DATE(a.clock_in) = ?
    GROUP BY o.id, o.name
    ORDER BY currently_in DESC, total_employees DESC
";

$office_stats_stmt = $conn->prepare($office_stats_query);
$office_stats_stmt->bind_param("s", $filter_date);
$office_stats_stmt->execute();
$office_stats = $office_stats_stmt->get_result();
$office_stats_stmt->close();

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earth_radius * $c;
}

// Function to generate pagination links
function generatePagination($current_page, $total_pages, $page_param) {
    if ($total_pages <= 1) return '';
    
    $pagination = '<div class="pagination">';
    
    // Previous button
    if ($current_page > 1) {
        $pagination .= '<a href="?' . http_build_query(array_merge($_GET, [$page_param => $current_page - 1])) . '" class="pagination-btn">';
        $pagination .= '<i class="fas fa-chevron-left"></i> Previous';
        $pagination .= '</a>';
    }
    
    // Page numbers
    $start_page = max(1, $current_page - 2);
    $end_page = min($total_pages, $current_page + 2);
    
    for ($i = $start_page; $i <= $end_page; $i++) {
        if ($i == $current_page) {
            $pagination .= '<span class="pagination-btn active">' . $i . '</span>';
        } else {
            $pagination .= '<a href="?' . http_build_query(array_merge($_GET, [$page_param => $i])) . '" class="pagination-btn">' . $i . '</a>';
        }
    }
    
    // Next button
    if ($current_page < $total_pages) {
        $pagination .= '<a href="?' . http_build_query(array_merge($_GET, [$page_param => $current_page + 1])) . '" class="pagination-btn">';
        $pagination .= 'Next <i class="fas fa-chevron-right"></i>';
        $pagination .= '</a>';
    }
    
    $pagination .= '</div>';
    return $pagination;
} 
ob_end_flush();
?>

<style>
.dashboard-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: linear-gradient(135deg, rgba(74, 144, 226, 0.1) 0%, rgba(74, 144, 226, 0.05) 100%);
    border-radius: 12px;
    padding: 1.5rem;
    border: 1px solid rgba(74, 144, 226, 0.2);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(74, 144, 226, 0.2);
}

.stat-card.absent-stats {
    background: linear-gradient(135deg, rgba(220, 53, 69, 0.1) 0%, rgba(220, 53, 69, 0.05) 100%);
    border: 1px solid rgba(220, 53, 69, 0.2);
}

.stat-card.leave-stats {
    background: linear-gradient(135deg, rgba(255, 193, 7, 0.1) 0%, rgba(255, 193, 7, 0.05) 100%);
    border: 1px solid rgba(255, 193, 7, 0.2);
}

.stat-icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
    opacity: 0.8;
}

.stat-value {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--primary-color);
    margin: 0.5rem 0;
}

.stat-label {
    font-size: 0.9rem;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-section {
    background: rgba(255, 255, 255, 0.05);
    padding: 1.5rem;
    border-radius: 12px;
    margin-bottom: 2rem;
}

.filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    align-items: end;
}

.filter-group {
    display: flex;
    flex-direction: column;
}

.filter-group label {
    font-size: 0.9rem;
    margin-bottom: 0.5rem;
    color: rgba(255, 255, 255, 0.8);
}

.real-time-indicator {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: rgba(40, 167, 69, 0.2);
    border-radius: 20px;
    font-size: 0.9rem;
}

.pulse-dot {
    width: 8px;
    height: 8px;
    background: var(--success-color);
    border-radius: 50%;
    animation: pulse-dot 2s ease-in-out infinite;
}

@keyframes pulse-dot {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(1.2); }
}

.office-breakdown {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.office-card {
    background: rgba(255, 255, 255, 0.05);
    padding: 1rem;
    border-radius: 8px;
    border-left: 4px solid var(--primary-color);
}

.office-card h4 {
    margin: 0 0 0.5rem 0;
    color: var(--primary-color);
}

.office-metric {
    display: flex;
    justify-content: space-between;
    padding: 0.25rem 0;
    font-size: 0.9rem;
}

.attendance-table-wrapper {
    overflow-x: auto;
}

.location-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.location-verified {
    background: rgba(40, 167, 69, 0.2);
    color: var(--success-color);
}

.location-suspicious {
    background: rgba(255, 193, 7, 0.2);
    color: var(--warning-color);
}

.export-buttons {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.live-duration {
    font-family: 'Courier New', monospace;
    font-weight: 700;
    color: var(--success-color);
}

.badge-danger {
    background: rgba(220, 53, 69, 0.2);
    color: var(--danger-color);
    border: 1px solid rgba(220, 53, 69, 0.3);
}

.badge-warning {
    background: rgba(255, 193, 7, 0.2);
    color: var(--warning-color);
    border: 1px solid rgba(255, 193, 7, 0.3);
}

.badge-success {
    background: rgba(40, 167, 69, 0.2);
    color: var(--success-color);
    border: 1px solid rgba(40, 167, 69, 0.3);
}

.absent-table {
    margin-top: 2rem;
}

.text-muted i {
    margin-right: 0.5rem;
}

.accuracy-indicator {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.accuracy-high {
    background: rgba(40, 167, 69, 0.2);
    color: var(--success-color);
}

.accuracy-medium {
    background: rgba(255, 193, 7, 0.2);
    color: var(--warning-color);
}

.accuracy-low {
    background: rgba(220, 53, 69, 0.2);
    color: var(--danger-color);
}

.present-percentage {
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.6);
    margin-top: 0.25rem;
}

.attendance-rate {
    font-size: 0.9rem;
    color: var(--success-color);
    font-weight: 600;
    margin-top: 0.5rem;
}

.absent-filters {
    background: rgba(255, 255, 255, 0.05);
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.absent-filters .filter-row {
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
}

/* Pagination Styles */
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 0.5rem;
    margin-top: 1.5rem;
    flex-wrap: wrap;
}

.pagination-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 6px;
    color: rgba(255, 255, 255, 0.8);
    text-decoration: none;
    font-size: 0.9rem;
    transition: all 0.3s ease;
}

.pagination-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    text-decoration: none;
}

.pagination-btn.active {
    background: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
}

.pagination-info {
    text-align: center;
    margin-top: 0.5rem;
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.9rem;
}

.table-header-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    flex-wrap: wrap;
    gap: 1rem;
}

.records-count {
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.9rem;
}

/* Deduction Button Styles */
.btn-deduct {
    background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
    color: white;
    border: none;
    padding: 0.4rem 0.8rem;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-deduct:hover {
    background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
}

.btn-deduct:disabled {
    background: #6c757d;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.btn-deduct-success {
    background: linear-gradient(135deg, #28a745 0%, #218838 100%);
    color: white;
    border: none;
    padding: 0.4rem 0.8rem;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: default;
}

.deduction-form {
    display: inline;
}

.flash-message {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    color: white;
    z-index: 10000;
    max-width: 400px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    animation: slideInRight 0.3s ease-out;
}

.flash-success {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    border-left: 4px solid #155724;
}

.flash-danger {
    background: linear-gradient(135deg, #dc3545 0%, #e83e8c 100%);
    border-left: 4px solid #721c24;
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

.deduction-info {
    font-size: 0.8rem;
    color: inherit; /* Inherit color from parent */
    margin-top: 0.5rem;
    text-align: center;
    opacity: 0.8;
}
</style>

<div class="main-content">
    <div class="content">
        <!-- Flash Messages -->
        <?php if (isset($_SESSION['flash_message'])): ?>
            <div class="flash-message flash-<?= $_SESSION['flash_type'] ?? 'info' ?>">
                <?= htmlspecialchars($_SESSION['flash_message']) ?>
                <button type="button" class="close-flash" style="background: none; border: none; color: white; margin-left: 1rem; cursor: pointer;">×</button>
            </div>
            <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
        <?php endif; ?>

        <div class="page-header">
            <h1><i class="fas fa-chart-bar"></i> Attendance Dashboard</h1>
            <div class="real-time-indicator">
                <span class="pulse-dot"></span>
                <span>Real-Time Monitoring</span>
            </div>
        </div>

        <!-- Tabs Navigation -->
        <div class="tabs-container">
            <div class="tabs">
                <a href="attendance.php" class="tab">
                    <i class="fas fa-user-clock"></i> My Attendance
                </a>
                <a href="attendance_dashboard.php" class="tab active">
                    <i class="fas fa-chart-bar"></i> Attendance Dashboard
                </a>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="dashboard-stats">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-value"><?= $stats['total_active'] ?? 0 ?></div>
                <div class="stat-label">Total Active Employees</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-user-check" style="color: var(--success-color);"></i></div>
                <div class="stat-value" style="color: var(--success-color);"><?= $stats['total_employees'] ?? 0 ?></div>
                <div class="stat-label">Present Today</div>
                <div class="present-percentage"><?= $present_percentage ?>% Attendance Rate</div>
            </div>
            <div class="stat-card absent-stats">
                <div class="stat-icon"><i class="fas fa-user-times" style="color: var(--danger-color);"></i></div>
                <div class="stat-value" style="color: var(--danger-color);"><?= $stats['absent_employees'] ?? 0 ?></div>
                <div class="stat-label">Absent (Unexcused)</div>
            </div>
            <div class="stat-card leave-stats">
                <div class="stat-icon"><i class="fas fa-umbrella-beach" style="color: var(--warning-color);"></i></div>
                <div class="stat-value" style="color: var(--warning-color);"><?= $stats['on_leave'] ?? 0 ?></div>
                <div class="stat-label">On Leave</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-user-minus" style="color: var(--primary-color);"></i></div>
                <div class="stat-value" style="color: var(--primary-color);"><?= $stats['clocked_out'] ?? 0 ?></div>
                <div class="stat-label">Clocked Out</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-value"><?= number_format($stats['avg_hours'] ?? 0, 1) ?>h</div>
                <div class="stat-label">Average Hours</div>
            </div>
            <?php if ($stats['low_accuracy_count'] > 0): ?>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-exclamation-triangle" style="color: var(--warning-color);"></i></div>
                <div class="stat-value" style="color: var(--warning-color);"><?= $stats['low_accuracy_count'] ?></div>
                <div class="stat-label">Low Accuracy Records</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Filters -->
        <div class="glass-card">
            <div class="card-header">
                <h3><i class="fas fa-filter"></i> Attendance Records Filters</h3>
            </div>
            <div class="card-body">
                <form method="GET" action="" class="filter-row">
                    <input type="hidden" name="attendance_page" value="1">
                    <input type="hidden" name="absent_page" value="<?= $absent_page ?>">
                    <div class="filter-group">
                        <label>Date</label>
                        <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>" 
                               class="form-control" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="filter-group">
                        <label>Office</label>
                        <select name="office" class="form-control">
                            <option value="all" <?= $filter_office === 'all' ? 'selected' : '' ?>>All Offices</option>
                            <?php 
                            $offices_result->data_seek(0);
                            while($office = $offices_result->fetch_assoc()): 
                            ?>
                                <option value="<?= $office['id'] ?>" <?= $filter_office == $office['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($office['name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All Status</option>
                            <option value="clocked_in" <?= $filter_status === 'clocked_in' ? 'selected' : '' ?>>Clocked In</option>
                            <option value="clocked_out" <?= $filter_status === 'clocked_out' ? 'selected' : '' ?>>Clocked Out</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Office Breakdown -->
        <?php if ($office_stats->num_rows > 0): ?>
        <div class="glass-card">
            <div class="card-header">
                <h3><i class="fas fa-building"></i> Office-Wise Breakdown</h3>
            </div>
            <div class="card-body">
                <div class="office-breakdown">
                    <?php while($office_stat = $office_stats->fetch_assoc()): ?>
                    <div class="office-card">
                        <h4><?= htmlspecialchars($office_stat['office_name']) ?></h4>
                        <div class="office-metric">
                            <span>Total Attendance:</span>
                            <strong><?= $office_stat['total_employees'] ?></strong>
                        </div>
                        <div class="office-metric">
                            <span>Currently In:</span>
                            <strong style="color: var(--success-color);"><?= $office_stat['currently_in'] ?></strong>
                        </div>
                        <div class="office-metric">
                            <span>Avg Hours:</span>
                            <strong><?= number_format($office_stat['avg_hours'] ?? 0, 1) ?>h</strong>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Attendance Records -->
        <div class="glass-card">
            <div class="card-header">
                <h3><i class="fas fa-table"></i> Attendance Records</h3>
                <div class="export-buttons">
                    <button onclick="exportToCSV()" class="btn btn-sm btn-secondary">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                    <button onclick="window.print()" class="btn btn-sm btn-secondary">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-header-info">
                    <div class="records-count">
                        Showing <?= min($records_per_page, $attendance_records->num_rows) ?> of <?= $total_attendance_records ?> records
                    </div>
                </div>
                <div class="attendance-table-wrapper">
                    <table class="table" id="attendance-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Office</th>
                                <th>Clock In</th>
                                <th>Clock Out</th>
                                <th>Duration</th>
                                <th>Location</th>
                                <th>Distance</th>
                                <th>Accuracy</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php while($record = $attendance_records->fetch_assoc()): 
                            $distance = calculateDistance(
                                $record['lat'], $record['lng'],
                                $record['office_lat'], $record['office_lng']
                            );
                            $distance_meters = round($distance * 1000);
                            $is_suspicious = $distance_meters > 100;
                        ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($record['emp_number']) ?></small>
                                </td>
                                <td><?= htmlspecialchars($record['office_name']) ?></td>
                                <td><?= date('g:i A', strtotime($record['clock_in'])) ?></td>
                                <td>
                                    <?php if ($record['clock_out']): ?>
                                        <?= date('g:i A', strtotime($record['clock_out'])) ?>
                                    <?php else: ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($record['status'] === 'clocked_in'): ?>
                                        <span class="live-duration" data-clock-in="<?= $record['clock_in'] ?>">
                                            --:--:--
                                        </span>
                                    <?php else: ?>
                                        <?php
                                        $hours = floor($record['duration_seconds'] / 3600);
                                        $minutes = floor(($record['duration_seconds'] % 3600) / 60);
                                        echo sprintf("%02d:%02d", $hours, $minutes);
                                        ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small><?= round($record['lat'], 6) ?>, <?= round($record['lng'], 6) ?></small>
                                </td>
                                <td>
                                    <span class="location-badge <?= $is_suspicious ? 'location-suspicious' : 'location-verified' ?>">
                                        <?= $distance_meters ?>m
                                    </span>
                                </td>
                                <td>
                                    <?php if ($record['accuracy']): ?>
                                        <span class="accuracy-indicator accuracy-<?= 
                                            $record['accuracy'] < 20 ? 'high' : 
                                            ($record['accuracy'] < 50 ? 'medium' : 'low') 
                                        ?>">
                                            <?= round($record['accuracy']) ?>m
                                        </span>
                                    <?php else: ?>
                                        <small class="text-muted">N/A</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $record['status'] === 'clocked_in' ? 'success' : 'primary' ?>">
                                        <?= strtoupper(str_replace('_', ' ', $record['status'])) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        <?php if ($attendance_records->num_rows === 0): ?>
                            <tr><td colspan="9" class="text-center text-muted">No attendance records found for the selected filters.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Attendance Records Pagination -->
                <?= generatePagination($attendance_page, $total_attendance_pages, 'attendance_page') ?>
                <?php if ($total_attendance_pages > 0): ?>
                <div class="pagination-info">
                    Page <?= $attendance_page ?> of <?= $total_attendance_pages ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Absent Employees -->
        <div class="glass-card">
            <div class="card-header">
                <h3><i class="fas fa-user-times" style="color: var(--danger-color);"></i> Absent Employees - <?= date('F j, Y', strtotime($absent_date)) ?></h3>
                <div class="export-buttons">
                    <button onclick="exportAbsentToCSV()" class="btn btn-sm btn-secondary">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                </div>
            </div>
            <div class="card-body">
                <!-- Absent Employees Filters -->
                <div class="absent-filters">
                    <form method="GET" action="" class="filter-row">
                        <input type="hidden" name="date" value="<?= htmlspecialchars($filter_date) ?>">
                        <input type="hidden" name="office" value="<?= htmlspecialchars($filter_office) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                        <input type="hidden" name="attendance_page" value="<?= $attendance_page ?>">
                        <input type="hidden" name="absent_page" value="1">
                        <div class="filter-group">
                            <label>Check Absence For Date</label>
                            <input type="date" name="absent_date" value="<?= htmlspecialchars($absent_date) ?>" 
                                   class="form-control" max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="filter-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Check Absence
                            </button>
                        </div>
                    </form>
                </div>

                <div class="table-header-info">
                    <div class="records-count">
                        Showing <?= min($records_per_page, $absent_employees->num_rows) ?> of <?= $total_absent_records ?> absent employees
                    </div>
                </div>

                <div class="attendance-table-wrapper">
                    <table class="table" id="absent-table">
                        <thead>
                            <tr>
                                <th>Employee ID</th>
                                <th>Name</th>
                                <th>Office</th>
                                <th>Department</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th>Deduction</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php 
                        while($employee = $absent_employees->fetch_assoc()): 
                            $is_deduction_processed = $employee['deduction_processed'] == 1;
                        ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($employee['emp_number']) ?></strong>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?></strong>
                                </td>
                                <td><?= htmlspecialchars($employee['office_name']) ?></td>
                                <td><?= htmlspecialchars($employee['department_name'] ?? 'N/A') ?></td>
                                <td>
                                    <small><?= htmlspecialchars($employee['email']) ?></small><br>
                                    <small class="text-muted"><?= htmlspecialchars($employee['phone']) ?></small>
                                </td>
                                <td>
                                    <span class="badge badge-danger">
                                        <i class="fas fa-user-times"></i> ABSENT
                                    </span>
                                </td>
                                <td>
                                    <?php if ($is_deduction_processed): ?>
                                        <span class="badge badge-success">
                                            <i class="fas fa-check-circle"></i> Deducted
                                        </span>
                                    <?php else: ?>
                                        <form method="POST" action="" class="deduction-form" onsubmit="return confirm('Are you sure you want to deduct 1 annual leave day from <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?> for absence on <?= date('M j, Y', strtotime($absent_date)) ?>?')">
                                            <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                            <input type="hidden" name="deduction_date" value="<?= $absent_date ?>">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                            <button type="submit" name="deduct_leave" class="btn-deduct">
                                                <i class="fas fa-minus-circle"></i> Deduct
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        <?php if ($absent_employees->num_rows === 0): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted">
                                    <i class="fas fa-check-circle" style="color: var(--success-color);"></i>
                                    All active employees are accounted for on <?= date('F j, Y', strtotime($absent_date)) ?>!
                                    <br>
                                    <small>
                                        (Present: <?= $stats['total_employees'] ?? 0 ?>, 
                                        On Leave: <?= $stats['on_leave'] ?? 0 ?>)
                                    </small>
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Absent Employees Pagination -->
                <?= generatePagination($absent_page, $total_absent_pages, 'absent_page') ?>
                <?php if ($total_absent_pages > 0): ?>
                <div class="pagination-info">
                    Page <?= $absent_page ?> of <?= $total_absent_pages ?>
                </div>
                <?php endif; ?>

                <div class="deduction-info">
                    <small><i class="fas fa-info-circle"></i> Deduction will subtract 1 day from employee's annual leave balance. Once processed, deduction cannot be reversed.</small>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Update live durations
function updateLiveDurations() {
    document.querySelectorAll('.live-duration').forEach(el => {
        const clockInTime = new Date(el.dataset.clockIn).getTime();
        const now = new Date().getTime();
        const diff = Math.floor((now - clockInTime) / 1000);
        const hours = Math.floor(diff / 3600);
        const minutes = Math.floor((diff % 3600) / 60);
        const seconds = diff % 60;
        el.textContent = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    });
}

// Update every second
setInterval(updateLiveDurations, 1000);
updateLiveDurations();

// Export to CSV function
function exportToCSV() {
    const table = document.getElementById('attendance-table');
    const rows = Array.from(table.querySelectorAll('tr'));
    const csv = rows.map(row => {
        const cells = Array.from(row.querySelectorAll('th, td'));
        return cells.map(cell => {
            const text = cell.innerText.replace(/\n/g, ' ').replace(/,/g, ';');
            return `"${text}"`;
        }).join(',');
    }).join('\n');
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `attendance_${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
}

// Export absent employees to CSV
function exportAbsentToCSV() {
    const table = document.getElementById('absent-table');
    const rows = Array.from(table.querySelectorAll('tr'));
    const csv = rows.map(row => {
        const cells = Array.from(row.querySelectorAll('th, td'));
        return cells.map(cell => {
            const text = cell.innerText.replace(/\n/g, ' ').replace(/,/g, ';');
            return `"${text}"`;
        }).join(',');
    }).join('\n');
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `absent_employees_${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
}

// Auto-refresh every 30 seconds
setTimeout(() => {
    location.reload();
}, 30000);

// Close flash messages
document.addEventListener('DOMContentLoaded', function() {
    const closeButtons = document.querySelectorAll('.close-flash');
    closeButtons.forEach(button => {
        button.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });

    // Auto-hide flash messages after 5 seconds
    const flashMessages = document.querySelectorAll('.flash-message');
    flashMessages.forEach(message => {
        setTimeout(() => {
            message.style.display = 'none';
        }, 5000);
    });
});
</script>
