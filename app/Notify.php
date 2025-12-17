<?php
use Aws\Sns\SnsClient;
use Aws\Exception\AwsException;

function sns_notify(string $subject, string $message, array $attrs = []): bool {
  $cfg = require __DIR__ . '/../config/aws.php';

  // Build the client configuration
  $client_config = [
    'version'     => '2010-03-31',
    'region'      => $cfg['region'],
    'credentials' => [
        'key'    => $cfg['credentials']['key'],
        'secret' => $cfg['credentials']['secret'],
    ]
  ];

  // Add session token if it exists in the config
  if (!empty($cfg['credentials']['token']) && $cfg['credentials']['token'] !== 'PASTE_YOUR_SESSION_TOKEN_HERE') {
      $client_config['credentials']['token'] = $cfg['credentials']['token'];
  }
  
  $sns = new SnsClient($client_config);

  $p = ['TopicArn'=>$cfg['topic_arn'],'Subject'=>$subject,'Message'=>$message];
  if ($attrs) foreach ($attrs as $k=>$v)
    $p['MessageAttributes'][$k] = ['DataType'=>'String','StringValue'=>(string)$v];
  try { 
    $sns->publish($p); 
    return true; 
  }
  catch (AwsException $e) { 
    error_log('[SNS] '.$e->getAwsErrorMessage()); 
    return false; 
  }
}

