
alias odx='od -t x1c'
alias odx="od -t x1c"

th_diff() {
  diff -U 20 $@ | colordiff | less -R
}

th_du() {
  du -h --max-depth=1 | sort -h
}


svn_diff_review() {
  svn diff --diff-cmd=diff -x -U25 | colordiff | less -r
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

#list_images() {
#  [ -z "$1" ] && return
#  identify $@ | sed -e 's/\[[0-9]\+\]//' | awk '{ printf "%-30s %s\n", $1, $3 }'
#}
list_images() {
  [ -z "$1" ] && return;
  identify -format "%wx%h   %f\n" "$@"
}
