#!/usr/bin/php
<?php

function dbg($message)
{
  // print "[d] $message\n";
}

function collect_data()
{
  $raw_output = shell_exec("df -T -B 1M");
  $lines = explode("\n", $raw_output);

  /* drop the header line */
  array_shift($lines);

  $entries = array();
  foreach ($lines as $line) {
    if ($line == "")
      continue;
    $toks = preg_split('/\s+/', $line);
    // var_dump($line, $toks);
  // exit();

    $rec = array();
    $rec['fsname'] = $toks[0];
    $rec['fstype'] = $toks[1];
    $rec['size'] = $toks[2];
    $rec['used'] = $toks[3];
    $rec['free'] = $toks[4];
    $rec['pp'] = $toks[5];
    $rec['mount'] = $toks[6];

    $entries[] = $rec;
  }

  return $entries;
}

function lookup_docker_data($container_id)
{
  dbg("Looking up docker container \"$container_id\"");
  $data_json = shell_exec("docker inspect $container_id");

  $data = json_decode($data_json, true);
  if (!$data)
    throw new \Exception("Failed to obtain docker container data for \"$container_id\"");
  return $data[0];
}

function resolve_docker_references(array &$entries)
{
  $mapids = array();

  /* first collect container entries, they will also contain the volume ids */
  foreach ($entries as $entry) {
    if (preg_match('{^/var/lib/docker/containers/([0-9a-f]+)/}', $entry['mount'], $regp)) {
      $container_id = $regp[1];
      dbg("Found docker container \"$container_id\"");
      $container_data = lookup_docker_data($container_id);
      // var_dump($container_data); exit();

      if (!isset($container_data['Name'])) {
        var_dump($container_data); exit();
      }

      $container_name = substr($container_data['Name'], 1);
      $mapids[$container_id] = "{docker:" . $container_name . "=" .
          substr($container_id, 0, 12) . "...}";

      $volume_path = $container_data['GraphDriver']['Data']['MergedDir'];
      if (preg_match('{^/var/lib/docker/overlay2/([0-9a-f]+)/}', $volume_path, $regp)) {
        $volume_id = $regp[1];
        $mapids[$volume_id] = "{docker:" . $container_name . ":fs=" .
            substr($volume_id, 0, 12) . "...}";
      }
    }
  }

  /* now we can resolve the mount points */
  $repl_keys = array();
  $repl_vals = array();
  foreach ($mapids as $xid => $xlab) {
    $repl_keys[] = $xid;
    $repl_vals[] = $xlab;
  }

  foreach ($entries as &$entry) {
    $entry['mount'] = str_replace($repl_keys, $repl_vals, $entry['mount']);
  }
}

function _sort_callback($a, $b)
{
  return strcmp($a['mount'], $b['mount']);
}

function sort_data(array &$entries)
{
  usort($entries, '_sort_callback');
}

function _fmt_size($str)
{
  if (preg_match('/^(\d+)(\d{3})$/', $str, $regp)) {
    $str = $regp[1] . "'" . $regp[2];
  }

  return $str;
}

function _fmt_mount($str)
{
  return preg_replace('/{([^:=]+:)([^:=]+)([^=]*)=(.*)}/',
    "\e[36m" . "{\\1" .
    "\e[36;1m" . "\\2" . "\e[m" .
    "\e[36m" . "\\3=\\4}" . "\e[m", $str);
}

// Filesystem                     Type          Total size
// ------------------------------ -----------   --------- ---------- ---------- ----- ----------------

function display_entries(array $entries)
{
  printf("        Filesystem                 Type        Total      Used      Avail.     %%    Mount point\n");
  printf("------------------------------ ------------ ---------- ---------- ---------- ----- ----------------\n");
  //      /dev/sda1                      ext4            153'844     92'692     54'870   63% /
  //      xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx xxxxxxxxxxxx xxxxxxxxxx xxxxxxxxxx xxxxxxxxxx xxxxx xxx...

  foreach ($entries as $entry) {
    printf("%-30s %-12s %10s %10s %10s %5s %s\n",
      $entry['fsname'],
      $entry['fstype'],
      _fmt_size($entry['size']),
      _fmt_size($entry['used']),
      _fmt_size($entry['free']),
      $entry['pp'],
      _fmt_mount($entry['mount']));
  }

}


$entries = collect_data();
// var_dump($entries); exit();


resolve_docker_references($entries);


sort_data($entries);


display_entries($entries);

