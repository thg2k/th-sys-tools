
alias odx='od -t x1c'
alias pico='nano'

thdiff() {
  diff -U 20 $@ | colordiff | less -R
}

thdu() {
  du -h --max-depth=1 | sort -h
}

svn_diff_review() {
  svn diff --diff-cmd=diff -x -U25 | colordiff | less -r
}

alias mc='mysql_choose'

#list_images() {
#  [ -z "$1" ] && return
#  identify $@ | sed -e 's/\[[0-9]\+\]//' | awk '{ printf "%-30s %s\n", $1, $3 }'
#}
list_images() {
  [ -z "$1" ] && return;
  identify -format "%wx%h   %f\n" "$@"
}

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
