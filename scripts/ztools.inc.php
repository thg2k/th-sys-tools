<?php
/**
 * ...
 */

/**
 * ...
 */
class PostfixMailLogParser
{
  /**
   * Syslog-style short month names
   *
   * @var array<string>
   */
  private static $_months = array(
      "Jan", "Feb", "Mar", "Apr", "May", "Jun",
      "Jul", "Aug", "Sep", "Oct", "Nov", "Dec");

  /**
   * Session mapping between message ids and related information
   *
   * @var array<string, array{
   *    ipaddr: string}>
   */
  private $_map_ids = array();

  /**
   * ...
   *
   * @param string $line ...
   * @return array{
   *    date: string,
   *    time: string,
   *    process: string,
   *    pid: int,
   *    message: string}|false ...
   */
  private static function _parse_syslog($line)
  {
    /* first, parse the common structure */
    $regexp = '{' .
        '(?<tmm>[A-Za-z]+) +(?<tdd>\d+) (?<ttm>\d{2}:\d{2}:\d{2}) ' .
        '(?<host>[a-z0-9-]+) ' .
        '(?<proc>.*?)(?:\[(?<pid>\d+)\])?: ' .
        '(?<msg>.*)$}';

    if (!preg_match($regexp, $line, $regp))
      return false;

    $km = array_search($regp['tmm'], self::$_months, true);
    if ($km === false)
      return false;

    // FIXME: correctly guess dates on december
    $rec = array();
    $rec['date'] = sprintf("%04d-%02d-%02d",
        (int) date("Y"),
        (int) ($km + 1),
        (int) $regp['tdd']);
    $rec['time'] = $regp['ttm'];
    $rec['process'] = $regp['proc'];
    $rec['pid'] = ($regp['pid'] != "" ? (int) $regp['pid'] : null);
    $rec['message'] = $regp['msg'];

    return $rec;
  }

  /**
   * Default constructor
   */
  public function __construct()
  {
    /* nothing to do */
  }

  /**
   * ...
   *
   * ...
   */
  private function _parse_mail_syslog(&$syslog)
  {
    static $_regexps;

    if ($_regexps === null) {
      $_regexps_parts = array(
        "conn_prewarn" =>
            'warning: hostname (<c_fakehost>.*?) does not resolve to address (<c_ip>.*?)(?:: (<x_reason>.*))?',
        "conn_start" =>
            'connect from (<c_host>.*?)\[(<c_ip>.*?)\]',
        "conn_lost" =>
            'lost connection after (<x_when>.*) from (<c_host>.*?)\[(<c_ip>.*?)\]',
        "conn_timeout" =>
            'timeout after (<x_when>.*) from (<c_host>.*?)\[(<c_ip>.*?)\]',
        "conn_end" =>
            'disconnect from (<c_host>.*?)\[(<c_ip>.*?)\](<x_info>.*)',
        "conn_spf" =>
            '(<x_res>None|Pass|Fail); identity=(<x_ident>.*?); client-ip=(<c_ip>.*?); ' .
            'helo=(<x_helo>.*?); envelope-from=(<x_from>.*?); receiver=(<x_recv>.*?)',
        "conn_reject_rcpt" =>
            'NOQUEUE: reject: RCPT from (<c_host>.*?)\[(<c_ip>.*?)\]: (<x_reason>.*)',
        "conn_auth_fail" =>
            'warning: (<c_host>.*?)\[(<c_ip>.*)\]: SASL (<x_type>[A-Z]+) authentication failed:(<x_code>.*)',
        "msg_start" =>
            '(<m_id>[A-Z0-9]{10,11}): client=(<c_host>.*?)\[(<c_ip>.*?)\](<x_msg>.*)',
        "msg_generic" =>
            '(<m_id>[A-Z0-9]{10,11}): (<x_msg>.*)',
        "sys_dovecot_1" =>
            '(<x_prefix>auth|auth-worker)(?:\(\d+\))?: (<x_facility>passwd-file|sql)\((<x_login>.*?),(<c_ip>.*)\): (<x_reason>.*)',
        "sys_dovecot_2" =>
            'auth: login\((<x_login>.*?),(<c_ip>.*)\): Request timed out waiting for client to continue authentication \((<x_timeout>\d+) secs\)',
        "sys_dovecot_3" =>
            'auth: login\(\?,(<c_ip>.*)\): Username character disallowed by auth_username_chars: 0x[0-9a-f]{2} \(username: (<x_login>.*?)\)',
        "anvil_stats_1" =>
            'statistics: max connection rate .* for \(.*\) at .*',
        "anvil_stats_2" =>
            'statistics: max connection count \d+ for \(.*\) at .*',
        "anvil_stats_3" =>
            'statistics: max cache size \d+ at .*',
      );

      foreach ($_regexps_parts as $_regexps_id => $_regexps_part) {
        $_regexps_part = str_replace("(<", "(?<", $_regexps_part);
        $_regexps[$_regexps_id] = '{^' . $_regexps_part . '$}';
      }
    }

    $message = $syslog['message'];
    $rec = array(
      'ipaddr' => "",
      'msgid' => "",
      'c_color' => "",
      'content' => $message);
    $retval = true;

    if (preg_match($_regexps['conn_prewarn'], $message, $regp)) {
      // (1) warning: hostname ... does not resolve back
      $rec['ipaddr'] = $regp['c_ip'];
      $rec['content'] = "hostname [" . $regp['c_fakehost'] . "] does not resolve back";
      if (isset($regp['x_reason']) && ($regp['x_reason'] != "")) {
        $rec['content'] .= " (" . $regp['x_reason'] . ")";
      }
    }
    elseif (preg_match($_regexps['conn_start'], $message, $regp)) {
      // (2) connect from {c_host}[{c_ip}]
      $rec['ipaddr'] = $regp['c_ip'];
      $rec['content'] = "connect from " . $regp['c_host'];
      $rec['c_color'] = "\x1b[1m";
    }
    elseif (preg_match($_regexps['conn_auth_fail'], $message, $regp)) {
    // elseif ($_match("warning: {c_host}[{c_ip}]: SASL LOGIN authentication failed: {x_code}")) {
      $rec['ipaddr'] = $regp['c_ip'];
    }
    elseif (preg_match($_regexps['conn_lost'], $message, $regp)) {
      // lost connection after {x_when} from {c_host}[{c_ip}]
      $rec['ipaddr'] = $regp['c_ip'];
      $rec['content'] = "lost connection after " . $regp['x_when'];
    }
    elseif (preg_match($_regexps['conn_timeout'], $message, $regp)) {
      // timeout after {x_when} from {c_host}[{c_ip}]
      $rec['ipaddr'] = $regp['c_ip'];
      $rec['content'] = "timeout after " . $regp['x_when'];
    }
    elseif (preg_match($_regexps['conn_end'], $message, $regp)) {
      // (3) disconnect from {c_host}[{c_ip}]
      $rec['ipaddr'] = $regp['c_ip'];
      $rec['content'] = "disconnect from " . $regp['c_host'] . $regp['x_info'];
      $rec['c_color'] = "\x1b[1m";
    }
    elseif (preg_match($_regexps['conn_spf'], $message, $regp)) {
      $rec['ipaddr'] = $regp['c_ip'];
      $rec['content'] =
          "result=" . $regp['x_res'] . "; " .
              "idnt=" . $regp['x_ident'] . "; " .
              "helo=" . $regp['x_helo'] . "; " .
              "from=" . $regp['x_from'] . "; " .
              "recv=" . $regp['x_recv'];
    }
    elseif (preg_match($_regexps['conn_reject_rcpt'], $message, $regp)) {
      // NOQUEUE: reject: RCPT from {c_host}[{c_ip}]: {x_reason}
      $rec['ipaddr'] = $regp['c_ip'];
      $rec['content'] =
          "NOQUEUE: reject RCPT: " . $regp['x_reason'];
    }
    elseif (preg_match($_regexps['msg_start'], $message, $regp)) {
      // {m_id}: client={c_host}[{c_ip}]{x_msg}
      $rec['ipaddr'] = $regp['c_ip'];
      $rec['msgid'] = $regp['m_id'];
      $rec['content'] = "client=" . $regp['c_host'] . $regp['x_msg'];
      $this->_map_ids[$regp['m_id']] = array(
          'ipaddr' => $regp['c_ip']);
    }
    elseif (preg_match($_regexps['msg_generic'], $message, $regp)) {
      $rec['msgid'] = $regp['m_id'];
      $rec['content'] = $regp['x_msg'];
      if (isset($this->_map_ids[$regp['m_id']]))
        $rec['ipaddr'] = $this->_map_ids[$regp['m_id']]['ipaddr'];
      else
        $rec['ipaddr'] = "**UNKNOWN**";
    }
    elseif (preg_match($_regexps['sys_dovecot_1'], $message, $regp)) {
      // auth: passwd-file({x_login},{c_ip}): {x_reason}
      $rec['ipaddr'] = $regp['c_ip'];
      $rec['content'] = $regp['x_prefix'] . ": " . $regp['x_facility'] . "(" . $regp['x_login'] . "): " . $regp['x_reason'];
      $rec['c_color'] = "\x1b[31;1m";
    }
    elseif (preg_match($_regexps['sys_dovecot_2'], $message, $regp)) {
      // auth: passwd-file({x_login},{c_ip}): {x_reason}
      $rec['ipaddr'] = $regp['c_ip'];
      // $rec['content'] = "auth: passwd-file: [" . $regp['x_login'] . "] " . $regp['x_reason'];
      $rec['c_color'] = "\x1b[31;1m";
    }
    elseif (preg_match($_regexps['sys_dovecot_3'], $message, $regp)) {
      // auth: passwd-file({x_login},{c_ip}): {x_reason}
      // auth: login(?,{c_ip}): Username character disallowed by auth_username_chars: 0x?? (username: {x_login})
      $rec['ipaddr'] = $regp['c_ip'];
      $rec['c_color'] = "\x1b[31;1m";
    }
    elseif (preg_match($_regexps['anvil_stats_1'], $message, $regp)) {
      $rec['content'] = "";
    }
    elseif (preg_match($_regexps['anvil_stats_2'], $message, $regp)) {
      $rec['content'] = "";
    }
    elseif (preg_match($_regexps['anvil_stats_3'], $message, $regp)) {
      $rec['content'] = "";
    }
    else {
      $retval = false;
    }

    $syslog['parsed'] = $rec;

    return $retval;
  }

  /**
   * ...
   *
   * ...
   */
  public function parseLine($line)
  {
    $syslog = self::_parse_syslog($line);
    if (!$syslog) {
      print "!!!!! ERROR !!!!!!! NOT A VALID LINE: $line\n";
      return false;
    }

    $this->_parse_mail_syslog($syslog);

    return $syslog;
  }
}
