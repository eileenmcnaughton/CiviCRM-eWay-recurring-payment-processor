<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM                                                            |
 +--------------------------------------------------------------------+
 | Copyright Henare Degan (C) 2012                                    |
 +--------------------------------------------------------------------+
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007.                                       |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License along with this program; if not, contact CiviCRM LLC       |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/
/**

 * eWay API call
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_job_eway($params) {
  require_once 'nusoap.php';

  $apiResult = array();

  // Get contribution status values
  $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');

  // Create eWay token clients
  $eway_token_clients = get_eway_token_clients($params['domain_id']);

  // Get any pending contributions and process them
  $pending_contributions = get_pending_recurring_contributions($eway_token_clients);

  $apiResult[] = "Processing " . count($pending_contributions) . " pending contributions";
  foreach ($pending_contributions as $pending_contribution) {
    $apiResult = array_merge($apiResult, _civicrm_api3_job_eway_process_contribution($eway_token_clients, $pending_contribution));
  }

  // Process today's scheduled contributions and process them
  $scheduled_contributions = get_scheduled_contributions($eway_token_clients);

  $apiResult[] = "Processing " . count($scheduled_contributions) . " scheduled contributions";
  foreach ($scheduled_contributions as $scheduled_contribution) {
    $apiResult = array_merge($apiResult, _civicrm_api3_job_eway_process_contribution($eway_token_clients, $scheduled_contribution));
  }

  return civicrm_api3_create_success($apiResult, $params);
}

/**
 *
 */
function _civicrm_api3_job_eway_process_contribution($eway_token_clients, $instance) {
  $apiResult = array();

  // Process the payment
  $apiResult[] = "Processing payment for " . $instance['type'] . " contribution ID: " . $instance['contribution']->id;
  $amount_in_cents = str_replace('.', '', $instance['contribution']->total_amount);

  $result = process_eway_payment(
    $eway_token_clients[$instance['contribution_recur']->payment_processor_id],
    $instance['contribution_recur']->processor_id, $amount_in_cents,
    $instance['contribution']->invoice_id, $instance['contribution']->source
  );

  // Process the contribution as either Completed or Failed
  if ($result['ewayTrxnStatus'] == 'True') {
    $apiResult[] = "Successfully processed payment for " . $instance['type'] . " contribution ID: " . $instance['contribution']->id;
    $apiResult[] = "Marking contribution as complete";
    $instance['contribution']->trxn_id = $result['ewayTrxnNumber'];
    complete_contribution($instance['contribution']);
  } else {
    $apiResult[] = "ERROR: failed to process payment for " . $instance['type'] . " contribution ID: " . $instance['contribution']->id;
    $apiResult[] = 'eWAY managed customer: ' . $instance['contribution_recur']->processor_id;
    $apiResult[] = 'eWAY response: ' . $result['faultstring'];
    $apiResult[] = "Marking contribution as failed";
    fail_contribution($instance['contribution']);
    $instance['contribution_recur']->failure_count += 1;
  }

  // Update the recurring transaction
  $apiResult[] = "Updating recurring contribution";
  update_recurring_contribution($instance['contribution_recur']);
  $apiResult[] = "Finished processing contribution ID: " . $instance['contribution']->id;

  return $apiResult;
}

/**
 * alter metadata
 * @param $params
 */
function _civicrm_api3_job_eway_spec(&$params) {
  $params['domain_id']['api.default'] = CRM_Core_Config::domainID();
  $params['domain_id']['type'] = CRM_Utils_Type::T_INT;
  $params['domain_id']['title'] = ts('Domain');
}

/**
 * get_eWay_token_clients
 *
 * Find the eWAY recurring payment processors
 *
 * @param $domainID
 *
 * @throws CiviCRM_API3_Exception
 * @return array An associative array of Processor Id => eWAY Token Client
 */
function get_eway_token_clients($domainID) {
  $params = array(
    'class_name' => 'Payment_Ewayrecurring'
  );
  if (!empty($domainID)) {
    $params['domain_id'] = $domainID;
  }

  $processors = civicrm_api3('payment_processor', 'get', $params);
  $result = array();
  foreach ($processors['values'] as $id => $processor) {
    $result[$id] = eway_token_client($processor['url_recur'], $processor['subject'], $processor['user_name'], $processor['password']);
  }
  return $result;
}

/**
 * get_first_contribution_from_recurring
 *
 * find the latest contribution belonging to the recurring contribution so that we
 * can extract some info for cloning, like source etc
 *
 * @param int $recur_id
 *
 * @return CRM_Contribute_BAO_Contribution contribution object
 */
function get_first_contribution_from_recurring($recur_id) {
  $contributions = new CRM_Contribute_BAO_Contribution();
  $contributions->whereAdd("`contribution_recur_id` = " . $recur_id);
  $contributions->orderBy("`id`");
  $contributions->find();

  while ( $contributions->fetch() ) {
    return clone ($contributions);
  }
}

/**
 * get_pending_recurring_contributions
 *
 * Gets recurring contributions that are in a pending state.
 * These are for newly created recurring contributions and should
 * generally be processed the same day they're created. These do not
 * include the regularly processed recurring transactions.
 *
 * @param $eway_token_clients
 *
 * @return array An array of associative arrays containing contribution & contribution_recur objects
 */
function get_pending_recurring_contributions($eway_token_clients) {
  if (empty($eway_token_clients)) {
    return array();
  }

  $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');

  // Get the Recurring Contributions that are Pending for this Payment Processor
  $recurring = new CRM_Contribute_BAO_ContributionRecur();
  $recurring->whereAdd("`contribution_status_id` = " . array_search('Pending', $contributionStatus));
  $recurring->whereAdd("`payment_processor_id` in (" . implode(', ', array_keys($eway_token_clients)) . ")");
  $recurring->find();

  $result = array();

  while ( $recurring->fetch() ) {
    // Get the Contribution
    $contribution = new CRM_Contribute_BAO_Contribution();
    $contribution->whereAdd("`contribution_recur_id` = " . $recurring->id);

    if ($contribution->find(true)) {
      $result[] = array(
        'type' => 'Pending',
        'contribution' => clone ($contribution),
        'contribution_recur' => clone ($recurring)
      );
    }
  }
  return $result;
}

/**
 * get_scheduled_contributions
 *
 * Gets recurring contributions that are scheduled to be processed today
 *
 * @param $eway_token_clients
 *
 * @return array An array of contribution_recur objects
 */
function get_scheduled_contributions($eway_token_clients) {
  if (empty($eway_token_clients)) {
    return array();
  }

  $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');

  // Get Recurring Contributions that are In Progress and are due to be processed by the eWAY Recurring processor
  $scheduled_today = new CRM_Contribute_BAO_ContributionRecur();
  if (_versionAtLeast(4.4)) {
    $scheduled_today->whereAdd("`next_sched_contribution_date` <= '" . date('Y-m-d 00:00:00') . "'");
  }
  else {
    $scheduled_today->whereAdd("`next_sched_contribution` <= '" . date('Y-m-d 00:00:00') . "'");
  }
  $scheduled_today->whereAdd("`contribution_status_id` = " . array_search('In Progress', $contributionStatus));
  $scheduled_today->whereAdd("`payment_processor_id` in (" . implode(', ', array_keys($eway_token_clients)) . ")");
  $scheduled_today->find();

  $result = array();

  while ( $scheduled_today->fetch() ) {
    $past_contribution = get_first_contribution_from_recurring($scheduled_today->id);

    $new_contribution_record = new CRM_Contribute_BAO_Contribution();
    $new_contribution_record->contact_id = $scheduled_today->contact_id;
    $new_contribution_record->receive_date = CRM_Utils_Date::isoToMysql(date('Y-m-d H:i:s'));
    $new_contribution_record->total_amount = $scheduled_today->amount;
    $new_contribution_record->non_deductible_amount = $scheduled_today->amount;
    $new_contribution_record->net_amount = $scheduled_today->amount;
    $new_contribution_record->invoice_id = md5(uniqid(rand(), TRUE));
    $new_contribution_record->contribution_recur_id = $scheduled_today->id;
    $new_contribution_record->contribution_status_id = array_search('Pending', $contributionStatus);
    if (_versionAtLeast(4.4)) {
      $new_contribution_record->financial_type_id = $scheduled_today->financial_type_id;
    }
    else {
      $new_contribution_record->contribution_type_id = $scheduled_today->contribution_type_id;
    }
    $new_contribution_record->currency = $scheduled_today->currency;

    // copy info from previous contribution belonging to the same recurring contribution
    if ($past_contribution != null) {
      $new_contribution_record->contribution_page_id = $past_contribution->contribution_page_id;
      $new_contribution_record->payment_instrument_id = $past_contribution->payment_instrument_id;
      $new_contribution_record->source = $past_contribution->source;
      $new_contribution_record->address_id = $past_contribution->address_id;
    }
    $new_contribution_record->save();

    $result[] = array(
      'type' => 'Scheduled',
      'contribution' => clone ($new_contribution_record),
      'contribution_recur' => clone ($scheduled_today)
    );
  }

  return $result;
}

/**
 * eway_token_client
 *
 * Creates an eWay SOAP client to the eWay token API
 *
 * @param string $gateway_url
 *          URL of the gateway to connect to (could be the test or live gateway)
 * @param string $eway_customer_id
 *          Your eWay customer ID
 * @param string $username
 *          Your eWay business centre username
 * @param string $password
 *          Your eWay business centre password
 * @return object A SOAP client to the eWay token API
 */
function eway_token_client($gateway_url, $eway_customer_id, $username, $password) {
  // Set up SOAP client
  $soap_client = new nusoap_client($gateway_url, false);
  $soap_client->namespaces['man'] = 'https://www.eway.com.au/gateway/managedpayment';

  // Set up SOAP headers
  $headers = "<man:eWAYHeader><man:eWAYCustomerID>" . $eway_customer_id . "</man:eWAYCustomerID><man:Username>" . $username . "</man:Username><man:Password>" . $password . "</man:Password></man:eWAYHeader>";
  $soap_client->setHeaders($headers);

  return $soap_client;
}

/**
 * process_eWay_payment
 *
 * Processes an eWay token payment
 *
 * @param object $soap_client
 *          An eWay SOAP client set up and ready to go
 * @param string $managed_customer_id
 *          The eWay token ID for the credit card you want to process
 * @param string $amount_in_cents
 *          The amount in cents to charge the customer
 * @param string $invoice_reference
 *          InvoiceReference to send to eWay
 * @param string $invoice_description
 *          InvoiceDescription to send to eWay
 * @throws SoapFault exceptions
 * @return array eWay response
 */
function process_eway_payment($soap_client, $managed_customer_id, $amount_in_cents, $invoice_reference, $invoice_description) {
  // PHP bug: https://bugs.php.net/bug.php?id=49669. issue with value greater than 2147483647.
  settype($managed_customer_id, "float");

  $paymentinfo = array(
    'man:managedCustomerID' => $managed_customer_id,
    'man:amount' => $amount_in_cents,
    'man:InvoiceReference' => $invoice_reference,
    'man:InvoiceDescription' => $invoice_description
  );
  $soapaction = 'https://www.eway.com.au/gateway/managedpayment/ProcessPayment';

  $result = $soap_client->call('man:ProcessPayment', $paymentinfo, '', $soapaction);

  return $result;
}

/**
 * complete_contribution
 *
 * Marks a contribution as complete
 *
 * @param CRM_Contribute_BAO_Contribution $contribution
 *          The contribution to mark as complete
 * @return CRM_Contribute_BAO_Contribution The contribution object
 */
function complete_contribution($contribution) {
  civicrm_api3('contribution', 'completetransaction', array(
    'id' => $contribution->id,
    'trxn_id' => $contribution->trxn_id
  ));
  return $contribution;
}

/**
 * fail_contribution
 *
 * Marks a contribution as failed
 *
 * @param CRM_Contribute_BAO_Contribution $contribution
 *          The contribution to mark as failed
 * @return CRM_Contribute_BAO_Contribution The contribution object
 */
function fail_contribution($contribution) {
  $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');

  $failed = new CRM_Contribute_BAO_Contribution();
  $failed->id = $contribution->id;
  $failed->find(true);
  $failed->contribution_status_id = array_search('Failed', $contributionStatus);
  $failed->receive_date = CRM_Utils_Date::isoToMysql(date('Y-m-d H:i:s'));
  $failed->save();

  return $failed;
}

/**
 * update_recurring_contribution
 *
 * Updates the recurring contribution
 *
 * @param object $current_recur
 *          The ID of the recurring contribution
 * @return object The recurring contribution object
 */
function update_recurring_contribution($current_recur) {
  $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');

  /*
   * Creating a new recurrence object as the DAO had problems saving unless all the dates were overwritten. Seems easier to create a new object and only update the fields that are needed @todo - switching to using the api would solve the DAO dates problem & api accepts 'In Progress' so no need to resolve it first.
   */
  $updated_recur = new CRM_Contribute_BAO_ContributionRecur();
  $updated_recur->id = $current_recur->id;
  $updated_recur->contribution_status_id = array_search('In Progress', $contributionStatus);
  $updated_recur->modified_date = CRM_Utils_Date::isoToMysql(date('Y-m-d H:i:s'));
  $updated_recur->failure_count = $current_recur->failure_count;

  /*
   * Update the next date to schedule a contribution. If all installments complete, mark the recurring contribution as complete
   */
  if (_versionAtLeast(4.4)) {
    $updated_recur->next_sched_contribution_date = CRM_Utils_Date::isoToMysql(date('Y-m-d 00:00:00', strtotime('+' . $current_recur->frequency_interval . ' ' . $current_recur->frequency_unit)));
  }
  else {
    $updated_recur->next_sched_contribution = CRM_Utils_Date::isoToMysql(date('Y-m-d 00:00:00', strtotime('+' . $current_recur->frequency_interval . ' ' . $current_recur->frequency_unit)));
  }
  if (isset($current_recur->installments) && $current_recur->installments > 0) {
    $contributions = new CRM_Contribute_BAO_Contribution();
    $contributions->whereAdd("`contribution_recur_id` = " . $current_recur->id);
    $contributions->find();
    if ($contributions->N >= $current_recur->installments) {
      if (_versionAtLeast(4.4)) {
        $updated_recur->next_sched_contribution_date = null;
      }
      else {
        $updated_recur->next_sched_contribution = null;
      }
      $updated_recur->contribution_status_id = array_search('Completed', $contributionStatus);
      $updated_recur->end_date = CRM_Utils_Date::isoToMysql(date('Y-m-d 00:00:00'));
    }
  }

  return $updated_recur->save();
}

/**
 * send_receipt_email
 *
 * Sends a receipt for a contribution
 *
 * @param string $contribution_id
 *          The ID of the contribution to mark as complete
 * @return bool Success or failure
 */
function send_receipt_email($contribution_id) {
  civicrm_api3('contribution', 'sendconfirmation', array('id' => $contribution_id));
}

/**
 * Version agnostic receipt sending function
 *
 * @param
 *          params
 */
function _sendReceipt($params) {
  if (_versionAtLeast(4.4)) {
    list($sent) = CRM_Core_BAO_MessageTemplate::sendTemplate($params);
  }
  else {
    list($sent) = CRM_Core_BAO_MessageTemplates::sendTemplate($params);
  }
  return $sent;
}

/**
 * is version of at least the version provided
 *
 * @param number $version
 * @return boolean
 */
function _versionAtLeast($version) {
  $codeVersion = explode('.', CRM_Utils_System::version());
  if (version_compare($codeVersion[0] . '.' . $codeVersion[1], $version) >= 0) {
    return TRUE;
  }
  return FALSE;
}
