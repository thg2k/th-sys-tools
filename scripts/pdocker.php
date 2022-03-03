#!/usr/bin/php
<?php

/**
 * Parses the pdocker aliases file
 *
 * @return array<string, string> ...
 */
function parse_aliases()
{
  $data = @file_get_contents("/opt/pdocker.txt");
  if ($data === false)
    return array();

  $data = str_replace("\r\n", "\n", $data);

  $retval = array();
  foreach (explode("\n", $data) as $line) {
    $line = trim($line);
    if (($line == "") || (substr($line, 0, 1) == ";"))
      continue;

    if ($line == "_EOF_")
      break;

    $toks = preg_split('/\s+/', $line);
    // if (isset($retval[$toks[0]]))
      // continue;
    // if (isset($retval[$toks[0]]))
      // throw new \Exception("Duplicated alias \"" . $toks[0] . "\"");

    $retval[$toks[0]] = $toks[1];
  }

  return $retval;
}

$Aliases = parse_aliases();
// var_dump($Aliases); exit();

$argv_copy = $argv;
$prog_name = array_shift($argv_copy);

$action = array_shift($argv_copy);
if ($action == "image")
  $action .= " " . array_shift($argv_copy);

if ($action == "") {
  ksort($Aliases);
  foreach ($Aliases as $alias => $label) {
    print $alias . "  " . $label . "\n";
  }
  print "\n";
  print("Usage: " . $argv[0] . " <action> [id]\n");

  print "\nCommands supported:\n";
  print "   inspect\n";
  print "   parse\n";
  print "   image history\n";
  print "   image ls\n";

  exit(1);
}

function manip(&$data) {
  global $Aliases;

  foreach ($Aliases as $alias => $label) {
    // $wrap = " \e[33;1m[$label]\e[m";
    // $data = str_replace($alias, $alias . $wrap, $data);

    $data = preg_replace("/(^|[^0-9a-f])($alias)([0-9a-f]*)/",
        "\\1\e[1m\\2\e[m\\3 \e[33;1m$label\e[m", $data);
  }
}


// print "[+] Executing...\n";
switch ($action) {
case 'ps':
  $data = shell_exec("docker ps -a");
  $xlines = explode("\n", trim($data));

  /* remove the headers */
  array_shift($xlines);

  $pad_cid = 13;  // 17ade7745a9c
  $pad_img = 26;  // phpmyadmin/phpmyadmin:4.9
  $pad_crt = 18;  // 45 minutes ago
  $pad_upt = 18;  // Up 13 minutes
  $pad_pps = 46;  // [94.130.180.73]:tcp{80->80, 443->443, 2022->22}; [10.99.101.1]:tcp{25->25}

  /* print our own headers */
  $_ctrl_s = "\e[m";
  $_ctrl_e = "\e[m";
  $data = array();
  $data[] = $_ctrl_s .
      str_pad("CONTAINER ID", $pad_cid) . " " .
      str_pad("IMAGE", $pad_img) . " " .
      str_pad("CREATED", $pad_crt) . " " .
      str_pad("STATUS", $pad_upt) . " " .
      str_pad("PORTS", $pad_pps) . " " .
      "NAMES" . $_ctrl_e . "\n";

  foreach ($xlines as $xline) {
    /* we need to use smart parsing, there are spaces in that stuff */
    $toks = preg_split('/\s{2,}/', $xline);
    if (count($toks) == 6) {
      $toks[6] = $toks[5];
      $toks[5] = "";
    }
    if (count($toks) != 7)
      exit("Bogus entry line: $xline\n");

    /* manip the status (Created/Up/Exited) */
    $_ctrl_s = "\e[m";
    if (substr($toks[4], 0, 6) == "Exited") {
      $_ctrl_s = "\e[31m";
      $toks[4] = "Ex" . substr($toks[4], 7);
    }
    elseif (substr($toks[4], 0, 2) == "Up") {
      $_ctrl_s = "\e[32m";
    }
    elseif (substr($toks[4], 0, 5) == "Creat") {
      $_ctrl_s = "\e[33m";
    }

    /* manip the ports */
    $_ports = preg_split('/, /', $toks[5]);
    $_ports_by_if = array();
    foreach ($_ports as $port) {
      if (preg_match('{^([0-9.]+):(\d+)->(\d+)/(tcp|udp)$}', $port, $regp)) {
        $_if_name = "[" .
            ($regp[1] == "0.0.0.0" ? "*" : $regp[1]) . "]:" . $regp[4];
        $_if_ports = ($regp[2] == $regp[3] ? $regp[2] :
            $regp[2] . "->" . $regp[3]);
        $_ports_by_if[$_if_name][] = $_if_ports;
      }
      elseif (preg_match('{^(\d+)/(tcp|udp)$}', $port, $regp)) {
        // not exposed?
        $_if_name = "[-]:" . $regp[2];
        $_if_ports = $regp[1];
        $_ports_by_if[$_if_name][] = $_if_ports;
      }
      elseif ($port != "") {
        exit("Bogus ports: " . $toks[5] . "\n");
      }
    }
    $_ports = array();
    foreach ($_ports_by_if as $_if => $_if_ports) {
      $_ports[$_if] = $_if . "{";
      foreach ($_if_ports as $idx => $port) {
        $_ports[$_if] .= ($idx > 0 ? "," : "") . $port;
      }
      $_ports[$_if] .= "}";
    }
    // print " PORTS: " . $toks[5] . "  ==>  " . implode("; ", $_ports) . "\n"; continue;
    $toks[5] = implode("; ", $_ports);

    $data[] = sprintf($_ctrl_s . "%-{$pad_cid}s %-{$pad_img}s %-{$pad_crt}s %-{$pad_upt}s %-{$pad_pps}s \e[1m%s" . $_ctrl_e . "\n",
        $toks[0],  // container id
        $toks[1],  // image name
        $toks[3],  // created
        $toks[4],  // uptime
        $toks[5],  // ports
        $toks[6]); // name
  }
  break;

case 'parse':
  $data = file_get_contents("php://stdin");
  manip($data);
  break;

case 'pull':
case 'inspect':
case 'image inspect':
  $id = array_shift($argv_copy);
  if ($id == "")
    die("Missing 'id' parameter");

  if (in_array($id, $Aliases))
    $id = array_search($id, $Aliases);

  $data = shell_exec("docker $action $id");

  manip($data);
  break;

case 'image history':
  if (!empty($argv_copy[0]) && in_array($argv_copy[0], $Aliases))
    $argv_copy[0] = array_search($argv_copy[0], $Aliases);

  $data = shell_exec("docker image history " . implode(" ", $argv_copy));

  manip($data);
  break;

case 'image ls';
  $data = shell_exec("docker image ls " . implode(" ", $argv_copy));

  /* remove the headers */
  $data = explode("\n", trim($data));
  array_shift($data);
  foreach ($data as &$xline) {
    $toks = preg_split('/  +/', $xline);
    // $xline = implode(" ", $toks);
    manip($toks[2]);
    if (strlen($toks[2]) == 12)
      $toks[2] .= str_repeat(" ", 48 - strlen($toks[2]));
    else
      $toks[2] .= str_repeat(" ", 65 - strlen($toks[2]));

    // $toks[2] = str_pad($toks[2], (strlen($toks[2]) 50 - strlen($

    $xline = sprintf("%-40s %-14s %s %-20s %-20s",
        $toks[0], $toks[1], $toks[2], $toks[3], $toks[4]);
  }
  
  $data = implode("\n", $data) . "\n";
  break;

default:
  die("Invalid action \"$action\"\n");
}

  
// print "[+] Reparsing...\n";

print (is_array($data) ? implode("", $data) : $data);
