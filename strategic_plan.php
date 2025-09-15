<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ob_start();

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'config.php';
$conn = getConnection();

// Get current user from session
$user = [
    'first_name' => isset($_SESSION['user_name']) ? explode(' ', $_SESSION['user_name'])[0] : 'User',
    'last_name' => isset($_SESSION['user_name']) ? (explode(' ', $_SESSION['user_name'])[1] ?? '') : '',
    'role' => $_SESSION['user_role'] ?? 'guest',
    'id' => $_SESSION['user_id']
];

// Permission check function
function hasPermission($requiredRole) {
    $userRole = $_SESSION['user_role'] ?? 'guest';
    
    // Permission hierarchy
    $roles = [
        'super_admin' => 5,
        'hr_manager' =>4 ,
        'managing_director'=>3,
        'dept_head' => 2,
        'section head'=>1,
        'employee' => 0
    ];
    
    $userLevel = $roles[$userRole] ?? 0;
    $requiredLevel = $roles[$requiredRole] ?? 0;
    
    return $userLevel >= $requiredLevel;
}

function formatDate($date) {
    if (!$date) return 'N/A';
    return date('M d, Y', strtotime($date));
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Add strategic plan
    if (isset($_POST['add_strategic_plan'])) {
        $name = $conn->real_escape_string($_POST['name']);
        $start_date = $conn->real_escape_string($_POST['start_date']);
        $end_date = $conn->real_escape_string($_POST['end_date']);
        
        $query = "INSERT INTO strategic_plan (name, start_date, end_date, created_at, updated_at) 
                  VALUES ('$name', '$start_date', '$end_date', NOW(), NOW())";
        
        if ($conn->query($query)) {
            $id = $conn->insert_id;
            // Handle image upload
            if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
                $start_year = date('Y', strtotime($start_date));
                $end_year = date('Y', strtotime($end_date));
                $folder = "Uploads/$start_year-$end_year/";
                if (!is_dir($folder)) {
                    mkdir($folder, 0777, true);
                }
                $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                    $image_name = $id . '.' . $ext;
                    $target = $folder . $image_name;
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                        $update_query = "UPDATE strategic_plan SET image='$target' WHERE id=$id";
                        $conn->query($update_query);
                    }
                }
            }
            $_SESSION['flash_message'] = "Strategic plan added successfully";
            $_SESSION['flash_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Error adding strategic plan: " . $conn->error;
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: strategic_plan.php?tab=strategic-plans");
        exit();
    }
    
    // Update strategic plan
    if (isset($_POST['update_strategic_plan'])) {
        $id = $conn->real_escape_string($_POST['id']);
        $name = $conn->real_escape_string($_POST['name']);
        $start_date = $conn->real_escape_string($_POST['start_date']);
        $end_date = $conn->real_escape_string($_POST['end_date']);
        
        // Get old data
        $old_query = "SELECT start_date, end_date, image FROM strategic_plan WHERE id='$id'";
        $old_result = $conn->query($old_query);
        if ($old_row = $old_result->fetch_assoc()) {
            $old_start = $old_row['start_date'];
            $old_end = $old_row['end_date'];
            $old_image = $old_row['image'];
            
            $image_update = '';
            $dates_changed = ($start_date != $old_start || $end_date != $old_end);
            
            if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
                // Delete old image
                if ($old_image && file_exists($old_image)) {
                    unlink($old_image);
                }
                // Upload new
                $start_year = date('Y', strtotime($start_date));
                $end_year = date('Y', strtotime($end_date));
                $folder = "Uploads/$start_year-$end_year/";
                if (!is_dir($folder)) {
                    mkdir($folder, 0777, true);
                }
                $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                    $image_name = $id . '.' . $ext;
                    $target = $folder . $image_name;
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                        $image_update = ", image='$target'";
                    }
                }
            } elseif ($dates_changed && $old_image) {
                // Move old image to new folder
                $old_start_year = date('Y', strtotime($old_start));
                $old_end_year = date('Y', strtotime($old_end));
                $new_start_year = date('Y', strtotime($start_date));
                $new_end_year = date('Y', strtotime($end_date));
                $new_folder = "Uploads/$new_start_year-$new_end_year/";
                if (!is_dir($new_folder)) {
                    mkdir($new_folder, 0777, true);
                }
                $ext = pathinfo($old_image, PATHINFO_EXTENSION);
                $new_image_name = $id . '.' . $ext;
                $new_target = $new_folder . $new_image_name;
                if (rename($old_image, $new_target)) {
                    $image_update = ", image='$new_target'";
                }
            }
            
            $query = "UPDATE strategic_plan SET name='$name', start_date='$start_date', 
                      end_date='$end_date' $image_update, updated_at=NOW() WHERE id='$id'";
            
            if ($conn->query($query)) {
                $_SESSION['flash_message'] = "Strategic plan updated successfully";
                $_SESSION['flash_type'] = "success";
            } else {
                $_SESSION['flash_message'] = "Error updating strategic plan: " . $conn->error;
                $_SESSION['flash_type'] = "danger";
            }
        }
        header("Location: strategic_plan.php?tab=strategic-plans");
        exit();
    }
    
    // Add objective
    if (isset($_POST['add_objective'])) {
        $strategic_plan_id = $conn->real_escape_string($_POST['strategic_plan_id']);
        $name = $conn->real_escape_string($_POST['name']);
        $start_date = $conn->real_escape_string($_POST['start_date']);
        $end_date = $conn->real_escape_string($_POST['end_date']);
        
        $query = "INSERT INTO objectives (strategic_plan_id, name, start_date, end_date, created_at, updated_at) 
                  VALUES ('$strategic_plan_id', '$name', '$start_date', '$end_date', NOW(), NOW())";
        
        if ($conn->query($query)) {
            $_SESSION['flash_message'] = "Objective added successfully";
            $_SESSION['flash_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Error adding objective: " . $conn->error;
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: strategic_plan.php?tab=objectives");
        exit();
    }
    
    // Update objective
    if (isset($_POST['update_objective'])) {
        $id = $conn->real_escape_string($_POST['id']);
        $strategic_plan_id = $conn->real_escape_string($_POST['strategic_plan_id']);
        $name = $conn->real_escape_string($_POST['name']);
        $start_date = $conn->real_escape_string($_POST['start_date']);
        $end_date = $conn->real_escape_string($_POST['end_date']);
        
        $query = "UPDATE objectives SET strategic_plan_id='$strategic_plan_id', name='$name', 
                  start_date='$start_date', end_date='$end_date', updated_at=NOW() WHERE id='$id'";
        
        if ($conn->query($query)) {
            $_SESSION['flash_message'] = "Objective updated successfully";
            $_SESSION['flash_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Error updating objective: " . $conn->error;
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: strategic_plan.php?tab=objectives");
        exit();
    }
    
    // Add strategy
    if (isset($_POST['add_strategy'])) {
        $strategic_plan_id = $conn->real_escape_string($_POST['strategic_plan_id']);
        $objective_id = $conn->real_escape_string($_POST['objective_id']);
        $name = $conn->real_escape_string($_POST['name']);
        $start_date = $conn->real_escape_string($_POST['start_date']);
        $end_date = $conn->real_escape_string($_POST['end_date']);
        
        $query = "INSERT INTO strategies (strategic_plan_id, objective_id, name, start_date, end_date, created_at, updated_at) 
                  VALUES ('$strategic_plan_id', '$objective_id', '$name', '$start_date', '$end_date', NOW(), NOW())";
        
        if ($conn->query($query)) {
            $_SESSION['flash_message'] = "Strategy added successfully";
            $_SESSION['flash_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Error adding strategy: " . $conn->error;
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: strategic_plan.php?tab=strategies");
        exit();
    }
    
    // Update strategy
    if (isset($_POST['update_strategy'])) {
        $id = $conn->real_escape_string($_POST['id']);
        $strategic_plan_id = $conn->real_escape_string($_POST['strategic_plan_id']);
        $objective_id = $conn->real_escape_string($_POST['objective_id']);
        $name = $conn->real_escape_string($_POST['name']);
        $start_date = $conn->real_escape_string($_POST['start_date']);
        $end_date = $conn->real_escape_string($_POST['end_date']);
        
        $query = "UPDATE strategies SET strategic_plan_id='$strategic_plan_id', objective_id='$objective_id', name='$name', 
                  start_date='$start_date', end_date='$end_date', updated_at=NOW() WHERE id='$id'";
        
        if ($conn->query($query)) {
            $_SESSION['flash_message'] = "Strategy updated successfully";
            $_SESSION['flash_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Error updating strategy: " . $conn->error;
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: strategic_plan.php?tab=strategies");
        exit();
    }
    
    // Add activity
    if (isset($_POST['add_activity'])) {
        $strategy_id = $conn->real_escape_string($_POST['strategy_id']);
        $activity = $conn->real_escape_string($_POST['activity']);
        $kpi = $conn->real_escape_string($_POST['kpi']);
        $target = $conn->real_escape_string($_POST['target']);
        $y1 = $conn->real_escape_string($_POST['Y1'] ?? '');
        $y2 = $conn->real_escape_string($_POST['Y2'] ?? '');
        $y3 = $conn->real_escape_string($_POST['Y3'] ?? '');
        $y4 = $conn->real_escape_string($_POST['Y4'] ?? '');
        $y5 = $conn->real_escape_string($_POST['Y5'] ?? '');
        $comment = $conn->real_escape_string($_POST['comment'] ?? '');
        
        $query = "INSERT INTO activities (strategy_id, activity, kpi, target, Y1, Y2, Y3, Y4, Y5, comment, created_at, updated_at) 
                  VALUES ('$strategy_id', '$activity', '$kpi', '$target', '$y1', '$y2', '$y3', '$y4', '$y5', '$comment', NOW(), NOW())";
        
        if ($conn->query($query)) {
            $_SESSION['flash_message'] = "Activity added successfully";
            $_SESSION['flash_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Error adding activity: " . $conn->error;
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: strategic_plan.php?tab=activities");
        exit();
    }
    
    // Update activity
    if (isset($_POST['update_activity'])) {
        $id = $conn->real_escape_string($_POST['id']);
        $activity = $conn->real_escape_string($_POST['activity']);
        $kpi = $conn->real_escape_string($_POST['kpi']);
        $target = $conn->real_escape_string($_POST['target']);
        $y1 = $conn->real_escape_string($_POST['Y1'] ?? '');
        $y2 = $conn->real_escape_string($_POST['Y2'] ?? '');
        $y3 = $conn->real_escape_string($_POST['Y3'] ?? '');
        $y4 = $conn->real_escape_string($_POST['Y4'] ?? '');
        $y5 = $conn->real_escape_string($_POST['Y5'] ?? '');
        $comment = $conn->real_escape_string($_POST['comment'] ?? '');
        
        $query = "UPDATE activities SET activity='$activity', kpi='$kpi', target='$target', 
                  Y1='$y1', Y2='$y2', Y3='$y3', Y4='$y4', Y5='$y5', comment='$comment', updated_at=NOW() WHERE id='$id'";
        
        if ($conn->query($query)) {
            $_SESSION['flash_message'] = "Activity updated successfully";
            $_SESSION['flash_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Error updating activity: " . $conn->error;
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: strategic_plan.php?tab=goals");
        exit();
    }
}

// Handle delete actions
if (isset($_GET['action'])) {
    if ($_GET['action'] == 'delete_strategic_plan' && isset($_GET['id'])) {
        $id = $conn->real_escape_string($_GET['id']);
        
        // Delete image
        $image_query = "SELECT image FROM strategic_plan WHERE id = '$id'";
        $image_result = $conn->query($image_query);
        if ($image_row = $image_result->fetch_assoc()) {
            if ($image_row['image'] && file_exists($image_row['image'])) {
                unlink($image_row['image']);
            }
        }
        
        // Delete related objectives, strategies, and activities
        $conn->query("DELETE FROM objectives WHERE strategic_plan_id = '$id'");
        $conn->query("DELETE a FROM activities a LEFT JOIN strategies s ON a.strategy_id = s.id WHERE s.strategic_plan_id = '$id'");
        $conn->query("DELETE FROM strategies WHERE strategic_plan_id = '$id'");
        
        // Delete the strategic plan
        $query = "DELETE FROM strategic_plan WHERE id = '$id'";
        
        if ($conn->query($query)) {
            $_SESSION['flash_message'] = "Strategic plan and its related data deleted successfully";
            $_SESSION['flash_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Error deleting strategic plan: " . $conn->error;
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: strategic_plan.php?tab=strategic-plans");
        exit();
    }
    
    if ($_GET['action'] == 'delete_objective' && isset($_GET['id'])) {
        $id = $conn->real_escape_string($_GET['id']);
        // Update strategies to remove objective_id reference
        $conn->query("UPDATE strategies SET objective_id=NULL WHERE objective_id='$id'");
        $query = "DELETE FROM objectives WHERE id = '$id'";
        
        if ($conn->query($query)) {
            $_SESSION['flash_message'] = "Objective deleted successfully";
            $_SESSION['flash_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Error deleting objective: " . $conn->error;
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: strategic_plan.php?tab=objectives");
        exit();
    }
    
    if ($_GET['action'] == 'delete_strategy' && isset($_GET['id'])) {
        $id = $conn->real_escape_string($_GET['id']);
        // Delete related activities
        $conn->query("DELETE FROM activities WHERE strategy_id = '$id'");
        $query = "DELETE FROM strategies WHERE id = '$id'";
        
        if ($conn->query($query)) {
            $_SESSION['flash_message'] = "Strategy deleted successfully";
            $_SESSION['flash_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Error deleting strategy: " . $conn->error;
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: strategic_plan.php?tab=strategies");
        exit();
    }
    
    if ($_GET['action'] == 'delete_activity' && isset($_GET['id'])) {
        $id = $conn->real_escape_string($_GET['id']);
        $query = "DELETE FROM activities WHERE id = '$id'";
        
        if ($conn->query($query)) {
            $_SESSION['flash_message'] = "Activity deleted successfully";
            $_SESSION['flash_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Error deleting activity: " . $conn->error;
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: strategic_plan.php?tab=activities");
        exit();
    }
}

// Fetch all strategic plans and get the latest one
$strategic_plans = [];
$latest_plan_id = null;
$query = "SELECT * FROM strategic_plan ORDER BY id DESC"; // Order by id DESC to get latest first
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $strategic_plans[] = $row;
        if ($latest_plan_id === null) {
            $latest_plan_id = $row['id']; // Store the latest plan ID
        }
    }
}

// Fetch all objectives
$objectives = [];
$query = "SELECT o.*, sp.name as strategic_plan_name 
          FROM objectives o 
          LEFT JOIN strategic_plan sp ON o.strategic_plan_id = sp.id 
          ORDER BY o.start_date DESC";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $objectives[] = $row;
    }
}

// Fetch all strategies
$strategies = [];
$query = "SELECT s.*, sp.name as strategic_plan_name, o.name as objective_name 
          FROM strategies s 
          LEFT JOIN strategic_plan sp ON s.strategic_plan_id = sp.id 
          LEFT JOIN objectives o ON s.objective_id = o.id 
          ORDER BY s.start_date DESC";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $strategies[] = $row;
    }
}

// Fetch all activities
$activities = [];
$query = "SELECT a.*, s.name as strategy_name, s.start_date as strategy_start, s.end_date as strategy_end, sp.name as strategic_plan_name, o.name as objective_name, s.strategic_plan_id as strategic_plan_id 
          FROM activities a 
          LEFT JOIN strategies s ON a.strategy_id = s.id 
          LEFT JOIN strategic_plan sp ON s.strategic_plan_id = sp.id 
          LEFT JOIN objectives o ON s.objective_id = o.id 
          ORDER BY s.start_date DESC";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $activities[] = $row;
    }
}

$conn->close();

// Get strategic plans for dropdown
$strategic_plans_dropdown = [];
foreach ($strategic_plans as $plan) {
    $strategic_plans_dropdown[$plan['id']] = $plan['name'];
}

// Get objectives for dropdown
$objectives_dropdown = [];
foreach ($objectives as $objective) {
    $objectives_dropdown[$objective['id']] = $objective['name'];
}

// Get strategies for dropdown
$strategies_dropdown = [];
foreach ($strategies as $strategy) {
    $strategies_dropdown[$strategy['id']] = $strategy['name'];
}

// Get flash message if exists
$flash_message = '';
$flash_type = '';
if (isset($_SESSION['flash_message'])) {
    $flash_message = $_SESSION['flash_message'];
    $flash_type = $_SESSION['flash_type'];
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}

include 'header.php';
include 'nav_bar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Strategic Plan - HR Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Styles remain unchanged */
        .card {
            background: var(--bg-glass);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            border-color: var(--border-accent);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .card-title {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--text-muted);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--text-secondary);
        }
        
        .empty-state p {
            margin-bottom: 1.5rem;
            color: var(--text-muted);
        }
        
        .tabs {
            display: flex;
            background: var(--bg-glass);
            border-radius: 12px;
            padding: 0.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
            backdrop-filter: blur(20px);
            overflow-x: auto;
            gap: 0.5rem;
        }
        
        .tabs ul {
            display: flex;
            list-style: none;
            margin: 0;
            padding: 0;
            width: 100%;
        }
        
        .tab-link {
            flex: 1;
            min-width: 150px;
            padding: 0.75rem 1.5rem;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 0.875rem;
            border-radius: 8px;
            transition: var(--transition);
            text-align: center;
            white-space: nowrap;
            position: relative;
            background: transparent;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
        }
        
        .tab-link:hover {
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--border-color);
        }
        
        .tab-link.active {
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            color: white;
            box-shadow: 0 4px 15px rgba(0, 212, 255, 0.3);
            border-color: var(--primary-color);
        }
        
        .tab-link.active::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            border-radius: 10px;
            z-index: -1;
            opacity: 0.3;
            filter: blur(4px);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .modal-content {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 0;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            animation: slideUp 0.3s ease-out;
        }
        
        .modal-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-glass);
        }
        
        .modal-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
        }
        
        .modal form {
            padding: 2rem;
            max-height: calc(90vh - 120px);
            overflow-y: auto;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .strategic-plans-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .strategic-plan-card {
            background: var(--bg-card);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            border: 1px solid var(--border-color);
        }
        
        .strategic-plan-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--border-accent);
        }
        
        .plan-image {
            height: 180px;
            overflow: hidden;
            position: relative;
        }
        
        .plan-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .strategic-plan-card:hover .plan-image img {
            transform: scale(1.05);
        }
        
        .plan-image-placeholder {
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            color: white;
            font-size: 3rem;
            opacity: 0.7;
        }
        
        .plan-details {
            padding: 1.5rem;
        }
        
        .plan-details h4 {
            margin: 0 0 0.5rem 0;
            font-size: 1.2rem;
            color: var(--text-primary);
        }
        
        .plan-dates {
            color: var(--text-muted);
            margin: 0 0 1rem 0;
            font-size: 0.9rem;
        }
        
        .plan-progress {
            margin-top: 1rem;
        }
        
        .progress-bar {
            height: 8px;
            background-color: var(--border-color);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        .progress-text {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        .plan-actions {
            padding: 0 1.5rem 1.5rem;
            display: flex;
            gap: 0.5rem;
        }
        
        .plan-actions .btn {
            flex: 1;
        }
        
        .preview-image {
            max-width: 100%;
            height: auto;
            margin-top: 0.5rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        
        .goals-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: var(--bg-glass);
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }
        
        .goals-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
        }
        
        .plan-selector {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .plan-selector label {
            font-weight: 500;
            color: var(--text-secondary);
        }
        
        .plan-selector select {
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.5rem 1rem;
            color: var(--text-primary);
            min-width: 200px;
        }
        
        .strategic-plan-image-container {
            width: 100%;
            height: 70vh;
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
            background: var(--bg-glass);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            margin-bottom: 2rem;
        }
        
        .strategic-plan-image-container img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            padding: 10px;
        }
        
        .no-image-placeholder {
            text-align: center;
            color: var(--text-muted);
        }
        
        .no-image-placeholder i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .no-image-placeholder h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        #strategies-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        #strategies-table th {
            background-color: var(--bg-glass);
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: var(--text-primary);
            border-bottom: 2px solid var(--border-color);
        }
        
        #strategies-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-secondary);
        }
        
        #strategies-table tr:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .text-center {
            text-align: center;
        }
        
        .mt-4 {
            margin-top: 1.5rem;
        }
        
        .year-input {
            width: 60px;
            text-align: center;
        }
        
        .edit-btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .tab-link {
                min-width: 120px;
            }
            
            .strategic-plans-grid {
                grid-template-columns: 1fr;
            }
            
            .goals-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .plan-selector {
                width: 100%;
                flex-direction: column;
                align-items: flex-start;
            }
            
            .plan-selector select {
                width: 100%;
            }
            
            .strategic-plan-image-container {
                height: 40vh;
            }
        }
    </style>
</head><body>
    <div class="container">
        
        <!-- Main Content Area -->
        <div class="main-content">
            <div class="content">
                <div class="leave-tabs">
                    <a href="strategic_plan.php?tab=goals" class="leave-tab">Strategic Plan</a>
                    <a href="employee_appraisal.php" class="leave-tab">Employee Appraisal</a>
                    <?php if (in_array($user['role'], ['hr_manager', 'super_admin', 'manager', 'managing_director', 'section_head', 'dept_head'])): ?>
                        <a href="performance_appraisal.php" class="leave-tab">Performance Appraisal</a>
                    <?php endif; ?>
                    <?php if (in_array($user['role'], ['hr_manager', 'super_admin', 'manager'])): ?>
                        <a href="appraisal_management.php" class="leave-tab">Appraisal Management</a>
                    <?php endif; ?>
                    <a href="completed_appraisals.php" class="leave-tab">Completed Appraisals</a>
                </div>
                
                <?php if ($flash_message): ?>
                <div class="alert alert-<?php echo $flash_type; ?>">
                    <?php echo $flash_message; ?>
                </div>
                <?php endif; ?>
                
                <div class="tabs">
                    <ul>
                        <li><a href="#" class="tab-link active" data-tab="goals">Goals</a></li>
                          <?php if (hasPermission('hr_manager')): ?>
                            <li><a href="#" class="tab-link" data-tab="strategic-plans">Strategic Plans</a></li>
                          <li><a href="#" class="tab-link" data-tab="objectives">Objectives</a></li>
                           <li><a href="#" class="tab-link" data-tab="strategies">Strategies</a></li>
                            <li><a href="#" class="tab-link" data-tab="activities">Activities</a></li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Goals Tab -->
                <div id="goals" class="tab-content active">
                    <div class="goals-header">
                        <h3 class="goals-title">Strategic Goals</h3>
                        <div class="plan-selector">
                            <label for="strategic_plan_select">Select Strategic Plan:</label>
                            <select id="strategic_plan_select">
                                <option value="">-- Choose a plan --</option>
                                <?php 
                                // Get the latest strategic plan ID
                                $latest_plan_id = null;
                                if (!empty($strategic_plans)) {
                                    $latest_plan = reset($strategic_plans);
                                    $latest_plan_id = $latest_plan['id'];
                                }
                                
                                foreach ($strategic_plans as $plan): 
                                ?>
                                <option value="<?php echo $plan['id']; ?>" 
                                        data-image="<?php echo htmlspecialchars($plan['image'] ?? ''); ?>"
                                        <?php echo ($plan['id'] == $latest_plan_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($plan['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Image container with automatic sizing -->
                    <div class="strategic-plan-image-container">
                        <?php if (!empty($strategic_plans) && isset($latest_plan['image']) && $latest_plan['image']): ?>
                            <img id="strategic_plan_image" src="<?php echo htmlspecialchars($latest_plan['image']); ?>" 
                                 alt="Strategic Plan">
                        <?php else: ?>
                            <div class="no-image-placeholder">
                                <i class="fas fa-image"></i>
                                <h3>No Strategic Plan Image Available</h3>
                                <p>Please select a strategic plan with an uploaded image</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Strategies Table -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h3 class="card-title">Strategic Plan Details</h3>
                        </div>
                        <div class="table-responsive">
                            <table class="table" id="strategies-table">
                                <thead>
                                    <tr>
                                        <th>Strategic Plan</th>
                                        <th>Objective</th>
                                        <th>Strategy</th>
                                        <th>Activity</th>
                                        <th>KPI</th>
                                        <th>Target</th>
                                        <th>Y1</th>
                                        <th>Y2</th>
                                        <th>Y3</th>
                                        <th>Y4</th>
                                        <th>Y5</th>
                                        <th>Comment</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($activities)): ?>
                                        <?php foreach ($activities as $activity): ?>
                                        <tr data-plan-id="<?php echo $activity['strategic_plan_id']; ?>">
                                            <td><?php echo htmlspecialchars($activity['strategic_plan_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($activity['objective_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($activity['strategy_name']); ?></td>
                                            <td><?php echo htmlspecialchars($activity['activity']); ?></td>
                                            <td><?php echo htmlspecialchars($activity['kpi'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($activity['target'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($activity['Y1'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($activity['Y2'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($activity['Y3'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($activity['Y4'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($activity['Y5'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($activity['comment'] ?? 'N/A'); ?></td>
                                            <td>
                                               <button class="btn btn-sm btn-primary edit-btn" 
        onclick="editActivity(
            <?php echo intval($activity['id']); ?>, 
            '<?php echo htmlspecialchars(json_encode($activity['activity'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 
            '<?php echo htmlspecialchars(json_encode($activity['kpi'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 
            '<?php echo htmlspecialchars(json_encode($activity['target'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 
            '<?php echo htmlspecialchars(json_encode($activity['Y1'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 
            '<?php echo htmlspecialchars(json_encode($activity['Y2'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 
            '<?php echo htmlspecialchars(json_encode($activity['Y3'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 
            '<?php echo htmlspecialchars(json_encode($activity['Y4'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 
            '<?php echo htmlspecialchars(json_encode($activity['Y5'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 
            '<?php echo htmlspecialchars(json_encode($activity['comment'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>'
        )">
    <i class="fas fa-edit"></i> Edit
</button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                    <tr>
                                        <td colspan="13" class="text-center">No activities found. Please add activities in the Activities tab.</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Strategic Plans Tab -->
                <div id="strategic-plans" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Strategic Plans</h3>
                            <button class="btn btn-primary" onclick="openModal('addStrategicPlanModal')">
                                <i class="fas fa-plus"></i> Add Strategic Plan
                            </button>
                        </div>
                        <?php if (empty($strategic_plans)): ?>
                        <div class="empty-state">
                            <i class="fas fa-chess-board"></i>
                            <h3>No Strategic Plans Found</h3>
                            <p>Get started by adding your first strategic plan</p>
                            <button class="btn btn-primary mt-3" onclick="openModal('addStrategicPlanModal')">
                                <i class="fas fa-plus"></i> Add Strategic Plan
                            </button>
                        </div>
                        <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Image</th>
                                    <th>Created At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($strategic_plans as $plan): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($plan['name']); ?></td>
                                    <td><?php echo formatDate($plan['start_date']); ?></td>
                                    <td><?php echo formatDate($plan['end_date']); ?></td>
                                    <td>
                                        <?php if (isset($plan['image']) && $plan['image']): ?>
                                        <img src="<?php echo htmlspecialchars($plan['image']); ?>" alt="Plan Image" width="50" height="50" style="border-radius: 4px;">
                                        <?php else: ?>
                                        N/A
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatDate($plan['created_at']); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" 
                                                onclick="editStrategicPlan(<?php echo $plan['id']; ?>, '<?php echo addslashes(htmlspecialchars($plan['name'])); ?>', '<?php echo $plan['start_date']; ?>', '<?php echo $plan['end_date']; ?>', '<?php echo addslashes(htmlspecialchars($plan['image'] ?? '')); ?>')">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <a href="strategic_plan.php?action=delete_strategic_plan&id=<?php echo $plan['id']; ?>" 
                                           class="btn btn-sm btn-danger" 
                                           onclick="return confirm('Are you sure you want to delete this strategic plan and all its related data?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Objectives Tab -->
                <div id="objectives" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Objectives</h3>
                            <button class="btn btn-primary" onclick="openModal('addObjectiveModal')">
                                <i class="fas fa-plus"></i> Add Objective
                            </button>
                        </div>
                        <?php if (empty($objectives)): ?>
                        <div class="empty-state">
                            <i class="fas fa-bullseye"></i>
                            <h3>No Objectives Found</h3>
                            <p>Get started by adding your first objective</p>
                            <button class="btn btn-primary mt-3" onclick="openModal('addObjectiveModal')">
                                <i class="fas fa-plus"></i> Add Objective
                            </button>
                        </div>
                        <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Strategic Plan</th>
                                    <th>Objectives</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Created At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($objectives as $objective): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($objective['strategic_plan_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($objective['name']); ?></td>
                                    <td><?php echo formatDate($objective['start_date']); ?></td>
                                    <td><?php echo formatDate($objective['end_date']); ?></td>
                                    <td><?php echo formatDate($objective['created_at']); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" 
                                                onclick="editObjective(<?php echo $objective['id']; ?>, <?php echo $objective['strategic_plan_id']; ?>, '<?php echo addslashes(htmlspecialchars($objective['name'])); ?>', '<?php echo $objective['start_date']; ?>', '<?php echo $objective['end_date']; ?>')">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <a href="strategic_plan.php?action=delete_objective&id=<?php echo $objective['id']; ?>" 
                                           class="btn btn-sm btn-danger" 
                                           onclick="return confirm('Are you sure you want to delete this objective?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Strategies Tab -->
                <?php if (hasPermission('hr_manager')): ?>
                <div id="strategies" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Strategies</h3>
                            <button class="btn btn-primary" onclick="openModal('addStrategyModal')">
                                <i class="fas fa-plus"></i> Add Strategy
                            </button>
                        </div>
                        <?php if (empty($strategies)): ?>
                        <div class="empty-state">
                            <i class="fas fa-lightbulb"></i>
                            <h3>No Strategies Found</h3>
                            <p>Get started by adding your first strategy</p>
                            <button class="btn btn-primary mt-3" onclick="openModal('addStrategyModal')">
                                <i class="fas fa-plus"></i> Add Strategy
                            </button>
                        </div>
                        <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Strategic Plan</th>
                                    <th>Objective</th>
                                    <th>Strategy</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Created At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($strategies as $strategy): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($strategy['strategic_plan_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($strategy['objective_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($strategy['name']); ?></td>
                                    <td><?php echo formatDate($strategy['start_date']); ?></td>
                                    <td><?php echo formatDate($strategy['end_date']); ?></td>
                                    <td><?php echo formatDate($strategy['created_at']); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" 
                                                onclick="editStrategy(<?php echo $strategy['id']; ?>, <?php echo $strategy['strategic_plan_id']; ?>, <?php echo $strategy['objective_id'] ?? 'null'; ?>, '<?php echo addslashes(htmlspecialchars($strategy['name'])); ?>', '<?php echo $strategy['start_date']; ?>', '<?php echo $strategy['end_date']; ?>')">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <a href="strategic_plan.php?action=delete_strategy&id=<?php echo $strategy['id']; ?>" 
                                           class="btn btn-sm btn-danger" 
                                           onclick="return confirm('Are you sure you want to delete this strategy?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Activities Tab -->
                <?php if (hasPermission('hr_manager')): ?>
                <div id="activities" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Activities</h3>
                            <button class="btn btn-primary" onclick="openModal('addActivityModal')">
                                <i class="fas fa-plus"></i> Add Activity
                            </button>
                        </div>
                        <?php if (empty($activities)): ?>
                        <div class="empty-state">
                            <i class="fas fa-tasks"></i>
                            <h3>No Activities Found</h3>
                            <p>Get started by adding your first activity</p>
                            <button class="btn btn-primary mt-3" onclick="openModal('addActivityModal')">
                                <i class="fas fa-plus"></i> Add Activity
                            </button>
                        </div>
                        <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Strategy</th>
                                    <th>Activity</th>
                                    <th>KPI</th>
                                    <th>Target</th>
                                    <th>Created At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activities as $activity): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($activity['strategy_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($activity['activity']); ?></td>
                                    <td><?php echo htmlspecialchars($activity['kpi'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($activity['target'] ?? 'N/A'); ?></td>
                                    <td><?php echo formatDate($activity['created_at']); ?></td>
                                    <td>
                                       <button class="btn btn-sm btn-primary edit-btn" 
        onclick="editActivity(
            <?php echo intval($activity['id']); ?>, 
            '<?php echo htmlspecialchars(json_encode($activity['activity'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 
            '<?php echo htmlspecialchars(json_encode($activity['kpi'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 
            '<?php echo htmlspecialchars(json_encode($activity['target'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 
            '<?php echo htmlspecialchars(json_encode($activity['Y1'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 
            '<?php echo htmlspecialchars(json_encode($activity['Y2'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 
            '<?php echo htmlspecialchars(json_encode($activity['Y3'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 
            '<?php echo htmlspecialchars(json_encode($activity['Y4'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 
            '<?php echo htmlspecialchars(json_encode($activity['Y5'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 
            '<?php echo htmlspecialchars(json_encode($activity['comment'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>'
        )">
    <i class="fas fa-edit"></i> Edit
</button>
                                        <a href="strategic_plan.php?action=delete_activity&id=<?php echo $activity['id']; ?>" 
                                           class="btn btn-sm btn-danger" 
                                           onclick="return confirm('Are you sure you want to delete this activity?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Add Strategic Plan Modal -->
    <div id="addStrategicPlanModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Add Strategic Plan</h3>
                <button class="close" onclick="closeModal('addStrategicPlanModal')">&times;</button>
            </div>
            <form method="POST" action="strategic_plan.php" enctype="multipart/form-data">
                <input type="hidden" name="add_strategic_plan" value="1">
                <div class="form-group">
                    <label class="form-label" for="name">Name</label>
                    <input type="text" class="form-control" id="name" name="name" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="start_date">Start Date</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="end_date">End Date</label>
                        <input type="date" class="form-control" id="end_date" name="end_date" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="image">Image</label>
                    <input type="file" class="form-control" id="image" name="image" accept="image/jpeg,image/png,image/gif">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addStrategicPlanModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Strategic Plan</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Strategic Plan Modal -->
    <div id="editStrategicPlanModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Edit Strategic Plan</h3>
                <button class="close" onclick="closeModal('editStrategicPlanModal')">&times;</button>
            </div>
            <form method="POST" action="strategic_plan.php" enctype="multipart/form-data">
                <input type="hidden" name="update_strategic_plan" value="1">
                <input type="hidden" id="edit_strategic_plan_id" name="id">
                <div class="form-group">
                    <label class="form-label" for="edit_name">Name</label>
                    <input type="text" class="form-control" id="edit_name" name="name" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="edit_start_date">Start Date</label>
                        <input type="date" class="form-control" id="edit_start_date" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_end_date">End Date</label>
                        <input type="date" class="form-control" id="edit_end_date" name="end_date" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Current Image</label>
                    <img id="edit_image_preview" src="" alt="Current Image" class="preview-image" style="display: none;">
                </div>
                <div class="form-group">
                    <label class="form-label" for="edit_image">New Image (optional)</label>
                    <input type="file" class="form-control" id="edit_image" name="image" accept="image/jpeg,image/png,image/gif">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editStrategicPlanModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Strategic Plan</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Add Objective Modal -->
    <div id="addObjectiveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Add Objective</h3>
                <button class="close" onclick="closeModal('addObjectiveModal')">&times;</button>
            </div>
            <form method="POST" action="strategic_plan.php">
                <input type="hidden" name="add_objective" value="1">
                <div class="form-group">
                    <label class="form-label" for="strategic_plan_id">Strategic Plan</label>
                    <select class="form-control" id="strategic_plan_id" name="strategic_plan_id" required>
                        <option value="">Select Strategic Plan</option>
                        <?php foreach ($strategic_plans_dropdown as $id => $name): ?>
                        <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="obj_name">Name</label>
                    <input type="text" class="form-control" id="obj_name" name="name" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="obj_start_date">Start Date</label>
                        <input type="date" class="form-control" id="obj_start_date" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="obj_end_date">End Date</label>
                        <input type="date" class="form-control" id="obj_end_date" name="end_date" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addObjectiveModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Objective</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Objective Modal -->
    <div id="editObjectiveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Edit Objective</h3>
                <button class="close" onclick="closeModal('editObjectiveModal')">&times;</button>
            </div>
            <form method="POST" action="strategic_plan.php">
                <input type="hidden" name="update_objective" value="1">
                <input type="hidden" id="edit_objective_id" name="id">
                <div class="form-group">
                    <label class="form-label" for="edit_obj_strategic_plan_id">Strategic Plan</label>
                    <select class="form-control" id="edit_obj_strategic_plan_id" name="strategic_plan_id" required>
                        <option value="">Select Strategic Plan</option>
                        <?php foreach ($strategic_plans_dropdown as $id => $name): ?>
                        <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="edit_obj_name">Name</label>
                    <input type="text" class="form-control" id="edit_obj_name" name="name" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="edit_obj_start_date">Start Date</label>
                        <input type="date" class="form-control" id="edit_obj_start_date" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_obj_end_date">End Date</label>
                        <input type="date" class="form-control" id="edit_obj_end_date" name="end_date" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editObjectiveModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Objective</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Add Strategy Modal -->
    <div id="addStrategyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Add Strategy</h3>
                <button class="close" onclick="closeModal('addStrategyModal')">&times;</button>
            </div>
            <form method="POST" action="strategic_plan.php">
                <input type="hidden" name="add_strategy" value="1">
                <div class="form-group">
                    <label class="form-label" for="strategy_strategic_plan_id">Strategic Plan</label>
                    <select class="form-control" id="strategy_strategic_plan_id" name="strategic_plan_id" required>
                        <option value="">Select Strategic Plan</option>
                        <?php foreach ($strategic_plans_dropdown as $id => $name): ?>
                        <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="strategy_objective_id">Objective</label>
                    <select class="form-control" id="strategy_objective_id" name="objective_id" required>
                        <option value="">Select Objective</option>
                        <?php foreach ($objectives_dropdown as $id => $name): ?>
                        <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="strategy_name">Name</label>
                    <input type="text" class="form-control" id="strategy_name" name="name" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="strategy_start_date">Start Date</label>
                        <input type="date" class="form-control" id="strategy_start_date" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="strategy_end_date">End Date</label>
                        <input type="date" class="form-control" id="strategy_end_date" name="end_date" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addStrategyModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Strategy</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Strategy Modal -->
    <div id="editStrategyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Edit Strategy</h3>
                <button class="close" onclick="closeModal('editStrategyModal')">&times;</button>
            </div>
            <form method="POST" action="strategic_plan.php">
                <input type="hidden" name="update_strategy" value="1">
                <input type="hidden" id="edit_strategy_id" name="id">
                <div class="form-group">
                    <label class="form-label" for="edit_strategy_strategic_plan_id">Strategic Plan</label>
                    <select class="form-control" id="edit_strategy_strategic_plan_id" name="strategic_plan_id" required>
                        <option value="">Select Strategic Plan</option>
                        <?php foreach ($strategic_plans_dropdown as $id => $name): ?>
                        <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="edit_strategy_objective_id">Objective</label>
                    <select class="form-control" id="edit_strategy_objective_id" name="objective_id" required>
                        <option value="">Select Objective</option>
                        <?php foreach ($objectives_dropdown as $id => $name): ?>
                        <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="edit_strategy_name">Name</label>
                    <input type="text" class="form-control" id="edit_strategy_name" name="name" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="edit_strategy_start_date">Start Date</label>
                        <input type="date" class="form-control" id="edit_strategy_start_date" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_strategy_end_date">End Date</label>
                        <input type="date" class="form-control" id="edit_strategy_end_date" name="end_date" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editStrategyModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Strategy</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Activity Modal -->
    <div id="addActivityModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Add Activity</h3>
                <button class="close" onclick="closeModal('addActivityModal')">&times;</button>
            </div>
            <form method="POST" action="strategic_plan.php">
                <input type="hidden" name="add_activity" value="1">
                <div class="form-group">
                    <label class="form-label" for="add_activity_strategy_id">Strategy</label>
                    <select class="form-control" id="add_activity_strategy_id" name="strategy_id" required>
                        <option value="">Select Strategy</option>
                        <?php foreach ($strategies_dropdown as $id => $name): ?>
                        <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="add_activity">Activity</label>
                    <textarea class="form-control" id="add_activity" name="activity" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="add_kpi">KPI</label>
                    <input type="text" class="form-control" id="add_kpi" name="kpi" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="add_target">Target</label>
                    <input type="text" class="form-control" id="add_target" name="target" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="add_y1">Y1</label>
                        <input type="number" class="form-control" id="add_y1" name="Y1" step="0.01" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="add_y2">Y2</label>
                        <input type="number" class="form-control" id="add_y2" name="Y2" step="0.01" min="0">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="add_y3">Y3</label>
                        <input type="number" class="form-control" id="add_y3" name="Y3" step="0.01" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="add_y4">Y4</label>
                        <input type="number" class="form-control" id="add_y4" name="Y4" step="0.01" min="0">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="add_y5">Y5</label>
                        <input type="number" class="form-control" id="add_y5" name="Y5" step="0.01" min="0">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="add_comment">Comment</label>
                    <textarea class="form-control" id="add_comment" name="comment" rows="3"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addActivityModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Activity</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Activity Modal -->
    <div id="editActivityModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Edit Activity</h3>
                <button class="close" onclick="closeModal('editActivityModal')">&times;</button>
            </div>
            <form method="POST" action="strategic_plan.php">
                <input type="hidden" name="update_activity" value="1">
                <input type="hidden" id="edit_activity_id" name="id">
                <div class="form-group">
                    <label class="form-label" for="edit_activity">Activity</label>
                    <textarea class="form-control" id="edit_activity" name="activity" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="edit_kpi">KPI</label>
                    <input type="text" class="form-control" id="edit_kpi" name="kpi">
                </div>
                <div class="form-group">
                    <label class="form-label" for="edit_target">Target</label>
                    <input type="text" class="form-control" id="edit_target" name="target">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="edit_y1">Y1</label>
                        <input type="number" class="form-control" id="edit_y1" name="Y1" step="0.01" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_y2">Y2</label>
                        <input type="number" class="form-control" id="edit_y2" name="Y2" step="0.01" min="0">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="edit_y3">Y3</label>
                        <input type="number" class="form-control" id="edit_y3" name="Y3" step="0.01" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_y4">Y4</label>
                        <input type="number" class="form-control" id="edit_y4" name="Y4" step="0.01" min="0">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="edit_y5">Y5</label>
                        <input type="number" class="form-control" id="edit_y5" name="Y5" step="0.01" min="0">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="edit_comment">Comment</label>
                    <textarea class="form-control" id="edit_comment" name="comment" rows="3"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editActivityModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Activity</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tab switching function
        function showTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-link').forEach(tab => {
                tab.classList.remove('active');
            });
            const tabContent = document.getElementById(tabId);
            if (tabContent) {
                tabContent.classList.add('active');
                const tabLink = document.querySelector(`.tab-link[data-tab="${tabId}"]`);
                if (tabLink) {
                    tabLink.classList.add('active');
                }
            }
        }

        // Add event listeners to tabs
        document.querySelectorAll('.tab-link').forEach(tab => {
            tab.addEventListener('click', (e) => {
                e.preventDefault();
                showTab(tab.getAttribute('data-tab'));
            });
        });

        // Set active tab based on URL query parameter
        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const activeTab = urlParams.get('tab') || 'goals';
            if (document.getElementById(activeTab)) {
                showTab(activeTab);
            }

            // Handle strategic plan selection
            const planSelect = document.getElementById('strategic_plan_select');
            const goalsImage = document.getElementById('strategic_plan_image');
            const placeholder = document.querySelector('.no-image-placeholder');

            // Plan selector change event
            planSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const imageUrl = selectedOption.getAttribute('data-image');
                const planId = selectedOption.value;
                
                // Update image
                if (imageUrl) {
                    goalsImage.src = imageUrl;
                    goalsImage.style.display = 'block';
                    if (placeholder) placeholder.style.display = 'none';
                } else {
                    goalsImage.style.display = 'none';
                    if (placeholder) placeholder.style.display = 'block';
                }
                
                // Filter strategies table
                const tableRows = document.querySelectorAll('#strategies-table tbody tr');
                tableRows.forEach(row => {
                    if (planId === '' || row.getAttribute('data-plan-id') === planId) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });

            // Initialize image and placeholder based on default selection
            const initialSelected = planSelect.options[planSelect.selectedIndex];
            if (initialSelected && initialSelected.getAttribute('data-image')) {
                goalsImage.src = initialSelected.getAttribute('data-image');
                goalsImage.style.display = 'block';
                if (placeholder) placeholder.style.display = 'none';
            } else {
                goalsImage.style.display = 'none';
                if (placeholder) placeholder.style.display = 'block';
            }

            // Initialize table filtering based on default selection
            const event = new Event('change');
            planSelect.dispatchEvent(event);
        });

        // Modal functions with improved error handling
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'flex';
                // Add modal backdrop styling
                modal.style.position = 'fixed';
                modal.style.top = '0';
                modal.style.left = '0';
                modal.style.width = '100%';
                modal.style.height = '100%';
                modal.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
                modal.style.justifyContent = 'center';
                modal.style.alignItems = 'center';
                modal.style.zIndex = '1000';
            } else {
                console.error(`Modal ${modalId} not found`);
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
            } else {
                console.error(`Modal ${modalId} not found`);
            }
        }

        function editStrategicPlan(id, name, startDate, endDate, image) {
            document.getElementById('edit_strategic_plan_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_start_date').value = startDate;
            document.getElementById('edit_end_date').value = endDate;
            const preview = document.getElementById('edit_image_preview');
            if (image) {
                preview.src = image;
                preview.style.display = 'block';
            } else {
                preview.style.display = 'none';
            }
            openModal('editStrategicPlanModal');
        }

        function editObjective(id, strategicPlanId, name, startDate, endDate) {
            document.getElementById('edit_objective_id').value = id;
            document.getElementById('edit_obj_strategic_plan_id').value = strategicPlanId;
            document.getElementById('edit_obj_name').value = name;
            document.getElementById('edit_obj_start_date').value = startDate;
            document.getElementById('edit_obj_end_date').value = endDate;
            openModal('editObjectiveModal');
        }

        function editStrategy(id, strategicPlanId, objectiveId, name, startDate, endDate) {
            document.getElementById('edit_strategy_id').value = id;
            document.getElementById('edit_strategy_strategic_plan_id').value = strategicPlanId;
            document.getElementById('edit_strategy_objective_id').value = objectiveId || '';
            document.getElementById('edit_strategy_name').value = name;
            document.getElementById('edit_strategy_start_date').value = startDate;
            document.getElementById('edit_strategy_end_date').value = endDate;
            openModal('editStrategyModal');
        }

        // Fixed editActivity function with proper error handling and field validation
        function editActivity(id, activity, kpi, target, y1, y2, y3, y4, y5, comment) {
            console.log('Editing activity with ID:', id); // Debug line
            
            // Ensure the modal exists
            const modal = document.getElementById('editActivityModal');
            if (!modal) {
                console.error('Edit Activity Modal not found');
                return;
            }
            
            // Helper function to set field values with proper null/undefined handling
            const setFieldValue = (fieldId, value) => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.value = value || '';
                } else {
                    console.error(`Field ${fieldId} not found`);
                }
            };
            
            // Set all form field values
            setFieldValue('edit_activity_id', id);
            setFieldValue('edit_activity', activity);
            setFieldValue('edit_kpi', kpi);
            setFieldValue('edit_target', target);
            setFieldValue('edit_y1', y1);
            setFieldValue('edit_y2', y2);
            setFieldValue('edit_y3', y3);
            setFieldValue('edit_y4', y4);
            setFieldValue('edit_y5', y5);
            setFieldValue('edit_comment', comment);
            
            // Open the modal
            openModal('editActivityModal');
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>