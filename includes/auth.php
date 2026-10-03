<?php
require_once __DIR__ . '/session.php';

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isStaff() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'staff';
}

function isStudent() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'student';
}

function redirectIfNotLoggedIn() {
    if (!isLoggedIn()) {
        header('Location: ../index.php');
        exit;
    }
}

function redirectIfNotStaff() {
    redirectIfNotLoggedIn();
    if (!isStaff()) {
        header('Location: ../student/dashboard.php');
        exit;
    }
}

function redirectIfNotStudent() {
    redirectIfNotLoggedIn();
    if (!isStudent()) {
        header('Location: ../staff/dashboard.php');
        exit;
    }
}
/**
 * Returns true if the current user is a staff member with the admin role.
 */
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'staff' && isset($_SESSION['staff_role']) && $_SESSION['staff_role'] === 'admin';
}

/**
 * Returns true if the current user is a staff member with the lead role.
 */
function isLead() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'staff' && isset($_SESSION['staff_role']) && $_SESSION['staff_role'] === 'lead';
}

/**
 * Returns true if the current user is a staff member with the viewer role.
 */
function isViewer() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'staff' && isset($_SESSION['staff_role']) && $_SESSION['staff_role'] === 'viewer';
}
?>