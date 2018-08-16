osTicket-mail-alert
==============
A plugin for [osTicket](https://osticket.com) which sends alerts about new, updated and overdue (requires core modification) tickets to any email address.

The from and to email addresses are configurable.

Originally forked from: [https://github.com/clonemeagain/osticket-slack](https://github.com/clonemeagain/osticket-slack).

Info
------
This plugin was designed/tested with osTicket-1.10.1

## Install
--------
1. Clone this repo or download the zip file and place the contents into your `include/plugins` folder.
1. Now the plugin needs to be enabled & configured, so login to osTicket, select "Admin Panel" then "Manage -> Plugins" you should be seeing the list of currently installed plugins.
1. Click on `Mail Notifier` and configure the email addresses. 
1. Click `Save Changes`!. 
1. After that, go back to the list of plugins and tick the checkbox next to "Mail Notifier" and select the "Enable" button.
