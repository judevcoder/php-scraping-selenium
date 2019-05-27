<?php
/**
 * Core helper functions for selenium process
 *
 * @package	uwa
 *
 * @copyright	GetMyInvoices
 */

namespace Facebook\WebDriver;

use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\WebDriverCapabilityType;
use Facebook\WebDriver\Remote\WebDriverBrowserType;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Firefox\FirefoxProfile;
use Facebook\WebDriver\Firefox\FirefoxDriver;
use Facebook\WebDriver\Firefox\FirefoxPreferences;
use Facebook\WebDriver\Chrome\ChromeOptions;

// Path of Webdriver library
$webdriver_loader = realpath('/home/ubuntu/selenium/webdriver/vendor/autoload.php');
require_once($webdriver_loader);

class GmiSelenium {

	/** @var RemoteWebDriver $webdriver **/
	public		$webdriver;
	private		$mode;
	private		$profile;
	private		$capabilities;
	public		$portal_name;
	public		$config_array;

	private		$username;
	private		$password;
	public		$process_uid;
	public		$screen_capture_location;
	public		$notification_uid;
	private		$downloaded_files = array();
	public		$download_folder = '';
	public		$no_margin_pdf = 0;
	public		$document_counter = 0;
	public		$docker_need_restart = false;
	public		$docker_restart_counter = 0;
	public		$checked_documents = array();
	public		$recaptcha_answer = '';

	// 2FA variables
	public		$two_factor_notif_title_en = "%portal% - Two-Factor-Authorization";
	public		$two_factor_notif_title_de = "%portal% - Zwei-Faktor-Anmeldung";
	public		$two_factor_notif_msg_en = "Please enter the two-factor-authorization code to proceed with the login.";
	public		$two_factor_notif_msg_de = "Bitte geben Sie den Code zur Zwei-Faktor-Anmeldung ein.";
	public		$two_factor_notif_msg_retry_en = "(Your last input was either wrong or too late)";
	public		$two_factor_notif_msg_retry_de = "(Ihre letzte Eingabe war leider falsch oder zu spät)";
	public 		$two_factor_timeout = 15;
	public		$two_factor_attempts = 0;

	// Variables for printing
	public		$request_start = '===REQUEST===';
	public		$login_failed = 'LOGIN_FAILED';
	public		$init_diagnostics_failed = 'INIT_DIAGNOSTICS_FAILED';
	public		$driver_creation_failed = 'DRIVER_CREATION_FAILED';
	public		$profile_creation_failed = 'PROFILE_CREATION_FAILED';
	public		$portal_success = 'PORTAL_SUCCESS';
	public		$portal_failed = 'PORTAL_FAILED';
	public		$cookies_dump = '===COOKIE_JSON===';

	// Language dependent month name
	public		$month_names_de = array('Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember');
	public 		$month_names_en = array('January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December');
	public 		$month_names_fr = array('janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre');
	public 		$month_names_nl = array('januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december');

	// Language dependent month abbreviation
	public		$month_abbr_de = array('Jan', 'Feb', 'Mrz', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez');
	public		$month_abbr_en = array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec');
	public		$month_abbr_fr = array('janv', 'févr', 'mars', 'avril', 'mai', 'juin', 'juil', 'août', 'sept', 'oct', 'nov', 'déc');
	public		$month_abbr_nl = array('jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec');

	/**
	 * Constructor. Initialize different member data
	 *
	 * @param	string	$mode
	 * @param	string	$portal_name
	 * @param	int		$process_uid
	 * @param	string	$username
	 * @param	string	$password
	 * @return	void
	 */
	public function init($mode, $portal_name, $process_uid, $username, $password) {

		$this->loadProperties();

		$this->log('>> Init GMI Constructor With : '.$mode.', '.$this->config_array['docker_url']);
		$this->mode = $mode;
		$this->portal_name = $portal_name;
		$this->process_uid = $process_uid;
		$this->username = $username;
		$this->password = $password;
		$this->notification_uid = 0;
		$this->two_factor_attempts = 1;

		$this->createBrowserProfile();
		$this->createBrowserCapabilities();
		$this->initDiagnostics();
	}

	/**
	 * Loads properties from config.json file from the $screen_capture_location provided at runtime
	 *
	 */
	private function loadProperties() {

		$property_file = $this->screen_capture_location.'config.json';
		if(file_exists($property_file)) {
			$this->config_array = json_decode(file_get_contents($property_file), true);
			$this->log('Loaded Config : '.print_r($this->config_array, true));

			if(!empty($this->config_array['download_folder']) && substr($this->config_array['download_folder'], -1) != '/') $this->config_array['download_folder'] .= '/';
		} else {
			$this->log($property_file.' - Not found');
		}
	}

	/**
	 * Restart docker and use last updated cookies
	 *
	 * @return	void
	 */
	public function restart() {
		if($this->docker_restart_counter < 10) {
			$this->log('Restarting docker - '.$this->docker_restart_counter);
			try {
				// Restart hub
				exec("docker restart selenium-hub-".$this->process_uid);

				// Restart node
				exec("docker restart selenium-node-".$this->process_uid);

				sleep(20);

				$this->createBrowserProfile();
				$this->createBrowserCapabilities();

				$this->docker_restart_counter++;
			} catch(\Exception $exception) {
				$this->log('Restart failed - '.$exception->getMessage());
				$this->exitFinal();
			}
		} else {
			$this->log('Restart limit reached');
			$this->exitFinal();
		}
	}

	/**
	 * Load cookies json from file
	 *
	 * @return	bool
	 */
	public function loadCookiesFromFile() {
		try {
			$cookie_file = $this->screen_capture_location."cookie.txt";
			if(file_exists($cookie_file)) {
				$cookies_from_file = json_decode(file_get_contents($cookie_file), true);
				$this->log('loadCookiesFromFile::Loading Cookies From File');
				if(!empty($cookies_from_file)) {
					foreach($cookies_from_file as $cookie_array) {
						$cookie = null;
						try {
							$cookie = Cookie::createFromArray($cookie_array);
							$this->webdriver->manage()->addCookie($cookie);
						} catch(\Exception $ex) {
							try {
								if(stripos($cookie['domain'],".") == 0) {
									$this->log('loadCookiesFromFile::Cookie domain starts with DOT ' . $cookie['name'] . ' , domain ' . $cookie['domain'] );
									$cookie['domain'] = substr($cookie['domain'],1);
									$this->log('loadCookiesFromFile::Stripped domain is '. $cookie['domain'] );
								}
								$this->webdriver->manage()->addCookie($cookie);
							} catch(\Exception $newex) {
								$this->log('loadCookiesFromFile::ERROR once again while loading a cookie ');
								var_dump($cookie_array);
							}
						}
					}
					$this->log('loadCookiesFromFile::Cookies added successfully');

					// Now load local storage and session storage too
					$this->loadLocalStorageFromFile();
					$this->loadSessionStorageFromFile();

					return true;
				} else {
					$this->log('loadCookiesFromFile::Cookies not found');
				}
			} else {
				$this->log('loadCookiesFromFile::Cookies file not found');
			}
		} catch (\Exception $exception) {
			$this->log('loadCookiesFromFile::ERROR in loadCookiesFromFile ');
			//var_dump($exception);
		}

		return false;
	}

	/**
	 * Get all the cookies for the current domain.
	 *
	 * @return mixed The array of cookies present.
	 */
	public function getCookies() {
		return $this->webdriver->manage()->getCookies();
	}

	/**
	 * Delete all the cookies that are currently visible.
	 *
	 */
	public function clearCookies() {
		$this->webdriver->manage()->deleteAllCookies();
	}

	/**
	 * Get all the cookies for the current domain and print it in logs with COOKIES_DUMP Header
	 *
	 * @param	bool	$only_save
	 * @return	void
	 */
	public function dumpCookies($only_save=false) {
		try {
			$cookieArray = array();
			$cookie_data = array();
			if($this->mode == 'firefox') {
				// In firefox fetch cookies data from sqlite
				if(!empty($this->config_array['profile_file_location']) && is_dir($this->config_array['profile_file_location'])) {
					// Find cookie sqlite file
					if(empty($this->config_array['cookie_sqlite'])) {
						$files = $this->search_directory('/cookies.sqlite/', $this->config_array['profile_file_location'], 1);
						if(!empty($files)) {
							foreach($files as $file) {
								if(basename($file) == 'cookies.sqlite') {
									$this->config_array['cookie_sqlite'] = $file;
								}
							}
						}
					}

					if(!empty($this->config_array['cookie_sqlite']) && file_exists($this->config_array['cookie_sqlite'])) {
						$datapack = new \SQLite3($this->config_array['cookie_sqlite'], \SQLITE3_OPEN_READONLY);
						$res = $datapack->query("SELECT * FROM moz_cookies");
						while($row = $res->fetchArray(\SQLITE3_ASSOC)) {
							$cookie_data[md5($row['name']."-".$row['host'])] = $row['expiry'];
						}
					}
				}
			}

			$cookies = $this->getCookies();

			/* @var Cookie $cookie */
			foreach($cookies as $cookie) {
				$expiry_ts = (int)$cookie->getExpiry();

				if($expiry_ts == 0 && !empty($cookie_data[md5($cookie->getName()."-".$cookie->getDomain())])) {
					$expiry_ts = $cookie_data[md5($cookie->getName()."-".$cookie->getDomain())];
				}

				$cookieArray[] = array(
					"name"       => $cookie->getName(),
					"value"      => $cookie->getValue(),
					"path"       => $cookie->getPath(),
					"domain"     => $cookie->getDomain(),
					"expiry"     => $expiry_ts,
					"expiration" => $expiry_ts > 0 ? date('Y-m-d h:i:s', $expiry_ts) : '',
					"expires"    => $expiry_ts > 0 ? date('D, j F Y H:i:s e', $expiry_ts) : '',
					"secure"     => $cookie->isSecure(),
					"httpOnly"   => $cookie->isHttpOnly()
				);
			}

			if(!empty($cookieArray)) {
				if(!$only_save) {
					echo ''.$this->cookies_dump.json_encode($cookieArray)."\n";
				}
				file_put_contents($this->screen_capture_location."cookie.txt", json_encode($cookieArray));
			}
		} catch(\Exception $exception) {
			$this->log('ERROR in dumpCookies - '.$exception->getMessage());
		}

		// Also save session storage and local storage
		$this->dumpLocalStorage();
		$this->dumpSessionStorage();
	}

	/**
	 * Load local storage from file
	 *
	 * @return	bool
	 */
	public function loadLocalStorageFromFile(){
		try {
			$file = $this->screen_capture_location."local_storage.txt";

			if(file_exists($file)) {
				$json_from_file = json_decode(file_get_contents($file), true);
				$this->log('Loading localStorage From File');

				if(!empty($json_from_file)) {
					foreach($json_from_file as $key => $val) {
						$this->log('Local Storage Adding For '. $key . ' - > ' . $val);
						$itemValue = $this->setLocalStorage($key, $val);
						$this->log('localStorage Updated For '. $key . ' - > ' . $itemValue);
					}

					$this->log('localStorage added successfully');
					return true;
				} else {
					$this->log('localStorage is Empty!!');
				}
			} else {
				$this->log('localStorage file not found');
			}
		} catch (\Exception $exception) {
			$this->log('ERROR in loadLocalStorageFromFile '.$exception->getMessage());
		}

		return false;
	}

	/**
	 * Load session storage from file
	 *
	 * @return	bool
	 */
	public function loadSessionStorageFromFile(){
		try {
			$file = $this->screen_capture_location."session_storage.txt";

			if(file_exists($file)) {
				$json_from_file = json_decode(file_get_contents($file), true);
				$this->log('Loading sessionStorage From File');

				if(!empty($json_from_file)) {
					foreach($json_from_file as $key => $val) {
						$this->log('Session Storage Adding For '. $key . ' - > ' . $val);
						$itemValue = $this->setSessionStorage($key, $val);
						$this->log('Session Storage Updated For '. $key . ' - > ' . $itemValue);
					}

					$this->log('sessionStorage added successfully');
					return true;
				} else {
					$this->log('sessionStorage is Empty!!');
				}
			} else {
				$this->log('sessionStorage file not found');
			}

		} catch (\Exception $exception) {
			$this->log('ERROR in loadSessionStorageFromFile '.$exception->getMessage());
		}

		return false;
	}

	/**
	 * Set key/pair value in session storage
	 *
	 * @param	string	$key
	 * @param	string	$value
	 * @return	mixed
	 */
	public function setSessionStorage($key, $value) {
		$itemValue = $this->webdriver->executeScript("sessionStorage.setItem(arguments[0], arguments[1]); return sessionStorage.getItem(arguments[0]);", array($key, $value));
		return $itemValue;
	}

	/**
	 * Get session storage data of current session
	 *
	 * @return    string
	 */
	public function getSessionStorage() {
		$sessionStorage = $this->webdriver->executeScript("return JSON.stringify(sessionStorage);");
		return $sessionStorage;
	}

	/**
	 * Get session storage of current domain and save in file
	 *
	 * @return void
	 */
	public function dumpSessionStorage() {
		try {

			$sessionStorage = $this->getSessionStorage();
			file_put_contents($this->screen_capture_location."session_storage.txt", $sessionStorage);

		} catch(\Exception $exception) {
			$this->log('ERROR in dumpSessionStorage');
			var_dump($exception);
		}
	}

	/**
	 * Set key/pair value in local storage
	 *
	 * @param	string	$key
	 * @param	string	$value
	 * @return	mixed
	 */
	public function setLocalStorage($key, $value) {
		$itemValue = $this->webdriver->executeScript("localStorage.setItem(arguments[0], arguments[1]); return localStorage.getItem(arguments[0]);", array($key, $value));
		return $itemValue;
	}

	/**
	 * Get local storage data of current session
	 *
	 * @return string
	 */
	public function getLocalStorage() {
		$localStorage = $this->webdriver->executeScript("return JSON.stringify(localStorage);");
		return $localStorage;
	}

	/**
	 * Get local storage of current domain and save in file
	 *
	 * @return void
	 */
	public function dumpLocalStorage() {
		try {

			$localStorage = $this->getLocalStorage();
			file_put_contents($this->screen_capture_location."local_storage.txt", $localStorage);

		} catch(\Exception $exception) {
			$this->log('ERROR in dumpLocalStorage');
			var_dump($exception);
		}
	}

	/**
	 * Creates Browser profile for given mode.
	 * Class must be initialised with proper mode, user_agent before calling this method
	 *
	 * @return void
	 */
	private function createBrowserProfile() {
		$this->log('Begin setBrowserProfile');

		try {
			if($this->mode == 'firefox') {
				putenv("webdriver.firefox.profile=selenium");
				$this->profile = new FirefoxProfile();
				$this->profile->setPreference('general.useragent.override', $this->config_array['user_agent']);
				$this->profile->setPreference(FirefoxPreferences::READER_PARSE_ON_LOAD_ENABLED, false);
				$this->profile->setPreference('browser.startup.homepage', 'http://www.google.com');
				$this->profile->setPreference('webdriver.firefox.profile', 'selenium');
				$this->profile->setPreference('javascript.enabled', true);

				// Set language
				$lang_string = $this->config_array['lang_code'] == 'en' ? 'en-US, en' : 'de-DE, de';
				$this->profile->setPreference('intl.accept_languages', $lang_string);

				// Proxy settings, using preferences, as in higher version of firefox,
				// settings with capabilities didn't worked
				if(!empty($this->config_array['proxy_host']) && !empty($this->config_array['proxy_lpm_port'])) {
					$proxy_host = $this->config_array['proxy_host'];
					$this->profile->setPreference('network.proxy.type', 1); // Manual
					$this->profile->setPreference('network.proxy.http', $proxy_host);
					$this->profile->setPreference('network.proxy.http_port', (int)$this->config_array['proxy_lpm_port']);
					$this->profile->setPreference('network.proxy.ssl', $proxy_host);
					$this->profile->setPreference('network.proxy.ssl_port', (int)$this->config_array['proxy_lpm_port']);
				}

				// Printer settings
				$this->profile->setPreference('print.print_to_filename', '/home/seluser/Downloads/' . $this->process_uid . '/print.pdf');
				$this->profile->setPreference('print.print_to_file', true);
				$this->profile->setPreference('print.print_bgimages', true);
				$this->profile->setPreference('print.always_print_silent', true);
				$this->profile->setPreference('print.print_headercenter', '');
				$this->profile->setPreference('print.print_headerleft', '');
				$this->profile->setPreference('print.print_headerright', '');
				$this->profile->setPreference('print.print_footercenter', '');
				$this->profile->setPreference('print.print_footerleft', '');
				$this->profile->setPreference('print.print_footerright', '');

				// Memory settings
				$this->profile->setPreference('memory.free_dirty_pages', true);
				$this->profile->setPreference('browser.cache.disk.enable', false);
				$this->profile->setPreference('browser.cache.memory.enable', false);
				$this->profile->setPreference('browser.cache.offline.enable', false);
				$this->profile->setPreference('network.http.use-cache', false);

				// Config for download begins
				$this->profile->setPreference("pdfjs.disabled", true);
				$this->profile->setPreference("browser.download.folderList", 2);
				$this->profile->setPreference("browser.download.dir", '/home/seluser/Downloads/' . $this->process_uid . '/');
				$this->profile->setPreference("browser.download.useDownloadDir", true);
				$this->profile->setPreference("browser.download.manager.showWhenStarting", false);

				// @see https://www.freeformatter.com/mime-types-list.html#mime-types-list
				$this->profile->setPreference("browser.helperApps.neverAsk.saveToDisk", "application/pdf;application/zip;application/vnd.ms-excel;application/vnd.openxmlformats-officedocument.spreadsheetml.sheet;application/vnd.openxmlformats-officedocument.wordprocessingml.document;application/msword");

				// Config for download ends

				$this->profile->addExtension('/home/ubuntu/selenium/webdriver/addons/get_all_cookies_in_xml-1.0-fx-windows.xpi');
				$this->log('Profile created for firefox ');

			} elseif($this->mode == 'chrome') {
				$chrome_options = new ChromeOptions();
				$this->profile = $chrome_options->addArguments(array(
					"--user-agent=" . $this->config_array['user_agent'],
					"--start-maximized",
					"--disable-infobars",
					"--kiosk-printing",
					"--kiosk",
					"--disable-web-security",
					"--disable-encrypted-media",
					"--window-size=1920,1080",
					"--homepage=http://www.google.com",
					"--lang=".$this->config_array['lang_code']
				));


				// Proxy settings
				if(!empty($this->config_array['proxy_host']) && !empty($this->config_array['proxy_lpm_port'])) {
					$this->profile->addArguments(array("--proxy-server=".$this->config_array['proxy_host'].":".$this->config_array['proxy_lpm_port']));
				}

				$this->profile->setExperimentalOption('prefs', array(
					"download.default_directory" => '/home/seluser/Downloads/' . $this->process_uid . '/',
					"download.prompt_for_download" => false,
					"plugins.always_open_pdf_externally" => true,
					"profile.default_content_setting_values.plugins" => 1,
					"profile.content_settings.plugin_whitelist.adobe-flash-player" => 1,
					"profile.content_settings.exceptions.plugins.*,*.per_resource.adobe-flash-player" => 1,
					"PluginsAllowedForUrls" => "https://fos2.floricolor.pt/",
					"printing.print_preview_sticky_settings.appState" => json_encode(array(
						"version" => 2,
						"selectedDestinationId" => "Save as PDF",
						"recentDestinations" => array(
							"id" => "Save as PDF",
							"origin" => "local"
						),
						"isHeaderFooterEnabled" => false
					)),
					"savefile.default_directory" => '/home/seluser/Downloads/' . $this->process_uid . '/'
				));
			}
		} catch(\Exception $exception) {
			$this->log('ERROR in createBrowserProfile');
			$this->sendRequestEx($this->profile_creation_failed);
			var_dump($exception);
			$this->exitFailure();
		}
	}

	/**
	 * Creates Browser Capabilities for given mode.
	 * Class must be initialised with proper mode, user_agent and createBrowserProfile method must be called before calling this this method
	 *
	 * @return	void
	 */
	private function createBrowserCapabilities() {
		$this->log('Begin createBrowserCapabilities');
		try {
			if($this->mode == 'firefox') {
				$this->log("Driver Type - ".WebDriverBrowserType::FIREFOX);
				$this->capabilities = DesiredCapabilities::firefox();
				$this->capabilities->setCapability(FirefoxDriver::PROFILE, $this->profile);
				$this->capabilities->setCapability('webdriver.firefox.profile', 'selenium');

				// Proxy settings
				if(!empty($this->config_array['proxy_user']) && !empty($this->config_array['proxy_pwd']) && !empty($this->config_array['proxy_lpm_port'])) {
					$proxy_host = $this->config_array['proxy_host'];
					$this->capabilities->setCapability(WebDriverCapabilityType::PROXY, array(
						'proxyType'     => 'MANUAL',
						'httpProxy'     => $proxy_host.':'.$this->config_array['proxy_lpm_port'],
						'httpProxyPort'  => $this->config_array['proxy_lpm_port'],
						'sslProxy'      => $proxy_host.':'.$this->config_array['proxy_lpm_port'],
						'sslProxyPort'  => $this->config_array['proxy_lpm_port'],
						'class'         => "org.openqa.selenium.Proxy",
						'autodetect'    => false,
						'noProxy'       => ''
					));
				}
			} elseif($this->mode == 'chrome') {
				$this->log("Driver Type - ".WebDriverBrowserType::CHROME);
				$this->capabilities = DesiredCapabilities::chrome();
				$this->capabilities->setCapability(ChromeOptions::CAPABILITY, $this->profile);
			}

			$this->webdriver = RemoteWebDriver::create($this->config_array['docker_url'], $this->capabilities, 180000, 180000);

			$window = new WebDriverDimension(1920, 1080);
			$this->webdriver->manage()->window()->setSize($window);
			$this->log('Successfully created Webdriver for '.$this->mode);
		} catch(\Exception $exception) {
			$this->log('ERROR in createBrowserCapabilities');
			$this->sendRequestEx($this->driver_creation_failed);
			var_dump($exception);
			$this->exitFailure();
		}
	}

	/**
	 * Method to check if web driver is created for the browser successfully.
	 * uses test url to load a json and check if URL is loading properly in the new webdriver instance
	 * This method should be called only after profile and capabilities are created
	 *
	 * @return void
	 */
	private function initDiagnostics() {
		$this->log('Begin initDiagnostics');
		try {
			$this->webdriver->manage()->deleteAllCookies();
			$this->webdriver->get('http://lumtest.com/myip.json');

			$this->log('>>Content of webpage - '.$this->webdriver->getPageSource());
			$this->log('>>Title of webpage is: '.$this->webdriver->getTitle());
			$this->log('>>URI of webpage is: '.$this->webdriver->getCurrentURL());

			// If Worker ping url is set, use it check configured language
			if(!empty($this->config_array['worker_ping']) && 1==2) { // Disable for now, due to ssl error
				$this->webdriver->manage()->deleteAllCookies();
				$this->webdriver->get($this->config_array['worker_ping']);

				$this->log('>>Content of webpage - '.$this->webdriver->getPageSource());
			}

			$this->log('>> Diagnostics Successful!!!');
		} catch(\Exception $exception) {
			$this->log('ERROR in initDiagnostics');
			$this->sendRequestEx($this->init_diagnostics_failed);
			var_dump($exception);
		}
	}

	/**
	 * Send request to UI and fetch two factor code
	 *
	 * @return	string
	 */
	public function fetchTwoFactorCode() {
		$this->log("--Fetching Two Factor Code--");
		$this->capture("TwoFactorFetchCode");

		$extra_data = array(
			"en_title" => $this->two_factor_notif_title_en,
			"en_msg" => $this->two_factor_notif_msg_en,
			"de_title" => $this->two_factor_notif_title_de,
			"de_msg" => $this->two_factor_notif_msg_de,
			"timeout" => $this->two_factor_timeout
		);

		$two_factor_code = $this->sendRequest($this->process_uid, $this->config_array['two_factor_shell_script'], $extra_data);
		$this->log($two_factor_code);

		return $two_factor_code;
	}

	/**
	 * Method to process two factor authentication.
	 * This method waits for shell script execution to return the two factor auth code and processes the two factor auth form.
	 *
	 * @param	string 	$two_fa_selector Css selector of the input text box where two factor code must be typed
	 * @param	string	$trusted_btn_selector Css selector of the submit button that should be pressed after entering two factor auth code
	 */
	public function processTwoFactorAuth($two_fa_selector, $trusted_btn_selector) {
		$this->log("--TWO FACTOR AUTH--");

		try {
			$this->capture("TwoFactorAuth");
		} catch (\Exception $exception) {
			$this->log('processTwoFactorAuth::ERROR while taking snapshot');
			//var_dump($exception);
		}

		if($this->getElementByCssSelector($two_fa_selector) != null) { // sample selector "form[class*=\"2fa-phone-form\"] input[name=\"code\"]"

			$two_factor_code = $this->fetchTwoFactorCode();
			if(trim($two_factor_code) !== "") {
				try {
					/* @var WebDriverElement $element */
					$element = $this->getElementByCssSelector($two_fa_selector);
					$element->sendKeys($two_factor_code);
					$this->log("SIGNIN_PAGE: Entering two_factor_code.");

					if($this->getElementByCssSelector($trusted_btn_selector) != null) {
						/* @var WebDriverElement $button */
						$button = $this->getElementByCssSelector($trusted_btn_selector);
						$button->click();
					}
					$this->log("SIGNIN_PAGE: Clicking the [SIGN_IN] button.");
					//$this->webdriver->getKeyboard()->pressKey(WebDriverKeys::ENTER);
					$this->capture("TwoFactorAuth-Filled");

					sleep(10);
					if($this->getElementByCssSelector($two_fa_selector) != null && $this->two_factor_attempts < 3) {
						$this->two_factor_attempts++;
						$this->notification_uid = "";
						$this->processTwoFactorAuth($two_fa_selector, $trusted_btn_selector);
					}
				} catch (\Exception $exception) {
					$this->log('processTwoFactorAuth::ERROR while taking snapshot');
				}
			}
		} else {
			$this->log("--TWO_FACTOR_REQUIRED--");
		}
	}

	/**
	 * Method to process captcha image.
	 * This method waits for shell script execution to return the captcha code from the image selector provided and enters the captcha code into the text box selector($captcha_input_selector) provided.
	 *
	 * @param	string $captcha_image_selector Css selector of the captcha image that needs solving
	 * @param	string $captcha_input_selector Css selector of the input text box where captcha code must be typed
	 */
	public function processCaptcha($captcha_image_selector, $captcha_input_selector) {
		$this->log("--IMAGE CAPTCHA--");
		try {
			$this->capture("ImageCaptcha");
		} catch (\Exception $exception) {
			$this->log('processCaptcha::ERROR while taking snapshot');
			//var_dump($exception);
		}

		if($this->getElementByCssSelector($captcha_image_selector) != null) {
			try {
				$this->captureElement($this->process_uid, $captcha_image_selector);
				$extra_data = array();
				$captcha_code = $this->sendRequest($this->process_uid, $this->config_array['captcha_shell_script'], $extra_data);
				try {
					/* @var WebDriverElement $element */
					$element = $this->getElementByCssSelector($captcha_input_selector);
					$element->sendKeys($captcha_code);
					$this->log("Captcha entered -> ".$captcha_code);
				} catch (\Exception $exception) {
					$this->log('1:processCaptcha::ERROR while processing captcha');
					//var_dump($exception);
				}
			} catch (\Exception $exception) {
				$this->log('2:processCaptcha::ERROR while processing captcha');
				//var_dump($exception);
			}
		}
	}

	/**
	 * Method to process google recaptcha
	 * It will send a request to 2captcha.com using a batch file or shell script and fetch the answer
	 *
	 * @param	string	$base_url		Base url of site in which recaptcha need to solve
	 * @param	string	$google_key		Google recaptcha key in portal
	 * @param	bool	$fill_answer	Fill response in dom
	 * @return	bool
	 */
	public function processRecaptcha($base_url, $google_key='', $fill_answer=true) {
		$this->recaptcha_answer = '';
		if(empty($google_key)) {
			/* @var WebDriverElement $element */
			$element = $this->getElementByCssSelector(".g-recaptcha");
			$google_key = $element->getAttribute('data-sitekey');
		}

		$this->log("--Google Re-Captcha--");
		if(!empty($this->config_array['recaptcha_shell_script'])) {
			$cmd = $this->config_array['recaptcha_shell_script'] . " --PROCESS_UID::" . $this->process_uid . " --GOOGLE_KEY::" . urlencode($google_key) . " --BASE_URL::" . urlencode($base_url);
			$this->log('Executing command : '.$cmd);
			exec($cmd, $output, $return_var);
			$this->log('Command Result : '.print_r($output, true));

			if(!empty($output)) {
				$recaptcha_answer = '';
				foreach($output as $line) {
					if(stripos($line, "RECAPTCHA_ANSWER") !== false) {
						$result_codes = explode("RECAPTCHA_ANSWER:", $line);
						$recaptcha_answer = $result_codes[1];
						break;
					}
				}

				if(!empty($recaptcha_answer)) {
					if($fill_answer) {
						$answer_filled = $this->webdriver->executeScript(
							"document.getElementById(\"g-recaptcha-response\").innerHTML = arguments[0];return document.getElementById(\"g-recaptcha-response\").innerHTML;",
							array($recaptcha_answer)
						);
						$this->log("recaptcha answer filled - ".$answer_filled);
					}

					$this->recaptcha_answer = $recaptcha_answer;
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Generic method to send command requests to external processes
	 * This method executes shell commands and returns back the output received from that after processing it.
	 *
	 * @param	string	$process_uid	process_uid string from caller portal script
	 * @param	string	$script_path	The script path that must be executed
	 * @param	mixed	$extra_data		associative array of params that must be sent to the command
	 * @return	string	Final processed output string requested by caller
	 *
	 */
	public function sendRequest($process_uid, $script_path,  $extra_data) {
		$this->log($script_path);
		$Result = "";

		try {
			$json = "";
			if(!empty($extra_data)) {
				$json = json_encode($extra_data, JSON_UNESCAPED_UNICODE);
			}

			$this->log("Json Value is " . $json);

			if(!empty($this->notification_uid)) {
				$cmd = $script_path . " --PROCESS_UID::".$process_uid. " --NOTIFICATION_UID::".$this->notification_uid. " 2>&1";
				$this->log('Executing command : '.$cmd);
				exec($cmd, $output, $return_var);
			} else {
				$cmd = $script_path . " --PROCESS_UID::".$process_uid. " --EXTRA_DATA::". urlencode($json);
				$this->log('Executing command : '.$cmd);
				exec($cmd, $output, $return_var);
			}

			$this->log("Exit Value is " . $return_var);
			$Result = $this->getProcessResult($output);

			$this->log("--RESULT--" . $Result);
			if(stripos($Result, "NOTIFICATION_UID") !== false) {
				$resultCodes = explode("NOTIFICATION_UID:", $Result);
				$this->notification_uid = end($resultCodes);
				sleep(30);
				$Result = $this->sendRequest($process_uid, $script_path,  $extra_data);
			} else if(stripos($Result, "TWO_FACTOR_CODE:") !== false) {
				$resultCodes = explode("TWO_FACTOR_CODE:", $Result);
				$Result = end($resultCodes);
			} else if(stripos($Result, "NOTIFICATION_EXPIRED:1") !== false) {
				$this->two_factor_expired();
				$Result = "";
			} else {
				sleep(30);
				$Result = $this->sendRequest($process_uid, $script_path, $extra_data);
			}
		} catch (\Exception $exception) {
			$this->log('sendRequest::ERROR ');
		}

		return $Result;
	}

	/**
	 * Method to parse the output string and get the two_factor_auth_code
	 *
	 * @param	mixed	$output				Raw output string response received from executing a shell script (usually from sendRequest method)
	 * @return	mixed	$two_factor_code	Final processed Two factor code string requested by caller
	 */
	public function getProcessResult($output) {
		$two_factor_code = "";
		$timeexpired_code = false;

		$this->log("Two factor processing- ". print_r($output, true));

		try {

			foreach($output as $line) {
				if(stripos($line, "NOTIFICATION_UID:") !== false) {
					$two_factor_code = $line;
					break;
				}

				if(stripos($line, "TWO_FACTOR_CODE:") !== false) {
					$two_factor_code = $line;
					break;
				}

				if(stripos($line, "NOTIFICATION_EXPIRED:1") !== false) {
					$two_factor_code = $line;
					$timeexpired_code = true;
					break;
				}
			}

			if(trim($two_factor_code) !== "") {
				$this->log("two_factor_code- ". $two_factor_code);
				return $two_factor_code;
			} else if($timeexpired_code) {
				return $two_factor_code;
			} else {
				$this->log("Waiting For two_factor_code- ". $two_factor_code);
				return $two_factor_code;
			}

		} catch (\Exception $exception) {
			$this->log('getProcessResult::ERROR ');
			//var_dump($exception);
		}

		return $two_factor_code;
	}

	/**
	 * Captures screenshot of the entire active screen and saves it under screen_capture_location
	 *
	 * @param	string	$filename	file name of the captured image
	 * @return string
	 */
	public function capture($filename) {
		try {
			$this->webdriver->takeScreenshot($this->screen_capture_location.$filename.'.png');
			file_put_contents($this->screen_capture_location.$filename.'.html', $this->webdriver->getPageSource());
			$this->log('Screenshot saved - '.$this->screen_capture_location.$filename.'.png');

			return $this->screen_capture_location.$filename.'.png';
		} catch (\Exception $exception) {
			$this->log('Error in capture - '.$exception->getMessage());
			return '';
		}
	}

	/**
	 * Trigger Init Required
	 *
	 */
	public function init_required() {
		$this->capture("init_required");
		$this->sendRequestEx(json_encode(array(
			'method' => 'initRequired'
		)));
		$this->exitFinal(); // Terminate execution
	}

	/**
	 * Trigger Two Factor Expired
	 *
	 */
	public function two_factor_expired() {
		$this->capture("two_factor_expired");
		$this->sendRequestEx(json_encode(array(
			'method' => 'TwoFactorExpired'
		)));
	}

	/**
	 * Trigger Account Not Required
	 *
	 */
	public function account_not_ready() {
		$this->capture("account_not_ready");
		$this->sendRequestEx(json_encode(array(
			'method' => 'AccountNotReady'
		)));
		$this->exitFinal(); // Terminate execution
	}

	/**
	 * Trigger No Permission
	 *
	 */
	public function no_permission() {
		$this->capture("no_permission");
		$this->sendRequestEx(json_encode(array(
			'method' => 'NoPermission'
		)));
		$this->exitFinal(); // Terminate execution
	}

	/**
	 * Trigger No Invoice
	 *
	 */
	public function no_invoice() {
		$this->capture("no_invoice");
		$this->sendRequestEx(json_encode(array(
			'method' => 'noInvoice'
		)));
		$this->exitFinal(); // Terminate execution
	}

	/**
	 * Trigger Success
	 *
	 */
	public function success() {
		$this->sendRequestEx(json_encode(array(
			'method' => 'success'
		)));
	}

	/**
	 * Trigger Invoice Requested
	 *
	 * @param	int	$invoice_uid
	 */
	public function invoice_requested($invoice_uid) {
		$this->sendRequestEx(json_encode(array(
			'method' => 'invoiceRequested',
			'data' => array(
				'invoice_uid' => $invoice_uid
			)
		)));
	}

	/**
	 * Update Process Lock File
	 * If this lock file is not updated for 30 minutes, process will be aborted
	 *
	 */
	public function update_process_lock() {
		$lock_file = $this->config_array['process_folder'].'process.gmi';
		file_put_contents($lock_file, time());
	}

	/**
	 * Create process completed flag file
	 *
	 */
	public function process_completed() {
		$lock_file = $this->config_array['process_folder'].'process.completed';
		file_put_contents($lock_file, time());
	}

	/**
	 * Captures screenshot of a specific web element by its CSS selector and saves it under screen_capture_location
	 *
	 * @param	string	$fileName	file name of the captured image
	 * @param	string	$selector	Css selector of the element that needs to be captured
	 * @return	string
	 */
	public function captureElement($fileName, $selector=null) {

		$screenshot = $this->screen_capture_location . time() . ".png";

		// Change the driver instance
		$this->webdriver->takeScreenshot($screenshot);

		if(!file_exists($screenshot)) {
			$this->log("Could not save screenshot");
			return $screenshot;
		}

		if(!(bool)$selector) {
			return $screenshot;
		}

		/* @var WebDriverElement $element */
		$element = $this->getElementByCssSelector($selector);
		$element_screenshot = $this->screen_capture_location . $fileName . ".png";
		$element_width = $element->getSize()->getWidth();
		$element_height = $element->getSize()->getHeight();

		$element_src_x = $element->getLocation()->getX();
		$element_src_y = $element->getLocation()->getY();

		// Create image instances
		$src = imagecreatefrompng($screenshot);
		$dest = imagecreatetruecolor($element_width, $element_height);

		// Copy
		imagecopy($dest, $src, 0, 0, $element_src_x, $element_src_y, $element_width, $element_height);
		imagepng($dest, $element_screenshot);

		if( ! file_exists($element_screenshot)) {
			$this->log("Could not save screenshot");
			return $screenshot;
		}

		return $element_screenshot;
	}

	/**
	 * Waits for an element for a specified number of seconds, default timeout = 15 secs
	 *
	 * @param	string		$selector 			Css selector of the element that needs waiting upon
	 * @param	callable	$successCallback	Callback function that must be called when element is found within the specified time period
	 * @param	callable	$failureCallBack	Callback function that must be called when element is NOT found within the specified time period
	 * @param	integer		$timeout			Number of seconds to wait for the element
	 */
	public function waitForCssSelectorPresent($selector, $successCallback, $failureCallBack, $timeout = 15) {
		try {
			$this->log("begin waitForCssSelectorPresent > ".$selector);
			$this->webdriver->wait($timeout, 800)->until(
				WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector($selector))
			);
			$successCallback();
		}  catch(\Exception $exception) {
			$this->log('Timeout waiting for element '.$exception);
			$failureCallBack();
		}
	}

	/**
	 * Get and return an element specified by a CSS selector
	 *
	 * @param	string				$selector Css selector of the element in the current document
	 * @return	WebDriverElement	null if not found
	 */
	public function getElementByCssSelector($selector) {
		$element = null;
		try {
			$element = $this->webdriver->findElement(WebDriverBy::cssSelector($selector));
		} catch(\Exception $exception) {
			$this->log('ERROR in getElementByCssSelector. Could not locate element - ' . $selector);
			//var_dump($exception);
		}

		return $element;
	}

	/**
	 * Get and return an element specified by its ID attribute
	 *
	 * @param	string				$selector ID of the element in the current document
	 * @return	WebDriverElement	null if not found
	 */
	public function getElementById($selector) {
		$element = null;
		try {
			$element = $this->webdriver->findElement(WebDriverBy::id($selector));
		} catch(\Exception $exception) {
			$this->log('ERROR in getElementById. Could not locate element - ' . $selector.$exception->getMessage());
		}
		return $element;
	}

	/**
	 * Navigate to url
	 *
	 * @param	string	$url
	 * @return	void
	 */
	public function openUrl($url) {
		$this->docker_need_restart = false;
		$this->log('Navigating to URL : '.$url);
		try {
			$this->webdriver->get($url);
		} catch(\Exception $exception) {
			$err_msg = $exception->getMessage();
			$this->log('Failed opening url - '.$err_msg);

			// If got curl error, then set restart required
			if(stripos($err_msg, 'Curl error') !== FALSE) {
				$this->docker_need_restart = true;
			}
		}
	}

	/**
	 * Create log
	 *
	 * @param	string	$str
	 * @return	void
	 */
	public function log($str) {
		$mem = memory_get_usage(true);
		$mem_usage = ceil($mem / (1024 * 1024)).'MB';
		echo date("Y-m-d H:i:s") . " - " . $mem_usage . " : " . $str . "\n";
	}

	/**
	 * PHP Downloader
	 * Download file using curl. In those portals where we get filename as broken utf8, this function can be used to download
	 *
	 * @param	$url
	 * @param	string	$orig_file_ext
	 * @param	string	$orig_filename
	 * @return	string
	 */
	public function custom_downloader($url, $orig_file_ext, $orig_filename='') {
		// Check if already exists
		if($this->document_exists($orig_filename)) {
			return '';
		}

		$this->no_margin_pdf = 1;

		// Prepare cookie string for curl
		$cookies = $this->getCookies();
		if(!empty($cookies)) {
			$cookie_arr = array();

			/* @var Cookie $cookie */
			foreach($cookies as $cookie) {
				$cookie_arr[] = $cookie->getName() . '=' . $cookie->getValue();
			}

			$cookie_string = implode("; ", $cookie_arr);

			// Prepare language header
			$lang_code = $this->config_array['lang_code'];
			$lang_header = $lang_code == 'en' ? 'en-US,en;q=0.5' : 'de-DE,de;q=0.8,en-US;q=0.6,en;q=0.4';

			// Start curl downloading
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
			curl_setopt($ch, CURLOPT_COOKIESESSION, 1);
			curl_setopt($ch, CURLOPT_HEADER, 1);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($ch, CURLOPT_ENCODING , "gzip");
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array(
				'Accept: application/pdf',
				'Accept-Language: '.$lang_header,
				'User-Agent: '.$this->config_array['user_agent'],
				'Connection: Keep-Alive',
				'Accept-Encoding: gzip, deflate'
			));
			curl_setopt($ch, CURLOPT_COOKIE, $cookie_string);

			$response = curl_exec($ch);
			$this->log("Response Size: ".strlen($response));

			$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
			curl_close($ch);

			// Fetch Header and Response Content
			$header = trim(substr($response, 0, $header_size));
			$headers = $this->get_headers_from_curl_response($header);
			$file_content = substr($response, $header_size);
			$this->log("File Content Size:  ".strlen($file_content));

			// Find filename from header
			$filename = '';
			if(isset($headers['Content-Disposition'])) {
				$parts = explode('filename="', $headers['Content-Disposition']);
				if(count($parts) == 2) {
					$encoded_filename = mb_detect_encoding($parts[1], 'UTF-8', true) ? $parts[1] : utf8_encode($parts[1]);
					$filename = str_replace('"', '', $encoded_filename);
					unset($encoded_filename);
				} else {
					preg_match_all("/\w+\-.\w.+/", $headers['Content-Disposition'], $output);
					$filename = $output[0][0];
					$filename = mb_detect_encoding($filename, 'UTF-8', true) ? $filename : utf8_encode($filename);
					unset($output);
				}
				unset($parts);

				// in some cases, filename header contains too many data with ";" as delimiter
				$file_parts = explode(";", $filename);
				$filename = $file_parts[0];
			}

			$this->log("Header String: ".$header);
			$this->log("Header Array: ".json_encode($headers));
			$this->log("Extracted Filename: ".$filename);

			if(trim($filename) != '') {
				// Clean filename
				$new_filename = $this->clean_filename($filename);
				$this->log("Clean filename - ".$new_filename);
				$filename = trim($new_filename);

				$file_ext = pathinfo($filename, PATHINFO_EXTENSION);

				// In portal mailjet, filename comes without extension
				if(empty($file_ext)) {
					$file_ext = $orig_file_ext;
				}

				$unique_file = false;
				$counter = 1;
				$temp_filename = $filename;
				do {
					if(file_exists($this->config_array['download_folder'].$temp_filename)) {
						$temp_filename = basename($filename, '.'.$file_ext).'('.$counter.').'.$file_ext;
						$counter ++;
					} else {
						$unique_file = true;
						$filename = $temp_filename;
					}
				} while(!$unique_file);
			} else {
				$filename = basename($orig_filename);
			}

			$this->log("Target File - ".$filename);
			file_put_contents($this->config_array['download_folder'].$filename, $file_content);

			sleep(1); // Make sure this write operation is really finished and then enforce a Window Defender check by opening file

			if(file_exists($this->config_array['download_folder'].$filename)) {
				$saved_content = file_get_contents($this->config_array['download_folder'].$filename);

				// Check for PDF file
				$ext = strtolower(pathinfo($this->config_array['download_folder'].$filename, PATHINFO_EXTENSION));
				if($ext == 'pdf' && strpos(strtoupper($saved_content), '%PDF') === false) {
					$this->log("Invalid PDF file");
					@unlink($this->config_array['download_folder'].$filename);
				} else {
					$filepath = $this->config_array['download_folder'].$filename;
					$this->downloaded_files[] = basename($filepath);
					$this->log('Downloaded File - ' . $filepath);

					return $filepath;
				}
			}
		}

		return '';
	}

	/**
	 * Helper function to get headers in array
	 *
	 * @param $header_text
	 * @return array
	 */
	private function get_headers_from_curl_response($header_text) {
		$headers = array();
		$lines = explode("\r\n", $header_text);
		$this->log("Header Count: ".count($lines));

		foreach ($lines as $i => $line) {
			if ($i === 0) {
				$headers['http_code'] = $line;
			} else {
				list($key, $value) = explode(': ', $line);
				$headers[$key] = $value;
			}
		}
		return $headers;
	}

	/**
	 * Clean filename
	 * Replace Umlaute characters
	 *
	 * @param $filename
	 * @return mixed
	 */
	private function clean_filename($filename) {
		$search  = array("ä" , "ö" , "ü" , "ß" , "Ä" , "Ö" , "Ü" , "é", "á", "ó");
		$replace = array("ae", "oe", "ue", "ss", "Ae", "Oe", "Ue", "e", "a", "o");

		$encoded_filename = mb_detect_encoding($filename, 'UTF-8', true) ? $filename : utf8_encode($filename);
		$result = str_replace($search, $replace, $encoded_filename);

		return $result;
	}

	/**
	 * Download file with url
	 *
	 * @param	string	$url
	 * @param	string	$file_ext
	 * @param	string	$filename
	 * @return	string
	 */
	public function direct_download($url, $file_ext, $filename='') {
		// Check if already exists
		if($this->document_exists($filename)) {
			return '';
		}

		$this->no_margin_pdf = 1;
		try {
			$this->webdriver->get($url);
		} catch(\Exception $exception) {
			$this->log('ERROR in direct_download ' . $exception->getMessage());
		}

		// Wait for completion of file download
		$this->wait_and_check_download($file_ext);

		// find new saved file and return its path
		return $this->find_saved_file($file_ext, $filename);
	}

	/**
	 * Download file by clicking on the selector provided
	 *
	 * @param	string	$selector
	 * @param	string	$file_ext
	 * @param	string	$filename
	 * @param	string	$selector_type
	 * @param	int		$wait_time
	 * @return	string
	 */
	public function click_and_download($selector, $file_ext, $filename='', $selector_type='CSS', $wait_time=30) {
		// Check if already exists
		if($this->document_exists($filename)) {
			return '';
		}

		$this->no_margin_pdf = 1;
		try {
			if(strtoupper($selector_type) == 'XPATH') {
				$element = $this->webdriver->findElement(WebDriverBy::xpath($selector));
			} else {
				$element = $this->getElementByCssSelector($selector);
			}

			if($element != null) {
				$this->log('click_and_download -> ' . $selector);
				$element->click();

				// Wait for 1 min before checking if file is saved or not
				if($wait_time > 0) {
					sleep($wait_time);
				}
			}
		} catch(\Exception $exception) {
			$this->log('ERROR in click_and_download. Could not locate element - ' . $selector);
			var_dump($exception);
		}

		// Wait for completion of file download
		$this->wait_and_check_download($file_ext);

		// find new saved file and return its path
		return $this->find_saved_file($file_ext, $filename);
	}

	/**
	 * Print by click on selector
	 *
	 * @param	string	$selector
	 * @param	string	$filename
	 * @return	string
	 */
	public function click_and_print($selector, $filename) {
		// Check if already exists
		if($this->document_exists($filename)) {
			return '';
		}

		$file_ext = $this->get_file_extension($filename);
		$this->no_margin_pdf = 1;
		try {
			$element = $this->getElementByCssSelector($selector);
			if($element != null) {
				$this->log('click_and_print -> ' . $selector);
				$element->click();
			}
		} catch(\Exception $exception) {
			$this->log('ERROR in click_and_print. Could not locate element - ' . $selector);
			var_dump($exception);
		}

		// Wait for completion of file download
		$this->wait_and_check_download($file_ext);

		// find new saved file and return its path
		return $this->find_saved_file($file_ext, $filename);
	}

	/**
	 * Open url, Capture screen and save as pdf
	 *
	 * @param	string	$url
	 * @param	string	$filename
	 * @param 	int		$delay_before_print
	 * @return	string
	 */
	public function download_capture($url, $filename, $delay_before_print=0) {
		// Check if already exists
		if($this->document_exists($filename)) {
			return '';
		}

		$this->no_margin_pdf = 0;
		$filepath = '';

		try {
			// Open new window
			$this->open_new_window();

			// Open URL in new window
			$this->webdriver->get($url);

			// Trigger print
			$filepath = $this->download_current($filename, $delay_before_print, true);

			// Close new window
			$this->close_new_window();

		} catch(\Exception $exception) {
			$this->log('ERROR in download_capture.');
			var_dump($exception);
		}

		// find new saved file and return its path
		return $filepath;
	}

	/**
	 * Capture current screen and save as pdf
	 *
	 * @param	string	$filename
	 * @param 	int		$delay_before_print
	 * @param 	bool	$skip_check
	 * @return	string
	 */
	public function download_current($filename, $delay_before_print=0, $skip_check=false) {
		// Check if already exists
		if(!$skip_check && $this->document_exists($filename)) {
			return '';
		}

		$file_ext = $this->get_file_extension($filename);
		$this->no_margin_pdf = 0;
		$filepath = '';

		try {
			// Put some delay if page rendering takes time
			// If page is not loaded by ajax, then such delay is not required
			if($delay_before_print > 0) {
				sleep($delay_before_print);
			}

			// Trigger print
			// Set window title to print, as chrome use window title to save pdf file
			$this->webdriver->executeScript('document.title = "print"; window.print();');
			sleep(10);

			// Wait for completion of file download
			$this->wait_and_check_download($file_ext);

			// find new saved file and return its path
			$filepath = $this->find_saved_file($file_ext, $filename);

		} catch(\Exception $exception) {
			$this->log('ERROR in download_capture.');
			var_dump($exception);
		}

		// find new saved file and return its path
		return $filepath;
	}

	/**
	 * Create json request log for new invoice, to be parsed later
	 * Also create new invoice record in db
	 *
	 * @param	string	$invoice_name
	 * @param	string	$invoice_date
	 * @param	float	$invoice_amount
	 * @param	string	$invoice_filename
	 * return	void
	 */
	public function new_invoice($invoice_name, $invoice_date, $invoice_amount, $invoice_filename) {
		$this->update_process_lock();
		$invoice_filename = $this->config_array['download_folder'].basename($invoice_filename);
		if(file_exists($invoice_filename)) {
			$invoice_data = array(
				"noMargin" => $this->no_margin_pdf,
				"invoiceName" => $invoice_name,
				"invoiceDate" => $invoice_date,
				"invoiceAmount" => $invoice_amount,
				"invoiceFilename" => $invoice_filename
			);

			if(!empty($this->config_array['new_invoice_shell_script'])) {
				$cmd  = $this->config_array['new_invoice_shell_script'];
				$cmd .= " --PROCESS_UID::".$this->process_uid;
				$cmd .= " --OPT::saveInvoice";
				$cmd .= " --invoiceData::".urlencode(json_encode($invoice_data));
				$ret = exec($cmd);
				$this->log('New Invoice CMD - '.$cmd."\n Output-".$ret);
			}

			$this->sendRequestEx(json_encode(array(
				'method' => 'newInvoice',
				'data' => $invoice_data
			)));

			if(!empty($invoice_name)) {
				$this->config_array['download_invoices'][] = $invoice_name;
			}
		}

		$this->no_margin_pdf = 0;
	}

	/**
	 * Find last downloaded file
	 * Rename it if filename passed as argument
	 *
	 * @param	string	$file_ext
	 * @param	string	$filename
	 * @return	string
	 */
	function find_saved_file($file_ext, $filename='') {
		$filepath = '';
		if(is_dir($this->config_array['download_folder'])) {
			$downloaded_files = $this->get_downloaded_files($file_ext);
			if(!empty($downloaded_files)) {
				foreach($downloaded_files as $downloaded_file) {
					if(!in_array(basename($downloaded_file), $this->downloaded_files)) {
						$filepath = $this->config_array['download_folder'].basename($downloaded_file);

						// If filename passed as argument, then rename file
						if(!empty($filename) && !empty($filepath)) {
							@rename($filepath, $this->config_array['download_folder'].$filename);

							if(file_exists($this->config_array['download_folder'].$filename)) {
								$filepath = $this->config_array['download_folder'].$filename;
							}
						}

						$this->downloaded_files[] = basename($filepath);
						break;
					}
				}
			}
		}

		$this->log('Downloaded File - ' . $filepath);
		return $filepath;
	}

	/**
	 * Wait for completion of file download
	 * timeout 1 min
	 *
	 * @param	string	$file_ext
	 * @param	int		$attempts
	 * @return	void
	 */
	public function wait_and_check_download($file_ext, $attempts=0) {
		$this->log('Waiting for download completion');
		usleep(10000); // 10 milliseconds
		$new_filepath = '';

		// If there is any .part file then wait for download completion
		// Timeout 5 minutes
		if(is_dir($this->config_array['download_folder'])) {
			$part_files = glob($this->config_array['download_folder'].'*.'.$file_ext.".part");
			if(!empty($part_files)) {
				// Wait till all part files got renamed by browser
				$start_time = time();
				while(true) {
					$time_lapsed = time() - $start_time;
					if($time_lapsed >= 300) {
						break;
					}

					usleep(5000); // 5 milliseconds

					$part_files = glob($this->config_array['download_folder'].'*.'.$file_ext.".part");
					if(empty($part_files)) {
						break;
					}
				}
			}
		}

		if(is_dir($this->config_array['download_folder'])) {
			$downloaded_files = $this->get_downloaded_files($file_ext);
			if(!empty($downloaded_files)) {
				foreach($downloaded_files as $downloaded_file) {
					if(!in_array(basename($downloaded_file), $this->downloaded_files)) {
						$new_filepath = $this->config_array['download_folder'].basename($downloaded_file);
						break;
					}
				}
			}
		}
		$this->log('Found new file - '.$new_filepath);

		// Check after 5 milliseconds if filesize is changed
		// if keep changing, means file is downloading, if not file download completed
		// timeout 5 min
		if(!empty($new_filepath) && file_exists($new_filepath)) {
			$start_time = time();
			$last_filesize = 0;
			while(true) {
				$new_filesize = filesize($new_filepath);
				if($last_filesize == $new_filesize && $new_filesize > 0) {
					break;
				}
				$last_filesize = $new_filesize;

				$time_lapsed = time() - $start_time;
				if($time_lapsed >= 300) {
					break;
				}

				usleep(5000); // 5 milliseconds
			}

			$this->log('Download completed - '.$new_filepath);
		} elseif($attempts < 5) {
			$attempts++;
			sleep(2);
			$this->wait_and_check_download($file_ext, $attempts);
		} else {
			$this->log('File save failed');
		}
	}

	/**
	 * Get all downloaded files sorted by filetime
	 *
	 * @param	string	$file_ext
	 * @return	mixed
	 */
	private function get_downloaded_files($file_ext) {
		$downloaded_files = array();
		if(is_dir($this->config_array['download_folder'])) {
			$downloaded_files = glob($this->config_array['download_folder'].'*.'.$file_ext);

			// Sort list by filetime
			if(!empty($downloaded_files)) {
				array_multisort(
					array_map('filemtime', $downloaded_files),
					SORT_NUMERIC,
					SORT_DESC,
					$downloaded_files
				);
			}
		}

		return $downloaded_files;
	}

	/**
	 * Check if invoice already exists
	 *
	 * @param	string	$invoice_number
	 * @return	bool
	 */
	public function invoice_exists($invoice_number) {
		$this->update_process_lock();

		// Update cookie file, So that in case if process terminated, we will have updated cookie always
		$this->dumpCookies(true);

		$this->increase_document_counter($invoice_number);

		if(!empty($invoice_number) && !empty($this->config_array['download_invoices'])) {
			return in_array($invoice_number, $this->config_array['download_invoices']);
		}

		return false;
	}

	/**
	 * Increase document counter for found documents
	 *
	 * @param	string	$invoice_name
	 * @return	void
	 */
	public function increase_document_counter($invoice_name) {
		$this->update_process_lock();

		if(trim($invoice_name) != '') {
			// if filename is given then strip extension
			$fext = $this->get_file_extension($invoice_name);
			if(!empty($fext)) {
				$invoice_name = basename($invoice_name, ".".$fext);
			}

			if(!in_array($invoice_name, $this->checked_documents)) {
				$this->checked_documents[] = $invoice_name;
				$this->document_counter++;
			}

			// Create a log for success with document counter
			$this->sendRequestEx(json_encode(array(
				'method' => 'success',
				'data' => array(
					'documentCounter' => $this->document_counter
				)
			)));
		}
	}

	/**
	 * Check if document exists. It internally checks invoice number too
	 *
	 * @param	string	$filename
	 * @return	bool
	 */
	public function document_exists($filename) {
		if(!empty($filename)) {
			if(!in_array(basename($filename), $this->downloaded_files)) {
				$this->increase_document_counter($filename);
			}

			$file_ext = $this->get_file_extension($filename);

			$filepath = $this->config_array['download_folder'].$filename;
			if(file_exists($filepath)) {
				$this->log('File exists - '.$filename);
				return true;
			}

			// If file not exists, then check if invoice number exists
			$invoice_number = basename($filename, '.'.$file_ext);
			if($this->invoice_exists($invoice_number)) {
				$this->log('Invoice number exists - '.$invoice_number);
				return true;
			}
		} else {
			$this->document_counter++;

			// Create a log for success with document counter
			$this->sendRequestEx(json_encode(array(
				'method' => 'success',
				'data' => array(
					'documentCounter' => $this->document_counter
				)
			)));
		}

		$this->log('Downloading Document '.$this->document_counter);

		return false;
	}

	/**
	 * Convert captured image to pdf document and delete captured image
	 *
	 * @param	string	$capture_name
	 * @param	string	$filename
	 * @return	void
	 */
	public function generate_pdf($capture_name, $filename) {
		// Resize image to 1200x1122, as in casperjs
		$thumb_w = 1200;
		$thumb_path = $this->config_array['download_folder'].$capture_name.'.png';
		$img = @imagecreatefrompng($thumb_path);
		if(is_resource($img) && strtoupper(get_resource_type($img)) == "GD") {
			$iOrigWidth = imagesx($img);
			$iOrigHeight = imagesy($img);
			if((int)$iOrigWidth > 0 && (int)$iOrigHeight > 0) {
				$fScale = $thumb_w/$iOrigWidth;
				$iNewWidth = floor($fScale * $iOrigWidth);
				$iNewHeight = floor($fScale * $iOrigHeight);

				$tmpimg = imagecreatetruecolor($iNewWidth, $iNewHeight);
				$white = imagecolorallocate($tmpimg, 255, 255, 255);
				imagefilledrectangle( $tmpimg, 0, 0, $iNewWidth, $iNewHeight, $white);
				imagecopyresampled($tmpimg, $img, 0, 0, 0, 0,$iNewWidth, $iNewHeight, $iOrigWidth, $iOrigHeight);
				imagedestroy($img);
				$img = $tmpimg;

				imagepng($img, $thumb_path);
				imagedestroy($img);
			}
		}

		// convert to pdf
		$target_pdf = $this->config_array['download_folder'].$filename;
		$cmd = 'convert -auto-orient -quality 100 ' . $this->config_array['download_folder'].$capture_name.'.png' . ' ' . escapeshellarg(trim($target_pdf));
		exec($cmd);

		@unlink($this->config_array['download_folder'].$capture_name.'.png');
	}

	/**
	 * Translate Date abbreviations
	 *
	 * @param	string	$date_str
	 * @return	string
	 */
	public function translate_date_abbr($date_str) {
		$lang_code = $this->config_array['lang_code'];
		$source_month_abbr = $this->month_abbr_de;
		if($lang_code == 'fr') {
			$source_month_abbr = $this->month_abbr_fr;
		} elseif($lang_code == 'nl') {
			$source_month_abbr = $this->month_abbr_nl;
		}

		for($i=0; $i<count($source_month_abbr); $i++) {
			if(stripos($date_str, $source_month_abbr[$i]) !== FALSE) {
				$date_str = str_replace($source_month_abbr[$i], $this->month_abbr_en[$i], $date_str);
				break;
			}
		}

		return $date_str;
	}

	/**
	 * Parse Date
	 *
	 * @param	string	$date_str
	 * @param	string	$input_date_format
	 * @param	string	$output_date_format
	 * @return	string
	 */
	public function parse_date($date_str, $input_date_format='', $output_date_format='') {
		$output_date_format = empty($output_date_format) ? 'Y-m-d' : $output_date_format;
		$parsed_date = '';
		try {
			// Check if any language parsing is required
			$lang_code = $this->config_array['lang_code'];
			$source_month_names = $this->month_names_de;
			if($lang_code == 'fr') {
				$source_month_names = $this->month_names_fr;
			} elseif($lang_code == 'nl') {
				$source_month_names = $this->month_names_nl;
			}

			for($i=0; $i<count($source_month_names); $i++) {
				if(stripos($date_str, $source_month_names[$i]) !== FALSE) {
					$date_str = str_replace($source_month_names[$i], $this->month_names_en[$i], $date_str);
					break;
				}
			}

			if(!empty($input_date_format)) {
				$d = \DateTime::createFromFormat($input_date_format, $date_str);
			} else {
				$d = new \DateTime($date_str);
			}

			if(!empty($d)) {
				$timestamp = $d->getTimestamp();
				$parsed_date = date($output_date_format, $timestamp);
			}

		} catch(\Exception $exception) {
			$this->log('ERROR in parsing date - '.$exception->getMessage());
		}

		return $parsed_date;
	}

	/**
	 * To send generic string output to the log for parsing
	 *
	 * @param	string	$string_request
	 * @param	string	$request_start
	 */
	public function sendRequestEx($string_request, $request_start='') {
		if(empty($request_start)) $request_start = $this->request_start;
		echo ''.$request_start.$string_request."\n";
	}

	/**
	 * Called when script execution completes successfully
	 *
	 */
	public function exitSuccess() {
		$this->success();
		$this->exitFinal();
	}

	/**
	 * Called when script execution completes with failure
	 *
	 */
	public function exitFailure() {
		$this->sendRequestEx(json_encode(array(
			'method' => 'portalFailed'
		)));
		$this->exitFinal();
	}

	/**
	 * Called when script failed with login error
	 *
	 * @param	int	$confirmed
	 */
	public function loginFailure($confirmed=0) {
		$this->log('Begin loginFailure ');
		$this->sendRequestEx($this->login_failed);

		if($confirmed == 1) {
			$this->sendRequestEx(json_encode(array(
				'method' => 'loginFailedConfirmed'
			)));
		} else {
			$this->sendRequestEx(json_encode(array(
				'method' => 'loginFailedExt'
			)));
		}
		$this->exitFinal();
	}

	/**
	 * Return last init url
	 *
	 * @param	string	$last_init_url
	 */
	public function lastInitUrl($last_init_url) {
		$this->sendRequestEx(json_encode(array(
			'method' => 'lastInitUrl',
			'data' => array(
				'url' => $last_init_url
			)
		)));
	}

	/**
	 * Method to call finally to close webdriver
	 *
	 */
	public function exitFinal() {
		// Save updated cookies
		$this->dumpCookies();

		// Save updated localStorage
		$this->dumpLocalStorage();

		// Save updated sessionStorage
		$this->dumpSessionStorage();

		// Trigger process completed
		$this->process_completed();

		try {
			if($this->webdriver != null) {
				$this->webdriver->quit();
			}
		} catch(\Exception $exception) {
			$this->log('ERROR in exitFinal');
		}
		die;
	}

	/**
	 * Open new window
	 *
	 * @param	void
	 */
	public function open_new_window() {
		$browser_windows = $this->webdriver->getWindowHandles();
		if(count($browser_windows) == 1) {
			try {
				// Create a new temporary tab
				$this->webdriver->getKeyboard()->sendKeys(array(
					WebDriverKeys::CONTROL,
					'n'
				));
			} catch(\Exception $exception) {
				$this->log('Unable to send control keys');
			}

			// If new window is not opened yet, then use javascript to open it
			$browser_windows = $this->webdriver->getWindowHandles();
			if(count($browser_windows) == 1) {
				$this->webdriver->executeScript("window.open();");
				$browser_windows = $this->webdriver->getWindowHandles();
			}
		}

		$this->webdriver->switchTo()->window(end($browser_windows));
	}

	/**
	 * Close new window
	 */
	public function close_new_window() {
		$browser_windows = $this->webdriver->getWindowHandles();
		$this->webdriver->switchTo()->window(current($browser_windows));

		try {
			// Release control key forcefully
			$this->webdriver->getKeyboard()->sendKeys(array(WebDriverKeys::NULL));
		} catch(\Exception $exception) {
			$this->log("Unable to send control keys");
		}
	}

	/**
	 * Returns a file's extension.
	 *
	 * @param	string	$filename
	 * @return	string
	 */
	public function get_file_extension($filename) {
		$exploded = explode('.', $filename);
		return strtolower(end($exploded));
	}

	/**
	 * Returns a list of files matching a pattern string in a directory and its subdirectories.
	 *
	 * @link	http://de2.php.net/manual/en/reserved.constants.php
	 *
	 * @param	string
	 * @param	string
	 * @param	int	Maximum number of recursion levels, -1 for infinite
	 * @param	int	Recursion level
	 * @param	bool	Return folders also ?
	 * @param	mixed	List of files or directories that will be ignored
	 * @return	mixed
	 */
	public function search_directory($pattern, $dir, $maxlevel=0, $level=0, $return_directories=false, $ignore_list=0) {
		$result = array();

		if($level > $maxlevel && $maxlevel != -1) return $result;
		if(substr($dir, -1) == DIRECTORY_SEPARATOR || substr($dir, -1) == '/') {
			$dir = substr($dir, 0, -1);
		}

		if(is_dir($dir)) {
			if($dh = opendir($dir)) {
				while(($file = readdir($dh)) !== false) {
					if(is_array($ignore_list)) {
						if(in_array($file, $ignore_list)) $file = '.'; // Mark to be ignored
					}

					if($file != '.' && $file != '..') {
						if(is_dir($dir.DIRECTORY_SEPARATOR.$file)) {
							$test_return = $this->search_directory($pattern, $dir.DIRECTORY_SEPARATOR.$file, $maxlevel, $level + 1, $return_directories);

							if(is_array($test_return)) {
								$temp = array_merge($test_return, $result);
								$result = $temp;
							}

							if(is_string($test_return)) {
								array_push($result, $test_return);
							}

							if($return_directories == true) {
								$add_it = false;

								if($pattern == '/.*/' || $pattern == '') {
									$add_it = true;
								} elseif(preg_match($pattern, $file)) {
									$add_it = true;
								}

								if($add_it) array_push($result, $dir.DIRECTORY_SEPARATOR.$file);
							}

						} else {
							$add_it = false;

							if($pattern == '/.*/' || $pattern == '') {
								$add_it = true;
							} elseif(preg_match($pattern, $file)) {
								$add_it = true;
							}

							if($add_it) array_push($result, $dir.DIRECTORY_SEPARATOR.$file);
						}
					}
				}

				closedir($dh);
			}
		}

		return $result;
	}
}
?>