<?php
// api/logout.php

require_once '../helpers/response.php';
require_once '../helpers/auth.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Invalid request method'], 405);
}

// Destroy the session
logout();

sendResponse(['message' => 'Logged out successfully'], 200);
?>
