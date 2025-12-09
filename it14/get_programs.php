<?php
// get_programs.php - AJAX endpoint to get programs by department
require_once 'config.php';

if (isset($_GET['department_id'])) {
    $department_id = $_GET['department_id'];
    $programs = getProgramsByDepartment($department_id);
    header('Content-Type: application/json');
    echo json_encode($programs);
    exit();
}
?>