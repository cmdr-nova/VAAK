<?php
/**
 * Back-compat: Social metrics URL now lives in unified Nova Admin.
 */
declare(strict_types=1);
$view = preg_replace('/[^a-z]/', '', (string) ($_GET['view'] ?? 'feed')) ?: 'feed';
header('Location: /admin?view=' . rawurlencode($view), true, 302);
exit;
