<?php
// get current directory
define('DIR', dirname(__FILE__));
// load config file
require(DIR . '/inc/config.inc.php');
// load the RollingCurl Request class
require(DIR . '/inc/Request.php');
// load the RollingCurl library
require(DIR . '/inc/RollingCurl.php');

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
		$this->startUrl = $startUrl;
        // store the base domain
        $info = parse_url($startUrl);
        $this->domain = $info['scheme'] . '://' . $info['host'];
		// open mysql connection
		$this->db = mysql_connect($dbHost, $dbUser, $dbPass);
        if ($this->db === false) {
            throw new Exception('The caterpillar database connection could not be established');
        }
		// use specified database
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
        // truncate old entries
		$this->resetIndex();

		// add initial URL to list
		$this->addUrl($this->startUrl);
		
		// begin crawling the url
		$this->crawlUrls();
	}

	/**
	 * Crawls the given URL and parses the data looking for
	 * any links.  If a link is found to be using the same
	 * domain name or is a relative URL, it is recursively
	 * parsed as well.
	 *
	 * @access	public
	 * @return	void
	 */
	public function crawlUrls()
	{
		// only execute if a cURL request is not running
		if (!$this->running) {
			// run the initial URL through the crawler
			$this->execute();
		}
	}
    
	/**
	 * Function to check whether a link already exists in the database or not.
	 * If the link exists the count is updated.
	 *
	 * @access	public
	 * @param	string	$link
	 * @return	bool
	 */
	protected function checkUrlExists($link)
	{
		$sql = sprintf('SELECT 1 FROM crawl_index WHERE link = "%s"', mysql_real_escape_string($link));
		$res = mysql_query($sql, $this->db);
		if (mysql_num_rows($res) > 0) {
			mysql_free_result($res);
			$sql = sprintf('UPDATE crawl_index set count = count + 1 WHERE link = "%s"', mysql_real_escape_string($link));
			mysql_query($sql, $this->db);
			return true;
		}
		return false;
	}

	/**
	 * Add the URL to the list of indexed pages and add
	 * to the crawlers queue to be parsed.
	 *
	 * @access	public
	 * @param	string	$url
	 * @return	void
	 */
	public function addUrl($url)
	{
		// add the found URL to the index
		$this->addUrlToIndex($url);

		// add url to Rolling Curl requests queue to scrape for more urls
		$this->addRequest($url);
	}

	/**
	 * Function to add a given URL to the crawled index table.
	 *
	 * @access 	public
	 * @param	string	$url
	 * @return	void
	 */
	public function addUrlToIndex($url)
	{
		// the link doesnt exist in the db, insert now
		$sql = sprintf('INSERT INTO crawl_index SET link = "%s", `count` = 1', mysql_real_escape_string($url));
		mysql_query($sql, $this->db);
		return mysql_insert_id();
	}

	/**
	 * Removes all old entries of the sitemap from the database.
	 *
	 * @access 	public
	 * @return	void
	 */
	public function resetIndex()
	{
		mysql_query('TRUNCATE crawl_index', $this->db);
	}
	
	/**
	 * Callback function from Rolling Curl which parses the recently
	 * scraped page for more URLs.
	 *
	 * @access	public
	 * @param	string	$html
	 * @param	int		$http_code
	 */
	public function parseHtml($html, $http_code)
	{
		if ($http_code >= 200 && $http_code < 400 && !empty($html)) {

			// find all urls on the page
			$urlMatches = array();
			if (preg_match_all('/href="([^#"]+)"/i', $html, $urlMatches, PREG_PATTERN_ORDER)) {

				// garbage collect url matches
				$urlMatches[0] = null;

				// garbage collect HTML
				unset($html);
				
				// create storage array of all valid matches
				$validMatches = array();

				// iterate over each link on the page
				foreach ($urlMatches[1] as $k => $link) {

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
					
					// dont index email addresses
					else if (strpos($link, 'mailto:') !== false) {
						continue;
					}
					
                    // check for a same directory reference
                    else if (strpos($link, 'http') === false && strpos($link, '/') === false) {
						if (strpos($link, 'www.') !== false) continue;
                        $link = $this->domain . '/' . $link;   
                    }
					// skip link if it isnt on the same domain
					else if (strpos($link, $this->domain) === false) {
                        continue;
					}
                    
                    // check to see if the link has already been added
					if ($this->checkUrlExists($link)) {
						continue;
					}

					// add URL to matches and crawler queue
					$this->addUrl($link);

				}

				// garbage collect
				unset($urlMatches);
				
				// crawl any newly found URLs
				$this->crawlUrls();

			}

		}
	}

}

// sample
$caterpillar = new Caterpillar('http://www.bechtler.org', $config['db_user'], $config['db_pass'], $config['db_name'], $config['db_host']);
$caterpillar->crawl();
?>
