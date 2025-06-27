<?php
// index.php

// Ensure session is started at the very beginning of the main page.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include your database configuration and URL helper.
require 'config.php';
require_once 'url_helper.php';

// Redirect if user is not logged in.
if (!isset($_SESSION['username'])) {
    redirect('login.php');
}

$projectError = ""; // Initialize error messages
$deleteProjectError = "";

/* ---------------------------
    Project Deletion (Admin Only)
------------------------------ */
// NOTE: Deletion still remains admin-only, as per the original requirement.
if (isset($_GET['deleteProject']) && isset($_SESSION['admin']) && $_SESSION['admin'] == 1) {
    $delID = intval($_GET['deleteProject']);
    try {
        $pdo->beginTransaction();
        // Delete associated stages first
        $stmtDelStages = $pdo->prepare("DELETE FROM tblproject_stages WHERE projectID = ?");
        $stmtDelStages->execute([$delID]);
        // Then delete the project itself
        $stmtDel = $pdo->prepare("DELETE FROM tblproject WHERE projectID = ?");
        $stmtDel->execute([$delID]);
        $pdo->commit();
        // Removed header redirect on success to keep user on the page,
        // but note that the page will still refresh due to GET request.
        // A success message could be added here if desired.
        // For a seamless experience without refresh, AJAX would be required.
    } catch (PDOException $e) {
        $pdo->rollBack();
        $deleteProjectError = "Error deleting project: " . $e->getMessage();
    }
}

/* ---------------------------
    Add Project Processing
------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['addProject'])) {
    $prNumber = trim($_POST['prNumber']);
    $projectDetails = trim($_POST['projectDetails']);
    // Ensure userID is set. This makes sure only logged-in users can add.
    $userID = $_SESSION['userID'] ?? null; 

    // Enhanced error handling with more specific messages and a check for '0'
    if (is_null($userID)) {
        $projectError = "You must be logged in to add a project.";
    } elseif (empty($prNumber)) {
        $projectError = "Project Number is a required field.";
    } elseif ($prNumber === '0') { // Specific check for '0', as req   uested
        $projectError = "Project Number must contain only numbers (e.g., '123')."; 
    } elseif (!preg_match('/^[\d\-]+$/', $prNumber)) { // General 'numbers only' check
        $projectError = "Project Number must contain only numbers (e.g., '123').";
    } elseif (empty($projectDetails)) { // Check for project details after all project number validations
        $projectError = "Project Details is a required field.";
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO tblproject (prNumber, projectDetails, userID) VALUES (?, ?, ?)");
            $stmt->execute([$prNumber, $projectDetails, $userID]);
            $newProjectID = $pdo->lastInsertId();
            // Insert stages for the new project (set createdAt for 'Purchase Request')
            // This needs $stagesOrder array defined. Assuming it's defined elsewhere or will be defined.
            // For now, hardcode a common set of stages if $stagesOrder is not provided.
            $stagesOrder = [
                'Purchase Request' => 'PR',
                'RFQ 1' => 'RFQ1',
                'RFQ 2' => 'RFQ2',
                'RFQ 3' => 'RFQ3',
                'Abstract of Quotation' => 'AQ',
                'Purchase Order' => 'PO',
                'Notice of Award' => 'NOA',
                'Notice to Proceed' => 'NTP'
            ];

            foreach ($stagesOrder as $stageName => $shortForm) {
                $insertCreatedAt = ($stageName === 'Purchase Request') ? date("Y-m-d H:i:s") : null;
                $stmtInsertStage = $pdo->prepare("INSERT INTO tblproject_stages (projectID, stageName, createdAt) VALUES (?, ?, ?)");
                $stmtInsertStage->execute([$newProjectID, $stageName, $insertCreatedAt]);
            }
            $pdo->commit();
            // Removed header redirect on success. The page will naturally reload on POST
            // so the new project should appear.
            // A success message could be set here like $projectSuccess = "Project added successfully!";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $projectError = "Error adding project: " . $e->getMessage();
        }
    }
}

/* ---------------------------
    Retrieve Projects (with optional search)
------------------------------ */
$search = "";
if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
}

// Modified SQL query to fetch additional stage information.
$sql = "SELECT p.*, u.firstname, u.lastname,
        (SELECT isSubmitted FROM tblproject_stages WHERE projectID = p.projectID AND stageName = 'Notice to Proceed') AS notice_to_proceed_submitted,
        (SELECT s.stageName FROM tblproject_stages s WHERE s.projectID = p.projectID AND s.isSubmitted = 0
            ORDER BY FIELD(s.stageName, 'Purchase Request','RFQ 1','RFQ 2','RFQ 3','Abstract of Quotation','Purchase Order','Notice of Award','Notice to Proceed') ASC
            LIMIT 1) AS first_unsubmitted_stage
        FROM tblproject p
        JOIN tbluser u ON p.userID = u.userID";

if ($search !== "") {
    $sql .= " WHERE p.projectDetails LIKE ? OR p.prNumber LIKE ?";
}
$sql .= " ORDER BY COALESCE(p.editedAt, p.createdAt) DESC";
$stmt = $pdo->prepare($sql);
if ($search !== "") {
    $stmt->execute(["%$search%", "%$search%"]);
} else {
    $stmt->execute();
}
$projects = $stmt->fetchAll();

/* ---------------------------
    Calculate Statistics
------------------------------ */
$totalProjects = count($projects);
$finishedProjects = 0;

foreach ($projects as $project) {
    if ($project['notice_to_proceed_submitted'] == 1) {
        $finishedProjects++;
    }
}

$ongoingProjects = $totalProjects - $finishedProjects;
$percentageDone = ($totalProjects > 0) ? round(($finishedProjects / $totalProjects) * 100, 2) : 0;
$percentageOngoing = ($totalProjects > 0) ? round(($ongoingProjects / $totalProjects) * 100, 2) : 0;

// Define $showTitleRight for the header.php
// Set to false for the dashboard to remove "Bids and Awards Committee Tracking System"
$showTitleRight = false; // Hide "Bids and Awards Committee Tracking System" on dashboard
include 'view/index_content.php';
?>
