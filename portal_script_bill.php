<?php
/**
 * Invoice Request for bill.com
 * Process Multi-Account  'select[name="selectedB2BGroupKey"] option'
 * Download invoice for orders depends on last_invoiceDate
 * Download message invoices
 * Support restart docker
 */

/*Define constants used in script*/
public $baseUrl = "http://bill.com";
public $homeUrl = "https://app.bill.com";
public $homepageUrl = "https://app.bill.com/OrgSelect";
public $loginUrl = "https://app.bill.com/Login";
public $recaptcha_verify_selector = "#recaptcha-verify-button";
public $loginLink_selector = ".btn-t10login";
public $username_selector = "#email";
public $password_selector = "#password";
public $submit_button_selector = ".form-button-container button";
public $remember_me = "input[name=\"rememberMe\"]";
public $login_tryout = 0;
public $compnaylink_selector = ".orgSelectOrgNameContent";
public $download_pdf_selector = "//a[text()='Print']";

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
		
		$this->openUrl("https://app.bill.com/");
		sleep(5);
		$this->exts->capture("Home-page-with-cookie");
		
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
		$this->openUrl($this->loginUrl);
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

/**
 * Method to Process Re Catcha
 */
function checkFillRecaptcha($counter) {

	$this->fillForm(0);
	
	if($this->exts->getElementByCssSelector("iframe[src*=\"https://www.google.com/recaptcha/api2/anchor?\"]") != null && $this->exts->getElementByCssSelector("textarea[name=\"g-recaptcha-response\"]") != null) {
		
		if($this->exts->getElementByCssSelector("div.g-recaptcha") != null) {
			$data_siteKey = trim($this->exts->getElementByCssSelector("div.g-recaptcha")->getAttribute("data-sitekey"));
		} else {
			$iframeUrl = $this->exts->getElementByCssSelector("iframe[src*=\"https://www.google.com/recaptcha/api2/anchor?\"]")->getAttribute("src");
			$tempArr = explode("&k=", $iframeUrl);
			$tempArr = explode("&", $tempArr[count($tempArr)-1]);
			
			$data_siteKey = trim($tempArr[0]);
			$this->exts->log("iframe url  - " . $iframeUrl);
		}
		$this->exts->log("SiteKey - " . $data_siteKey);
		
		$isCaptchaSolved = $this->exts->processRecaptcha($this->exts->webdriver->getCurrentURL(), $data_siteKey, false);
		$this->exts->log("isCaptchaSolved - " . $isCaptchaSolved);
		
		if($isCaptchaSolved) {
			$this->exts->webdriver->executeScript(
				"document.querySelector(\"#g-recaptcha-response\").innerHTML = arguments[0];",
				array($this->exts->recaptcha_answer)
			);
			sleep(2);
			$this->exts->webdriver->executeScript(
				"window[___grecaptcha_cfg.clients[0].aa.l.callback](arguments[0])",
				array($this->exts->recaptcha_answer)
			);
			sleep(5);
		}
		
		sleep(5);
		
	}
	
	if($this->exts->getElementByCssSelector("iframe[src*=\"https://www.google.com/recaptcha/api2/anchor?\"]") != null && $this->exts->getElementByCssSelector("textarea[name=\"g-recaptcha-response\"]") != null) {
		$counter++;
		sleep(5);
		if($counter < 3) {
			$this->checkFillRecaptcha($counter);
			$this->exts->log("Retry reCaptcha");
		} else {
			$this->exts->capture("LoginFailed");
			$this->exts->loginFailure();
		}
	}
}

/**
 * Method to exceute main process
 */
function processAfterLogin($count) {

	$this->openUrl("https://app.bill.com/OrgSelect");
	sleep(5);
	
	$companylinks = $this->exts->webdriver->findElements(WebDriverBy::cssSelector(".orgSelectOrgNameContent"));
	$linkscount = count($companylinks);

	$this->exts->log("Company Count - ".$linkscount);

	if ($companylinks != null && $linkscount > 0) {

		if ($linkscount > 1) {
			$this->exts->log("This user has ".$linkscount." companies!");
		}
		else {
			$this->exts->log("This user has only a company!");
		}

		for ($i = 0; $i < $linkscount; $i++) {
			if ($i > 0) {
				$this->openUrl("https://app.bill.com/OrgSelect");
				sleep(5);
			}

			$this->exts->webdriver->findElements(WebDriverBy::cssSelector(".orgSelectOrgNameContent"))[$i]->click();
			sleep(3);

			$checked_login = false;

			if($this->checkLogin()) {
				$this->exts->log("Logged in already");
				$checked_login = true;
			} else {
				$this->exts->log("Please login");
				$this->fillForm(0);

				if($this->checkLogin()) {
					$this->exts->success();
					$checked_login = true;
				} else {
					if($this->exts->getElementByCssSelector("div[style*=\"visibility: visible\"] > div > iframe[src*=\"google.com/recaptcha/\"]") != null) {
						$this->checkFillRecaptcha(0);
					} else if($this->exts->getElementByCssSelector("iframe[src*=\"google.com/recaptcha/\"]") != null) {
						$this->checkFillRecaptcha(0);
					}
					
					if($this->checkLogin()) {
						$this->exts->success();
						$checked_login = true;
					} else {
						$this->exts->capture("LoginFailed");
						$this->exts->loginFailure();
					}
				}
			}

			if ($checked_login) {

				try {
					$receivables = $this->exts->webdriver->findElement(WebDriverBy::xpath("//a[@class='PrimaryNavBar-navItem' and text()='Receivables']"));
				} catch(\Exception $excp) {
					$receivables = null;
					$this->exts->log("Company - " . $excp->getMessage());
				}

				if ($receivables == null) {
					$receivables_exist = false;
					$invoiceUrl = $this->homeUrl."/neo/frame/invoices";
					$this->openUrl($invoiceUrl);
					sleep(5);

					$this->exts->webdriver->findElement(WebDriverBy::cssSelector("#GridFilterPaymentStatus-button"))->click();
					sleep(2);
					$this->exts->webdriver->findElement(WebDriverBy::cssSelector("li[data-qa='InvoiceList-payment-status-filter-paid']"))->click();
					sleep(5);

					$this->Receivables($receivables_exist);
				}
				elseif ($receivables != null) {

					$receivables_exist = true;
					$receivables -> click();
					sleep(3);

					try {
						$invoice = $this->exts->webdriver->findElement(WebDriverBy::xpath("//a[@class='SecondaryNavBar-navItem' and text()='Invoices']"));
					} catch(\Exception $excp) {
						$invoice = null;
						$this->exts->log("No Access: You do not have the necessary credentials to perform this task.");
					}

					if ($invoice !=null ) {
						$invoice -> click();
						sleep(3);

						$this->exts->webdriver->findElement(WebDriverBy::cssSelector("select[name=\"paymentStatus\"] option[value='0']"))->click();
						sleep(2);

						$this->exts->webdriver->findElement(WebDriverBy::cssSelector("button[name='changeFilter']"))->click();
						sleep(3);

						$this->Receivables($receivables_exist);
					}
				}
			}
		}
	}

	elseif ($companylinks == null) {
		$checked_login = false;

		if($this->checkLogin()) {
			$this->exts->log("Logged in already");
			$checked_login = true;
		} else {
			$this->exts->log("Please login");
			$this->fillForm(0);

			if($this->checkLogin()) {
				$this->exts->success();
				$checked_login = true;
			} else {
				if($this->exts->getElementByCssSelector("div[style*=\"visibility: visible\"] > div > iframe[src*=\"google.com/recaptcha/\"]") != null) {
					$this->checkFillRecaptcha(0);
				} else if($this->exts->getElementByCssSelector("iframe[src*=\"google.com/recaptcha/\"]") != null) {
					$this->checkFillRecaptcha(0);
				}
				
				if($this->checkLogin()) {
					$this->exts->success();
					$checked_login = true;
				} else {
					$this->exts->capture("LoginFailed");
					$this->exts->loginFailure();
				}
			}
		}

		if ($checked_login) {
			$receivables_exist = false;
			$this->exts->log("This user has only a company!");
			$invoiceUrl = $this->homeUrl."/neo/frame/invoices";
			$this->openUrl($invoiceUrl);
			sleep(5);

			$this->exts->webdriver->findElement(WebDriverBy::cssSelector("#GridFilterPaymentStatus-button"))->click();
			sleep(2);
			$this->exts->webdriver->findElement(WebDriverBy::cssSelector("li[data-qa='InvoiceList-payment-status-filter-paid']"))->click();
			sleep(5);

			$this->Receivables($receivables_exist);
			
		}
	}

	$this->exts->exitFinal();
}

/**
 * Method to download invoice pdf for each company
 */
function Receivables($receivables_exist) {
	$file_ext = "pdf";
	if (!$receivables_exist) {
		$loadmorelink = $this->exts->webdriver->findElements(WebDriverBy::xpath("div[data-qa='loadMoreLink']"));
		if ($loadmorelink !=null ){
			$loadmorelink = $this->LoadMoreInvoiceList($loadmorelink);
		}

		if ($loadmorelink == null) {
			try {
	            $invoiceRowElements = $this->exts->webdriver->findElements(WebDriverBy::cssSelector("div.ag-body > div.ag-body-viewport-wrapper div.ag-row.ag-row-level-0.SmartGrid-Row"));
	            $invoiceCount = count($invoiceRowElements);
	            if ($invoiceCount > 0) {
	                foreach($invoiceRowElements as $invoiceRowElement) {
	                    $invoiceNumberElements = array();
	                    $invoiceDetailLinkElements = array();
	                    try {
	                        $invoiceNumberElements = $invoiceRowElement->findElements(WebDriverBy::cssSelector("div[col-id=\"invoiceNumber\"]"));
	                        $invoiceDetailLinkElements = $invoiceRowElement->findElements(WebDriverBy::cssSelector("div[col-id=\"invoiceNumber\"] a[href*=\"frame/invoice-detail-redirect?invoiceId=\"]"));
	                    } catch(\Exception $exception){
	                        $this->exts->log("Exception checking invoice presence ".$exception->getMessage());
	                    }
	                    
	                    if(!empty($invoiceDetailLinkElements) && !empty($invoiceNumberElements)) {
	                        try {
	                            $invoiceNumber = trim($invoiceNumberElements[0]->getText());
	                            $invoiceNumber = trim(str_replace('INVOICE #', '', $invoiceNumber));
	                            $this->exts->log("invoiceNumber - ".$invoiceNumber);
	                            
	                            $invoiceDate = $invoiceRowElement->findElements(WebDriverBy::cssSelector("div[col-id=\"invoiceDate\"]"))[0]->getText();
	                            $invoiceDate = trim(str_replace('INVOICE DATE', '', $invoiceDate));
	                            $parsed_date = $this->exts->parse_date($invoiceDate);
								$this->exts->log("invoiceDate - " . $parsed_date);
	                            
	                            $invoiceAmount = $invoiceRowElement->findElements(WebDriverBy::cssSelector("div[col-id=\"amount\"]"))[0]->getText();
	                            $invoiceAmount = trim(str_replace('TOTAL', '', $invoiceAmount));
	                            $invoiceAmount = $this->AmountCurrency($invoiceAmount);
	                            $this->exts->log("invoiceAmount - ".$invoiceAmount);

	                            $invoiceDetailLink = trim($invoiceDetailLinkElements[0]->getAttribute("href"));
	                            $this->exts->log("invoiceDetailLink - ".$invoiceDetailLink);

	                            $file_name = "Invoice_".$invoiceNumber.".pdf";

								$invoice_id = explode("invoiceId=", $invoiceDetailLink)[1];
								$invoice_id = explode("&pageName", $invoice_id)[0];
								$invoice_url = $this->homeUrl."/Invoice2PdfServlet?Id=".$invoice_id."&PresentationType=PDF";

								$downloaded_file = $this->exts->direct_download($invoice_url, $file_ext, $file_name);

								if(file_exists($downloaded_file)) {
									$this->exts->new_invoice($invoiceNumber, $parsed_date, $invoiceAmount, $file_name);
								}

	                        } catch(\Exception $exception){
	                            $this->exts->log("Exception checking invoice element ".$exception->getMessage());
	                        }
	                    }
	                }
	            }
	        } catch(\Exception $exception){
	            $this->exts->log("Exception checking invoice page ".$exception->getMessage());
	        }
		}
	}

	elseif ($receivables_exist) {
		$invoiceCount = count($this->exts->webdriver->findElements(WebDriverBy::tagName('tr')));
		$this->exts->log("Invoice Count - ".$invoiceCount);

		if ($invoiceCount > 1) {
			for ($number = 1; $number <= $invoiceCount; $number++) {

				try {

					$invoiceNumber = $this->exts->webdriver->findElement(WebDriverBy::xpath("//table/tbody/tr[".$number."]/td[1]/a/span"))->getText();
					$this->exts->log("invoiceNumber - " . $invoiceNumber);
					
					$invoiceDate = $this->exts->webdriver->findElement(WebDriverBy::xpath("//table/tbody/tr[".$number."]/td[2]/span"))->getText();
					$parsed_date = $this->exts->parse_date($invoiceDate);
					$this->exts->log("invoiceDate - " . $parsed_date);

					$invoiceAmount = $this->exts->webdriver->findElement(WebDriverBy::xpath("//table/tbody/tr[".$number."]/td[6]/span"))->getText();
					$this->exts->log("invoiceAmount - " . $invoiceAmount);

					$file_name = "Invoice_".$invoiceNumber.".pdf";

					$invoiceDetailLink = $this->exts->webdriver->findElement(WebDriverBy::xpath("//table/tbody/tr[".$number."]/td[1]/a"))->getAttribute("href");
					$invoice_id = explode("id=", $invoiceDetailLink)[1];
					$invoice_url = $this->homeUrl."/Invoice2PdfServlet?Id=".$invoice_id."&PresentationType=PDF";

					$downloaded_file = $this->exts->direct_download($invoice_url, $file_ext, $file_name);

					if(file_exists($downloaded_file)) {
						$this->exts->new_invoice($invoiceNumber, $parsed_date, $invoiceAmount, $file_name);
					}
				}
				catch(\Exception $excp) {
					$this->exts->log("Exception checking invoice element ".$excp->getMessage());
				}
			}
		}
		else {
			$this->exts->log("There is no paid invoice");
		}
	}
}

/**
 * Method to change amountcurrency from string to symbol
 */
function AmountCurrency($invoiceAmount) {

	if (strpos($invoiceAmount, 'USD') !== false) {
	    $invoiceAmount = str_replace("USD", "$", $invoiceAmount);
	    $invoiceAmount = str_replace(" ", "", $invoiceAmount);
	}
	elseif (strpos($invoiceAmount, 'EUR') !== false) {
	    $invoiceAmount = str_replace("EUR", "€", $invoiceAmount);
	    $invoiceAmount = str_replace(" ", "", $invoiceAmount);
	}
	elseif (strpos($invoiceAmount, 'GBP') !== false) {
	    $invoiceAmount = str_replace("GBP", "£", $invoiceAmount);
	    $invoiceAmount = str_replace(" ", "", $invoiceAmount);
	}

	return $invoiceAmount;
}

/**
 * Method to check loadmorelink in neo invoice page
 */
function LoadMoreInvoiceList($loadmorelink) {
	$loadmorelink->click();
	sleep(3);

	$loadmorelink = $this->exts->webdriver->findElements(WebDriverBy::xpath("div[data-qa='loadMoreLink']"));
	if ($loadmorelink !=null ){
		$this->LoadMoreInvoiceList($loadmorelink);
	}
	else {
		$loadmorelink = null;
		return $loadmorelink;
	}

}