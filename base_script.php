<?php
/**
 * Selenium script, for specific process/portal
 *
 * @package	uwa
 *
 * @copyright	GetMyInvoices
 */

namespace Facebook\WebDriver;

$gmi_selenium_core = realpath('/home/ubuntu/selenium/selenium.php');
require_once($gmi_selenium_core);

class PortalScript {

	private	$exts;
	public	$setupSuccess = false;
	private	$username;
	private	$password;
	public $support_restart = true;

	public function __construct($mode, $portal_name, $process_uid, $username, $password) {

		$this->username = $username;
		$this->password = $password;

		$this->exts = new GmiSelenium();
		$this->exts->screen_capture_location = '%SCREEN_CAPTURE_LOCATION%';
		$this->exts->init($mode, $portal_name, $process_uid, $username, $password);
		$this->setupSuccess = true;
	}

	/**
	 * Method thats called first for executing portal script, this method should not be altered by Users.
	*/
	public function run(){
		if($this->setupSuccess) {
			try {
				// Start portal script execution
				$this->initPortal(0);

				// Save updated cookies
				$this->exts->dumpCookies();
			} catch(\Exception $exception) {
				$this->exts->log('Selenium Exception: '.$exception->getMessage());
				$this->exts->capture("error");
				var_dump($exception);
			}

			$this->exts->log('Execution completed');

			$this->exts->process_completed();
		} else {
			echo 'Script execution failed.. '."\n";
		}
	}

	/**
	 * Navigate url Catch exception, if failed then restart docker and process
	 *
	 * @param	string	$url
	 * @return	void
	 */
	public function openUrl($url) {
		$this->exts->openUrl($url);

		// Check if docker restart required
		if($this->support_restart && $this->exts->docker_need_restart) {
			$this->exts->restart();

			// If docker restarted failed, it will die and below code will not get executed
			$this->initPortal(0);
		}
	}

	/**********************************************************************/
	/**************Portal Specific Script Should Begin Now*****************/
	/**********************************************************************/

	%PHP_SELENIUM_PORTAL_SCRIPT%
}

$portal = new PortalScript("firefox", '%portal_name%', '%process_uid%', '%username%', '%password%');
$portal->run();
?>