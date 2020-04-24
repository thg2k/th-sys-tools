#!/usr/bin/env php
<?php

$home = getenv("HOME");
$history = $home . "/.bash_history";

$fd = fopen($history, "r");
if ($fd === false)
  die("ERROR: cannot open history at '$history'\n");

$CFG_PREFIX = 60;
$CFG_CUTOFF = 3000;

$stats_removed_dups = 0;
$stats_removed_long = 0;
$stats_saved = 0;

$lines = array();
$lineno = 0;
while (!feof($fd)) {
  /* aquire line */
  $lineno++;
  $line = fgets($fd);

  if ($line === false)
    continue;

  /* chop end of line */
  if (substr($line, -1) != "\n") {
    die("ERROR AT $lineno: not EOL\n");
  }
  $line = substr($line, 0, -1);

  // if ((strpos($line, "\n") !== false) ||
       // strpos($line, "\r") !== false) {
// var_dump($line);
    // die("ERROR AT $lineno: extra EOL\n");
  // }

  /* prepare representation data */
  $_length = strlen($line);
  if ($_length > $CFG_PREFIX)
    $_prefix = substr($line, 0, $CFG_PREFIX) . "...[TRIMMED]";
  else
    $_prefix = $line;


  /* discard extra long lines */
  if ($_length > $CFG_CUTOFF) {
    printf("SKIP %6d LEN %6d [TLL] : %s\n", $lineno, $_length, $_prefix);
    $stats_removed_long++;
    continue;
  }

  /* discard duplicates */
  if (in_array($line, $lines, true)) {
    printf("SKIP %6d LEN %6d [DUP] : %s\n", $lineno, $_length, $_prefix);
    $stats_removed_dups++;
    continue;
  }

  /* copy this line */
  printf("LINE %6d LEN %6d [---] : %s\n", $lineno, $_length, $_prefix);
  $lines[] = $line;
  $stats_saved++;
}

printf("\n");
printf("Operation completed.\n");
printf("\n");
printf("    Processed lines: %6d\n", $lineno);
printf("     Discarded dups: %6d\n", $stats_removed_dups);
printf("     Discarded long: %6d\n", $stats_removed_long);
printf("          Preserved: %6d\n", $stats_saved);
printf("\n");

file_put_contents($history . ".purified", implode("\n", $lines));
