<?php
/**
 * Invoice Request for priceminister.com
 * Process Multi-Account  'select[name="selectedB2BGroupKey"] option'
 * Download invoice for orders depends on last_invoiceDate
 * Download message invoices
 * Support restart docker
 */

/*Define constants used in script*/
public $baseUrl = "https://priceminister.com";
public $loginUrl = "https://www.priceminister.com/connect?action=login";
public $username_selector = "#auth_user_identifier";
public $password_selector = "#userpassword";
public $submit_button_selector = "#sbtn_login";
public $login_tryout = 0;
public $restrictPages = 3;


/**
 * Entry Method thats called for a portal
 * @param Integer $count Number of times portal is retried.
 */
private function initPortal($count) {

	if($this->exts->docker_restart_counter == 0) {
		$this->restrictPages = isset($this->exts->config_array["restrictPages"]) ? (int)@$this->exts->config_array["restrictPages"] : 3;
	}
	
	$this->openUrl($this->baseUrl);
	sleep(5);
	$this->exts->capture("Home-page-without-cookie");
	
	$isCookieLoginSuccess = false;
	$cookie_file = $this->exts->screen_capture_location."cookie.txt";
	
	if($this->exts->loadCookiesFromFile()) {
		sleep(2);
		
		$this->openUrl("https://www.priceminister.com/user");
		sleep(10);
		$this->exts->capture("Home-page-with-cookie");
		
		if($this->checkLogin()) {
			$isCookieLoginSuccess = true;
			$this->exts->success();
			$this->exts->capture("LoginSuccess");
			$this->processAfterLogin(0);
		} else {
			$this->openUrl($this->loginUrl);
			sleep(3);
		}
	} else {
		$this->openUrl($this->loginUrl);
		sleep(5);

	}
	
	if(!$isCookieLoginSuccess) {
		$isCookieLoginSuccess = false;
		$this->fillForm(0);
		
		if($this->checkLogin()) {
			$this->exts->success();
			$this->exts->capture("LoginSuccess");
			$this->processAfterLogin(0);
		} else {
			$this->RetryLogin($isCookieLoginSuccess);
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
				sleep(20);
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

	if($this->exts->getElementByCssSelector($this->password_selector) != null || $this->exts->getElementByCssSelector($this->username_selector) != null) {
		$count++;
		sleep(3);
		if($count < 3) {
			$this->fillForm($count);
			$this->exts->log("Retry login");
		} else {
			$this->exts->capture("LoginFailed");
			$this->exts->loginFailure();
		}
	}

}

/**
 * Method to Check where user is logged in or not
 * return boolean true/false
 */
function checkLogin(){
	$this->exts->log("Begin checkLogin ");
	$isLoggedIn = false;

	if($this->exts->getElementByCssSelector($this->password_selector) != null || $this->exts->getElementByCssSelector($this->username_selector) != null) {
		$this->fillForm(0);
		sleep(5);
	}
	
	if($this->exts->getElementByCssSelector("a[href*=\"connect?action=logout\"]") != null) {
		$isLoggedIn = true;
		$this->exts->log("Login Successfully!!!");
	} else {
		$isLoggedIn = false;
		$this->exts->log("Login Failed!!!");
	}
	
	return $isLoggedIn;
}

function processAfterLogin($count) {
	$this->exts->getElementByCssSelector("a[href*=\"usersecure?action=invoicesaction\"]")->click();
	sleep(5);

	$loadmorelink = false;
	$loadMoreLinkButton = $this->exts->getElementByCssSelector("#invoices_more_result");
	if ($loadMoreLinkButton !=null ) {
		$loadmorelink = true;
		$this->LoadMoreResult($loadmorelink);
		$loadmorelink = false;
	}

	$this->exts->log("LoadMoreLink - ".$loadmorelink);
	if (!$loadmorelink) {
		try {
			$invoiceRowElements = $this->exts->webdriver->findElements(WebDriverBy::xpath("//table/tbody"));
	        $invoiceCount = count($invoiceRowElements);
	        if ($invoiceCount > 0) {
	        	for ($i=1; $i <= $invoiceCount; $i++) {
	        		$invoiceNumber = trim($this->exts->webdriver->findElements(WebDriverBy::xpath("//table/tbody[".$i."]/tr/td[1]/a/span[@class='lbl']"))[0]->getText());
	            	$invoiceUrl = trim($this->exts->webdriver->findElements(WebDriverBy::xpath("//table/tbody[".$i."]/tr/td[1]/a[@class='lnk_pdf']"))[0]->getAttribute("href"));
	            	$invoiceDate = trim($this->exts->webdriver->findElements(WebDriverBy::xpath("//table/tbody[".$i."]/tr/td[@class='date']"))[0]->getText());
	            	$parsed_date = $this->exts->parse_date($invoiceDate);
	            	$invoiceAmount = trim($this->exts->webdriver->findElements(WebDriverBy::xpath("//table/tbody[".$i."]/tr/td[3]"))[0]->getText());

	            	$invoiceName = explode("n°", $invoiceAmount);

	            	$file_name = "Invoice_".$invoiceName[0].$invoiceName[1].".pdf";

	            	$this->exts->log("invoiceNumber - ".$invoiceNumber);
	            	$this->exts->log("invoiceUrl - ".$invoiceUrl);
	            	$this->exts->log("invoiceDate - ".$parsed_date);
	            	$this->exts->log("invoiceAmount - ".$invoiceAmount);

	            	if((int)@$this->restrictPages > 0 ) {
	            		$current_year = date("Y");
						if (strpos($invoiceDate, $current_year)) {
							$downloaded_file = $this->exts->direct_download($invoiceUrl, "pdf", $file_name);
							if(file_exists($downloaded_file)) {
								$this->exts->new_invoice($invoiceNumber, $parsed_date, $invoiceAmount, $file_name);
							}
						}
					}
					else {
		            	$downloaded_file = $this->exts->direct_download($invoiceUrl, "pdf", $file_name);
						if(file_exists($downloaded_file)) {
							$this->exts->new_invoice($invoiceNumber, $parsed_date, $invoiceAmount, $file_name);
						}
					}

	            }
	        }
		}
		catch(\Exception $excp) {
			$this->exts->log("Exception - ".$excp->getMessage());
		}
	}
}

function LoadMoreResult($loadmorelink=true) {
	try {
		$this->exts->getElementByCssSelector("#invoices_more_result")->click();
		sleep(3);
		$loadMoreLinkButton = $this->exts->getElementByCssSelector("#invoices_more_result");
		if ($loadMoreLinkButton !=null ){
			$loadmorelink = true;
			$this->LoadMoreResult($loadmorelink);
		}
	}
	catch(\Exception $excp) {
		$loadmorelink = false;
	}
	return $loadmorelink;
}

function RetryLogin($isCookieLoginSuccess){

	if($this->checkLogin()) {
		$isCookieLoginSuccess = true;
		$this->exts->success();
		$this->exts->capture("LoginSuccess");
		$this->processAfterLogin(0);
		return $isCookieLoginSuccess;
	} else {
		$this->login_tryout++;
		if ($this->login_tryout > 3) {
			$this->exts->log("Login Failed");
			return;
		}
		else {
			$this->fillForm(0);
			sleep(10);
			$isCookieLoginSuccess = false;
			$this->RetryLogin($isCookieLoginSuccess);
		}
	}
}