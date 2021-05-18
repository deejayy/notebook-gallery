#!/usr/bin/php
<?php

	require('phpQuery.php');
	define('DEBUG', 1);
	// define('TESTING', 1);
	gc_enable();

	date_default_timezone_set('Europe/Budapest');

	/**
	 * Common helper functions
	 */
	class helper {
		/**
		 * Simple log function, prints to stdout with timestamp
		 *
		 * @param string $str string to print
		 *
		 * @return null
		 */
		public static function log($str) {
			if (is_array($str)) {
				$str = print_r($str, true);
			}
			if (defined('DEBUG') && DEBUG) {
				printf("[%s] %s\n", date('Y-m-d H:i:s'), $str);
			}
		}

		/**
		 * Strips unneeded characters from a string
		 *
		 * @param string $str string to clean
		 *
		 * @return string
		 */
		public static function clean($str) {
			if (is_array($str)) {
				foreach ($str as &$s) {
					$s = helper::clean($s);
				}
				$ret = $str;
			} else {
				$ret = trim(preg_replace(array('/[\r\n]|:$| $/', '/[\t ]+/'), array('', ' '), $str));
			}
			return $ret;
		}

		/**
		 * Strips special characters from a file's basename (MS compatibility)
		 *
		 * @param string $str full path and filename
		 *
		 * @return string
		 */
		public static function basenameClean($str) {
			return preg_replace('/[\?\&\:]/', '_', basename($str));
		}
	}

	/**
	 * Downloader
	 */
	class downloader {
		/**
		 * Cache path, relative
		 */
		private $cachePath = 'cache';

		/**
		 * Browser user agent
		 */
		private $userAgent = 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.4 (KHTML, like Gecko) Chrome/22.0.1229.94 Safari/537.4';

		/**
		 * Returns the cache directory
		 *
		 * @return string
		 */
		function getCacheDirectory() {
			return $this->cachePath;
		}

		/**
		 * Downloads a file or read it from filesystem (if cached)
		 *
		 * @param string $url      file's URL
		 * @param string $filename save as filename (or load from cache)
		 * @param string $post     optional post parameters for download
		 *
		 * @return string content of the URL/file
		 */
		function getCache($url, $filename, $post = array()) {
			$myFile = $this->cachePath . DIRECTORY_SEPARATOR . $filename;

			if (file_exists($myFile)) {
				helper::log('Cache hit: ' . $myFile);
				$ret = file_get_contents($myFile);
			} else {
				helper::log('URL: ' . $url);
				helper::log('Downloading to: ' . $myFile);
				$c = curl_init();
				curl_setopt($c, CURLOPT_URL, $url);
				curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($c, CURLOPT_USERAGENT, $this->userAgent);
				curl_setopt($c, CURLOPT_REFERER, preg_replace('/(http:\/\/.*?\/).*/', '$1', $url));
				if (count($post)) {
					curl_setopt($c, CURLOPT_POSTFIELDS, $post);
				}
				$ret = curl_exec($c);
				curl_close($c);

				file_put_contents($myFile, $ret);
			}

			return preg_replace('/<\?xml .*\?>/im', '', $ret);
		}
	}

	class shop {
		/**
		 * shop name, private identifier, naming base
		 */
		protected $shopName = '';
		/**
		 * domain name for fetching individual products details page
		 */
		protected $shopDomain = '';
		/**
		 * product listing url (usually a search result page or main category)
		 */
		protected $shopListUrl = '';
		/**
		 * strip the GET parameters from product urls
		 */
		protected $shopProductUrlStripGet = 0;
		/**
		 * amount of result pages (if limited to a number of products)
		 */
		protected $shopListUrlCount = 1;
		/**
		 * start of page listing
		 */
		protected $shopListUrlCountStart = 1;
		/**
		 * if search query needs a list of POST parameters
		 */
		protected $shopListPostData = array();
		/**
		 * name of the field in the POST parameteres, appended to $shopListPostData
		 */
		protected $shopListPostCounterField = '';
		/**
		 * a phpquery selector for getting a product's url, must contain href
		 */
		protected $shopPqListNode = '';
		/**
		 * a phpquery selector for getting product's name from details page
		 */
		protected $shopPqProductName = '';
		/**
		 * a phpquery selector for getting product's price from details page
		 */
		protected $shopPqPriceField = '';
		/**
		 * price multiplier, eg. div by 100, tax correction, currency conversion
		 */
		protected $shopPriceMultiplier = 1;
		/**
		 * a phpquery selector for parameter names
		 */
		protected $shopPqParameterNames = '';
		/**
		 * a phpquery selector for parameter values
		 */
		protected $shopPqParameterValues = '';
		/**
		 * transliterate site's fixed parameter names to custom
		 */
		protected $shopTransliterateParameters = array();
		/**
		 * sorting parameter names in result
		 */
		protected $shopParameterOrder = array('TermékNév' => 1, 'Ár' => 1);
		/**
		 * called by prepareProduct(), loaded from XML
		 */
		protected $prepareFunction;
		/**
		 * Parameters to load from XML and assign to this->
		 */
		protected $xmlStructure = array(
			'shopName',
			'shopDomain',
			'shopListUrl',
			'shopProductUrlStripGet',
			'shopListUrlCount',
			'shopListUrlCountStart',
			'shopListPostData',
			'shopListPostCounterField',
			'shopPqListNode',
			'shopPqProductName',
			'shopPqPriceField',
			'shopPriceMultiplier',
			'shopPqParameterNames',
			'shopPqParameterValues',
		);

		private $shopListFiles = array();
		private $productUrls = array();
		private $products = array();
		private $productParameters = array();
		private $productValues = array();
		private static $downloader;

		/**
		 * Initialization
		 *
		 * @param string $xmlPath optional xml path to load
		 *
		 * @return null
		 */
		function __construct($xmlPath = '') {
			date_default_timezone_set('Europe/Budapest');
			helper::log(__FUNCTION__ . ' called on ' . get_class($this) . ', shop name: ' . $this->shopName);
			$this->prepareFunction = create_function('&$product', '');
			$this->downloader = new downloader();
			if ($xmlPath !== '') {
				$this->loadXML($xmlPath);
			}
		}

		/**
		 * Loads XML and prepares data structures
		 *
		 * 1. get common parameters according to $xmlStructure and assigns it to $this->
		 * 2. get prepare functions body and creates a function
		 * 3. assigns transliterable product property names to unified names
		 * 4. defines final product property order for CSV output, not specified names follows these
		 *
		 * @param string $xmlPath path of the input file
		 *
		 * @return null
		 */
		function loadXML($xmlPath) {
			$shopConfig = new DOMDocument();
			$shopConfig->load($xmlPath);

			foreach ($shopConfig->getElementsByTagName('shop') as $shopNode) {
				foreach ($this->xmlStructure as $nodeName) {
					$this->setShopParameterXML($shopNode, $nodeName);
				}

				if ($methodSource = $shopNode->getElementsByTagName('prepareProduct')->item(0)->nodeValue) {
					$this->prepareFunction = create_function('&$product', $methodSource);
				}

				foreach ($shopNode->getElementsByTagName('shopTransliterateParameters')->item(0)->getElementsByTagName('trDefine') as $trDefine) {
					$this->shopTransliterateParameters[$trDefine->attributes->getNamedItem('title')->nodeValue] = $trDefine->nodeValue;
				}

				foreach ($shopNode->getElementsByTagName('shopParameterOrder')->item(0)->getElementsByTagName('poName') as $poName) {
					$this->shopParameterOrder[$poName->nodeValue] = 1;
				}
			}

			$shopConfig = null;
			unset($shopConfig);
			helper::log('GC: ' . gc_collect_cycles());
		}

		/**
		 * Assign loaded parameters to variable
		 *
		 * @param mixed  $shopNode xml node
		 * @param string $nodeName node name
		 *
		 * @return null
		 */
		function setShopParameterXML($shopNode, $nodeName) {
			$nodeValue = $shopNode->getElementsByTagName($nodeName)->item(0)->nodeValue;
			if (trim($nodeValue) !== '') {
				$this->$nodeName = $nodeValue;
			}
		}

		/**
		 * Entry point
		 *
		 * @return null
		 */
		function getData() {
			helper::log(__FUNCTION__ . ' called on ' . get_class($this));
			$this->getList();
			$this->getProducts();
			$this->writeCSV();
		}

		/**
		 * Download product lists from $shopListUrl
		 *
		 * If neccessary, iterate from $shopListUrlCountStart to $shopListUrlCount, stores to individual files
		 * %d in $shopListUrl is replaced by $listIterator
		 *
		 * @return null
		 */
		function getList() {
			helper::log(__FUNCTION__ . ' called on ' . get_class($this) . ', from: ' . $this->shopListUrlCountStart . ', count: ' . $this->shopListUrlCount);
			if (defined('TESTING') && TESTING) {
				$this->shopListUrlCount = $this->shopListUrlCountStart;
			}
			@mkdir($this->downloader->getCacheDirectory() . DIRECTORY_SEPARATOR . $this->shopName);
			for ($listIterator = $this->shopListUrlCountStart; $listIterator <= $this->shopListUrlCount; $listIterator++) {
				$fileName = sprintf("%s" . DIRECTORY_SEPARATOR . "%s-%02d", $this->shopName, $this->shopName, $listIterator);

				helper::log(__FUNCTION__ . ': ' . sprintf($this->shopListUrl, $listIterator) . ' -> ' . $fileName);
				if (count($this->shopListPostData)) {
					$this->shopListPostData[$this->shopListPostCounterField] = $listIterator;
				}
				$listPage = $this->downloader->getCache(sprintf($this->shopListUrl, $listIterator), $fileName, $this->shopListPostData);
				$this->addList($listPage);

				$this->shopListFiles[] = $fileName;
			}
		}

		/**
		 * Collects product page URLs
		 *
		 * Finds links by $shopPqListNode selector, puts in $productUrls
		 *
		 * @param string $html html document
		 *
		 * @return null
		 */
		private function addList($html) {
			helper::log(__FUNCTION__ . ' called on ' . get_class($this) . ', size: ' . strlen($html));
			$listHtml = phpQuery::newDocument($html);
			$links = $listHtml[$this->shopPqListNode];
			foreach ($links as $link) {
				$this->productUrls[] = pq($link)->attr('href');
			}
			$listHtml = null;
			unset($listHtml);
			helper::log('GC: ' . gc_collect_cycles());
		}

		/**
		 * Downloads product pages from $productUrl URL array
		 *
		 * @return null
		 */
		function getProducts() {
			helper::log(__FUNCTION__ . ' called on ' . get_class($this) . ', amount: ' . count($this->productUrls));
			foreach ($this->productUrls as $productUrl) {
				if (defined('TESTING') && TESTING) {
					if ($i++ > 10) {
						break;
					}
				}

				if ($this->shopProductUrlStripGet) {
					$productUrl = preg_replace('/\?.*/', '', $productUrl);
				}
				$productHtml = $this->downloader->getCache($this->shopDomain . $productUrl, $this->shopName . DIRECTORY_SEPARATOR . helper::basenameClean($productUrl));
				$this->parseProduct($productHtml);
			}
		}

		/**
		 * Assmeble a full product information
		 *
		 * 1. calls prepareProduct
		 * 2. gets common parameters (name by $shopPqProductName selector, price by $shopPqPriceField selector)
		 * 3. gets product property names and values (getParamsAndValues())
		 * 4. stores all in $this->products[]
		 *
		 * @param string $html html document
		 *
		 * @return null
		 */
		private function parseProduct($html) {
			helper::log(__FUNCTION__ . ' called on ' . get_class($this) . ', size: ' . strlen($html));
			$product = phpQuery::newDocument($html);
			$this->prepareProduct($product);

			$productName = helper::clean($product[$this->shopPqProductName]->text());
			$productPrice = helper::clean($product[$this->shopPqPriceField]->text());

			$this->products[$productName]['TermékNév'] = $productName;
			$this->products[$productName]['Ár'] = round(preg_replace('/[^0-9]/', '', $productPrice) * $this->shopPriceMultiplier) . ' Ft';

			$params = $this->getParamsAndValues($product);

			foreach ($params as $parameter => $value) {
				if (!array_key_exists($parameter, $this->shopParameterOrder)) {
					$this->shopParameterOrder[$parameter] = 1;
				}
				$this->products[$productName][$parameter] = $value;
			}
			$product = null;
			unset($product);
			helper::log('GC: ' . gc_collect_cycles());
		}

		/**
		 * Calls $prepareFunction loaded from xml if exists
		 */
		function prepareProduct(&$product) {
			$method = $this->prepareFunction;
			$method($product);
		}

		/**
		 * Assigns product properyt names and values
		 *
		 * Get property names by $shopPqParameterNames selector, values from $shopPqParameterValues selector
		 * Neccessary cleaning and preparing tasks
		 * Returned nodes count should be equal for proper assigning (use prepareFunction to achieve this)
		 *
		 * @param mixed $product phpQuery node
		 *
		 * @return array
		 */
		function getParamsAndValues($product) {
			helper::log(__FUNCTION__ . ' called on ' . get_class($this));

			$myParams = $product[$this->shopPqParameterNames];
			$myValues = $product[$this->shopPqParameterValues];

			$this->productParameters = helper::clean(pq($myParams)->getString());
			$this->productValues = pq($myValues)->getString();
			$params = array();

			for ($paramIterator = 0; $paramIterator < count($this->productParameters); $paramIterator ++) {
				if (array_key_exists($this->productParameters[$paramIterator], $this->shopTransliterateParameters) && $this->shopTransliterateParameters[$this->productParameters[$paramIterator]]) {
					$param = $this->shopTransliterateParameters[$this->productParameters[$paramIterator]];
				} else {
					$param = $this->productParameters[$paramIterator];
				}
				$params[$param] = helper::clean($this->productValues[$paramIterator]);
			}

			return $params;
		}

		/**
		 * Writes all product data to a CSV file
		 */
		function writeCSV() {
			file_put_contents($this->shopName . '.csv', $this->formatCSV());
		}

		/**
		 * Formats CSV from $this->products
		 *
		 * @return string CSV
		 */
		function formatCSV() {
			$ret = '';

			if (isset($this->shopParameterOrder['-'])) {
				unset($this->shopParameterOrder['-']);
			}

			$ret .= implode("\t", array_keys($this->shopParameterOrder)) . "\n";
			foreach ($this->products as $product => $productSpec) {
				foreach ($this->shopParameterOrder as $paramName => $exists) {
					$ret .= sprintf("%s\t", $productSpec[$paramName]);
				}
				$ret .= "\n";
			}
			return $ret;
		}
	}

	if ($argc > 1) {
		$shop = new shop($argv[1]);
		$shop->getData();
		$shop = null;
	}
