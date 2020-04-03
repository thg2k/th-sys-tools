#!/usr/bin/php
<?php


function parse_aliases() {
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
// var_dump($Aliases);

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
  die("Invalid action \"$action\"");
}

  
// print "[+] Reparsing...\n";


print $data;
