#!/usr/bin/php
<?php

function display_section_hdr($name, $color) {
  $width = 40;
  print "\n";
  print "\e[$color;1;37m" . str_repeat(" ", $width) . "\e[m\n";
  print "\e[$color;1;37m" . str_pad($name, $width, " ", STR_PAD_BOTH) . "\e[m\n";
  print "\e[$color;1;37m" . str_repeat(" ", $width) . "\e[m\n";
  print "\n";
}

function smart_pad($_raw, $_fmt, $width) {
  if ($width > 0)
    return ($width > strlen($_raw) ? str_repeat(" ", $width - strlen($_raw)) : "") . $_fmt . " ";
  else
    return " " . $_fmt . (-$width > strlen($_raw) ? str_repeat(" ", -$width - strlen($_raw)) : "");
}

function hdr_pad($_raw, $width) {
  return "\e[36m" . str_pad($_raw, $width, " ", STR_PAD_BOTH) . "\e[m";
}

function display_std_output($output) {
  /* determine cols */
  $cols = (int) exec("tput cols");
  if (!$cols)
    $cols = 80;

  $lines = explode("\n", $output);

  $state_step = 0;
  $state_hdrs = null;
  $full_line_length = 145;

  foreach ($lines as $line) {
    switch ($state_step) {
    case 0: // expecting chain intro
      if (preg_match('/^Chain (\S+) (.*)$/', $line, $regp)) {
        print "\e[36mChain \e[1m{$regp[1]}\e[22m {$regp[2]}\e[m " .
            str_repeat("-", ($full_line_length - strlen($line) - 1)) . "\n";
        $state_step++;
        continue;
      }
      throw new Exception("Invalid unexpected output (step 0)");

    case 1: // expecting section headers
      $state_hdrs = preg_split('/\s+/', trim($line));
      $state_step++;
      continue;

    case 2: // expecting inner data
      /* an empty line determines next section */
      if ($line == "") {
        if ($state_hdrs !== null)
          print "   (empty)\n";
        $state_step = 0;
        print str_repeat("-", $full_line_length) . "\n\n";
        continue;
      }

      /* ok then, we need to do output, check if we have headers */
      if ($state_hdrs !== null) {
        $buf = "";
        $state_hdrs[6] = "interface in";
        $state_hdrs[7] = "interface out";
        $buf .= " " . hdr_pad(array_shift($state_hdrs), 4) . " |";
        $buf .= " " . hdr_pad(array_shift($state_hdrs), 5) . " |";
        $buf .= " " . hdr_pad(array_shift($state_hdrs), 5) . " |";
        $buf .= " " . hdr_pad(array_shift($state_hdrs), 19) . " |";
        $buf .= " " . hdr_pad(array_shift($state_hdrs), 4) . " |";
        $buf .= " " . hdr_pad(array_shift($state_hdrs), 3) . " |";
        $buf .= " " . hdr_pad(array_shift($state_hdrs), 17) . " |";
        $buf .= " " . hdr_pad(array_shift($state_hdrs), 17) . " |";
        $buf .= " " . hdr_pad(array_shift($state_hdrs), 17) . " |";
        $buf .= " " . hdr_pad(array_shift($state_hdrs), 17) . " |";
        $buf .= " " . hdr_pad("other", 0);
        $state_hdrs = null;
        print $buf . "\n";
      }



      $toks = preg_split('/\s+/', trim($line));

      $buf = "";

      /* ----- num ----- */
      $_raw = $_fmt = "#" . array_shift($toks);
      $buf .= smart_pad($_raw, $_fmt, 5) . "|";

      /* ----- pkts ----- */
      $_raw = $_fmt = array_shift($toks);
      if (preg_match('/^([0-9]+)([^0-9]+)$/', $_raw, $regp)) {
        /* mark in magenta the multiplier */
        $_fmt = $regp[1] . "\e[35m" . $regp[2] . "\e[m";
      }
      $buf .= str_repeat(" ", 6 - strlen($_raw)) . $_fmt . " |";

      /* ----- bytes ----- */
      $_raw = $_fmt = array_shift($toks);
      if (preg_match('/^([0-9]+)([^0-9]+)$/', $_raw, $regp)) {
        /* mark in magenta the multiplier */
        $_fmt = $regp[1] . "\e[35m" . $regp[2] . "\e[m";
      }
      $buf .= str_repeat(" ", 6 - strlen($_raw)) . $_fmt . " |";

      /* ----- target ----- */
      $_raw = $_fmt = array_shift($toks);
      switch ($_raw) {
      case "REJECT": $_fmt = "\e[1;31m" . $_raw . "\e[m"; break;
      case "DROP":   $_fmt = "\e[31m"   . $_raw . "\e[m"; break;
      case "ACCEPT": $_fmt = "\e[1;32m" . $_raw . "\e[m"; break;
      case "LOG":    $_fmt = "\e[1;36m" . $_raw . "\e[m"; break;
      case "RETURN": $_fmt = "\e[34m"   . $_raw . "\e[m"; break;
      }
      $buf .= smart_pad($_raw, $_fmt, -20) . "|";

      /* ----- prot ----- */
      $_raw = $_fmt = array_shift($toks);
      if ($_raw == "all")
        $_raw = $_fmt = "*";
      $buf .= smart_pad($_raw, $_fmt, -5) . "|";

      /* ----- opt ----- */
      $_raw = $_fmt = array_shift($toks);
      if ($_raw == "--")
        $_raw = $_fmt = "";
      $buf .= smart_pad($_raw, $_fmt, -4) . "|";

      /* ----- in ----- */
      $_raw = $_fmt = array_shift($toks);
      if (substr($_raw, 0, 1) == "!")
        $_fmt = "\e[1;31m!\e[m" . substr($_raw, 1);
      $buf .= smart_pad($_raw, $_fmt, -18) . "|";

      /* ----- out ----- */
      $_raw = $_fmt = array_shift($toks);
      if (substr($_raw, 0, 1) == "!")
        $_fmt = "\e[1;31m!\e[m" . substr($_raw, 1);
      $buf .= smart_pad($_raw, $_fmt, -18) . "|";

      /* ----- source ----- */
      $_raw = $_fmt = array_shift($toks);
      if ($_raw == "0.0.0.0/0")
        $_raw = $_fmt = "*";
      if (substr($_raw, 0, 1) == "!")
        $_fmt = "\e[1;31m!\e[m" . substr($_raw, 1);
      $buf .= smart_pad($_raw, $_fmt, -18) . "|";

      /* ----- destination ----- */
      $_raw = $_fmt = array_shift($toks);
      if ($_raw == "0.0.0.0/0")
        $_raw = $_fmt = "*";
      if (substr($_raw, 0, 1) == "!")
        $_fmt = "\e[1;31m!\e[m" . substr($_raw, 1);
      $buf .= smart_pad($_raw, $_fmt, -18) . "|";

      /* everything else */
      if ($cols > $full_line_length) {
        $buf .= " " . substr(implode(" ", $toks), 0, $cols - $full_line_length + 5);
      }

      print $buf . "\n";
    }
  }
}

$target = (isset($argv[1]) ? $argv[1] : "");

if (!$target || ($target == "nat")) {
  $nat_out = shell_exec("iptables -t nat -L -v -n --line-numbers");
  display_section_hdr("NAT TABLE", 45);
  display_std_output($nat_out);
}

if (!$target || ($target == "filter")) {
  $filter_out = shell_exec("iptables -L -v -n --line-numbers");
  display_section_hdr("FILTER TABLE", 44);
  display_std_output($filter_out);
}
