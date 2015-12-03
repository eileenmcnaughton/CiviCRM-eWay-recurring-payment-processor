<?php

/**
 * Class CRM_Core_Payment_Ewayrecurring
 */
class CRM_Core_Payment_Ewayrecurring extends CRM_Core_Payment {

  /**
   * (not used, implicit in the API, might need to convert?)
   */
  const CHARSET  = 'UTF-8';

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;

  /**
   * Class Constructor.
   *
   * @param string $mode the mode of operation: live or test
   * @param array $paymentProcessor
   *
   * @return \CRM_Core_Payment_Ewayrecurring
   */
  public function __construct($mode, &$paymentProcessor) {
    // As this handles recurring and non-recurring, we also need to include original api libraries
    require_once 'packages/eWAY/eWAY_GatewayRequest.php';
    require_once 'packages/eWAY/eWAY_GatewayResponse.php';

    // Mod is live or test.
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName    = ts('eWay Recurring');
  }


  /**
   * Singleton function used to manage this object.
   *
   * This function is not required on CiviCRM 4.6.
   *
   * @param string $mode the mode of operation: live or test
   *
   * @param array $paymentProcessor
   * @param null $paymentForm
   * @param bool $force
   *
   * @return object
   * @static
   */
  public static function &singleton($mode, &$paymentProcessor, &$paymentForm = NULL, $force = FALSE) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === NULL) {
      self::$_singleton[$processorName] = new CRM_Core_Payment_Ewayrecurring($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  /**
   * Add client encryption script if public key is in the signature field.
   *
   * By adding the public key to the eway configuration sites can enable client side encryption.
   *
   * https://www.eway.com.au/developers/api/client-side-encryption
   *
   * @param CRM_Core_Form $form
   *
   * @return bool
   *   Should form building stop at this point?
   */
  public function buildForm(&$form) {
    if (!empty($this->_paymentProcessor['signature'])) {
      $region = CRM_Core_Resources::isAjaxMode() ? 'ajax-snippet' : 'page-footer';
      CRM_Core_Resources::singleton()->addSetting(array('eway' => array('ewayKey' => $this->_paymentProcessor['signature'], 'formSelector' => (CRM_Core_Resources::isAjaxMode() ? '.crm-ajax-container form' : '#crm-main-content-wrapper form'))))
      ->addScriptFile('com.chrischinchilla.ewayrecurring', 'js/EwayClientSide.js', 5, $region)
      //->addScriptUrl('https://secure.ewaypayments.com/scripts/eCrypt.debug.js', 1, 'page-footer');
       ->addScriptUrl('https://secure.ewaypayments.com/scripts/eCrypt.js', 10, $region);
    }
    return FALSE;
  }

  /**********************************************************
   * This function sends request and receives response from eWAY payment gateway.
   *
   * http://www.eway.com.au/Support/Developer/PaymentsRealTime.aspx
   *
   * Currently these eWay params are not used for recurring :
   *  - $creditCardType = $params['credit_card_type'];
   *  - $currencyID    = $params['currencyID'];
   *  - $country        = $params['country'];
   *
   * @param array $params
   *
   * @throws Exception
   * @return array
   */
  public function doDirectPayment(&$params) {
    if (!defined('CURLOPT_SSLCERT')) {
      CRM_Core_Error::fatal(ts('eWAY - Gateway requires curl with SSL support'));
    }

    /*
     * OPTIONAL: If TEST Card Number force an Override of URL and CustomerID.
     * During testing CiviCRM once used the LIVE URL.
     * This code can be uncommented to override the LIVE URL that if CiviCRM does that again.
     * if ( ( $gateway_URL == "https://www.eway.com.au/gateway_cvn/xmlpayment.asp")
     *   && ( $params['credit_card_number'] == "4444333322221111" ) ) {
     *   $ewayCustomerID = "87654321";
     *   $gateway_URL    = "https://www.eway.com.au/gateway/rebill/test/Upload_test.aspx";
     * }
     */

    // Was the recurring payment check box checked?
    if (isset($params['is_recur']) && $params['is_recur'] == 1) {
      // Create the customer via the API.
      try{
        $result = $this->createToken($this->_paymentProcessor, $params);
      }
      catch (Exception $e) {
        return self::errorExit(9010, $e->getMessage());
      }

      // We've created the customer successfully.
      $managed_customer_id = $result;

      try {
        $initialPayment = civicrm_api3('ewayrecurring', 'payment', array(
          'invoice_id' => $params['invoiceID'],
          'amount_in_cents' => round(((float) $params['amount']) * 100),
          'managed_customer_id' => $managed_customer_id,
          'description' => $params['description'] . ts('first payment'),
          'payment_processor_id' => $this->_paymentProcessor['id'],
        ));

        // Here we compensate for the fact core accepts 0 as a valid frequency
        // interval and set it.
        $extra = array();
        if (empty($params['frequency_interval'])) {
          $params['frequency_interval'] = 1;
          $extra['frequency_interval'] = 1;
        }
        $params['trxn_id'] = $initialPayment['values'][$managed_customer_id]['trxn_id'];
        $params['contribution_status_id'] = 1;
        $params['payment_status_id'] = 1;
        // If there's only one installment, then the recurring contribution is now complete
        if (isset($params['installments']) && $params['installments'] == 1) {
          $status = CRM_Core_OptionGroup::getValue('contribution_status', 'Completed', 'name');
        }
        else {
          $status = CRM_Core_OptionGroup::getValue('contribution_status', 'In Progress', 'name');
        }
        // Save the eWay customer token in the recurring contribution's processor_id field.
        civicrm_api3('contribution_recur', 'create', array_merge(array(
          'id' => $params['contributionRecurID'],
          'processor_id' => $managed_customer_id,
          'contribution_status_id' => $status,
          'next_sched_contribution_date' => CRM_Utils_Date::isoToMysql(
            date('Y-m-d 00:00:00', strtotime('+' . $params['frequency_interval'] . ' ' . $params['frequency_unit']))),
        ), $extra));

        // Send recurring Notification email for user.
        $recur = new CRM_Contribute_BAO_ContributionRecur();
        $recur->id = $params['contributionRecurID'];
        $recur->find(TRUE);
        // If none found then effectively FALSE.
        $autoRenewMembership = civicrm_api3('membership', 'getcount', array('contribution_recur_id' => $recur->id));
        if ((!empty($params['selectMembership']) || !empty($params['membership_type_id'])
          && !empty($params['auto_renew']))
        ) {
          $autoRenewMembership = TRUE;
        }

        CRM_Contribute_BAO_ContributionPage::recurringNotify(
          CRM_Core_Payment::RECURRING_PAYMENT_START,
          $params['contactID'],
          CRM_Utils_Array::value('contributionPageID', $params),
          $recur,
          $autoRenewMembership
        );
      }
      catch (CiviCRM_API3_Exception $e) {
        return self::errorExit(9014, 'Initial payment not processed' . $e->getMessage());
      }

    }
    // This is a one off payment. This code is similar to in core.
    else {
      try {
        $result = $this->processSinglePayment($params);
        $params = array_merge($params, $result);

      }
      catch (CRM_Core_Exception $e) {
        return self::errorExit(9001, $e->getMessage());
      }
    }
    return $params;
  } // end function doDirectPayment

  // None of these functions have been changed, unless mentioned.

  /**
   * Checks to see if invoice_id already exists in db.
   *
   * @param int $invoiceId The ID to check.
   *
   * @param null $contributionID
   *   If a contribution exists pass in the contribution ID.
   *
   * @return bool
   *   True if ID exists, else false
   */
  protected function checkDupe($invoiceId, $contributionID = NULL) {
    $contribution = new CRM_Contribute_DAO_Contribution();
    $contribution->invoice_id = $invoiceId;
    $contribution->contribution_status_id = 1;
    if ($contributionID) {
      $contribution->whereAdd("id <> $contributionID");
    }
    return $contribution->find();
  }

  /**
   * This function checks the eWAY response status - returning a boolean false if status != 'true'.
   *
   * @param stdObj $response
   *
   * @return bool
   */
  public function isError($response) {
    if ($response->ResponseCode == '00' && $response->AuthorisationCode) {
      return FALSE;
    }
    return TRUE;
  }

  /**************************************************
   * Produces error message and returns from class
   *************************************************
   *
   * @param null $errorCode
   * @param null $errorMessage
   *
   * @return object
   */
  public function errorExit ($errorCode = NULL, $errorMessage = NULL) {
    $e = CRM_Core_Error::singleton();

    if ($errorCode) {
      $e->push($errorCode, 0, NULL, $errorMessage);
    }
    else {
      $e->push(9000, 0, NULL, 'Unknown System Error.');
    }
    return $e;
  }

  /**************************************************
   * NOTE: 'doTransferCheckout' not implemented
   *************************************************
   *
   * @param $params
   * @param $component
   *
   * @throws Exception
   */
  public function doTransferCheckout(&$params, $component) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
  }

  /********************************************************************************************
   * This public function checks to see if we have the right processor config values set
   *
   * NOTE: Called by Events and Contribute to check config params are set prior to trying
   *       register any credit card details
   *
   * @internal param string $mode the mode we are operating in (live or test) - not used but could be
   * to check that the 'test' mode CustomerID was equal to '87654321' and that the URL was
   * set to https://www.eway.com.au/gateway_cvn/xmltest/TestPage.asp
   *
   * @return null|string $errorMsg if any errors found - null if OK
   */
  public function checkConfig() {
    $errorMsg = array();
    // Not sure why this is not being called but appears that subject is
    // required if an @ is in the username (new style)
    if (empty($this->_paymentProcessor['subject'])) {
      $errorMsg[] = ts('eWAY CustomerID is not set for this payment processor');
    }

    if (empty($this->_paymentProcessor['url_site'])) {
      $errorMsg[] = ts('eWAY Gateway URL is not set for this payment processor');
    }

    // TODO: Check that recurring config values have been set
    if (!empty($errorMsg)) {
      if (civicrm_api3('setting', 'getvalue', array(
        'group' => 'eway',
        'name' => 'eway_developer_mode'
      ))) {
        CRM_Core_Session::setStatus(ts('Site is in developer mode so these errors are being ignored: ' . implode(', ', $errorMsg)));
        return NULL;
      }
      return implode('<p>', $errorMsg);
    }
    else {
      return NULL;
    }
  }


  /**
   * Cancel EWay Subscription.
   *
   * All details about recurring contributions are maintained in CiviCRM, and
   * eWAY only records the Token Customer and completed contributions. Given
   * this, we can provide a do-nothing implementation of 'cancelSubscription'
   *
   * @param string $message
   * @param array $params
   *
   * @return bool
   */
  public function cancelSubscription(&$message = '', $params = array()) {
    return TRUE;
  }

  /**
   * Change the amount of the subscription.
   *
   * All details about recurring contributions are maintained in CiviCRM, and
   * eWAY only records the Token Customer and completed contributions. Given
   * this, we can provide a do-nothing implementation of 'changeSubscriptionAmount'
   *
   * @param string $message
   * @param array $params
   *
   * @return bool
   */
  public function changeSubscriptionAmount(&$message = '', $params = array()) {
    return TRUE;
  }

  /**
   * Get the subscription URL.
   *
   * @param int $entityID
   * @param null $entity
   * @param string $action
   *
   * @return string
   */
  public function subscriptionURL($entityID = NULL, $entity = NULL, $action = 'cancel') {
    $url = parent::subscriptionURL($entityID, $entity, $action);
    if (!isset($url)) {
      return NULL;
    }
    if (stristr($url, '&cs=')) {
      return $url;
    }
    $user_id = CRM_Core_Session::singleton()->get('userID');
    $contact_id = $this->getContactID($entity, $entityID);
    if ($contact_id && $user_id != $contact_id) {
      return $url . '&cs=' . CRM_Contact_BAO_Contact_Utils::generateChecksum($contact_id, NULL, 'inf');
    }
    return $url;
  }

  /**
   * Get the relevant contact ID.
   *
   * @param $entity
   * @param $entityID
   *
   * @return array|int
   */
  public function getContactID($entity, $entityID) {
    if ($entity == 'recur') {
      $entity = 'contribution_recur';
    }
    try {
      return civicrm_api3($entity, 'getvalue', array('id' => $entityID, 'return' => 'contact_id'));
    }
    catch (Exception $e) {
      return 0;
    }
  }

  /**
   * Pass xml to eWay gateway and return response if the call succeeds.
   *
   * @param array $eWAYRequestFields
   *
   * @return mixed
   * @throws \CRM_Core_Exception
   */
  protected function callEwayGateway($eWAYRequestFields) {
    $submit = curl_init($this->_paymentProcessor['url_site']);
    if (!$submit) {
      throw new CRM_Core_Exception('Could not initiate connection to payment gateway');
    }

    curl_setopt($submit, CURLOPT_POST, TRUE);
    // Return the result on success, FALSE on failure.
    curl_setopt($submit, CURLOPT_RETURNTRANSFER, TRUE);
    // Return an error if we hit 404 etc.
    curl_setopt($submit, CURLOPT_FAILONERROR, TRUE);
    curl_setopt($submit, CURLOPT_VERBOSE, FALSE);
    curl_setopt($submit, CURLOPT_POSTFIELDS, json_encode($eWAYRequestFields));
    curl_setopt($submit, CURLOPT_TIMEOUT, 36000);
    curl_setopt($submit, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($submit, CURLOPT_USERPWD, $this->_paymentProcessor['user_name'] . ':' . $this->_paymentProcessor['password']);
    // if open_basedir or safe_mode are enabled in PHP settings CURLOPT_FOLLOW_LOCATION won't work so don't apply it
    // it's not really required CRM-5841
    if (ini_get('open_basedir') == '' && ini_get('safe_mode' == 'Off')) {
      // Ensure any Location headers are followed.
      //curl_setopt($submit, CURLOPT_FOLLOWLOCATION, 1);
    }

    $responseData = curl_exec($submit);

    //----------------------------------------------------------------------------------------------------
    // See if we had a curl error - if so tell 'em and bail out
    //
    // NOTE: curl_error does not return a logical value (see its documentation), but
    // a string, which is empty when there was no error.
    //----------------------------------------------------------------------------------------------------
    if ((curl_errno($submit) > 0) || (strlen(curl_error($submit)) > 0)) {
      $errorNum  = curl_errno($submit);
      $errorDesc = curl_error($submit);

      if ($errorNum == 0) {
        // Paranoia - in the unlikely event that 'curl' errno fails.
        $errorNum = 9005;
      }

      if (strlen($errorDesc) == 0) {
        // Paranoia - in the unlikely event that 'curl' error fails.
        $errorDesc = "Connection to eWAY payment gateway failed";
      }

      throw new CRM_Core_Exception($errorNum . ' ' . $errorDesc);
    }

    //----------------------------------------------------------------------------------------------------
    // If NULL data returned - tell 'em and bail out
    //
    // NOTE: You will not necessarily get a string back, if the request failed for
    // any reason, the return value will be the boolean false.
    //----------------------------------------------------------------------------------------------------
    if (($responseData === FALSE) || (strlen($responseData) == 0)) {
      throw new CRM_Core_Exception("Error: Connection to payment gateway failed - no data returned.");
    }

    //----------------------------------------------------------------------------------------------------
    // If gateway returned no data - tell 'em and bail out
    //----------------------------------------------------------------------------------------------------
    if (empty($responseData)) {
      throw new CRM_Core_Exception("Error: No data returned from payment gateway.");
    }

    //----------------------------------------------------------------------------------------------------
    // Success so far - close the curl and check the data
    //----------------------------------------------------------------------------------------------------
    curl_close($submit);

    return $responseData;
  }

  /**
   * Does the CiviCRM version supports immediate recurring payments.
   *
   * At this stage this is more a place holder but not all versions can cope with doing the payment now.
   *
   * @return bool
   */
  public function supportsImmediateRecurringPayment() {
    return TRUE;
  }

  /**
   * Create token on eWay.
   *
   * @param $paymentProcessor
   * @param array $params
   *
   * @return int
   *   Unique id of the token created to manage this customer in eway.
   *
   * @throws \CRM_Core_Exception
   */
  protected function createToken($paymentProcessor, $params) {
    if (civicrm_api3('setting', 'getvalue', array(
      'group' => 'eway',
      'name' => 'eway_developer_mode'
      ))) {
      // I'm not sure about setting status as in future we might do this in an api call.
      CRM_Core_Session::setStatus(ts('Site is in developer mode. No communication with eway has taken place'));
      return uniqid();
    }
    $gateway_URL = $paymentProcessor['url_recur'];
    $soap_client = new nusoap_client($gateway_URL, FALSE);
    $err = $soap_client->getError();
    if ($err) {
      throw new CRM_Core_Exception(htmlspecialchars($soap_client->getDebug(), ENT_QUOTES));
    }

    // Set namespace.
    $soap_client->namespaces['man'] = 'https://www.eway.com.au/gateway/managedpayment';

    // Set SOAP header.
    $headers = "<man:eWAYHeader><man:eWAYCustomerID>"
      . $this->_paymentProcessor['subject']
      . "</man:eWAYCustomerID><man:Username>"
      . $this->_paymentProcessor['user_name']
      . "</man:Username><man:Password>"
      . $this->_paymentProcessor['password']
      . "</man:Password></man:eWAYHeader>";
    $soap_client->setHeaders($headers);

    // Add eWay customer.
    $requestBody = array(
      // Crazily eWay makes this a mandatory field with fixed values.
      'man:Title' => 'Mr.',
      'man:FirstName' => $params['first_name'],
      'man:LastName' => $params['last_name'],
      'man:Address' => $params['street_address'],
      'man:Suburb' => $params['city'],
      'man:State' => $params['state_province'],
      'man:Company' => '',
      'man:PostCode' => $params['postal_code'],
      // TODO: Remove this hardcoded hack - use $params['country']
      'man:Country' => 'au',
      'man:Email' => $params['email'],
      'man:Fax' => '',
      'man:Phone' => '',
      'man:Mobile' => '',
      'man:CustomerRef' => '',
      'man:JobDesc' => '',
      'man:Comments' => '',
      'man:URL' => '',
      'man:CCNumber' => $params['credit_card_number'],
      'man:CCNameOnCard' => $this->getCreditCardName($params),
      'man:CCExpiryMonth' => $this->getCreditCardExpiryMonth($params),
      'man:CCExpiryYear' => $this->getCreditCardExpiryYear($params),
    );
    // Hook to allow customer info to be changed before submitting it.
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $requestBody);
    $soapAction = 'https://www.eway.com.au/gateway/managedpayment/CreateCustomer';
    $result = $soap_client->call('man:CreateCustomer', $requestBody, '', $soapAction);
    if ($result === FALSE) {
      throw new CRM_Core_Exception('Failed to create managed customer - result is FALSE');
    }
    elseif (is_array($result)) {
      $message = '';
      foreach ($result as $key => $value) {
        $message .= " $key : $value ";
      }
      throw new CRM_Core_Exception('Failed to create managed customer - ' . $message);
    }
    elseif (!is_numeric($result)) {
      throw new CRM_Core_Exception('Failed to create managed customer - result is ' . $result);
    }
    return $result;
  }

  /**
   * Get Credit card name from parameters.
   *
   * @param array $params
   *
   * @return string
   *   Credit card name
   */
  protected function getCreditCardName(&$params) {
    $credit_card_name = $params['first_name'] . " ";
    if (strlen($params['middle_name']) > 0) {
      $credit_card_name .= $params['middle_name'] . " ";
    }
    $credit_card_name .= $params['last_name'];
    return $credit_card_name;
  }

  /**
   * Get credit card expiry month.
   *
   * @param array $params
   *
   * @return string
   */
  protected function getCreditCardExpiryYear(&$params) {
    $expireYear = substr($params['year'], 2, 2);
    return $expireYear;
  }

  /**
   * Get credit card expiry month.
   *
   * 2 Chars Required parameter.
   *
   * @param $params
   *
   * @return string
   */
  protected function getCreditCardExpiryMonth(&$params) {
    return sprintf('%02d', (int) $params['month']);
  }

  /**
   * Get amount in cents.
   *
   * eg. 100 for $1
   *
   * @param $params
   *
   * @return float
   */
  protected function getAmountInCents(&$params) {
    $amountInCents = round(((float) $params['amount']) * 100);
    return $amountInCents;
  }

  /**
   * Get request to send to eWay.
   *
   * @param $params
   *   Form parameters - this could be altered by hook so is a reference
   *
   * @return GatewayRequest
   * @throws \CRM_Core_Exception
   */
  protected function getEwayRequestFields(&$params) {
    $fields = $this->mapToEwayDirect($params);
    // See https://eway.io/api-v3/#payment-methods - if storing a token it would
    // be TokenPayment.
    $fields['Method'] = 'ProcessPayment';

    // Allow further manipulation of the arguments via custom hooks ..
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $fields);

    // Check for a duplicate after the hook has been called.
    if ($this->checkDupe($params['invoiceID'], CRM_Utils_Array::value('contributionID', $params))) {
      throw new CRM_Core_Exception('It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem.  Check your email for a receipts.  If you do not receive a receipt within 2 hours you can try your transaction again.  If you continue to have problems please contact the site administrator.');
    }
    return $fields;
  }

  /**
   * Map parameters to the eway Direct fields.
   *
   * This mapping applies to token & single IF they are on the new url end_points
   *
   * https://eway.io/api-v3/#direct-connection
   *
   * @param array $params
   *
   * @return array
   */
  protected function mapToEwayDirect($params) {
    $ewayFields = array(
      'Customer' => array(
        'Reference' => $params['contactID'],
        'FirstName' => $params['first_name'],
        'LastName' => $params['last_name'],
        'PostalCode' => ($params['postal_code']),
        'Email' => CRM_Utils_Array::value('email', $params),
        'Country' => strtolower($params['country']),
        'City' => $params['city'],
        'Street1' => $params['street_address'],
        'Street2' => !empty($params['supplemental_address_1']) ? $params['supplemental_address_1'] : '',
        'State' => $params['state_province'],
        'CardDetails' => array(
          'ExpiryMonth' => ($this->getCreditCardExpiryMonth($params)),
          'ExpiryYear' => $this->getCreditCardExpiryYear($params),
          'Name' => $this->getCreditCardName($params),
          'Number' => $params['credit_card_number'],
          'CVN' => $params['cvv2'],
        ),
      ),
      'Payment' => array(
        'TotalAmount' => $this->getAmountInCents($params),
        'InvoiceNumber' => $params['invoiceID'],
        'InvoiceDescription' => $this->getPaymentDescription($params, 64),
        'InvoiceReference' => $params['invoiceID'],
      ),
      'CustomerIP' => $params['ip_address'],
      'DeviceID' => 'civicrm',
      'TransactionType' => 'Purchase',
  );
    return $ewayFields;
  }

  /**
   * Process a one-off payment and return result or throw exception.
   *
   * @param $params
   *
   * @return array
   *   Result of payment.
   * @throws \CRM_Core_Exception
   */
  protected function processSinglePayment(&$params) {
    $eWAYRequestFields = $this->getEwayRequestFields($params);

    if ($this->getDummySuccessResult()) {
      return $this->getDummySuccessResult();
    };

    $responseData = $this->callEwayGateway($eWAYRequestFields);
    //----------------------------------------------------------------------------------------------------
    // Payment successfully sent to gateway - process the response now
    //----------------------------------------------------------------------------------------------------
    $eWAYResponse = json_decode($responseData);

    if (self::isError($eWAYResponse)) {
      throw new CRM_Core_Exception(ts("Transaction failure (%1)", array(1 => $eWAYResponse->Errors)));
    }

    $status = ($eWAYResponse->BeagleScore) ? ($eWAYResponse->ResponseMessage . ': ' . $eWAYResponse->BeagleScore) : $eWAYResponse->ResponseMessage;
    $result = array(
      'trxn_id' => $eWAYResponse->TransactionID,
      'trxn_result_code' => $status,
      'payment_status_id' => 1,
    );
    return $result;
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   *
   * Only those determined by getPaymentFormFields will actually be assigned to the form.
   *
   * This has been overridden in order that browser-encrypted cvv field will not be subject to core validation.
   * @return array
   *   field metadata
   */
  public function getPaymentFormFieldsMetadata() {
    $metadata = parent::getPaymentFormFieldsMetadata();
    if (!empty($this->_paymentProcessor['signature'])) {
      unset($metadata['cvv2']['rules']);
    }
    return $metadata;
  }

  /**
   * Validate Payment instrument validation.
   *
   * @param array $values
   * @param array $errors
   */
  public function validatePaymentInstrument($values, &$errors) {
    if (empty($this->_paymentProcessor['signature'])) {
      parent::validatePaymentInstrument($values, $errors);
    }
    else {
      foreach (array('credit_card_number', ) as $field) {
        if (substr($values[$field], 0, 9) != 'eCrypted:') {
          $errors[$field] = ts('Invalid encrypted value');
        }
      }
    }
  }

  /**
   * If the site is in developer mode then early exit with mock success.
   *
   * @return array|bool
   * @throws \CiviCRM_API3_Exception
   */
  protected function getDummySuccessResult() {
    // If the site is in developer mode we return a mock success.
    if (civicrm_api3('setting', 'getvalue', array(
      'group' => 'eway',
      'name' => 'eway_developer_mode'
    ))) {
      return array(
        'trxn_id' => uniqid(),
        'trxn_result_code' => TRUE,
      );
    }
    return FALSE;
  }

  /**
   * Get description of payment to pass to processor.
   *
   * This is often what people see in the interface so we want to get
   * as much unique information in as possible within the field length (& presumably the early part of the field)
   *
   * People seeing these can be assumed to be advanced users so quantity of information probably trumps
   * having field names to clarify
   *
   * @param array $params
   * @param int $length
   *
   * @return string
   */
  protected function getPaymentDescription($params, $length = 24) {
    $parts = array('contactID', 'contributionID', 'description', 'billing_first_name', 'billing_last_name');
    $validParts = array();
    if (isset($params['description'])) {
      $uninformativeStrings = array(ts('Online Event Registration: '), ts('Online Contribution: '));
      $params['description'] = str_replace($uninformativeStrings, '', $params['description']);
    }
    foreach ($parts as $part) {
      if ((!empty($params[$part]))) {
        $validParts[] = $params[$part];
      }
    }
    return substr(implode('-', $validParts), 0, $length);
  }

}
