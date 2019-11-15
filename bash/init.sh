_th_sys_tools_root=$(dirname $(dirname ${BASH_SOURCE[0]}))
echo "Loading th-sys-tools in $_th_sys_tools_root"

export HISTSIZE=100000
export HISTFILESIZE=100000
#export HISTCONTROL=ignoredups:erasedups
shopt -s histappend

. $_th_sys_tools_root/bash/funcs.sh

export PATH=$PATH:$_th_sys_tools_root/bin

unset _th_sys_tools_root
