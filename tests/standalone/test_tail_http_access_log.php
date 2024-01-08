#!/usr/bin/env php
<?php

chdir(__DIR__);

$test_mode = (isset($argv[1]) ? $argv[1] : null);
$test_case = (isset($argv[2]) ? $argv[2] : null);

/**
 * Executes a command line
 *
 * @param string $cmdline Command line
 * @return void
 */
function test_exec($cmdline)
{
  print "[+] Executing: $cmdline\n";
  system($cmdline);
}

/**
 * Executes a test case using a command line and a case file
 *
 * @param int $case Case number
 * @param array<string> Command line
 * @param string $casefile Case output file
 * @return void
 */
function test_script_exec($case, array $cmdline, $casefile)
{
  global $test_mode, $test_case;

  if (($test_case != "") && ($test_case != $case))
    return;

  $cmdline = implode(";", $cmdline);

  print "[+] Executing command: $casefile\n";
  system(sprintf('(cd ../../; %s) > %s 2>&1', $cmdline, escapeshellarg($casefile . ".tmp")));

  if ($test_mode == "save") {
    rename("$casefile.tmp", "$casefile.out");
    return;
  }
  elseif ($test_mode == "show") {
    print "\n" . str_repeat("-", 79);
    print file_get_contents("$casefile.tmp");
    print str_repeat("-", 79) . "\n\n";
    unlink("$casefile.tmp");
    return;
  }
  elseif (($test_mode != "") && ($test_mode != "exec")) {
    print "Error: Test mode must be one of: exec, save, show\n";
    exit(1);
  }

  $cmdline = sprintf("%s -u %s %s",
      "diff",
      "$casefile.out",
      "$casefile.tmp");
  system($cmdline, $ret);
  if ($ret) {
    print "\n\n*** Error *** Output does not match! (case $casefile)\n\n";
    exit(1);
  }
  unlink("$casefile.tmp");
}

/* ------------------------------------------------------------------------ */

/* generate command line usage due to missing file (goes to stderr) */
test_script_exec(0, array(
    './scripts/tail_http_access_log.php ""',
    './scripts/tail_http_access_log.php -Z',
    './scripts/tail_http_access_log.php -h',
  ), "test_tail_http_access_log.case0");

/* ------------------------------------------------------------------------ */

/* run the full analysis of the data file */
test_script_exec(1, array(
    './scripts/tail_http_access_log.php -c 999 -S ' .
        './tests/standalone/test_tail_http_access_log.data.log',
  ), "test_tail_http_access_log.case1");

/* ------------------------------------------------------------------------ */

/* check the rollbacking */
test_script_exec(2, array(
    'echo "# -B 99"',
    './scripts/tail_http_access_log.php -c 999 -B 99 ' .
        './tests/standalone/test_tail_http_access_log.data.log',
    'echo "# -B 100"',
    './scripts/tail_http_access_log.php -c 999 -B 100 ' .
        './tests/standalone/test_tail_http_access_log.data.log',
    'echo "# -B 101"',
    './scripts/tail_http_access_log.php -c 999 -B 101 ' .
        './tests/standalone/test_tail_http_access_log.data.log',
  ), "test_tail_http_access_log.case2");

/* ------------------------------------------------------------------------ */

print "[+] All tests successfull\n";

exit(0);
