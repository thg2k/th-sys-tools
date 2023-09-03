#!/bin/bash

SA_VERSION_PHPSTAN="1.10.28"
SA_VERSION_FIXUP="0.4.0"
SA_PATH=".local.tools"

set -eu

cd -- "$(dirname -- "${BASH_SOURCE[0]}")"/..

mkdir -p $SA_PATH

if [ ! -e $SA_PATH/fixup-source-files-$SA_VERSION_FIXUP.php ]
then
  rm -f $SA_PATH/fixup-source-files-*
  wget -nv -O $SA_PATH/fixup-source-files-$SA_VERSION_FIXUP.php \
      "https://raw.githubusercontent.com/thg2k/fixup-source-files/v$SA_VERSION_FIXUP/bin/fixup_source_files.php"
  chmod 755 $SA_PATH/fixup-source-files-$SA_VERSION_FIXUP.php
fi

if [ ! -e $SA_PATH/phpstan-$SA_VERSION_PHPSTAN.phar ]
then
  rm -f $SA_PATH/phpstan-*
  wget -nv -O $SA_PATH/phpstan-$SA_VERSION_PHPSTAN.phar \
      "https://github.com/phpstan/phpstan/releases/download/$SA_VERSION_PHPSTAN/phpstan.phar"
  chmod 755 $SA_PATH/phpstan-$SA_VERSION_PHPSTAN.phar
fi

action="${1:-}"
shift || :

[ -z "$action" -o "$action" = "fixup" ] &&
  $SA_PATH/fixup-source-files-$SA_VERSION_FIXUP.php check

[ -z "$action" -o "$action" = "phpstan" ] &&
  $SA_PATH/phpstan-$SA_VERSION_PHPSTAN.phar analyse -c .phpstan.neon --no-progress --no-ansi $@
