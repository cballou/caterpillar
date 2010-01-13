<?php 
class RollingCurl {

    // window_size represents the number
	// simultaneous connections allowed
	// based on max_window_size and the
	// current number of requests
    protected $window_size = 1;
	// the maximum allowable window size
	protected $max_window_size = 5;
	// holds the callback function
    protected $callback;
	// the request queue
    protected $requests = array();
	// number of current connections in the queue
	protected $num_requests = 0;
	// holds headers
	protected $headers = array();
	// keeps track of whether a cURL request is running
	protected $running = 0;
	
    // set your base options that you want to be used with EVERY request
    protected $options = array(CURLOPT_USERAGENT 	=> 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)',
							 CURLOPT_SSL_VERIFYPEER => 0,
                             CURLOPT_RETURNTRANSFER => 1,
                             CURLOPT_FOLLOWLOCATION => 1,
                             CURLOPT_MAXREDIRS 		=> 5,
                             CURLOPT_CONNECTTIMEOUT => 30,
                             CURLOPT_TIMEOUT 		=> 30);
    
	/**
	 * Default constructor.
	 *
	 * @access	public
	 * @param	string|array	$callback
	 * @return	void
	 */
	public function __construct($callback = null) {
        $this->callback = $callback;
    }
    
	/**
	 * Magic getter method.
	 *
	 * @access	public
	 * @param	string	$name
	 * @return	mixed
	 */
    public function __get($name) {
        return (isset($this->{$name})) ? $this->{$name} : null;
    }

	/**
	 * Magic setter method.
	 *
	 * @access	public
	 * @param	string	$name
	 * @param	string	$value
	 * @return	bool
	 */
    public function __set($name, $value){
        // append the base options & headers
        if ($name == "options" || $name == "headers") {
            $this->{$name} = $this->{$name} + $value;
        } else {
            $this->{$name} = $value;
        }
        return true;
    }
    
    /**
	 * Add a request to the requests queue.
	 *
	 * @access	public
	 * @param	Request	$request
	 * @return	bool
	 */
    public function add(Request $request) {
         $this->requests[] = $request;
		 ++$this->num_requests;
         return true;        
    }
    
    /**
	 * Create a new request and add it to the queue.
	 *
	 * @access	public
	 * @param	string	$url
	 * @param	string	$method
	 * @param	string	$post_data
	 * @param	string	$headers
	 * @param	array	$options
	 * @return	bool
	 */
    public function addRequest($url, $method = "GET", $post_data = null, $headers = null, $options = null) {
         $this->requests[] = new Request($url, $method, $post_data, $headers, $options);
		 ++$this->num_requests;
         return true;
    }
    
    /**
	 * Shortcut to create a new GET request and add
	 * it to the queue.
	 *
	 * @access	public
	 * @param	string	$url
	 * @param	string	$headers
	 * @param	array	$options
	 * @return	bool
	 */
    public function addGetRequest($url, $headers = null, $options = null) {
        return $this->request($url, "GET", null, $headers, $options);
    }

    /**
	 * Shortcut to create a new POST request and add
	 * it to the queue.
	 *
	 * @access	public
	 * @param	string	$url
	 * @param	string	$post_data
	 * @param	string	$headers
	 * @param	array	$options
	 * @return	bool
	 */    
    public function addPostRequest($url, $post_data = null, $headers = null, $options = null) {
        return $this->request($url, "POST", $post_data, $headers, $options);
    }
    
    /**
	 * Execute the cURL request(s).
	 *
	 * @access	public
	 * @param	int		$max_window_size
	 * @return	mixed
	 */
    public function execute($max_window_size = null) {
		// validate we have requests
		if ($this->num_requests < 1) {
			throw new Exception('You must have at least one request before executing rolling cURL.');
		} else if ($this->num_requests == 1) {
			file_put_contents(DIR . '/log.txt', "Single request: \n", FILE_APPEND);
            return $this->single_curl();
        } else {
            // start the rolling curl
			file_put_contents(DIR . '/log.txt', "Multi request: \n", FILE_APPEND);
            return $this->rolling_curl($max_window_size);
        }
    }   
    
	/**
	 * Perform a single cURL request and return
	 * data and status to the specified callback
	 * function if it exists.
	 *
	 * @access	private
	 * @return	mixed
	 */
    private function single_curl() {
        $ch = curl_init();        
        $options = $this->get_options($this->requests[0]);
        curl_setopt_array($ch, $options);
        $output = curl_exec($ch);
		$output = substr($output, strpos($output, '<body'));
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		// clear all requests and reset counts
		$this->resetRequests();
        // send the return values to the callback function
        if ($this->callback) {
            if (method_exists($this, $this->callback))
                $this->{$this->callback}($output, $http_code);
			else throw new Exception('The callback method ' . $this->callback . ' doesnt exist.');
        } else {
            return $output;
        }
		return true;
    }
        
	/**
	 * Perform parallel cURL requests and return
	 * data and status to th specified callback function
	 * (if it exists) on completion of each request.
	 *
	 * @access	private
	 * @param	int		$max_window_size
	 * @return	mixed
	 */
    private function rolling_curl($max_window_size = null) {    
        if ($max_window_size) 
            $this->max_window_size = $max_window_size;

        // make sure the rolling window isn't greater
		// than the total number of requests
        if ($this->num_requests < $this->max_window_size) {
            $this->window_size = $this->num_requests;
        } else {
			$this->window_size = $this->max_window_size;	
		}

        // window size must be greater than 1
        if ($this->window_size < 2) {
			throw new Exception('Window size must be greater than 1 for parallel requests to occur.');
        }
            
        $master = curl_multi_init();
        $curl_arr = array();
            
        // start the first batch of requests
        for ($i = 0; $i < $this->window_size; $i++) {
            $options = $this->get_options($this->requests[$i]);
			$ch = curl_init();
            curl_setopt_array($ch, $options);
            curl_multi_add_handle($master, $ch);
        }
            
        do {
            while (($execrun = curl_multi_exec($master, $this->running)) == CURLM_CALL_MULTI_PERFORM);
            if ($execrun != CURLM_OK)
                throw new Exception('An error (' . $execrun . ') occurred retrieving the page');
            // a request was just completed -- find out which one
            while ($done = curl_multi_info_read($master)) {

                // get the info and content returned on the request
                $http_code 	= curl_getinfo($done['handle'], CURLINFO_HTTP_CODE);
                $output 	= curl_multi_getcontent($done['handle']);
				$output 	= substr($output, strpos($output, '<body'));

                // send the return values to the callback function
				if ($this->callback) {
					if (method_exists($this, $this->callback))
						$this->{$this->callback}($output, $http_code);
					else throw new Exception('The callback method ' . $this->callback . ' doesnt exist.');
				}

				// check if we need to increase the window size
				// if the number of requests has increased
				if ($this->num_requests > $this->window_size &&
					$this->num_requests < $this->max_window_size) {
					$this->window_size = $this->num_requests;
				}

                // start a new request
                if ($i < $this->num_requests && isset($this->requests[$i])) {
                    $ch = curl_init();
                    $options = $this->get_options($this->requests[$i++]); 
                    curl_setopt_array($ch,$options);
                    curl_multi_add_handle($master, $ch);
                }

                // remove the curl handle that just completed
                curl_multi_remove_handle($master, $done['handle']);
              
            }
        } while ($this->running);
        curl_multi_close($master);
		
		// clear all requests and reset counts
		$this->resetRequests();
        return true;
    }
   
	/**
	 * Clears all requests and resets counts.
	 */
    private function resetRequests()
	{
		$this->requests = array();
		$this->window_size = 1;
		$this->num_requests = 0;
	}
    
	/**
	 * Helper function to set extra options and
	 * header data for a given cURL request.
	 *
	 * @access	private
	 * @param	Request	$request
	 * @return	array
	 */
    private function get_options($request) {
        // options for this entire curl object
        $options = $this->__get('options');
        $headers = $this->__get('headers');

		// append custom options for this specific request
		if ($request->options) {
            $options = $this->__get('options') + $request->options;
        } 

		// set the request URL
        $options[CURLOPT_URL] = $request->url;

        // posting data w/ this request?
        if ($request->post_data) {
            $options[CURLOPT_POST] = 1;
            $options[CURLOPT_POSTFIELDS] = $request->post_data;
        }
        if ($headers) {
            $options[CURLOPT_HEADER] = 0;
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        return $options;
    }
    
    public function __destruct() {
        unset($this->window_size, $this->callback, $this->options, $this->headers, $this->requests);
	}
}

?>