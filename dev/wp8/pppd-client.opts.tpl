# WP8 lab PPPoE client (independent Linux pppd in its own network namespace). Template rendered by
# dev/wp8/client_agent.py into the untracked state directory (mode 600, deleted after pppd has read it).
plugin pppoe.so
nic-@NIC@
unit @UNIT@
user @USER@
password @PASSWORD@
noauth
nodetach
noipdefault
nodefaultroute
noccp
lcp-echo-interval 5
lcp-echo-failure 4
maxfail 1
debug
logfile @LOG@
