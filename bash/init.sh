_th_sys_tools_root=$(dirname $(dirname ${BASH_SOURCE[0]}))
if [ -n "$PS1" ]
then
  echo "Loading th-sys-tools v0.1.1 in $_th_sys_tools_root"
fi

export HISTSIZE=100000
export HISTFILESIZE=100000
#export HISTCONTROL=ignoredups:erasedups
shopt -s histappend

. $_th_sys_tools_root/bash/funcs.sh

pathmunge () {
    case ":${PATH}:" in
        *:"$1":*)
            ;;
        *)
            if [ "$2" = "after" ] ; then
                PATH=$PATH:$1
            else
                PATH=$1:$PATH
            fi
    esac
}

pathmunge $_th_sys_tools_root/bin after
if [ "$EUID" = "0" ]
then
  pathmunge $_th_sys_tools_root/sbin after
fi

export PATH

unset _th_sys_tools_root
unset -f pathmunge
