#!/bin/bash

set -eu

if [ ! -e "bash/init.sh" ]
then
  echo "Error: Cannot locate source, wrong cwd?" >&2
  exit 1
fi

ver="${1:-}"

if [ "$ver" = "" ]
then
  echo "Usage: $0 <version>  (no 'v' prefix)" >&2
  exit 1
fi

sed -i -e 's/^\(_th_sys_tools_version\)=.*$/\1="'$ver'"/' bash/init.sh
