<?php
// Ensure Patchwork is loaded before any WordPress or plugin code so that
// Brain Monkey can redefine core functions when required.
require_once __DIR__ . '/../vendor/antecedent/patchwork/Patchwork.php';

// Load Composer's autoloader for the test suite.
require_once __DIR__ . '/../vendor/autoload.php';
