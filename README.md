# “Th” System Tools

This is a collection of system command line tools that are designed to be **easy to audit** and **non-invasive** to make your daily life as a sysadmin or devops easier.

- [1. Introduction](#1-introduction)
- [2. Installation](#2-installation)
  * [2.1. Manual installation - Local](#21-manual-installation---local)
  * [2.2. Manual installation - Global](#22-manual-installation---global--recommended-)
  * [2.3. Automatic installation](#23-automatic-installation)
- [3. Package structure and components](#3-package-structure-and-components)
  * [3.1. `bash` folder](#31--bash--folder)
  * [3.2. `bin` and `sbin` folders](#32--bin--and--sbin--folders)
  * [3.3. `scripts` folder](#33--scripts--folder)
- [4. Description of commands and functions](#4-description-of-commands-and-functions)

# 1. Introduction

If you are an old school sysadmin like me, you will find yourself facing the same difficulties over and over when managing vanilla systems as CentOS 7 that do not offer certain conveniences.

If you are operating from a remote console that offers plenty of screen space (i.e. columns, *precious columns*), you will find that many basic linux command line commands are designed for 80-columns legacy screen, which make them difficult to operate.

That is, of course, if you are not using those fancy complex systems that take manual tasks off your hands (and usually as well as the ultimate control of your servers).

This package contains **simple**, **non-invasive** scripts and tools to ease your pain without doing things without you knowing it, and without learning new commands as they mimick the old school commands.


# 2. Installation

The best way to get started using this package is to simply source `init.sh` into your login shell.

This package works from source, **no building process is required**.

## 2.1. Manual installation - Local

Modify your `~/.bashrc` to include the source the init script:

```bash
  # ~/.bashrc:
  . $HOME/path/to/th-sys-tools/bash/init.sh
```

## 2.2. Manual installation - Global (recommended)

Just clone this repository and symlink the `bash/init.sh` file in your `/etc/profile.d` folder like this (assumes you cloned this into `/opt/th-sys-tools`):

```bash
  ln -sf /opt/th-sys-tools/bash/init.sh /etc/profile.d/th-sys-tools.sh
```

## 2.3. Automatic installation

~~Install this package using your distro package manager~~ (not yet supported, rpm/deb help wanted)


# 3. Package structure and components

## 3.1. `bash` folder

Contains the `init.sh` script to initialize your bash shell and the `funcs.sh` file that contains simple shell functions and aliases.

## 3.2. `bin` and `sbin` folders

Contains symlinks to scripts and tools useful for both **users** and **root**.

## 3.3. `scripts` folder

Contains the actual implementation.

# 4. Description of commands and functions

| Command        | Component     | Description |
| -------------- |:-------------:| ----------- |
| mysql_choose   | bash          | Checks your `~/.my.cnf` for multiple client definitions and lets you choose an entry to set the environemental variable `MYSQL_GROUP_SUFFIX` to the desired value. After that, all mysql-family commands will use those settings. |
| mysql_dropall  | bash          | Ever noticed that mysql does not provide a way to drop all tables from a database? This commands queries your database structure and **drops all tables and views** so, *use carefully*. |
| piptables      | scripts       | PHP-wrapped `iptables` command that colors and reformats the output nicely. |
| pdocker        | scripts       | PHP-wrapped `docker` command that colors and reformats the output nicely. |
| pdf            | scripts       | PHP-wrapped `df` command that colors and reformats the output nicely. |
| tail_http_access_log   | scripts | Tails a standard *combined* httpd `access_log` with coloring and formatting. |
| tail_http_error_log    | scripts | Tails a standard httpd `error_log` with coloring and formatting. |
| tail_mysql_general_log | scripts | Tails the MySQL `general_log` with coloring and formatting, allows to isolate DML queries. Useful to inspect the database activity of a rogue web app that doesn't allow a reliable built-in way to do so at application level. |

And, hopefully, much more to come!
