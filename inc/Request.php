<?php
/*
authored by Josh Fraser (www.joshfraser.com)
released under Apache License 2.0
*/

// class that represent a single curl request
class Request {
	
    // stores the url, method, post_data, headers and options for each request
    private $settings = array();

	/**
	 * Default constructor.
	 *
	 * @access	public
	 * @param	string	$url
	 * @param	string	$method
	 * @param	string	$post_data
	 * @param	string	$headers
	 * @param	array	$options
	 */
    public function __construct($url, $method = "GET", $post_data = null, $headers = null, $options = null) {
        $this->settings['url'] = $url;
        $this->settings['method'] = $method;
        $this->settings['post_data'] = $post_data;
        $this->settings['headers'] = $headers;
        $this->settings['options'] = $options;
    }
    
	/**
	 * Magic getter method.
	 *
	 * @access	public
	 * @param	string	$name
	 * @return	mixed
	 */
    public function __get($name) {
        if (isset($this->settings[$name])) {
            return $this->settings[$name];
        }
        return false;
    }

	/**
	 * Magic setter method.
	 *
	 * @access	public
	 * @param	string	$name
	 * @param	string	$value
	 * @return	bool
	 */
    public function __set($name, $value) {
        $this->settings[$name] = $value;
        return true;
    }
    
	/**
	 * Destructor to unset settings.
	 *
	 * @access	public
	 * @return	void
	 */
    public function __destruct() {
    	unset($this->settings);
	}
}
?>