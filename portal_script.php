<?php
/**
 * Invoice Request for floricolor.pt
 * Process Multi-Account  'select[name="selectedB2BGroupKey"] option'
 * Download invoice for orders depends on last_invoiceDate
 * Download message invoices
 * Support restart docker
 */

/*Define constants used in script*/
public $baseUrl = "https://www.floricolor.pt";
public $orderUrl = "https://fos2.floricolor.pt/#orderlist";
public $username_selector = "#email";
public $password_selector = "#password";

/**
 * Entry Method thats called for a portal
 * @param Integer $count Number of times portal is retried.
 */
private function initPortal($count) {
	
	$this->openUrl($this->baseUrl);
	sleep(5);
	$this->exts->capture("Home-page-without-cookie");
	
	$isCookieLoginSuccess = false;
	$cookie_file = $this->exts->screen_capture_location."cookie.txt";
	
	if($this->exts->loadCookiesFromFile()) {
		sleep(2);
		
		$this->openUrl($this->orderUrl);
		sleep(20);
		$this->exts->capture("Home-page-with-cookie");

		if ($this->exts->getElementByCssSelector("a[href='http://www.adobe.com/go/getflashplayer']") != null) {
			$this->exts->getElementByCssSelector("a[href='http://www.adobe.com/go/getflashplayer']")->click();
			sleep(15);
		}
		
		if($this->checkLogin()) {
			$isCookieLoginSuccess = true;
			$this->exts->success();
			$this->exts->capture("LoginSuccess");
			$this->processAfterLogin(0);
		} else {
			$this->openUrl($this->loginUrl);
			sleep(5);
		}
	} else {
		$this->openUrl($this->orderUrl);
		sleep(5);
	}
	
	if(!$isCookieLoginSuccess) {
		$this->fillForm(0);
		
		if($this->checkLogin()) {
			$this->exts->success();
			$this->exts->capture("LoginSuccess");
			
			$this->processAfterLogin(0);
		} else {
			if($this->exts->getElementByCssSelector("div[style*=\"visibility: visible\"] > div > iframe[src*=\"google.com/recaptcha/\"]") != null) {
				$this->checkFillRecaptcha(0);
			} else if($this->exts->getElementByCssSelector("iframe[src*=\"google.com/recaptcha/\"]") != null) {
				$this->checkFillRecaptcha(0);
			}
			
			if($this->checkLogin()) {
				$this->exts->success();
				$this->exts->capture("LoginSuccess");
				
				$this->processAfterLogin(0);
			} else {
				$this->exts->capture("LoginFailed");
				$this->exts->loginFailure();
			}
		}
	}
}

/**
 * Method to fill login form
 * @param Integer $count Number of times portal is retried.
 */
function fillForm($count){
	$this->exts->log("Begin fillForm ".$count);
	$this->exts->log("Begin fillForm URL - ".$this->exts->webdriver->getCurrentUrl());
	
	try {
		
		if($this->login_tryout == 0) {
			if($this->exts->getElementByCssSelector($this->password_selector) != null || $this->exts->getElementByCssSelector($this->username_selector) != null) {

				if ($this->exts->getElementByCssSelector($this->username_selector)->getAttribute('value') == null) {
					$this->exts->log("Enter Username");
					$this->exts->getElementByCssSelector($this->username_selector)->sendkeys($this->username);
					sleep(2);
				}

				if ($this->exts->getElementByCssSelector($this->password_selector)->getAttribute('value') == null) {
					$this->exts->log("Enter Password");
					$this->exts->getElementByCssSelector($this->password_selector)->sendkeys($this->password);
					sleep(2);
				}

				$this->exts->getElementByCssSelector($this->submit_button_selector)->click();
			}

			$this->exts->log("END fillForm URL - ".$this->exts->webdriver->getCurrentUrl());
		} else {
			$this->exts->log("END fillForm URL - ".$this->exts->webdriver->getCurrentUrl());
			$this->exts->init_required();
		}
	} catch(\Exception $exception){
		$this->exts->log("Catch fillForm URL - ".$this->exts->webdriver->getCurrentUrl());
		$this->exts->log("Exception filling login form ".$exception->getMessage());
	}
}

/**
 * Method to Check where user is logged in or not
 * return boolean true/false
 */
function checkLogin(){
	$this->exts->log("Begin checkLogin ");
	$isLoggedIn = false;
	
	if($this->exts->getElementByCssSelector("a[href*=\"Logout\"]") != null) {
		$isLoggedIn = true;
		$this->exts->log("Login Successfully!!!");
	} else {
		$isLoggedIn = false;
		$this->exts->log("Login Failed!!!");
	}
	
	return $isLoggedIn;
}