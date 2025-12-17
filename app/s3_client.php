<?php
// app/s3_client.php
declare(strict_types=1);

use Aws\S3\S3Client;

$root = dirname(__DIR__, 1);
require_once $root . '/vendor/autoload.php';

function s3_cfg(): array {
  $cfgPath = dirname(__DIR__, 1) . '/config/aws.php';
  if (!is_file($cfgPath)) {
    throw new RuntimeException('Missing config/aws.php');
  }
  /** @var array $cfg */
  $cfg = require $cfgPath;

  if (!isset($cfg['credentials']) && (isset($cfg['key']) || isset($cfg['secret']) || isset($cfg['token']))) {
    $cfg['credentials'] = [
      'key'    => $cfg['key']    ?? null,
      'secret' => $cfg['secret'] ?? null,
      'token'  => $cfg['token']  ?? null,
    ];
  }
  if (empty($cfg['region']))  throw new RuntimeException('config/aws.php: "region" is empty.');
  if (empty($cfg['bucket']))  throw new RuntimeException('config/aws.php: "bucket" is empty.');
  if (empty($cfg['credentials']['key']) || empty($cfg['credentials']['secret'])) {
    throw new RuntimeException('config/aws.php: credentials.key/credentials.secret are empty.');
  }
  return $cfg;
}

function s3_client(): S3Client {
  static $cli = null;
  static $sig = null; // signature of current creds

  $cfg = s3_cfg();
  $curSig = hash('sha256',
    ($cfg['credentials']['key'] ?? '') . '|' .
    ($cfg['credentials']['secret'] ?? '') . '|' .
    ($cfg['credentials']['token'] ?? '')
  );

  // Recreate client if first time or creds changed (new token)
  if (!$cli || $sig !== $curSig) {
    $cli = new S3Client([
      'version'           => 'latest',
      'region'            => $cfg['region'],
      'signature_version' => 'v4',
      'credentials'       => [
        'key'    => $cfg['credentials']['key'],
        'secret' => $cfg['credentials']['secret'],
        'token'  => $cfg['credentials']['token'] ?? null,
      ],
    ]);
    $sig = $curSig;
  }
  return $cli;
}

function s3_bucket(): string {
  return s3_cfg()['bucket'];
}
