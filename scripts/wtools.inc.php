<?php
/**
 * ...
 */

/**
 * ...
 */
class Inotify {
  /**
   * Internal Inotify resource handle
   *
   * @var resource
   */
  private $_inotify;

  /**
   * Default constructor
   */
  public function __construct() {
    $this->_inotify = inotify_init();
  }

  /**
   * Destructor
   */
  public function __destruct() {
    fclose($this->_inotify);
  }

  /**
   * ...
   *
   * @param string $pathname ...
   * @param int $mask ...
   * @return int ...
   */
  public function addWatch($pathname, $mask) {
    return inotify_add_watch($this->_inotify, $pathname, $mask);
  }

  /**
   * ...
   *
   * @param int $watch_descriptor ...
   * @return bool ...
   */
  public function removeWatch($watch_descriptor) {
    return inotify_rm_watch($this->_inotify, $watch_descriptor);
  }

  /**
   * ...
   *
   * @return array ...
   */
  public function poll() {
    return inotify_read($this->_inotify);
  }
}

/**
 * ...
 */
class TailException extends Exception {
}

/**
 * ...
 */
class Tail {
  /**
   * ...
   *
   * @var resource
   */
  private $_fd;

  /**
   * ...
   *
   * @var Inotify
   */
  private $_inotify;

  /**
   * ...
   *
   * @param string $pathname ...
   * @param bool $follow ...
   */
  public function __construct($pathname, $follow = true) {
    $this->_fd = @fopen($pathname, "r");
    if ($this->_fd === false)
      throw new TailException("Cannot open file \"$pathname\" for reading");

    /* position yourself at the end of the file */
    // fseek($this->_fd, -1024, SEEK_END);
    fseek($this->_fd, 0, SEEK_END);

    /* discard a possibly partial line */
    // fgets($this->_fd);

    /* create a new Inotify instance */
    if ($follow) {
      $this->_inotify = new Inotify();

      /* set up the watch on this same file */
      $this->_inotify->addWatch($pathname,
          IN_MODIFY | IN_ATTRIB | IN_DELETE_SELF | IN_MOVE_SELF);
    }
  }

  /**
   * ...
   *
   * @return void
   */
  public function rollback() {
    fseek($this->_fd, 0, SEEK_SET);
  }

  /**
   * ...
   *
   * @param int $amount ...
   */
  public function rollbackBytes($amount) {
    /* attempt to rollback that amount plus one, this way we can chop off one
     * line in case it's partial and position to the first beginning on line
     * since the amount requested */
    $ret = fseek($this->_fd, -($amount + 1), SEEK_END);

    if ($ret < 0) {
      fseek($this->_fd, 0, SEEK_SET);
    }
    else {
      /* discard a possibly partial line */
      fgets($this->_fd);
    }
  }

  /**
   * ...
   *
   * @return string|false ...
   */
  public function read() {
    do {
// dbg("--------------------");
// dbg("BEFORE");
// var_dump(ftell($this->_fd), feof($this->_fd));
      $buffer = fgets($this->_fd);
// dbg("AFTER");
// var_dump($buffer, ftell($this->_fd), feof($this->_fd));
// dbg("--------------------");
      if ($buffer !== false) {
        $buffer = rtrim($buffer, "\r\n");
        return $buffer;
      }

      if (!$this->_inotify) {
        return false;
      }

      /* no data to read at the moment, set up the inotify */
      // dbg("going to inotify");
      $fd_pos = ftell($this->_fd);
      $event = $this->_inotify->poll();
      $event = true;
      fseek($this->_fd, $fd_pos, SEEK_SET);
    }
    while ($event);
  }
}

/**
 * ...
 */
class NetworkLookup
{
  /**
   * Main networks database
   *
   * @var array{
   *    v4: list<string>,
   *    v6: list<string>}
   */
  private $_nets;

  /**
   * Reverse network lookup (from position to label id)
   *
   * @var array{
   *    v4: list<int>,
   *    v6: list<int>}
   */
  private $_lookups;

  /**
   * Label ids
   *
   * @var list<string>
   */
  private $_labels;

  /**
   * ...
   */
  public function __construct()
  {
    $this->_nets = array('v4' => array(), 'v6' => array());
    $this->_lookups = array('v4' => array(), 'v6' => array());
    $this->_labels = array();
  }

  /**
   * Adds a group of network masks with a specific label
   *
   * @param array<string> $v4s IPv4 network addresses
   * @param array<string> $v6s IPv6 network addresses
   * @param string $label Assigned label
   */
  public function addGroup($v4s, $v6s, $label)
  {
    /* step 0 - assign an id for this specific label */
    $label_id = array_search($label, $this->_labels);
    if ($label_id === false) {
      $label_id = count($this->_labels);
      $this->_labels[] = $label;
    }

    /* step 1 - convert masks to binary form */
    foreach (array('v4' => $v4s, 'v6' => $v6s) as $type => $ipxs) {
      foreach ($ipxs as $ipx) {
        /* step 1 - split the mask from the ip address */
        if (!preg_match('{^([0-9a-f:.]+)(?:/(\d{1,3}))?$}', $ipx, $regp) ||
            ($ip_packed = @inet_pton($regp[1])) == "") {
          trigger_error("Invalid IP$type network address: $ipx",
              E_USER_WARNING);
          continue;
        }
        $ip = $regp[1];
        $mask = (isset($regp[2]) && ($regp[2] != "") ? (int) $regp[2] :
            ($type == 'v4' ? 32 : 128));

        $ip_binary = "";
        for ($p = 0; $p < strlen($ip_packed); $p += 4) {
          list(, $_num) = unpack("N", substr($ip_packed, $p, 4));
          $ip_binary .= sprintf("%032b", $_num);
        }

        /* step 3 - apply the mask */
        $net = substr($ip_binary, 0, $mask);

        /* step 4 - store and index the result */
        $this->_nets[$type][] = $net;
        $this->_lookups[$type][] = $label_id;
      }
    }
  }

  /**
   * ...
   *
   * @param string $ip ...
   * @return string|false|null ...
   */
  public function lookup($ip)
  {
    /* convert the ip in binary notation */
    $ip_packed = inet_pton($ip);
    if ($ip_packed == "")
      return false;

    $ip_binary = "";
    for ($p = 0; $p < strlen($ip_packed); $p += 4) {
      list(, $_num) = unpack("N", substr($ip_packed, $p, 4));
      $ip_binary .= sprintf("%032b", $_num);
    }

    $type = strlen($ip_packed) == 4 ? 'v4' : 'v6';
    foreach ($this->_nets[$type] as $ref => $net) {
      if (!strncmp($net, $ip_binary, strlen($net)))
        return $this->_labels[$this->_lookups[$type][$ref]];
    }
    return null;
  }
}
// $nl = new NetworkLookup();
// $nl->addGroup(array('10.0.0.0/8'), array('ff10::/16'), 'cf1');
// $nl->addGroup(array('20.0.0.0/8'), array('ff20::/16'), 'cf2');
// var_dump($nl);
// var_dump($nl->lookup('10.0.0.1'));
// var_dump($nl->lookup('10.1.0.1'));
// var_dump($nl->lookup('11.1.0.1'));
// var_dump($nl->lookup('20.1.0.1'));
// var_dump($nl->lookup('21.1.0.1'));
// var_dump($nl->lookup('ff10::1'));
// var_dump($nl->lookup('ff11::1'));
// var_dump($nl->lookup('ff20::1'));
// exit();

/**
 * ...
 */
class WTools {
  /**
   * ...
   *
   * @param string $line ...
   * @param int $chars ...
   * @return string ...
   */
  public static function truncConsoleLine($line, $chars) {
    if ($chars <= 0)
      return $line;
    $visible_chars = 0;
    $in_escape_sequence = false;
    $i = 0;
    // dbg("starting with #line=" . strlen($line) . " max=" . $chars);
    while (($i < strlen($line)) && ($visible_chars < $chars)) {
      $chr = $line[$i++];
      // dbg("processing #chr=" . ord($chr) . " [visible=" . $visible_chars . "]");

      if ($in_escape_sequence) {
        if ($chr == "m")
          $in_escape_sequence = false;
        continue;
      }

      if ($chr == "\x1b") {
        $in_escape_sequence = true;
        continue;
      }

      $visible_chars++;
    }

    if ($visible_chars == $chars)
      return substr($line, 0, $i) . "\x1b[m";

    return $line;
  }

  /**
   * Retrieves CloudFlare known ips
   *
   * @return array{
   *    v4: list<string>,
   *    v6: list<string>} ...
   */
  public static function fetchKnownIps_cloudflare() {
    $v4 = array();
    $v6 = array();

    $raw = file("https://www.cloudflare.com/ips-v4/");
    foreach ($raw as $ip) {
      $v4[] = trim($ip);
    }

    $raw = file("https://www.cloudflare.com/ips-v6/");
    foreach ($raw as $ip) {
      $v6[] = trim($ip);
    }

    return array(
      'v4' => $v4,
      'v6' => $v6);
  }

  /**
   * Retrieves CloudFlare known ips
   *
   * @param 'bot'|'special'|'user' $type ...
   * @return array{
   *    v4: list<string>,
   *    v6: list<string>} ...
   */
  public static function fetchKnownIps_google($type) {
    switch ($type) {
    case 'bot':
      $label = 'google_user';
      $url = "https://developers.google.com/static/search/apis/ipranges/googlebot.json";
      break;

    case 'special':
      $label = 'google_user';
      $url = "https://developers.google.com/static/search/apis/ipranges/special-crawlers.json";
      break;

    case 'user':
      $label = 'google_user';
      $url = "https://developers.google.com/static/search/apis/ipranges/user-triggered-fetchers.json";
      break;

    default:
      throw new \InvalidArgumentException("Invalid type '$type'");
    }

    $data = file_get_contents($url);

    $json = json_decode($data, true);

    $retval = array('v4' => array(), 'v6' => array());
    foreach ($json['prefixes'] as $entry) {
      if (isset($entry['ipv4Prefix']))
        $retval['v4'][] = $entry['ipv4Prefix'];
      if (isset($entry['ipv6Prefix']))
        $retval['v6'][] = $entry['ipv6Prefix'];
    }

    return $retval;
  }

  /**
   * Retrieves all known ips
   *
   * @return array<string, array{
   *    v4: list<string>,
   *    v6: list<string>}> ...
   */
  public static function fetchKnownIpsCached() {
    /* attempt to obtain local cache data */
    $cache_version = "v1";
    $cache_file = "/tmp/.wtools-lookup-networks." . posix_getuid() . ".cache";
    $cache_data = @file_get_contents($cache_file);

    $groups = null;
    if ($cache_data !== false) {
      $cache_data = json_decode($cache_data, true);
      if (($cache_data !== false) && isset($cache_data['version']) &&
          is_string($cache_data['version']) &&
          ($cache_data['version'] == $cache_version)) {
        /**
         * @var array{
         *    expire: int,
         *    groups: array<string, array{
         *       v4: list<string>,
         *       v6: list<string>}>} $cache_data */
        if ($cache_data['expire'] > time()) {
          print "[d] PULLING KNOWN IPS FROM CACHE\n";
          return $cache_data['groups'];
        }
      }
    }

    print "[d] PULLING KNOWN IPS FROM REMOTE\n";
    $groups = array();
    $groups['cloudflare'] = self::fetchKnownIps_cloudflare();
    $groups['google:bot'] = self::fetchKnownIps_google('bot');
    $groups['google:ads'] = self::fetchKnownIps_google('special');
    $groups['google:user'] = self::fetchKnownIps_google('user');

    /* write the cache file */
    $cache_data = array(
      'version' => $cache_version,
      'expire' => time() + 86400,
      'groups' => $groups);
    file_put_contents($cache_file, json_encode($cache_data));

    return $groups;
  }

  /**
   * ...
   *
   * @param array<string> $groups ...
   * @return NetworkLookup ...
   */
  public static function fetchNetworkLookupCached($groups)
  {
    $nl = new NetworkLookup();

    $real_groups = array(
      'local' => false,
      'cloudflare' => false,
      'google:bot' => false,
      'google:ads' => false,
      'google:user' => false,
    );

    foreach ($groups as $group) {
      switch ($group) {
      case "none":
        break;

      case "local":
        $real_groups['local'] = true;
        break;

      case "google":
        $real_groups['google:bot'] = true;
        $real_groups['google:ads'] = true;
        $real_groups['google:user'] = true;
        break;

      case "all":
        foreach (array_keys($real_groups) as $g) {
          $real_groups[$g] = true;
        }
        break;

      default:
        if (!isset($real_groups[$group]))
          throw new \InvalidArgumentException("Bad group '$group'");
        $real_groups[$group] = true;
      }
    }

    $known_ips = null;

    foreach ($real_groups as $real_group => $enabled) {
      if (!$enabled)
        continue;

      /* local group is not cached, easy stuff */
      if ($real_group == "local") {
        $nl->addGroup(array("127.0.0.1"), array("::1"), "localhost");
        continue;
      }

      if (!$known_ips) {
        $known_ips = self::fetchKnownIpsCached();
      }

      assert(isset($known_ips[$real_group]));
      $ips = $known_ips[$real_group];
      $nl->addGroup($ips['v4'], $ips['v6'], $real_group);
    }

    return $nl;
  }

  /**
   * ...
   *
   * @param string $str ...
   * @return array{
   *    stamp: int,
   *    peer: string,
   *    ip: string,
   *    user: string,
   *    proto: string,
   *    date: string,
   *    time: string,
   *    req: string,
   *    status: int,
   *    size: int,
   *    referer: ?string,
   *    agent: ?string,
   *    duration: ?int}|false ...
   */
  public static function parseAccessLog_th_lf($str) {
    // basic?
    //     IP USER IDENT [REQ_TIME] "REQ" STATUS SIZE
    //
    // th_lf_1 format:
    //     IP USER PROTO [REQ_TIME] "REQ" STATUS SIZE "REFERER" "AGENT"
    //
    // th_lf_2 format:
    //     IP USER PROTO [REQ_TIME] ...
    //     "REQ" STATUS SIZE "REFERER" "AGENT" DURATION
    //
    // th_lf_3 format:
    //     PEER>IP USER PROTO [REQ_TIME] ...
    //     "REQ" STATUS SIZE "REFERER" "AGENT" DURATION
    //
    if (!preg_match('{^' .
        // peer available only in: th_lf_3
        '(?:(?P<peer>[0-9.a-f:]+)>)?' .       // peer?:    172.71.26.115
        '(?P<ip>[0-9.a-f:]+) ' .              // ip:       81.202.254.26
        '(?P<user>[^ ]+) ' .                  // user:     WonderSeller
        '(?P<proto>[^ ]+) ' .                 // proto:    TLSv1.2
        '\[(?P<date>\d+/\w+/\d{4}):' .        // date:     17/May/2018
          '(?P<time>[0-9:]+) ' .              // time:     00:58:13
          '(?P<zone>\+\d+)\] ' .              // zone:     +0200
        '(?P<req>"(?:\\\\.|[^"])*") ' .       // req:      GET /blabla HTTP/1.1
        '(?P<status>\d+) ' .                  // status:   200
        '(?<size>\d+|-)' .                    // size:     168682
        // the following are only available in combined
        '(?: ' .
        '(?P<referer>"(?:\\\\.|[^"])*")? ' .  // referer:  -
        '(?P<agent>"(?:\\\\.|[^"])*")' .      // agent:    Mozilla/5.0 (...)
        // duration available only in: th_lf_2, th_lf_3
        '(?: (?P<duration>\d+))?' .           // duration: 242232 (usec)
        ')?$}',
        $str, $regp))
      return false;
    /**
     * @var array{
     *    peer: ?non-empty-string,
     *    ip: non-empty-string,
     *    user: non-empty-string,
     *    proto: non-empty-string,
     *    date: non-empty-string,
     *    time: non-empty-string,
     *    zone: non-empty-string,
     *    req: non-empty-string,
     *    status: numeric-string,
     *    size: non-empty-string,
     *    referer: non-empty-string,
     *    agent: non-empty-string,
     *    duration?: numeric-string} $regp
     */

    $stamp = \DateTime::createFromFormat("d/M/Y G:i:s P",
        $regp['date'] . " " . $regp['time'] . " " . $regp['zone']);

    $rec = array();
    $rec['stamp'] = $stamp->getTimestamp();
    $rec['peer'] = ($regp['peer'] != $regp['ip'] ? $regp['peer'] : "");
    $rec['ip'] = $regp['ip'];
    $rec['user'] = ($regp['user'] != "-" ? $regp['user'] : "");
    $rec['proto'] = ($regp['proto'] != "-" ? $regp['proto'] : "");
    $rec['date'] = $regp['date'];
    $rec['time'] = $regp['time'];
    $rec['req'] = $regp['req'];
    $rec['status'] = (int) $regp['status'];
    $rec['size'] = (int) $regp['size'];
    $rec['referer'] = (empty($regp['referer']) ? null :
        ($regp['referer'] != '"-"' ? substr($regp['referer'], 1, -1) : ""));
    $rec['agent'] = (empty($regp['agent']) ? null :
        ($regp['agent'] != '"-"' ? substr($regp['agent'], 1, -1) : ""));
    $rec['duration'] = (isset($regp['duration']) ?
        (int) round($regp['duration'] / 1000) : null);

    return $rec;
  }
}
