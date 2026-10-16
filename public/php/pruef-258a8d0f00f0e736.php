<?php
// TEMPORÄR – Serverprüfung Mitgliederbereich, wird nach dem Test wieder entfernt.
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
$oben = dirname(__DIR__, 2);
$probe = $oben . '/.schreibprobe-' . bin2hex(random_bytes(4));
$schreibbar = @file_put_contents($probe, 'x') !== false;
if ($schreibbar) { @unlink($probe); }
echo json_encode([
  'php' => PHP_VERSION,
  'pdo_sqlite' => extension_loaded('pdo_sqlite'),
  'sqlite_version' => class_exists('SQLite3') ? SQLite3::version()['versionString'] : null,
  'fileinfo' => extension_loaded('fileinfo'),
  'gd' => extension_loaded('gd'),
  'gd_webp' => function_exists('imagewebp'),
  'imagick' => extension_loaded('imagick'),
  'zip' => class_exists('ZipArchive'),
  'mbstring' => extension_loaded('mbstring'),
  'upload_max_filesize' => ini_get('upload_max_filesize'),
  'post_max_size' => ini_get('post_max_size'),
  'max_file_uploads' => ini_get('max_file_uploads'),
  'memory_limit' => ini_get('memory_limit'),
  'max_execution_time' => ini_get('max_execution_time'),
  'file_uploads' => ini_get('file_uploads'),
  'oben_schreibbar' => $schreibbar,
  'oben_name' => basename($oben),
  'httpdocs_name' => basename(dirname(__DIR__)),
  'sapi' => PHP_SAPI,
], JSON_PRETTY_PRINT);
