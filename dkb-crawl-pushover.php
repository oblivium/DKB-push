#!/usr/bin/php
<?php

chdir(__DIR__);
require('simple_html_dom.php');
require('config.php');
require_once('Pushover.php');

$url = 'https://www.dkb.de/';
define('CSV_HEADER_LINES', 7);
define('CSV_EC_COLUMN_DATE', 0);
define('CSV_EC_COLUMN_DATE2', 1);
define('CSV_EC_COLUMN_SUBJECT1', 3);
define('CSV_EC_COLUMN_SUBJECT2', 4);
define('CSV_EC_COLUMN_VALUE', 7);
define('CSV_CC_COLUMN_DATE', 2);
define('CSV_CC_COLUMN_SUBJECT', 3);
define('CSV_CC_COLUMN_VALUE', 4);

function doCurlPost($action, $data) {
	global $url, $ch;

	$lastUri = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
	if ($lastUri) { curl_setopt($ch, CURLOPT_REFERER, $lastUri); }

	curl_setopt($ch, CURLOPT_URL, $url . $action);
	curl_setopt($ch, CURLOPT_POST, count($data));
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

	return curl_exec($ch);
}

function doCurlGet($path) {
	global $url, $ch;

	$lastUri = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
	if ($lastUri) { curl_setopt($ch, CURLOPT_REFERER, $lastUri); }

	curl_setopt($ch, CURLOPT_URL, $path);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

	return curl_exec($ch);
}

function findLineInCSV($line, $csv) {
	foreach ($csv as $k => $v) {
		if ($k < CSV_HEADER_LINES) continue;
		if ($v == $line) {
			return $k;
		}
	}

	return false;
}

//
// CURL init
//
$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIESESSION, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, 'data/cookie.txt');
curl_setopt($ch, CURLOPT_COOKIEJAR, 'data/cookie.txt');
//curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_CAINFO, 'cacert.pem');

//
// LOGIN
//
echo 'Logging in...';
$result = doCurlGet($url.'banking');
$dom = str_get_html($result);
$form = $dom->find('form', 1);

$post_data = array();
foreach ($form->find('input') as $elem) {
	if ($elem->name == 'j_username') $elem->value = $kto;
	if ($elem->name == 'j_password') $elem->value = $pin;

	$post_data[$elem->name] = $elem->value;
}
$html_ = doCurlPost('banking', $post_data);

if (strpos($html_, 'Letzte Anmeldung:') !== false) {
	echo "OK!\n";
} else {
	echo 'Error. Login failed!';
	die();
}

//
// get Konten
//
echo "get Konten...\n";
$accounts = array();
$matches = array();

$dom_ = str_get_html($html_);
$cnt = 0;
foreach ($dom_->find('table[class=financialStatusTable] tr') as $k => $row) {
	if ($row->class != 'mainRow') { continue; }

	// loop
	$td = $row->find('td', 0);
	if (!$td) continue;

	$desc = trim(strip_tags($td->find('div', 0)->plaintext));
	$nr = trim($td->find('div', 1)->plaintext);
	$nr = str_replace('*', '_', $nr);
	$ec = strpos($td->find('div', 1)->class, 'iban') !== false;

	if ($desc == 'Depot') break;

	echo "  found '$desc' ($nr)";
	echo $ec ? " - is EC" :  " - is CC";
	echo " - load Details";
	$html = doCurlGet($url . 'banking/finanzstatus?$event=paymentTransaction&table=cashTable&row='.$cnt);

	// download CSV
	echo " - download CSV";
	$ums = $ec ? 'kontoumsaetze' : 'kreditkartenumsaetze';
	$csv = doCurlGet($url . 'banking/finanzstatus/'.$ums.'?$event=csvExport');

	$row->clear();
	unset($row);

	$cnt++;

	echo "\n";
	$accounts[$nr] = ['desc' => $desc, 'csv' => $csv, 'nr' => $nr, 'type' => $ec?'ec':'cc'];
}

#print_r($accounts);

//
// Logout
//
echo "Logout!\n";
$html = doCurlGet($url . '/DkbTransactionBanking/banner.xhtml?$event=logout');

//
// Parse CSV
//
echo "Parse CSV\n";
$push = array();
foreach ($accounts as $account) {
	$cnt = 0;
	$lines = explode("\n", $account['csv']);

	$exists = file_exists($file = __DIR__ . '/data/' . $account['nr']);
	$csv = $exists? file($file, FILE_IGNORE_NEW_LINES) : false;
	file_put_contents($file, $account['csv']);
	if (!$exists) {
		// no push on first run. just save the csv for later comparison
		continue;
	}

	foreach ($lines as $k => $line) {
		if ($k < CSV_HEADER_LINES || !$line) continue;

		$data = explode(';', $line);
		$data = array_map(function($e){return trim($e, '" ><');}, $data);

		$lineNbr = findLineInCSV($line, $csv);
		if ($lineNbr === false) {
			// push
			if (++$cnt >= 5) break; // no more than 5 push messages per account per run
			echo $str = "    new entry: $line\n";

			if ($account['type'] == 'ec') {
				// Strip CC data out of Verwendungszweck
				$data[CSV_EC_COLUMN_SUBJECT2] = preg_replace('#(\d{4}) \d{4} \d{4} (\d{4})#', '$1 XXXX XXXX $2', $data[CSV_EC_COLUMN_SUBJECT2]);

				$push[] = array(
					$account['desc'],
					$data[CSV_EC_COLUMN_DATE],
					$data[CSV_EC_COLUMN_SUBJECT1] . ' ' . $data[CSV_EC_COLUMN_SUBJECT2],
					$data[CSV_EC_COLUMN_VALUE]
				);
			} else {
				$push[] = array(
					$account['desc'],
					$data[CSV_CC_COLUMN_DATE],
					$data[CSV_CC_COLUMN_SUBJECT],
					$data[CSV_CC_COLUMN_VALUE]
				);
			}
		} else if ($account['type'] == 'cc' || $data[CSV_EC_COLUMN_DATE2]) {
			// line found in old CSV .. we can stop here with this CSV
			// in the CSV file of EC cards, predated payments appear on top. Predated payments have an empty CSV_EC_COLUMN_DATE2 column.
			// so we have to always look below them for possibly new transactions
			break;
		}
	}
}

//
// Push
//
echo "PUSH via Pushover\n";
foreach ($push as $k => $elem) {
/*	if ($k && $k%3 == 0) {
		echo "Sleeping..\n";
		sleep(10);
	} */
	list($desc, $date, $subject, $value) = $elem;
	$color = $value[0] == '-' ? 'red' : 'green';

	$title = $desc . ' ' .$value .' Euro';
	$message = '<b>'. $date . '</b>'. "\n" . $subject . "\n" . "\n". '<b><font color="'.$color.'">' . $value . ' Euro</font></b>';
	$utf8message = utf8_encode($message);

	echo "\n";
	$push = new Pushover();
	$push->setToken($pushover_app_token);
	$push->setUser($pushover_user);
	$push->setHtml(1);
	$push->setTitle($title);
	$push->setMessage($utf8message);
	//$push->setUrl('http://test.com');
	//$push->setUrlTitle($title);
	$push->setDevice($pushover_device_name);
	$push->setPriority(1);
	$push->setRetry(60); //Used with Priority = 2; Pushover will resend the notification every 60 seconds until the user accepts.
	$push->setExpire(3600); //Used with Priority = 2; Pushover will resend the notification every 60 seconds for 3600 seconds. After that point, it stops sending notifications.
	#$push->setCallback('http://yoururl.com');
	$push->setTimestamp(time());
	$push->setDebug(true);
	$push->setSound('bike');
	$go = $push->send();
	echo '<pre>';
	print_r($go);
	echo '</pre>';


}
?>
