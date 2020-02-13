<?php

if (!isset($_SERVER['argc']))
  die('This file can only be run from command line');

require_once BASE.'/inc/core.php';
require_once BASE.'/inc/utils.php';

$dbh = $settings->getDatabase();
if (!$dbh)
  die('No database configured');

try {
  $statement = $dbh->prepare('SELECT * FROM pending_actions;');
  $statement->execute();

  while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
    $node = $settings->getNodeBySerial($row['serialno']);
    $client = $node->rest();
    $msgid = $row['msgid'];
    $actionid = $row['actionid'];

    if ($msgid && $actionid) {
      switch ($row['action']) {
        case 'bounce':
          echo "Bounce: $msgid:$actionid\n";
          $client->operation('/protobuf', 'POST', null, [
            'command' => 'G',
            'program' => 'smtpd',
            'payload' => [
              'conditions' => [
                'ids' => [
                  [
                    'transaction' => $msgid,
                    'queue' => $actionid
                  ]
                ]
              ],
              'action' => 'BOUNCE'
            ]
          ]);
          break;
        case 'delete':
          echo "Delete: $msgid:$actionid\n";
          $client->operation('/protobuf', 'POST', null, [
            'command' => 'G',
            'program' => 'smtpd',
            'payload' => [
              'conditions' => [
                'ids' => [
                  [
                    'transaction' => $msgid,
                    'queue' => $actionid
                  ]
                ]
              ],
              'action' => 'DELETE'
            ]
          ]);
          break;
        case 'retry':
          echo "Release: $msgid:$actionid\n";
          $client->operation('/protobuf', 'POST', null, [
            'command' => 'G',
            'program' => 'smtpd',
            'payload' => [
              'conditions' => [
                'ids' => [
                  [
                    'transaction' => $msgid,
                    'queue' => $actionid
                  ]
                ]
              ],
              'move' => [
                'queue' => 'ACTIVE'
              ]
            ]
          ]);
          break;
      }
    } else {
      echo "Skipping item ($msgid:$actionid)...\n";
    }
    $cleanup = $dbh->prepare('DELETE FROM pending_actions WHERE msgid = :msgid AND actionid = :actionid;');
    $cleanup->execute([':msgid' => $row['msgid'], ':actionid' => $row['actionid']]);
  }
} catch (RestException $e) {
  echo $e->getMessage();
} catch (PDOException $e) {
  echo $e->getMessage();
}
