<?php
/** PHP 8.0 needs explicit access; PHP 8.1+ reflection is already accessible. */
if (!defined('GTLM_TEST_FIXTURE') || !GTLM_TEST_FIXTURE || !str_starts_with(DB_NAME, 'gtlm_test_')) exit(2);
function gtlm_test_access($reflection) {
 if (PHP_VERSION_ID < 80100) $reflection->setAccessible(true);
 return $reflection;
}
