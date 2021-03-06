<?php
/**
 *
 * Low-Level Drupal service methods
 *
 * This code is released under the GNU General Public License.
 *
 */

define('DRUPAL_LANGUAGE_NONE', 'und');

define('AUTH_ANONYMOUS', 0);
define('AUTH_BASIC', 1);
define('AUTH_SESSION', 2);

define('USERNAME_MAX_LENGTH', 60);

class DrupalServiceException extends Exception { public $response; }
class DrupalServiceNotFoundException extends DrupalServiceException { }
class DrupalServiceAuthException extends DrupalServiceException { }
class DrupalServiceConflictException extends DrupalServiceException { }

class DrupalServiceResponse
{
public $status;
public $json;

function __construct($status, $json)
{
$this->status=$status;
$this->json=$json;
}

}

class DrupalServiceAPIClient
{
// API url
protected $url;
protected $debug=false;
protected $uid=false;

// Basic auth username and password
protected $auth=0;
protected $username;
protected $password;
private $session_cookie=null;
private $csrf_token=null;

// API key auth (WIP)
protected $apikey;
protected $api_username;
protected $api_password;

// Current language
protected $language;

// Product currency
protected $currency='EUR';

function __construct($url)
{
$this->url=$url;
$this->language=DRUPAL_LANGUAGE_NONE;
}

public function set_language($l)
{
$this->language=$l;
}

public function set_currency($c)
{
$this->currency=$c;
}

public function set_auth($username, $password)
{
if (!$this->validate_username($username))
	throw new DrupalServiceException('Invalid username', 500);
if (!is_string($password))
	throw new DrupalServiceException('Invalid password', 500);
$this->username=$username;
$this->password=$password;
}

/* Checks from Drupal 7 user_validate_name() */
private function validate_username($name)
{
if (!is_string($name))
	return false;
if (substr($name, 0, 1) == ' ')
	return false;
if (substr($name, -1) == ' ')
	return false;
if (strpos($name, '  ') !== FALSE)
	return false;
if (preg_match('/[^\\x{80}-\\x{F7} a-z0-9@+_.\'-]/i', $name))
	return false;
if (preg_match('/[\\x{80}-\\x{A0}' . '\\x{AD}' . '\\x{2000}-\\x{200F}' . '\\x{2028}-\\x{202F}' . '\\x{205F}-\\x{206F}' . '\\x{FEFF}' . '\\x{FF01}-\\x{FF60}' . '\\x{FFF9}-\\x{FFFD}' . '\\x{0}-\\x{1F}]/u', $name))
	return false;
return true;
}

public function login()
{
return $this->login_session();
}

public function password($username)
{
if (!$this->validate_username($username))
	throw new DrupalServiceException('Invalid username', 500);
$r=$this->executePOST('file.json', $data);
return json_decode($r);
}

public function set_debug($bool)
{
$this->debug=$bool;
}

public function set_api_auth($u, $p)
{
$this->api_username=$u;
$this->api_password=$p;
}

private function getcurl($url)
{
$curl=curl_init($url);
$header=array( 'Content-Type: application/json');
if (is_string($this->csrf_token))
	$header[]='X-CSRF-Token: '.$this->csrf_token;

$options=array(
	CURLOPT_HEADER => FALSE,
	CURLOPT_RETURNTRANSFER => TRUE,
	CURLINFO_HEADER_OUT => TRUE,
	CURLOPT_HTTPHEADER => $header);
curl_setopt_array($curl, $options);

if (!empty($this->api_username) && !empty($this->api_password))
	curl_setopt($curl, CURLOPT_USERPWD, $this->api_username . ":" . $this->api_password);

if (is_string($this->session_cookie))
	curl_setopt($curl, CURLOPT_COOKIE, $this->session_cookie);

return $curl;
}

protected function handleStatus($status, $error, $response)
{
switch ($status) {
	case 200:
	case 201:
		return true;
	case 0:
		throw new DrupalServiceException('CURL Error: '.$error, $status);
	case 400:
		if ($response=='["You must specify a unique sku value"]')
			throw new DrupalServiceConflictException('SKU in use', 409);
		else
			throw new DrupalServiceException('Bad request:'.$response, $status);
	case 403:
		throw new AuthenticationException('Authentication error: '.$response, $status);
	case 401:
		throw new AuthenticationException('Authentication error: '.$response, $status);
	case 404:
		throw new DrupalServiceNotFoundException('Requested item not found', $status);
	case 409:
		throw new DrupalServiceConflictException('Conflict in request', $status);
	case 500:
		throw new DrupalServiceException('Internal error', $status);
	default:
		throw new DrupalServiceException($response, $status);
}

}

protected function executeGET($endpoint, array $query=null)
{
$url=$this->url.'/'.$endpoint;
if (is_array($query))
	$url.='?'.http_build_query($query);

$curl=$this->getcurl($url);
curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');

if ($this->debug)
	slog('GET', $url);

$response=curl_exec($curl);
$status=curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error=curl_error($curl);
curl_close($curl);

$this->handleStatus($status, $error, $response);

return $response;
}

protected function executeDELETE($endpoint)
{
$url=$this->url.'/'.$endpoint;
$curl=$this->getcurl($url);
curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');

if ($this->debug)
	slog('DELETE', $url);

$response=curl_exec($curl);
$status=curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error=curl_error($curl);
curl_close($curl);

$this->handleStatus($status, $error, $response);

return $response;
}

protected function executePOST($endpoint, $data)
{
$url=$this->url.'/'.$endpoint;

$curl=$this->getcurl($url);
curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

if ($this->debug)
	slog('POST', array($url, $data));

$response=curl_exec($curl);
$status=curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error=curl_error($curl);
curl_close($curl);

$this->handleStatus($status, $error, $response);

return $response;
}

protected function executePUT($endpoint, $data)
{
$url=$this->url.'/'.$endpoint;

$curl=$this->getcurl($url);
curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

if ($this->debug)
	slog('PUT', array($url, $data));

$response=curl_exec($curl);
$status=curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error=curl_error($curl);
curl_close($curl);

$this->handleStatus($status, $error, $response);

return $response;
}

protected function insert_fields(array &$param, array $fields=null)
{
if (!is_array($fields))
	return;
$param['fields']=implode(',', $fields);
}

protected function insert_filters(array &$param, array $filter=null)
{
if (!is_array($filter))
	return;
foreach ($filter as $f=>$q) {
	$k=sprintf('filter[%s]', $f);
	$param[$k]=is_array($q) ? $q[0] : $q;
	$k=sprintf('filter_op[%s]', $f);
	$param[$k]=is_array($q) ? $q[1] : 'CONTAINS';
}
}

protected function insert_sortby(array &$param, array $sortby=null)
{
if (!is_array($sortby))
	return;
$sb=array();
$sm=array();
foreach ($sortby as $f => $o) {
	$sb[]=$f;
	$sm[]=$o;
}
$param['sort_by']=implode(',', $sb);
$param['sort_order']=implode(',', $sm);
}

protected function page_param($page, $pagesize)
{
return array('page'=>(int)$page, 'pagesize'=>(int)$pagesize);
}

/*******************************************************************
 * User
 *******************************************************************/

protected function login_session()
{
$user=array(
	'username'=>$this->username,
	'password'=>$this->password,
);

$data=json_encode($user);
$r=$this->executePOST('user/login.json', $data);
$this->user=json_decode($r);
$ud=$this->user->user;
$this->session_cookie=$this->user->session_name.'='.$this->user->sessid;
$this->csrf_token=$this->user->token;
$this->uid=$this->user->user->uid;

$ut=sprintf('%s:%s:%s:%d', $this->user->sessid, $this->user->session_name, $this->csrf_token, $this->uid);

$u=array();
$u['apitoken']=$ut;
$u['username']=$ud->name;
$u['uid']=$this->uid;
$u['created']=$ud->created;
$u['access']=$ud->access;
$u['email']=$ud->mail;
$u['roles']=$ud->roles;

if ($this->debug)
	slog('User', json_encode($this->user));

if (property_exists($ud, "field_name")) {
	// XXX
}
if (property_exists($ud, "field_image")) {
	// XXX
}

return $u;
}

public function set_session_data($id, $name, $token, $uid)
{
$this->session_cookie=$name.'='.$id;
$this->csrf_token=$token;
$this->uid=$uid;
}

public function get_user_data()
{
return $this->user;
}

public function index_users()
{
$r=$this->executeGET('user.json');
return json_decode($r);
}

public function retrieve_user($uid)
{
if ($uid===-1)
	$uid=$this->uid;
if (!is_numeric($uid))
	throw new DrupalServiceException('Invalid user ID', 500);
$tmp=sprintf('user/%d.json', $uid);
$r=$this->executeGET($tmp);
return json_decode($r);
}

/******************************************************************
 * Files
 ******************************************************************/

// 'create' or 'create_raw'
public function upload_file($file, $filename=null, $manage=true)
{
if(!file_exists($file))
	throw new DrupalServiceException('File does not exist', 404);

if(!is_readable($file))
	throw new DrupalServiceException('File is not readable', 403);

$tmp=array(
	'filesize' => filesize($file),
	'filename' => is_string($filename) ? $filename : basename($file),
	'file' => base64_encode(file_get_contents($file)),
	'uid' => $this->uid);
if (!$manage)
	$tmp['status']=0;

$data=json_encode($tmp);
$r=$this->executePOST('file.json', $data);
return json_decode($r);
}

// get any binary files
public function view_file($fid, $data=false, $styles=false)
{
if (!is_numeric($fid))
	throw new DrupalServiceException('Invalid file ID', 500);
$tmp=sprintf('file/%d.json', $fid);
$p=array(
	'file_contents'=>$data ? 1 : 0,
	'image_styles'=>$styles ? 1 : 0
);
$r=$this->executeGET($tmp, $p);
return json_decode($r);
}

// delete file
public function delete_file($fid)
{
if (!is_numeric($fid))
	throw new DrupalServiceException('Invalid file ID', 500);
$tmp=sprintf('file/%d.json', $fid);
$r=$this->executeDELETE($tmp);
return json_decode($r);
}

// get files list
public function index_files($page=0, $pagesize=20)
{
$param=$this->page_param($page, $pagesize);
$r=$this->executeGET('file.json');
return json_decode($r);
}


/******************************************************************
 * Nodes
 ******************************************************************/

public function retrieve_node($nid)
{
if (!is_numeric($nid))
	throw new DrupalServiceException('Invalid node ID', 500);
$tmp=sprintf('node/%d.json', $nid);
$r=$this->executeGET($tmp);
return json_decode($r);
}

protected function prepare_node_fields($title, $type, array $fields=null)
{
$data=array(
	'uid'=>$this->uid,
	'language'=>$this->language);
if (is_string($title))
	$data['title']=$title;
if (is_string($type))
	$data['type']=$type;

if (is_array($fields)) {
	foreach ($fields as $field=>$content) {
		$data[$field]=is_array($content) ? $content : array($this->language=>array('value'=>$content));
	}
}
return $data;
}

public function create_node($type, $title, array $fields=null)
{
$r=$this->executePOST('node.json', json_encode($this->prepare_node_fields($title, $type, $fields)));
return json_decode($r);
}

public function update_node($nid, $title, array $fields)
{
$r=$this->executePUT(sprintf('node/%d.json', $nid), json_encode($this->prepare_node_fields($title, null, $fields)));
return json_decode($r);
}

public function delete_node($nid)
{
if (!is_numeric($nid))
	throw new DrupalServiceException('Invalid node ID', 500);
if ($nid<0)
	throw new DrupalServiceException('Invalid node ID', 500);
$r=$this->executeDELETE(sprintf('node/%d.json', $nid));
return json_decode($r);
}

public function index_nodes($page=0, $pagesize=20, array $fields=null, array $params=null)
{
$param=$this->page_param($page, $pagesize);
if (is_array($fields))
	$param['fields']=$fields;
if (is_array($params))
	$param['parameters']=$params;

$r=$this->executeGET('node.json', $param);
return json_decode($r);
}

/******************************************************************
 * Commerce Product
 ******************************************************************/

protected function validate_product_sku($sku)
{
if (!is_string($sku))
	return false;
$sku=trim($sku);
if (empty($sku))
	return false;
if (strpos($sku, ',') !== false)
	return false;
// We require a bit more checks than that
if (strlen($sku)<3)
	return false;

return true;
}

public function get_product_from_response($data)
{
if (!is_object($data))
        return false;
// Services API returns a object with product id a key.
// Not very convinient that, so pop the product object.
$ov=get_object_vars($data);
$prod=array_pop($ov);
if (!is_object($prod))
        return false;
return $prod;
}

protected function prepare_product_fields($type, $sku, $title, $price, array $fields=null)
{
// Type, Title, SKU, commerce_price_amount and commerce_price_currency_code are always required for products
$data=array(
	'title'=>$title,
	'sku'=>$sku,
	'type'=>$type,
	'commerce_price_amount'=>$price,
	'commerce_price_currency_code'=>$this->currency
);

// XXX 'uid'=>$this->uid

if (is_array($fields)) {
	foreach ($fields as $field=>$content) {
		$data[$field]=$content;
	}
}
return $data;
}

public function index_products($page=0, $pagesize=20, array $fields=null, array $filter=null, array $sortby=null)
{
$param=array(
	'limit'=>(int)$pagesize,
	'offset'=>(int)($page-1)*$pagesize
);

$this->insert_fields($param, $fields);
$this->insert_filters($param, $filter);
$this->insert_sortby($param, $sortby);

$r=$this->executeGET('product.json', $param);
return json_decode($r);
}

public function create_product($type, $sku, $title, $price, array $fields=null)
{
if (!is_string($type) || trim($type)=='')
	throw new DrupalServiceException('Invalid product type', 500);
if (!$this->validate_product_sku($sku))
	throw new DrupalServiceException('Invalid product SKU', 500);
if (!is_string($title) || trim($title)=='')
	throw new DrupalServiceException('Invalid product title', 500);
if (!is_numeric($price) || $price<0)
	throw new DrupalServiceException('Invalid product price', 500);

$r=$this->executePOST('product.json', json_encode($this->prepare_product_fields($type, $sku, $title, $price, $fields)));
return json_decode($r);
}

public function get_product($pid)
{
if (!is_numeric($pid))
	throw new DrupalServiceException('Invalid product ID', 500);
if ($pid<1)
	throw new DrupalServiceException('Invalid product ID', 500);

$r=$this->executeGET(sprintf('product/%d.json', $pid));
return json_decode($r);
}

public function get_product_by_sku($sku)
{
if (!$this->validate_product_sku($sku))
	throw new DrupalServiceException('Invalid product SKU', 500);

$r=$this->executeGET(sprintf('product.json?filter[sku]=%s', $sku));
return json_decode($r);
}

public function update_product_by_sku($sku, array $fields)
{
$data=$this->get_product_by_sku($sku);
if (!$data)
	return false;
$p=$this->get_product_from_response($data);
return $this->update_product($p->product_id, $fields);
}

public function update_product($pid, array $fields)
{
if (!is_numeric($pid))
	throw new DrupalServiceException('Invalid product ID', 500);
if ($pid<1)
	throw new DrupalServiceException('Invalid product ID', 500);

if (count($fields)==0)
	return true;

$r=$this->executePUT(sprintf('product/%d.json', $pid), json_encode($fields));
return json_decode($r);
}

public function delete_product($pid)
{
if (!is_numeric($pid))
	throw new DrupalServiceException('Invalid product ID', 500);
if ($pid<1)
	throw new DrupalServiceException('Invalid product ID', 500);
$this->executeDELETE(sprintf('product/%d.json', $pid));
// We return true ok success blindly, as any error code (404, etc) throws an exception
return true;
}

/******************************************************************
 * Commerce Cart
 ******************************************************************/

public function index_cart()
{
return json_decode($this->executeGET('cart.json'));
}

public function create_cart()
{
return json_decode($this->executePOST('cart.json', json_encode(array())));
}

// Custom commerce_services addition
public function add_to_order_by_sku($order_id, $sku, $quantity=1)
{
if (!$this->validate_product_sku($sku))
	throw new DrupalServiceException('Invalid product SKU', 500);

if ($quantity<1 || !is_numeric($quantity))
	throw new DrupalServiceException('Invalid product quantity', 500);

$r=array(
	"order_id"=>(int)$order_id,
	"type"=>"product",
	"line_item_label"=>"$sku",
	"quantity"=>(int)$quantity
);
return json_decode($this->executePOST('line-item.json', json_encode($r)));
}

public function add_to_cart_by_sku($sku, $quantity=1)
{
if (!$this->validate_product_sku($sku))
	throw new DrupalServiceException('Invalid product SKU', 500);
if ($quantity<1 || !is_numeric($quantity))
	throw new DrupalServiceException('Invalid product quantity', 500);

$r=array(
	"type"=>"product",
	"line_item_label"=>"$sku",
	"quantity"=>(int)$quantity
);
return json_decode($this->executePOST('line-item.json', json_encode($r)));
}

public function remove_from_cart_by_sku($sku)
{
if (!$this->validate_product_sku($sku))
	throw new DrupalServiceException('Invalid product SKU', 500);

// The client should not need to deal with internal IDs and such details, and we deal with SKUs only.
// Unfortunately the Drupal api does not use SKU but line items, so jump around some hoops to get the ID from the SKU.

$data=$this->index_cart();

$cart=null;
// Loop over the "one" property that is a number
foreach ($data as $c) {
	$cart=$c;
}

if (!is_object($cart))
	throw new DrupalServiceException('Failed to query user cart', 500);

if ($cart->status!=='cart')
	throw new DrupalServiceException('Failed to query user cart', 500);

$pid=false;
if (property_exists($cart, "commerce_line_items_entities")) {
	foreach ($cart->commerce_line_items_entities as $id=>$pr) {
		if ($pr->type!=='product')
			continue;		
		if ($pr->line_item_label===$sku) {
			$pid=$id;
			break;
		}
	}
}

if ($pid!==false)
	return json_decode($this->executeDELETE(sprintf('line-item/%d.json', $pid)));

return false;
}

public function add_to_order_by_product_id($order_id, $product_id, $quantity=1)
{
if ($order_id<1 || !is_numeric($order_id))
	throw new DrupalServiceException('Invalid product id', 500);

if ($product_id<1 || !is_numeric($product_id))
	throw new DrupalServiceException('Invalid product id', 500);

if ($quantity<1 || !is_numeric($quantity))
	throw new DrupalServiceException('Invalid product quantity', 500);
$r=array(
	"order_id"=>(int)$order_id,
	"type"=>"product",
	"commerce_product"=>(int)$product_id,
	"quantity"=>(int)$quantity
);
return json_decode($this->executePOST('line-item.json', json_encode($r)));
}

/******************************************************************
 * Commerce Checkout. Needs customized commerce_services module
 ******************************************************************/

public function checkout_cart()
{
$r=$this->executePOST('checkout.json', json_encode(array()));
return json_decode($r);
}

/******************************************************************
 * Commerce Product Order
 ******************************************************************/

public function index_orders($page=0, $pagesize=20, array $fields=null, array $filter=null, array $sortby=null)
{
$param=array(
	'limit'=>(int)$pagesize,
	'offset'=>(int)($page-1)*$pagesize
);

$this->insert_fields($param, $fields);
$this->insert_filters($param, $filter);
$this->insert_sortby($param, $sortby);

$r=$this->executeGET('order.json', $param);
return json_decode($r);
}

public function set_order_status($oid, $status)
{
$data=array("status"=>$status, "log"=>"Status set via API");
$r=$this->executePUT(sprintf('order/%d.json', $oid), json_encode($data));
return json_decode($r);
}

public function set_order_completed($oid)
{
return $this->set_order_status($oid, "completed");
}

/******************************************************************
 * Views
 ******************************************************************/

public function retrieve_view($name, $display='services_1', $assoc = FALSE)
{
if (!is_string($name))
	throw new DrupalServiceException('Invalid view name', 500);
// We need the display name as of services 1.3
// https://www.drupal.org/project/services_views/issues/2929369
$tmp=sprintf('views/%s.json?display_id=%s', $name, $display);
$r=$this->executeGET($tmp);
return json_decode($r, $assoc);
}

/******************************************************************
 * Custom endpoints (example view service)
 ******************************************************************/

public function retrieve_resource($name, $assoc = FALSE)
{
if (!is_string($name))
	throw new DrupalServiceException('Invalid resource name', 500);

$tmp=sprintf('%s.json', $name);
$r=$this->executeGET($tmp);
return json_decode($r, $assoc);
}

}
?>
