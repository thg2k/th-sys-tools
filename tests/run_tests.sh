#!/bin/bash

cd -- "$(dirname -- "${BASH_SOURCE[0]}")"

cd standalone
retval=0

./test_tail_http_access_log.php || retval=1

./test_tail_http_error_log.php || retval=1

./test_tail_mail_log.php || retval=1

exit $retval
