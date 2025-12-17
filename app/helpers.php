<?php
// app/helpers.php

/**
 * Escapes a string for safe HTML output.
 *
 * @param mixed $v The value to escape.
 * @return string The escaped HTML string.
 */
if (!function_exists('e')) {
  function e(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
  }
}