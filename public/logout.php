<?php
/**
 * Ends the current session and returns the user to the login page.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
session_destroy();
header('Location: login.php');
exit;
