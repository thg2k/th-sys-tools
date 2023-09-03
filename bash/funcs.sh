
##############################################################################
# odx
#
# ...
#
alias odx='od -A x -t x1c'


##############################################################################
# pico
#
# Alias of 'nano'.
#
alias pico='nano'


##############################################################################
# xmlcheck
#
# Checks for XML syntax, only outputs in case of errors
#
alias xmlcheck='xmllint -noout'


##############################################################################
# passwd-ls
#
# Formatted content of /etc/passwd
#
passwd-ls() {
  awk -F: '
    BEGIN {
      printf("---------------------+---+--------+--------+--------------------------------+----------------+-------------------\n");
      printf("   USERNAME            x     UID      GID      HOME                             SHELL            NAME\n");
      printf("---------------------+---+--------+--------+--------------------------------+----------------+-------------------\n");
    } {
      printf("%-20s | %-1s | %6d | %6d | %-30s | %-14s | %s\n", $1, $2, $3, $4, $6, $7, $5);
    }
    END {
      printf("---------------------+---+--------+--------+--------------------------------+----------------+-------------------\n");
    }' /etc/passwd
}


##############################################################################
# thdiff
#
# ...
#
thdiff() {
  diff -U 20 $@ | colordiff | less -R
}


##############################################################################
# thdu
#
# Executes 'du' with one level of depth
#
thdu() {
  du -h --max-depth=1 | sort -h
}


##############################################################################
# thsysdu
#
# Executes 'du' with one level of depth
#
thsysdu() {
  (cd /; du -h --max-depth=0 $(find . -maxdepth 1 -not -type l -not -name proc -not -name dev -not -name sys -not -name .)) | sort -h
}



##############################################################################
# auto_scp
#
# ...
#
auto_scp() {
  if [ -z "$2" ]
  then
    echo "Usage: auto_scp <file> <user@host:target> [push|pull]" >&2
    return 1
  fi

  case "$3" in
    pull)
      echo "=== Pulling initial file from '$2' onto '$1'..."
      scp "$2" "$1" || return 1
      ;;
    push)
      echo "=== Pushing initial file to '$2' from '$1'..."
      scp "$1" "$2" || return 1
      ;;
  esac

  echo "=== Waiting for changes to '$1', copying to '$2'..."
  while inotifywait -q -e close_write "$1"
  do
    echo "=== Changes detected! Copying file..."
    scp "$1" "$2"
    echo "Waiting for more changes..."
  done
}


##############################################################################
# svn_diff_review
#
# Colored version of 'svn diff' with pager support, similar to 'git log'.
#
svn_diff_review() {
  svn diff --diff-cmd=diff -x -U25 $@ | colordiff | less -r
}


##############################################################################
# list_images
#
# Lists all image files in the current folder with size
#
#list_images() {
#  [ -z "$1" ] && return
#  identify $@ | sed -e 's/\[[0-9]\+\]//' | awk '{ printf "%-30s %s\n", $1, $3 }'
#}
list_images() {
  [ -z "$1" ] && return;
  identify -format "%wx%h   %f\n" "$@"
}


##############################################################################
# mysql_dropall
#
# Drops all tables and views in a database without the need for dropping the database.
#
mysql_dropall() {
  [ -n "$1" ] || return 1
  read -p "Are you sure you want to drop all tables from database \"$1\"? "
  [ "$REPLY" = "yes" -o "$REPLY" = "y" ] || return 1

  echo -en "Dropping ALL tables from database \"$1\"... "
  echo $(echo "SET FOREIGN_KEY_CHECKS = 0;";
    IFS=$'\t\n'
    last_table=""

    for table in `mysql -N --batch -e "SHOW FULL TABLES;" "$1"`
    do
      if [ $table == "BASE TABLE" ]; then
        echo "DROP TABLE \`$last_table\`;"
      elif [ $table == "VIEW" ]; then
        echo "DROP VIEW \`$last_table\`;"
      else
        last_table=$table
      fi
    done) | mysql --batch "$1"

  echo "Done."
}


##############################################################################
# mysql_choose
#
# Parses your '.my.cnf' file to select between configuration suffixes.
#
mysql_choose() {
  if [ ! -f $HOME/.my.cnf ]
  then
    echo "Error: Cannot find configuration file ~/.my.cnf" >&2
    return 1;
  fi

  # solution with mapfile, i need to prepend the DEFAULT:
  #mapfile -t my_cfgs_suffixes < <(grep -oP '(?<=\[client)(\..*)(?=\])' $HOME/.my.cnf)
  #my_cfgs_suffixes=(DEFAULT "${arr[@]}")

  # solution with manual configuration, only supports tokens:
  my_cfgs_suffixes=("" $(grep -oP '(?<=\[client)(\..*)(?=\])' $HOME/.my.cnf))

  if [ ${#my_cfgs_suffixes[@]} -gt 1 ]
  then
    echo "I found ${#my_cfgs_suffixes[@]} possible MySQL configurations:"
    for ((i = 0; i < ${#my_cfgs_suffixes[@]}; i++))
    do
      echo "  $i  ${my_cfgs_suffixes[$i]:-DEFAULT}"
    done
    read -p "Which configuration you want to activate? [0-$((i-1))] "
    export MYSQL_GROUP_SUFFIX=${my_cfgs_suffixes[$REPLY]}
    echo "Exported MYSQL_GROUP_SUFFIX=$MYSQL_GROUP_SUFFIX"
  else
    echo "Only DEFAULT configuration available, skipping."
  fi
}


##############################################################################
# mc
#
# Alias of 'mysql_choose'
#
alias mc='mysql_choose'


##############################################################################
# git-manage
#
# Helps manage forked repositories by keeping your forked branches in sync.
#
git-manage() {
  mgit_downstream=$1
  mgit_upstream=$2
  mgit_branch=$3
  mgit_action=$4

  if [ -z "$mgit_downstream" ]
  then
    echo "Usage: git-manage <downstream> <upstream> <branch> <action>" >&2
    echo "" >&2
    echo "Examples:" >&2
    echo "  \$ git-manage my_company upstream funny_branch show-branch" >&2
    echo "  \$ git-manage my_company upstream funny_branch push" >&2
    echo "" >&2
    return 1
  fi

  if [ -z "$mgit_branch" ]
  then
    git branch -a
    return 0
  fi

  if [ -z "$mgit_action" ]
  then
    mgit_action="show-branch"
  fi

  case "$mgit_action" in
    show-branch|show)
      (set -x; git show-branch $mgit_downstream/$mgit_branch $mgit_upstream/$mgit_branch)
      ;;
    push)
      (set -x; git push $mgit_downstream $mgit_upstream/$mgit_branch:$mgit_branch)
      ;;
    *)
      echo "Error: Invalid action \"$mgit_action\"" >&2
      return 1
      ;;
  esac
}


##############################################################################
# xcomposer
#
# Fully version-locked PHP composer wrapper command
#
# Environment:
#  - XCOMPOSER_PATH
#
xcomposer() {
  local xcomposer_default_version="1.10.26"

  if [ "${1:-}" = "ver-init" ]
  then
    echo "$xcomposer_default_version" > composer.ver-lock
    echo "Created file 'composer.ver-lock' with $xcomposer_default_version" >&2
    return 0
  fi

  if [ ! -e "composer.ver-lock" ]
  then
    echo "Error: Cannot find file 'composer.ver-lock'!" >&2
    echo "You should run: xcomposer ver-init" >&2
    return 1
  fi

  local xcomposer_version=`cat composer.ver-lock`
  local xcomposer_path="$HOME/.cache/xcomposer/$xcomposer_version"

  if [ -n "${XCOMPOSER_PATH:-}" ]
  then
    xcomposer_path="$XCOMPOSER_PATH/$xcomposer_version"
  fi

  if [ ! -e "$xcomposer_path" ]
  then
    echo "(xcomposer: installing composer version $xcomposer_version)" >&2
    mkdir -p "$xcomposer_path"/bin
    wget -nv -O "$xcomposer_path"/bin/composer \
      https://github.com/composer/composer/releases/download/$xcomposer_version/composer.phar
    chmod 755 "$xcomposer_path"/bin/composer
    echo
  fi

  echo "(xcomposer: using composer version $xcomposer_version)" >&2
  COMPOSER_CACHE_DIR="$xcomposer_path"/cache \
    "$xcomposer_path"/bin/composer $@
}
