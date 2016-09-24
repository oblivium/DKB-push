DKB-push
========

Scrapes the DKB online banking and sends a PUSH message for every new transaction

This PHP script scrapes the online banking website of the DKB (Deutsche Kreditbank Berlin). After login, the transactions of all accounts are downloaded as CSV files and compared with previously downloaded CSVs. If threre's a new entry, a PUSH message is sent via Boxcar and/or Pushover App to any iOS device.

[Simple HTML DOM](http://simplehtmldom.sourceforge.net/) is used for HTML scraping.

To receive the PUSH messages, you need to download the Boxcar 2 app on the iOS Appstore (free). This script sends a maximum of 5 push messages per account and pauses every 3 messages. No push messages are send on the first run. All downloaded CSVs are stored in the data directory for later comparison.

Configuration
-------------
Rename config.php-example to config.php and enter the login credentials for the DKB online banking. You also have to enter Boxcar and/or Pushover access tokens and settings. For more info on the Pushover class please refer to https://github.com/cschalenborgh/php-pushover 

Usage
-----
This script is best run from cron in your local network, ie on a homeserver or Raspberry Pi.

### Example cron entry
This runs the script every two hours during bank business hours:

0 8,10,12,14,16,18 * * 1-6 /path/to/dkb-crawl-boxcar.php 
