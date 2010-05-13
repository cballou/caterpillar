== About Caterpillar ==

Caterpillar is a PHP library intended for website crawling and screen scraping.  It handles parallel requests using a modified version of Josh Fraser's Rolling Curl (http://code.google.com/p/rolling-curl/) library which utilizes curl_multi() functions in an efficient manner.  You can learn more about Josh and his current projects on his blog, Online Aspect (http://www.onlineaspect.com/).

Because requests are handled in parallel, the fastest completed requests will trigger enqueuing any newly found URLs, ensuring the crawler runs continuously and efficiently.  Rolling Curl is set to allow for a maximum number of simultaneous connections to ensure you do not DOS attack the requested host with requests.

== Quick Installation ==

1. Import the caterpillar.sql file into the database of your choice.
2. Copy the library to your application and include.
3. Modify the configuration file /caterpillar/inc/config.inc.php with your MySQL database login credentials.
4. Your database user will need the privilege for creating and dropping TEMPORARY TABLES.
4. Use the following example for usage:

	<?php
	require_once('caterpillar.php');

	$caterpillar = new Caterpillar('http://www.url-to-crawl.org', $config['db_user'], $config['db_pass'], $config['db_name'], $config['db_host']);
	$caterpillar->crawl();
	?>
