<?php
// get current directory
if (!defined('CATERPILLAR_DIR')) define('CATERPILLAR_DIR', dirname(__FILE__));
// load the RollingCurl Request class
require(CATERPILLAR_DIR . '/inc/RollingCurl_Request.php');
// load the RollingCurl library
require(CATERPILLAR_DIR . '/inc/RollingCurl.php');
// set the memory limit
ini_set('memory_limit', '64M');

/**
 * The caterpillar class is intended to be used for
 * educational purposes only.
 *
 * @author      Corey Ballou
 * @copyright   Copyright (c) 2009, Corey Ballou
 * @license     http://www.apache.org/licenses/LICENSE-2.0
 * @link        http://www.jqueryin.com/projects/caterpillar
 * @version     1.0
 */
class Caterpillar extends RollingCurl {

	// CATERPILLAR VARS
	protected $startUrl;        // the starting URL of the crawler
    protected $domain;          // the domain path
	protected $ch;              // stores the curl handler
    protected $db;              // stores the database connection
	protected $maxDepth = 3;    // the maximum number of trailing forward slashes
	protected $startTime;		// the timestamp that caterpillar started

	protected $tempTable;					// the name of the temporary table
	protected $page_cache = array();		// the page cache for batching counts
	protected $pagesRequested = array();	// handles checking for first page requests

    /**
     *  Default constructor.
     *
     *  @access public
     *  @param  string  $startUrl   The starting url for crawling
     *  @param  string  $dbUser     The database username
     *  @param  string  $dbPass     The database password
     *  @param  string  $dbName     The database name
     *  @param  string  $dbHost     The database host
     *  @return void
     */
    public function __construct($startUrl, $dbUser, $dbPass, $dbName, $dbHost)
    {
		// register the Rolling Curl callback
		parent::__construct('parseHtml');

        // validate the url is valid HTTP or HTTPS
        if (strpos($startUrl, 'http://') === false &&
            strpos($startUrl, 'https://') === false) {
            throw new Exception('Your starting caterpillar URL must begin with http or https.');
        }

		// store the base url
		$this->startUrl = rtrim($startUrl, '/') . '/';

		// store the start time
		$this->startTime = date('Y-m-d H:i:s');

        // store the base domain
        $info = parse_url($startUrl);
        $this->domain = $info['scheme'] . '://' . $info['host'];

		// open mysql connection
		$this->db = mysql_connect($dbHost, $dbUser, $dbPass);
        if ($this->db === false) {
            throw new Exception('The caterpillar database connection could not be established');
        }

		// use user specified database
		if (!mysql_select_db($dbName)) {
            throw new Exception('An error occurred attempting to connect to the database ' . $dbName);
        }

    }

	/**
	 * Begins the crawling process.  Initially starts
	 * by removing all old crawled entries from the database.
	 *
	 * @access 	public
	 * @return	void
	 */
	public function crawl()
	{
        // truncate any non-existant pages
		$this->resetIndex();

		// create a temporary table for incrementing counts
		$this->createTempTable();

		// add initial URL to crawl list
		$this->addRequest($this->startUrl);

		// begin crawling the url
		$this->crawlUrls();

		// update url counts and remove the temp table
		$this->finalizeUrlCounts();
	}

	/**
	 * Function to check whether a link already exists in the database or not.
	 * If the link exists the count is updated.
	 *
	 * @access	public
	 * @param	string	$link
	 * @param	string	$html
	 * @param	string	$filesize
	 * @return	bool
	 */
	public function checkUrlExists($link, $html, $filesize)
	{
		$sql = sprintf('SELECT SQL_NO_CACHE id, filesize, last_tested FROM crawl_index WHERE link = "%s"', mysql_real_escape_string($link));
		$res = mysql_query($sql, $this->db);
		if (mysql_num_rows($res) > 0) {
			$res = mysql_fetch_assoc($res);
			if ($res['last_tested'] == $this->startTime) {
				$this->updateInboundCount($res);
			} else {
				$this->updateInboundCount($res, $html, $filesize);
			}
			return true;
		}
		return false;
	}

	/**
	 * Function to update the inbound link count of a URL.
	 *
	 * @access	protected
	 * @param	mixed		$page
	 * @param	string		$html
	 * @param	mixed		$filesize
	 */
	protected function updateInboundCount($page, $html = '', $filesize = null)
	{
		// no filesize to check because its already been updated
		if ($filesize === null) {
			if (count($this->page_cache) < 1000) {
				$this->page_cache[] = (is_array($page)) ? $page['id'] : $page;
				return true;
			} else {
				// bulk insert rows
				$sql = sprintf('INSERT INTO %s (page_id) VALUES (%s)', $this->tempTable, implode('),(', $this->page_cache));
				// reset cache to empty state
				$this->page_cache = array();
			}
		}
		// filesize hasnt changed
		else if ($page['filesize'] == $filesize) {
			$sql = sprintf('UPDATE crawl_index SET
							`count` = `count` + 1,
							last_tested = "%s"
							WHERE id = %d',
							mysql_real_escape_string($this->startTime),
							$page['id']);
		}
		// filesize has changed since last check
		else {
			$sql = sprintf('UPDATE crawl_index SET
							`count` = `count` + 1,
							filesize = %1$d,
							contenthash = "%2$s",
							last_update = "%3$s",
							last_tested = "%3$s"
							WHERE id = %4$d',
							$filesize,
							mysql_real_escape_string($this->_crc32($html)),
							mysql_real_escape_string($this->startTime),
							$page['id']);
		}
		return mysql_query($sql);
	}

	/**
	 * Checks for a link's last_tested time to see if it has already
	 * been crawled during this session.
	 *
	 * @access	protected
	 * @param	string		$link
	 */
	protected function checkIfFirstRequest($link)
	{
		// simple cache to avoid db
		if (isset($this->pagesRequested[$link]))
			return $this->pagesRequested[$link];

		// cache miss, need to check
		$sql = sprintf('SELECT SQL_NO_CACHE id, last_tested FROM crawl_index WHERE link = "%s"', mysql_real_escape_string($link));
		$res = mysql_query($sql, $this->db);
		if (mysql_num_rows($res) > 0) {
			$res = mysql_fetch_assoc($res);
			$this->pagesRequested[$link] = $res['id'];
			if ($res['last_tested'] != $this->startTime) {
				return true;
			} else {
				return $res['id'];
			}
		}
		return true;
	}

	/**
	 * Function to add a given URL to the crawled index table.
	 *
	 * @access 	public
	 * @param	string	$url
	 * @param	string	$html
	 * @param	string	$filesize
	 * @return	void
	 */
	public function addUrlToIndex($url, $html, $filesize)
	{
		// the link doesnt exist in the db, insert now
		$sql = sprintf('INSERT INTO crawl_index SET
						link = "%1$s",
						`count` = 1,
						contenthash = "%2$s",
						filesize = %3$d,
						last_update = "%4$s",
						last_tested = "%4$s"',
						mysql_real_escape_string($url),
						mysql_real_escape_string($this->_crc32($html)),
						$filesize,
						mysql_real_escape_string($this->startTime));

		mysql_query($sql, $this->db);
		return mysql_insert_id();
	}

	/**
	 * Callback function from Rolling Curl which parses the recently
	 * scraped page for more URLs.
	 *
	 * @access	protected
	 * @param	string	$url		The url requested
	 * @param	string	$html		The body content
	 * @param	int		$http_code	The returned HTTP code
	 * @param	string	$title		The document title
	 * @param	int		$filesize	The size of the downloaded file in bytes
	 */
	protected function parseHtml($url, $html, $http_code, $title, $filesize)
	{
		if ($http_code >= 200 && $http_code < 400 && !empty($html)) {

			// add URL to index (or update count)
			if (!$this->checkUrlExists($url, $html, $filesize)) {
				$this->addUrlToIndex($url, $html, $filesize);
			}

			// find all urls on the page
			$urlMatches = array();
			if (preg_match_all('/href="([^#"]*)"/i', $html, $urlMatches, PREG_PATTERN_ORDER)) {

				// garbage collect
				unset($html, $urlMatches[0]);

				// iterate over each link found on the page
				foreach ($urlMatches[1] as $k => $link) {

					$link = trim($link);
					if (strlen($link) === 0) $link = '/';

					// don't allow more than maxDepth forward slashes in the URL
					if ($this->maxDepth > 0
                        && strpos($link, 'http') === false
                        && substr_count($link, '/') > $this->maxDepth) {
						continue;
					}

					// check for a relative path starting with a forward slash
					if (strpos($link, 'http') === false && strpos($link, '/') === 0) {
						// update the link with the full domain path
                        $link = $this->domain . $link;
					}
                    // check for a same directory reference
                    else if (strpos($link, 'http') === false && strpos($link, '/') === false) {
						if (strpos($link, 'www.') !== false) continue;
                        $link = $this->domain . '/' . $link;
                    }
					// dont index email addresses
					else if (strpos($link, 'mailto:') !== false) {
						continue;
					}
					// skip link if it isnt on the same domain
					else if (strpos($link, $this->domain) === false) {
                        continue;
					}

					// only add request if this is the first time in this session
					$link_id = $this->checkIfFirstRequest($link);
					if ($link_id === true) {
						$this->addRequest($link);
					} else {
						// update the inbound link count
						$this->updateInboundCount($link_id);
					}

				}

				// garbage collect
				unset($urlMatches);

				// crawl any newly found URLs
				$this->crawlUrls();

			}

		}
	}

	/**
	 * Crawls the given URL and parses the data looking for
	 * any links.  If a link is found to be using the same
	 * domain name or is a relative URL, it is recursively
	 * parsed as well.
	 *
	 * @access	protected
	 * @return	void
	 */
	protected function crawlUrls()
	{
		// only execute if a cURL request is not running
		if (!$this->running) {
			$this->execute();
		}
	}

	/**
	 * Removes all old entries of the sitemap from the database.
	 *
	 * @access 	private
	 * @return	void
	 */
	private function resetIndex()
	{
		// remove any non-existant links
		$sql = sprintf('DELETE FROM crawl_index WHERE last_update < "%s"', date('Y-m-d H:i:s', strtotime('-2 weeks')));
		mysql_query($sql, $this->db);

		// update all inbound link counters to 1
		mysql_query('UPDATE crawl_index SET `count` = 1');

		// optimize the table due to the deletions
		mysql_query('OPTIMIZE TABLE crawl_index');
	}

	/**
	 * Creates a temporary table for handling page counters.
	 *
	 * @access	private
	 */
	private function createTempTable()
	{
		// generate a temp table name
		$this->tempTable = 'crawl_index_temp';

		// ensure the temp table doesnt exist
		$sql = sprintf('DROP TABLE IF EXISTS %s', $this->tempTable);
		mysql_query($sql, $this->db);

		// create the table and give an exclusive write lock
		$sql = sprintf('CREATE TEMPORARY TABLE %s (page_id INT(10) UNSIGNED NOT NULL) ENGINE=MEMORY', $this->tempTable);
		mysql_query($sql, $this->db);
	}

	/**
	 * Updates the inbound link counts.
	 *
	 * @access	private
	 */
	private function finalizeUrlCounts()
	{
		// batch process any remaining pages in the page cache
		if (!empty($this->page_cache)) {
			$sql = sprintf('INSERT INTO %s (page_id) VALUES (%s)', $this->tempTable, implode('),(', $this->page_cache));
			mysql_query($sql);
		}

		// update the counts
		$sql = sprintf('UPDATE crawl_index
						LEFT JOIN (SELECT page_id, COUNT(page_id) AS total FROM %s GROUP BY page_id) AS crawl_count
						ON (crawl_index.id = crawl_count.page_id)
						SET crawl_index.count = (crawl_index.count + IFNULL(crawl_count.total, 0))',
						$this->tempTable);

		mysql_query($sql);

		// delete the temporary table
		$sql = sprintf('DROP TABLE %s', $this->tempTable);
		mysql_query($sql);
	}

}
?>
