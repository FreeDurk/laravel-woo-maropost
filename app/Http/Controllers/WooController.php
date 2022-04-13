<?php

namespace App\Http\Controllers;
use Automattic\WooCommerce\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App;
use Str;

class WooController extends Controller
{
	private $neto_username;
	private $neto_key;
	private $neto_action_add_ustomer;
	private $neto_action_get_ustomer;
	private $neto_action_update_customer;
	private $neto_url;
    private $neto_action_get_order;
    private $neto_action_add_order;
	private $neto_action_add_payment;

	public function __construct(){

		$this->neto_username = "devdev";
		$this->neto_key = "iZcvHE6gLwO6qrFMnLNDezJUQIEHfm2c";

		$this->neto_action_add_customer = "AddCustomer";
		$this->neto_action_get_customer = "GetCustomer";
        $this->neto_action_update_customer = "UpdateCustomer";
        $this->neto_action_get_order = "GetOrder";
        $this->neto_action_add_order = "AddOrder";
		$this->neto_action_add_payment = "AddPayment";

        $this->neto_url = "https://wolfgroup.neto.com.au/do/WS/NetoAPI";

	}

	public function index(){
		return view('welcome');
	}
	// ExternalOrderReference duplicates
	// Add Customer
	// Add Order
	// Add Payment
	
    public function sync(){
		$woo_url = env('WOOCOMMERCE_STORE_URL');
		$woo_ck = env('WOOCOMMERCE_CONSUMER_KEY');
		$woo_cs = env('WOOCOMMERCE_CONSUMER_SECRET');
		$create_payment=null;
		$woocommerce = new Client($woo_url , $woo_ck ,$woo_cs, ['version' => 'wc/v3',]);

		$options = [
			'status' => ['processing' , 'on-hold']
		];

		$orders = $woocommerce->get('orders' , $options);
		
		foreach ($orders as $key => $order) {
			
			$customer = [
				"Filter" => [
					"Username" => "mwc_".$order->number,
					"Email" => "t_".$order->billing->email,
					"OutputSelector" => ["ID" ,"Username","EmailAddress" ,"BillingAddress" ,"ShippingAddress"]
				],
			];
            
            // Check if existing Customer by username
			$get_customer_response = Http::withHeaders([
	
				"NETOAPI_ACTION" => $this->neto_action_get_customer,
				"NETOAPI_USERNAME" =>$this->neto_username,
				"NETOAPI_KEY" => $this->neto_key,
				"Accept" => "application/json"
	
			])->post($this->neto_url , $customer)->json();
			
			$response = $get_customer_response['Customer'] ? 

			$this->customer_action($order ,'update_customer') : $this->customer_action($order ,'add_customer');
			
            if($response['Ack'] == "Success"){
            	// create order
				$create_order = $this->addOrder($order , $response['Username']);

				if($create_order['order_id']){
					$create_payment = $this->addPayment($order , $create_order['order_id']);
				}
            } 
		}

		return response()->json(['msg' => 'Done']);

	}

	public function customer_action($order  = [], String $action){
		
		$username =  "mwc_".$order->number;

		$customer = [
			"Customer" => [
				"Username" => $username,
				"EmailAddress" => "t_".$order->billing->email,
				"UserGroup" => "WooCommerce",
				"BillingAddress" => [
					"BillFirstName" => $order->billing->first_name,
					"BillLastName"  => $order->billing->last_name,
					"BillCompany"   => $order->billing->company,
                    "BillStreet1"   => $order->billing->address_1,
                    "BillStreet2"	=> $order->billing->address_2,
                    "BillCity"      => $order->billing->city,
                    "BillState"     => $order->billing->state,
                    "BillPostCode"  => $order->billing->postcode,
                    "BillPhone"     => $order->billing->phone,
                ],
                "ShippingAddress" => [
                    "ShipFirstName" => $order->shipping->first_name,
                    "ShipLastName"  => $order->shipping->last_name,
                    "ShipCompany"   => $order->shipping->company,
                    "ShipStreet1"   => $order->shipping->address_1,
                    "ShipStreet2"   => $order->shipping->address_2,
                    "ShipCity"      => $order->shipping->city,
                    "ShipState"     => $order->shipping->state,
                    "ShipPostCode"  => $order->shipping->postcode,
                    "ShipPhone"     => $order->billing->phone,
                ]
            ],
        ];
        
       $customer_action_response = Http::withHeaders([

			"NETOAPI_ACTION" => $action == 'update_customer' ? $this->neto_action_update_customer : $this->neto_action_add_customer,
			"NETOAPI_USERNAME" =>$this->neto_username,
			"NETOAPI_KEY" => $this->neto_key,
			"Accept" => "application/json"

		])->post($this->neto_url , $customer)->json();

		$customer_action_response['action'] = $action;
		$customer_action_response['Username'] = $username;

        return  $customer_action_response;
	}

    public function getOrder($order_id = '564386'){
		
        $customer = [
			"Filter" => [
                "OrderID" => $order_id,
				"OutputSelector" => [
                    "Username","ID","Email","ShipAddress","BillAddress","PurchaseOrderNumber"
                ]
			]
		];

        $get_customer_response = Http::withHeaders([

			"NETOAPI_ACTION" => "GetOrder",
			"NETOAPI_USERNAME" =>$this->neto_username,
			"NETOAPI_KEY" => $this->neto_key,
			"Accept" => "application/json"

		])->post($this->neto_url , $customer)->json();

		return  $get_customer_response;
    }

    public function addOrder($order , String $username){
		$order_id = $order->id;

		$create_order = [
            "Order" => [
                "OrderID"  => $order_id,
                "OrderApproval" => App::environment('local') ? 0 : 1,
                "PurchaseOrderNumber" => "ma_".$order->number,
                "Username" => $username,
                "CustomerRef4" => $order->id,
                "SalesChannel" => "WooCommerce",
                "OrderStatus" => "New",
                "OrderType" => "sales",
                "TaxInclusive" => true ,
                "DatePlaced" => $order->date_created,
                "DateInvoiced" => $order->date_created,

                "BillFirstName" => $order->billing->first_name,
                "BillLastName"  => $order->billing->last_name,
                "BillCompany"   => $order->billing->company,
                "BillStreet1"   => $order->billing->address_1,
                "BillStreet2"	=> $order->billing->address_2,
                "BillCity"      => $order->billing->city,
                "BillState"     => $order->billing->state,
				"BillCountry"     => $order->billing->country,
                "BillPostCode"  => $order->billing->postcode,
                "BillPhone"     => $order->billing->phone,

                "ShipFirstName" => $order->shipping->first_name,
                "ShipLastName"  => $order->shipping->last_name,
                "ShipCompany"   => $order->shipping->company,
                "ShipStreet1"   => $order->shipping->address_1,
                "ShipStreet2"   => $order->shipping->address_2,
                "ShipCity"      => $order->shipping->city,
                "ShipState"     => $order->shipping->state,
				"ShipCountry"   => $order->shipping->country,
                "ShipPostCode"  => $order->shipping->postcode,
                "ShipPhone"     => $order->billing->phone,
				
                "OrderLine" => 
					collect($order->line_items)->transform(function($order_line) use ($order){
						return [
							"SKU" => $order_line->sku,
							"Quantity" => $order_line->quantity,
							"UnitPrice" => $order_line->subtotal + $order_line->subtotal_tax /$order_line->quantity,
							"DiscountPercent" => $order->discount_total / count($order->line_items) * 100,
							"ExternalOrderReference" => $order->id
						];
					})->toArray()
                ,
                "ShipMethod" => $order->shipping_lines[0]->method_title,
                "ShippingCost" => $order->shipping_total
            ]
        ];
		
		$add_order_response = Http::withHeaders([

			"NETOAPI_ACTION" =>  $this->neto_action_add_order,
			"NETOAPI_USERNAME" =>$this->neto_username,
			"NETOAPI_KEY" => $this->neto_key,
			"Accept" => "application/json"

		])->post($this->neto_url , $create_order)->json();

		$add_order_response['order_id'] = $order_id;

		return $add_order_response;
    }

	public function addPayment($order , $order_id){
		$create_payment = [
			"Payment" => [
				"OrderID" => $order_id,
				"PaymentMethodName" => "TestPayments",
				"DatePaid" => $order->date_paid,
				"AmountPaid" => $order->total,
				"CardAuthorisation" => Str::uuid()
			]
		];

		$add_payment_response = Http::withHeaders([

			"NETOAPI_ACTION" =>   $this->neto_action_add_payment,
			"NETOAPI_USERNAME" => $this->neto_username,
			"NETOAPI_KEY" => $this->neto_key,
			"Accept" => "application/json"

		])->post($this->neto_url , $create_payment)->json();

		return $add_payment_response;
	}
}
