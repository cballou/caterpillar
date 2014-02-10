# About Caterpillar #

Caterpillar is a PHP library intended for website crawling and screen scraping.  It handles parallel requests using a modified version of Josh Fraser's Rolling Curl (http://code.google.com/p/rolling-curl/) library which utilizes curl_multi() functions in an efficient manner.  You can learn more about Josh and his current projects on his blog, Online Aspect (http://www.onlineaspect.com/).

Because requests are handled in parallel, the fastest completed requests will trigger enqueuing any newly found URLs, ensuring the crawler runs continuously and efficiently.  Rolling Curl is set to allow for a maximum number of simultaneous connections to ensure you do not DOS attack the requested host with requests.

## Quick Installation ##

1. Create a database of your liking and create a user with extended privileges for `CREATE TEMPORARY TABLES`.
2. Import the `caterpillar.sql` file into the database of your choice.
3. Copy the library to your application and include.
4. Modify the configuration file /caterpillar/inc/config.inc.php with your MySQL database login credentials.
5. Use the following example for usage:

    ```php
    <?php
	require_once('caterpillar.php');
    
    // database configuration params
    $config = array(
        'db_user' => 'your database user',
        'db_pass' => 'your database user password',
        'db_name' => 'the database name',
        'db_host' => '127.0.01'
    );

    // instantiate the crawler
	$caterpillar = new Caterpillar(
	    'http://www.url-to-crawl.org', 
	    $config['db_user'], 
	    $config['db_pass'], 
	    $config['db_name'], 
	    $config['db_host']
	);
	
    // begin crawling, results get stored in database
	$caterpillar->crawl();
    ```
    
## Where Are My Results? ##

After crawling, your database results can be found in the table `crawl_index`. This table has the following structure:

* **id**: A unique, auto-incrementing row identifier.
* **link**: The page URL that was crawled.
* **count**: The number of times the page url was encountered while crawling your site.
* **filesize**: The size of the crawled file in bytes.
* **contenthash**: A unique CRC32 hash of the file contents for determining if the file has been updated since last crawled.
* **last_update**: A MySQL `DATE()` value of the last timestamp the page content has been added/updated/changed.
* **last_tested**: A MySQL `DATE()` value of the last timestamp the page has been tested for content changes.

You can easily utilize these results for a number of purposes, i.e. creating a weighted Google Sitemaps XML file based on inbound link popularity of pages.


[![Bitdeli Badge](https://d2weczhvl823v0.cloudfront.net/cballou/caterpillar/trend.png)](https://bitdeli.com/free "Bitdeli Badge")

