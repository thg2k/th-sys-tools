#!/usr/bin/env php
<?php

chdir(__DIR__);

$save_mode = ((isset($argv[1]) ? $argv[1] : null) == "save");

function test_exec($cmdline)
{
  print "[+] Executing: $cmdline\n";
  system($cmdline);
}

function test_script_exec(array $cmdline, $case)
{
  global $save_mode;

  $cmdline = implode(";", $cmdline);

  print "[+] Executing command: $case\n";
  system(sprintf('(cd ../../; %s) > %s 2>&1', $cmdline, escapeshellarg($case . ".tmp")));

  if ($save_mode) {
    rename("$case.tmp", "$case.out");
    return;
  }

  $cmdline = sprintf("%s -u %s %s",
      "diff",
      "$case.out",
      "$case.tmp");
  $ret = system($cmdline);
  if ($ret != "") {
    print "\n\n*** Error *** Output does not match! (case $case)\n\n";
    exit(1);
  }
  unlink("$case.tmp");
}

/* ------------------------------------------------------------------------ */

/* generate command line usage due to missing file (goes to stderr) */
test_script_exec(array(
    './scripts/tail_http_access_log.php ""',
    './scripts/tail_http_access_log.php -Z',
    './scripts/tail_http_access_log.php -h',
  ), "test_tail_http_access_log.case0");

/* ------------------------------------------------------------------------ */

/* run the full analysis of the data file */
test_script_exec(array(
    './scripts/tail_http_access_log.php -S -c 999 ' .
      './tests/standalone/test_tail_http_access_log.data.log',
  ), "test_tail_http_access_log.case1");

/* ------------------------------------------------------------------------ */

/* check the rollbacking */
test_script_exec(array(
  'echo "# -B 99"',
  './scripts/tail_http_access_log.php -B 99 -c 999 ' .
      './tests/standalone/test_tail_http_access_log.data.log',
  'echo "# -B 100"',
  './scripts/tail_http_access_log.php -B 100 -c 999 ' .
      './tests/standalone/test_tail_http_access_log.data.log',
  'echo "# -B 101"',
  './scripts/tail_http_access_log.php -B 101 -c 999 ' .
      './tests/standalone/test_tail_http_access_log.data.log',
  ), "test_tail_http_access_log.case2");

print "[+] All tests successfull\n";

exit(0);
