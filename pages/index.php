<?php
if (!defined('WEB_LOGUI')) die('File not included');

if (is_array($_POST['bulk-action'])) {
  $esBackend = new ElasticsearchBackend($settings->getElasticsearch());
  $dbh = $settings->getDatabase();

  if ($dbh) {
    $bulk_action = $_POST['bulk-action-cmd'];
    if (!in_array($bulk_action, ['bounce', 'delete', 'retry']))
      die('Invalid command');

    foreach ($_POST['bulk-action'] as $i) {
      if (!$_POST['bulk-action-index'][$i] || !$_POST['bulk-action-id'][$i])
        continue;
      $m = $esBackend->getMail($_POST['bulk-action-index'][$i], $_POST['bulk-action-id'][$i]);
      if (!$m)
        die('Invalid mail');

      $statement = $dbh->prepare('INSERT INTO pending_actions (msgid, actionid, serialno, action) VALUES (:msgid, :actionid, :serialno, :action);');
      $statement->execute([':msgid' => $m->msgid, ':actionid' => $m->msgactionid, ':serialno' => $m->serialno, ':action' => $bulk_action]);
    }
  }

  header('Location: ?page=index&pending='.$_POST['bulk-action-cmd']);
  die();
}

$action_colors = array(
  'DELIVER' => '#5cb85c',
  'QUEUE' => '#00aeef',
  'QUARANTINE' => '#f0ad4e',
  'ARCHIVE' => '#b8b8b8',
  'REJECT' => '#d9534f',
  'DELETE' => '#000',
  'BOUNCE' => '#000',
  'ERROR' => '#000',
  'DEFER' => '#e83e8c',
);

$action_icons = array(
	'DELIVER' => 'fas fa-check',
	'QUEUE' => 'fas fa-exchange-alt',
	'QUARANTINE' => 'fas fa-inbox',
	'ARCHIVE' => 'fas fa-inbox',
	'REJECT' => 'fas fa-ban',
	'DELETE' => 'far fa-trash-alt',
	'BOUNCE' => 'fas fa-reply',
	'ERROR' => 'fas fa-exclamation',
	'DEFER' => 'far fa-clock',
);

function get_preview_link($m, $opts = [])
{
  return '?'.http_build_query(array(
    'page' => 'preview',
    'id' => $m['doc']->id
  ) + $opts);
}

// Backend
$esBackend = new ElasticsearchBackend($settings->getElasticsearch());
$dbh = $settings->getDatabase();
if ($dbh)
  $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Default values
$search = isset($_GET['search']) ? $_GET['search'] : '';
$size = isset($_GET['size']) ? intval($_GET['size']) : 50;
$size = !isset($_GET['exportcsv']) && $size > 1000 ? 1000 : $size;
$bulk_action_enabled = false;

// time partitioning
[$es_start_ts, $es_stop_ts] = valid_date_range($_GET['start'], $_GET['stop']);

// Select box arrays
$pagesize = [25, 50, 100, 500, 1000];

// Initial settings
$results = [];
$prev_button = false;
$next_button = false;
$param = [];
$errors = [];

// Set offset with GET
$param['offset'] = 0;
if (isset($_GET['offset'])) {
  $param['offset'] = (int)$_GET['offset'];
  $prev_button = true; // enable "previous" page button
}

$param['sort'] = 'date';
if (isset($_GET['sort']) && in_array($_GET['sort'], ['to', 'from', 'subject', 'date']))
  $param['sort'] = $_GET['sort'];

$param['sortorder'] = 'DESC';
if (isset($_GET['order']) && in_array(strtoupper($_GET['order']), ['DESC', 'ASC']))
  $param['sortorder'] = strtoupper($_GET['order']);

$param['index_range'] = ['start_ts' => $es_start_ts, 'stop_ts' => $es_stop_ts];

if (isset($_GET['unsetfilter'])) {
  $filters = $_SESSION['filters'] ?? [];
  foreach ($filters as $field => $filter)
    foreach ($filter as $key => $value)
      if ($value['id'] == $_GET['unsetfilter']) {
        unset($filters[$field][$key]);
        if (count($filters[$field]) < 1)
          unset($filters[$field]);
      }
  $_SESSION['filters'] = $filters;
}

// ff = field, fo = operator, fv = value
if (isset($_GET['ff']) && isset($_GET['fo'])) {
  addFilter($_GET['ff'], $_GET['fo'], $_GET['fv'] ?? '');
} else if (isset($_GET['mf']) && is_array($_GET['mf']['f'])) {
  $_SESSION['filters'] = [];
  $active_view_id = $_GET['mf']['id'];
  foreach ($_GET['mf']['f'] as $filter) {
    if (isset($filter['ff']) && isset($filter['fo']) && isset($filter['fv']))
      addFilter($filter['ff'], $filter['fo'], $filter['fv']);
  }
}

if (isset($_SESSION['filters']))
  $param['filters'] = $_SESSION['filters'];

$mailHistory = $esBackend->loadMailHistory($search, $size, $param, $errors);

$results = $mailHistory['items'];
$total_count = $mailHistory['total'];
if (!$results)
  $results = [];

ksort($errors);

if (count($results) > $size) {
  array_pop($results);
  $next_button = true; // enable "next" page button
}

$mails = array();
$mails_inqueue = [];

foreach ($results as $m) {
  if ($i > $size) { break; }
  $i++;
  if ($m['type'] == 'archive') {
    $m['doc']->msgaction = 'ARCHIVE';
  }
  if ($m['type'] == 'queue' && $m['doc']->msgaction == 'DELIVER') $m['doc']->msgaction = 'QUEUE';

  $mail = array();
  $mail['template'] = new StdClass();

  if ($dbh && in_array($m['doc']->queue['action'] ?? $m['doc']->msgaction, ['QUEUE', 'QUARANTINE'])) {
    $mail['template']->inqueue = true;
    $mail['template']->pending_action = null;
    $mails_inqueue[] = ['msgid' => $m['doc']->msgid, 'actionid' => $m['doc']->msgactionid];
  }

  $mail['doc'] = $m['doc'];
  $mail['index'] = $m['index'];

  if ($m['doc']->msgts0 + (3600 * 24) > time())
    $mail['today'] = true;
  $mail['time'] = $m['doc']->msgts0 - $_SESSION['timezone'] * 60;

  $mail['preview'] = $preview;
  $mail['previewlink'] = get_preview_link($m, ['index' => $m['index']]);
  $mail['action'] = $m['doc']->queue['action'] ?? $m['doc']->msgaction;
  $mail['action_color'] = $action_colors[$mail['action']];
  $mail['action_icon'] = $action_icons[$mail['action']];
  if ($settings->getDisplayScores()) {
    $printscores = array();
    $scores = history_parse_scores($m['doc']);
    foreach ($scores as $engine => $s) {
      if ($engine == 'rpd' && $s['score'] != 'Unknown')
        $printscores[] = strtolower($s['score']);
      if ($engine == 'kav' && $s['score'] != 'Ok')
        $printscores[] = 'virus';
      if ($engine == 'clam' && $s['score'] != 'Ok')
        $printscores[] = 'virus';
      if ($engine == 'rpdav' && $s['score'] != 'Ok')
        $printscores[] = 'virus';
      if ($engine == 'sa')
        $printscores[] = $s['score'];
    }
    $mail['scores'] = implode(', ', array_unique($printscores));
  }
  $mails[] = $mail;
}

// check for pending actions
if (count($settings->getNodes() ?? 0 > 0) && count($mails_inqueue) > 0) {
  try {
    $filter = [];
    $params = [];

    foreach ($mails_inqueue as $k => $m) {
      $filter[] = '(msgid = :msgid'.$k.' AND actionid = :actionid'.$k.')';
      $params['msgid'.$k] = $m['msgid'];
      $params['actionid'.$k] = $m['actionid'];
    }
    $q = $dbh->prepare('SELECT * FROM pending_actions WHERE '.implode(' OR ', $filter).';');
    foreach ($params as $k => $v)
      $q->bindValue($k, $v);
    $q->execute();
    $pending = $q->fetchAll();

    foreach ($mails as $m) {
      if ($m['template']->inqueue) {
        foreach ($pending as $p)
          if ($p['msgid'] == $m['doc']->msgid && $p['actionid'] == $m['doc']->msgactionid) {
            $m['template']->pending_action = $p['action'];
            break;
          }
      }
    }
    $bulk_action_enabled = true;
  } catch (PDOException $e) {}
}

// csv export
if (isset($_GET['exportcsv'])) {
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename=export.csv');

  $fp = fopen('php://output', 'w');

  $csv_export = $_GET['export'];

  if ($csv_export['headers'] == true) {
    $csv_headers = [];
    if ($csv_export['action'])
      $csv_headers[] = 'action';
    if ($csv_export['from'])
      $csv_headers[] = 'from';
    if ($csv_export['to'])
      $csv_headers[] = 'to';
    if ($csv_export['subject'])
      $csv_headers[] = 'subject';
    if ($csv_export['status'])
      $csv_headers[] = 'status';
    if ($csv_export['date'])
      $csv_headers[] = 'date';
    if ($settings->getDisplayScores() && $csv_export['scores'])
      $csv_headers[] = 'scores';

    fputcsv($fp, $csv_headers);
  }

  foreach ($mails as $mail) {
    $csv_mail = [];
    if ($csv_export['action'])
      $csv_mail[] = $mail['doc']->queue['action'] ?? $mail['doc']->msgaction;
    if ($csv_export['from'])
      $csv_mail[] = $mail['doc']->msgfrom;
    if ($csv_export['to'])
      $csv_mail[] = $mail['doc']->msgto;
    if ($csv_export['subject'])
      $csv_mail[] = $mail['doc']->msgsubject;
    if ($csv_export['status']) {
      if ($mail['doc']->msgaction == 'QUARANTINE')
        $csv_mail[] = 'Quarantine';
      elseif ($mail['doc']->msgaction == 'ARCHIVE')
        $csv_mail[] = 'Archive';
      elseif ($mail['doc']->msgaction == 'QUEUE' && $mail['doc']->queue['action'] != 'DELIVER')
        $csv_mail[] = str_replace('%1', $mail['doc']->msgretries, 'In queue (retry '.$mail['doc']->queue['retry'].')').' '.$mail['doc']->msgdescription;
      else
        $csv_mail[] = $mail['doc']->queue['errormsg'] ?? $mail['doc']->msgdescription;
    }
    if ($csv_export['date'])
      $csv_mail[] = date('Y-m-d H:i:s', $mail['time']);
    if ($settings->getDisplayScores() && $csv_export['scores'])
        $csv_mail[] = $mail['scores'];

    fputcsv($fp, $csv_mail);
  }

  die();
}

$paging['offset'] = $param['offset'] + $size;

$mailAccess = Session::Get()->getAccess('mail') ?? [];
$domainAccess = Session::Get()->getAccess('domain') ?? [];

require_once BASE.'/inc/twig.php';

$twigLocals = [
  'search'                    => $search,
  'size'                      => $size,
  'errors'                    => $errors,
  'mailhasmultipleaddresses'  => count($mailAccess) != 1 || count($domainAccess) > 0,
  'search_domains'            => (count($domainAccess) > 0 && count($domainAccess) < 30) ? Session::Get()->getAccess('domain') : [],
  'feature_scores'            => $settings->getDisplayScores(),
  'es_start_ts'               => $es_start_ts,
  'es_stop_ts'                => $es_stop_ts,
  'mails'                     => $mails,
  'mails_count'               => $total_count,
  'prev_button'               => $prev_button,
  'next_button'               => $next_button,
  'pagesizes'                 => $pagesize,
  'paging'                    => $paging,
  'filters'                   => $_SESSION['filters'] ?? [],
  'sortby'                    => $param['sort'],
  'sortorder'                 => $param['sortorder'],
  'active_view_id'            => $active_view_id ?? false,
  'table_columns'             => $settings->getDisplayIndexColumns(),
  'bulk_action_enabled'       => $bulk_action_enabled
];

echo $twig->render('index.twig', $twigGlobals + $twigLocals);
