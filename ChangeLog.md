# ChangeLog

## Version 0.4.0 -- xx/xx/xxxx

 - Added 'xcomposer' bash function.
 - Various new features and fixes to the 'gitlab_docker_manager' command.
 - General improvements to the tail scripts and added 'tail_mail_log'.


## Version 0.3.1 -- 03/11/2022

 - Fixed bug in tail scripts in determining terminal columns.


## Version 0.3.0 -- 05/10/2022

 - Major improvements to the `gitlab_odcker_manager` (`gdm`) command.
 - Added new command `duplicate_remove`.
 - Improved `pdocker ps` parsing and output.
 - Major improvements to the `tail_http_access_log` command, the new default
   behaviour matches the one from regular `tail` command, use the new `-f`
   command line switch to *follow* the file.
