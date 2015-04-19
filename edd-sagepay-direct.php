<?php
/**
 * Plugin Name: SagePay Direct Gateway for Easy Digital Downloads
 * Plugin URI: http://www.patsatech.com/
 * Description: Easy Digital Downloads Plugin for accepting payment through SagePay Direct.
 * Version: 1.0.0
 * Author: PatSaTECH
 * Author URI: http://www.patsatech.com
 * Contributors: patsatech
 * Requires at least: 3.5
 * Tested up to: 4.1
 *
 * Text Domain: sagepay_direct_patsatech
 * Domain Path: /lang/
 *
 * @package  SagePay Direct Gateway for Easy Digital Downloads
 * @author PatSaTECH
 */

// registers the gateway
function sagepay_direct_register_gateway($gateways) {
	$gateways['sagepay_direct'] = array('admin_label' => 'SagePay Direct', 'checkout_label' => __('Credit Card', 'sagepay_direct_patsatech'));
	return $gateways;
}
add_filter('edd_payment_gateways', 'sagepay_direct_register_gateway');

function edd_sagepay_direct_remove_cc_form() {
    global $edd_options;

	$cardtypes = array(
					'MC' => 'MasterCard',
					'VISA' => 'VISA Credit',
					'DELTA' => 'VISA Debit',
					'UKE' => 'VISA Electron',
					'MAESTRO' => 'Maestro (Switch)',
					'AMEX' => 'American Express',
					'DC' => 'Diner\'s Club',
					'JCB' => 'JCB Card',
					'LASER' => 'Laser'
					);			
					
	ob_start(); ?>
	<p id="edd-card-type-wrap">
		<label for="card_type" class="edd-label">Card Type <span class="edd-required-indicator">*</span></label>
		<span class="edd-description">Select the type of card.</span>
		<select name="card_type" class="edd-select required">
			<?php
			
				foreach ($edd_options['sagepay_direct_cardtypes'] as $name => $value ){
				?>
					<option value="<?php echo $name; ?>"><?php echo $value; ?></option>
				<?php	
				}
			?>
		</select>
	</p>
	<?php
		
	echo ob_get_clean();
}
add_action( 'edd_before_cc_expiration', 'edd_sagepay_direct_remove_cc_form' );

// processes the payment
function sagepay_direct_process_payment($purchase_data) {
    global $edd_options;
    
    // check there is a gateway name
    if ( ! isset( $purchase_data['post_data']['edd-gateway'] ) )
    return;
    
    // collect payment data
    $payment_data = array( 
        'price'         => $purchase_data['price'], 
        'date'          => $purchase_data['date'], 
        'user_email'    => $purchase_data['user_email'], 
        'purchase_key'  => $purchase_data['purchase_key'], 
        'currency'      => edd_get_currency(), 
        'downloads'     => $purchase_data['downloads'], 
        'user_info'     => $purchase_data['user_info'], 
        'cart_details'  => $purchase_data['cart_details'], 
        'gateway'       => 'sagepay_direct',
        'status'        => 'pending'
     );
        
	$required = array(
    				'edd_first'     => __( 'First Name is not entered.', 'sagepay_direct_patsatech' ),
                    'edd_last'		=> __( 'Last Name is not entered.', 'sagepay_direct_patsatech' ),
					'card_cvc' 		=> __( 'Card CVV is not entered.', 'sagepay_direct_patsatech' ),
					'card_name' 	=> __( 'Card Holder Name is not entered.', 'sagepay_direct_patsatech' ),
                    'card_address'	=> __( 'Billing Address is not entered.', 'sagepay_direct_patsatech' ),
                    'card_city' 	=> __( 'Billing City is not entered.', 'sagepay_direct_patsatech' ),
                    'card_zip'      => __( 'Billing Zip / Postal Code is not entered.', 'sagepay_direct_patsatech' )
                    );

	foreach( $required as $field => $error ) {
    	if( ! $purchase_data['post_data'][$field] ) {
        	edd_set_error( 'billing_error', $error );
		}
	}
    
    if (!sagepay_direct_is_credit_card_number($purchase_data['post_data']['card_number'])){
		edd_set_error( 'invalid_card_number', __('Credit Card Number is not valid.', 'sagepay_direct_patsatech') );
	}
		
    if (!sagepay_direct_is_correct_expire_date($purchase_data['post_data']['card_exp_month'], $purchase_data['post_data']['card_exp_year'])){
		edd_set_error( 'invalid_card_expiry', __('Card Expire Date is not valid.', 'sagepay_direct_patsatech') );
	}
    
	$errors = edd_get_errors();
	
	
	if ( $errors ) {
        // problems? send back
		edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
    }else{
	
	    // record the pending payment
    	$payment = edd_insert_payment( $payment_data );
		
	    // check payment
	    if ( !$payment ) {
	        // problems? send back
			edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
	    } else {
	        
	        $time_stamp = date("ymdHis");
	        $orderid = $edd_options['sagepay_direct_vendor_name'] . "-" . $time_stamp . "-" . $payment;
			
	        $sp_arg['ReferrerID'] 			= 'CC923B06-40D5-4713-85C1-700D690550BF';
	        $sp_arg['Amount'] 				= $purchase_data['price'];
			$sp_arg['CustomerName']			= substr($purchase_data['post_data']['edd_first'].' '.$purchase_data['post_data']['edd_last'], 0, 100);
	        $sp_arg['CustomerEMail'] 		= substr($purchase_data['post_data']['edd_email'], 0, 255);
	        $sp_arg['BillingSurname'] 		= substr($purchase_data['post_data']['edd_last'], 0, 20);
	        $sp_arg['BillingFirstnames'] 	= substr($purchase_data['post_data']['edd_first'], 0, 20);
	        $sp_arg['BillingAddress1'] 		= substr($purchase_data['post_data']['card_address'], 0, 100);
	        $sp_arg['BillingAddress2'] 		= substr($purchase_data['post_data']['card_address_2'], 0, 100);
	        $sp_arg['BillingCity'] 			= substr($purchase_data['post_data']['card_city'], 0, 40);
			if( $purchase_data['post_data']['billing_country'] == 'US' ){
	        	$sp_arg['BillingState'] 		= $purchase_data['post_data']['card_state'];
			}else{
	        	$sp_arg['BillingState'] 		= '';
			}
	        $sp_arg['BillingPostCode'] 		= substr($purchase_data['post_data']['card_zip'], 0, 10);
	        $sp_arg['BillingCountry'] 		= $purchase_data['post_data']['billing_country'];
	        //$sp_arg['BillingPhone'] 		= substr($purchase_data['post_data']['edd_phone'], 0, 20);
	        $sp_arg['DeliverySurname'] 		= substr($purchase_data['post_data']['edd_last'], 0, 20);
	        $sp_arg['DeliveryFirstnames'] 	= substr($purchase_data['post_data']['edd_first'], 0, 20);
	        $sp_arg['DeliveryAddress1'] 	= substr($purchase_data['post_data']['card_address'], 0, 100);
	        $sp_arg['DeliveryAddress2'] 	= substr($purchase_data['post_data']['card_address_2'], 0, 100);
	        $sp_arg['DeliveryCity'] 		= substr($purchase_data['post_data']['card_city'], 0, 40);
			if( $purchase_data['post_data']['billing_country'] == 'US' ){
	        	$sp_arg['DeliveryState'] 	= $purchase_data['post_data']['card_state'];
			}else{
	        	$sp_arg['DeliveryState'] 	= '';
			}
	        $sp_arg['DeliveryPostCode'] 	= substr($purchase_data['post_data']['card_zip'], 0, 10);
	        $sp_arg['DeliveryCountry'] 		= $purchase_data['post_data']['billing_country'];
	        //$sp_arg['DeliveryPhone'] 		= substr($purchase_data['post_data']['edd_phone'], 0, 20);
	        $sp_arg['CardHolder'] 			= $purchase_data['post_data']['card_name'];
	        $sp_arg['CardNumber'] 			= $purchase_data['post_data']['card_number'];
	        $sp_arg['StartDate'] 			= '';
	        $sp_arg['ExpiryDate'] 			= sprintf("%02d", $purchase_data['post_data']['card_exp_month']).date("y", strtotime("01/01/".$purchase_data['post_data']['card_exp_year']));
	        $sp_arg['CV2'] 					= $purchase_data['post_data']['card_cvc'];
	        $sp_arg['CardType'] 			= $purchase_data['post_data']['card_type'];
	        $sp_arg['VPSProtocol'] 			= "3.00";
	        $sp_arg['Vendor'] 				= $edd_options['sagepay_direct_vendor_name'];
	        $sp_arg['Description'] 			= sprintf(__('Order #%s' , 'sagepay_direct_patsatech'), $payment);
	        $sp_arg['Currency'] 			= edd_get_currency();
	        $sp_arg['TxType'] 				= $edd_options['sagepay_direct_transtype'];
	        $sp_arg['VendorTxCode'] 		= $orderid; 
			
	        $post_values = "";
	        foreach( $sp_arg as $key => $value ) {
	            $post_values .= "$key=" . urlencode( $value ) . "&";
	        }
	        $post_values = rtrim( $post_values, "& " );
			
			if( $edd_options['sagepay_direct_mode'] == 'test' ){
				$gateway_url = 'https://test.sagepay.com/gateway/service/vspdirect-register.vsp';
			}else if( $edd_options['sagepay_direct_mode'] == 'live' ){
				$gateway_url = 'https://live.sagepay.com/gateway/service/vspdirect-register.vsp';
			}
	        
	        $response = wp_remote_post($gateway_url, array( 
															'body' => $post_values,
															'method' => 'POST',
															'sslverify' => FALSE
															));
			
			EDD()->session->set('sagepay_vtc', $orderid);
			EDD()->session->set('sagepay_oid', $payment);
			
			if (!is_wp_error($response) && $response['response']['code'] >= 200 && $response['response']['code'] < 300 ) {
				
		        $resp = array();
		        
		        $lines = preg_split( '/\r\n|\r|\n/', $response['body'] );
		        foreach($lines as $line){
	                $key_value = preg_split( '/=/', $line, 2 );
	                if(count($key_value) > 1)
	                    $resp[trim($key_value[0])] = trim($key_value[1]);
		        }
		        
				if ( $resp['Status'] == "OK" || $resp['Status'] == "REGISTERED" || $resp['Status'] == "AUTHENTICATED" ) {
					
					edd_update_payment_status($payment, 'publish');
					edd_set_payment_transaction_id( $payment, $resp['VPSTxId'] );
					edd_empty_cart();
					edd_send_to_success_page();
					
				}else if($resp['Status'] == "3DAUTH"){
					
					if($resp['3DSecureStatus'] == 'OK'){
						
						if( isset($resp['ACSURL']) && isset($resp['MD']) ){
							
					        $array = array(
					        				'PaReq' => $resp['PAReq'],
					        				'MD' => $resp['MD'],
					        				'TermUrl' => trailingslashit(home_url()).'?sagepay_direct=ipn'
					        				);
					        
							$sagepay_arg_array = array();
							
							foreach ($array as $key => $value) {
								$sagepay_arg_array[] = '<input type="hidden" name="'.esc_attr( $key ).'" value="'.esc_attr( $value ).'" />';
							}
							
							echo '<form action="'.$resp['ACSURL'].'" method="post" name="sagepay_direct_3dsecure_form" >
									' . implode('', $sagepay_arg_array) . '
									</form>
									<b> Please wait while you are being redirected.</b>
									<script type="text/javascript" event="onload">
											document.sagepay_direct_3dsecure_form.submit();
									</script>';
						}
					}
				
				}else{
					if(isset($resp['StatusDetail'])){
						
						edd_set_error( 'error_tranasction_failed', __('Transaction Failed. '.$resp['StatusDetail'], 'sagepay_direct_patsatech'));
						
						edd_send_back_to_checkout('?payment-mode=sagepay_direct');
						
					}else{
						
						edd_set_error( 'error_tranasction_failed', __('Transaction Failed with '.$resp['Status'].' status for Unknown Reason.', 'sagepay_direct_patsatech'));
						
						edd_send_back_to_checkout('?payment-mode=sagepay_direct');
					}
				}
			
			}else{
				
				edd_set_error( 'error_tranasction_failed', __('Gateway Error. Please Notify the Store Owner about this error.', 'sagepay_direct_patsatech'));
				
				edd_send_back_to_checkout('?payment-mode=sagepay_direct');
			}
			
	    }
		
	}
	
}
add_action('edd_gateway_sagepay_direct', 'sagepay_direct_process_payment');

function sagepay_direct_ipn() {
	global $edd_options;
	
	if ( isset($_REQUEST['MD']) && isset($_REQUEST['PaRes']) && $_GET['sagepay_direct'] == 'ipn' ){
	
		$request_array = array(
								'MD' => $_REQUEST['MD'],
								'PARes' => $_REQUEST['PaRes'],
								'VendorTxCode' => EDD()->session->get('sagepay_vtc'),
								);
					
		$request = http_build_query($request_array);
		
		if( $edd_options['sagepay_direct_mode'] == 'test' ){
			$gateway_url = 'https://test.sagepay.com/gateway/service/direct3dcallback.vsp';
		}else if( $edd_options['sagepay_direct_mode'] == 'live' ){
			$gateway_url = 'https://live.sagepay.com/gateway/service/direct3dcallback.vsp';
		}
		
		$response = wp_remote_post( $gateway_url, array( 
										                'body' => $request,
										                'method' => 'POST',
										            	'sslverify' => false
										            	) );
		
		if (!is_wp_error($response) && $response['response']['code'] >= 200 && $response['response']['code'] < 300 ) { 
			
			$resp = array();   
			
			$lines = preg_split( '/\r\n|\r|\n/', $response['body'] );
			foreach($lines as $line){            
				$key_value = preg_split( '/=/', $line, 2 );
				if(count($key_value) > 1)
					$resp[trim($key_value[0])] = trim($key_value[1]);
				}
				
				if ( $resp['Status'] == "OK" || $resp['Status'] == "REGISTERED" || $resp['Status'] == "AUTHENTICATED" ) {
					edd_update_payment_status(EDD()->session->get('sagepay_oid'), 'publish');
					edd_set_payment_transaction_id( $payment, $resp['VPSTxId'] );
					edd_empty_cart();
					edd_send_to_success_page();	
				}else if($resp['Status'] == "3DAUTH"){
				
				if($resp['3DSecureStatus'] == 'OK'){
				
					if( isset($resp['ACSURL']) && isset($resp['MD']) ){
					
						$array = array(
										'PaReq' => $resp['PAReq'],
							        	'MD' => $resp['MD'],
							        	'TermUrl' => trailingslashit(home_url()).'?sagepay_direct=ipn'
										);
						
						$sagepay_arg_array = array();
									
						foreach ($array as $key => $value) {
							$sagepay_arg_array[] = '<input type="hidden" name="'.esc_attr( $key ).'" value="'.esc_attr( $value ).'" />';
						}
						
						echo '<form action="'.$resp['ACSURL'].'" method="post" name="sagepay_direct_3dsecure_form" >
								' . implode('', $sagepay_arg_array) . '
							</form>		
							<b> Please wait while you are being redirected.</b>			
							<script type="text/javascript" event="onload">
								ocument.sagepay_direct_3dsecure_form.submit();
							</script>';
							
					}
				}
				
			}else{
				if(isset($resp['StatusDetail'])){
							
					edd_set_error( 'error_tranasction_failed', __('Transaction Failed. '.$resp['StatusDetail'], 'sagepay_direct_patsatech'));
								
					edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
				}else{
							
					edd_set_error( 'error_tranasction_failed', __('Transaction Failed with '.$resp['Status'].' status for Unknown Reason.', 'sagepay_direct_patsatech'));
								
					edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
				}
			}
						
		}else{
					
			edd_set_error( 'error_tranasction_failed', __('Gateway Error. Please Notify the Store Owner about this error.', 'sagepay_direct_patsatech'));
								
			edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
						
		}
				
	}
	
}
add_action( 'init', 'sagepay_direct_ipn' );
 
function sagepay_direct_is_correct_expire_date($month, $year)
{
	$now       = time();
    $result    = false;
    $thisYear  = (int)date('y', $now);
    $thisMonth = (int)date('m', $now);

    if (is_numeric($year) && is_numeric($month))
    {
    	if($thisYear == (int)$year)
	    {
	    	$result = (int)$month >= $thisMonth;
		}			
		else if($thisYear < (int)$year)
		{
			$result = true;
		}
    }

	return $result;
}	

function sagepay_direct_is_credit_card_number($toCheck){
	if (!is_numeric($toCheck))
    	return false;

	$number = preg_replace('/[^0-9]+/', '', $toCheck);
    $strlen = strlen($number);
    $sum    = 0;

    if ($strlen < 13)
    	return false;

	for ($i=0; $i < $strlen; $i++)
    {
    	$digit = substr($number, $strlen - $i - 1, 1);
        if($i % 2 == 1)
        {
        	$sub_total = $digit * 2;
            if($sub_total > 9)
            {
            	$sub_total = 1 + ($sub_total - 10);
			}
		}
        else
        {
        	$sub_total = $digit;
		}
        $sum += $sub_total;
	}

    if ($sum > 0 AND $sum % 10 == 0)
    	return true;

	return false;
}
 
function sagepay_direct_add_settings($settings) {
 
	$sagepay_direct_settings = array(
		array(
			'id' => 'sagepay_direct_settings',
			'name' => '<strong>' . __('SagePay Direct Settings', 'sagepay_direct_patsatech') . '</strong>',
			'desc' => __('Configure the gateway settings', 'sagepay_direct_patsatech'),
			'type' => 'header'
		),
		array(
			'id' => 'sagepay_direct_vendor_name',
			'name' => __('Vendor Name', 'sagepay_direct_patsatech'),
			'desc' => __('Please enter your vendor name provided by SagePay.', 'sagepay_direct_patsatech'),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'sagepay_direct_mode',
			'name' => __('Mode Type', 'sagepay_direct_patsatech'),
			'desc' => __('Select Simulator, Test or Live modes.', 'sagepay_direct_patsatech'),
			'type' => 'select',
			'options' => array( 
								'test' => 'Test',
								'live' => 'Live'
								),
			'size' => 'regular'
		),
		array(
			'id' => 'sagepay_direct_transtype',
			'name' => __('Transition Type', 'sagepay_direct_patsatech'),
			'desc' => __('Select Payment, Deferred or Authenticated.', 'sagepay_direct_patsatech'),
			'type' => 'select',
			'options' => array(
								'PAYMENT' => __('Payment', 'sagepay_direct_patsatech'), 
								'DEFFERRED' => __('Deferred', 'sagepay_direct_patsatech'), 
								'AUTHENTICATE' => __('Authenticate', 'sagepay_direct_patsatech')
								),
			'size' => 'regular'
		),
		array(
			'id' => 'sagepay_direct_cardtypes',
			'name' => __('Accepted Cards', 'sagepay_direct_patsatech'),
			'desc' => __('Select which card types to accept.', 'sagepay_direct_patsatech'),
			'type' => 'multicheck',
			'options' => array(
								'MC' => 'MasterCard',
								'VISA' => 'VISA Credit',
								'DELTA' => 'VISA Debit',
								'UKE' => 'VISA Electron',
								'MAESTRO' => 'Maestro (Switch)',
								'AMEX' => 'American Express',
								'DC' => 'Diner\'s Club',
								'JCB' => 'JCB Card',
								'LASER' => 'Laser'
								),
			'size' => 'regular'
		),
	);
 
	return array_merge($settings, $sagepay_direct_settings);	
}
add_filter('edd_settings_gateways', 'sagepay_direct_add_settings');