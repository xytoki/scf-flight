<?php
use Riverline\MultiPartParser\StreamedPart;
class xyTokiSCF{
	static $scFlight;
	static $event;
	static $context;
	static $cookies;
	static $setcookies=[];
	static $origserver=[];
	static $wwwRoot=false;
	static function strrep1($needle, $replace, $haystack) {
		$pos = strpos($haystack, $needle);
		if ($pos === false) {
			return $haystack;
		}
		return substr_replace($haystack, $replace, $pos, strlen($needle));
	}
	static function replaceClasses(){
		require dirname(__FILE__).'/Flight/Request.php';
		require dirname(__FILE__).'/Flight/Response.php';
	}
    static function clean(){
		Flight::response()->clear();
		Flight::response()->sent=false;
		Flight::router()->reset();
		global $_GET,$_POST,$_COOKIE,$_FILES,$_SERVER;
		$_GET=[];
		$_POST=[];
		$_COOKIE=[];
		$_FILES=[];
		if(self::$origserver){
			$_SERVER=self::$origserver;
		}
        self::$setcookies=[];
	}
	static function parseHeaders(){
		$reqHeaders=array_merge(
			self::$scFlight['event']['headers'],
			self::$scFlight['event']['headerParameters']
		);
		if(!self::$origserver){
			self::$origserver=$_SERVER;
		}
		foreach($reqHeaders as $a=>$b){
			$_SERVER['HTTP_'.str_replace("-","_",strtoupper($a))]=$b;
		}
		//ip method
		$_SERVER['REMOTE_ADDR']=self::$scFlight['event']["requestContext"]['sourceIp'];
		$_SERVER['REQUEST_METHOD']=strtoupper(self::$scFlight['event']['httpMethod']);
		$_SERVER['CONTENT_TYPE']=$_SERVER['HTTP_CONTENT_TYPE'];
	}
	static function parseGet(){
		global $_GET;
		$reqGet=array_merge(
			self::$scFlight['event']['queryString'],
			self::$scFlight['event']['queryStringParameters']
		);
		array_map("urldecode",$reqGet);
		$_GET=$reqGet;
		self::$scFlight['get']=$reqGet;
	}
	static function parsePost(){
		global $_POST,$_FILES;
			$post=[];
			$file=[];
			if($_SERVER['REQUEST_METHOD']=="POST"){
				if(stristr($_SERVER['HTTP_CONTENT_TYPE'],'x-www-form-urlencoded')){
					//urlform
					parse_str(self::$scFlight['event']['body'],$post);
				}else if(stristr($_SERVER['HTTP_CONTENT_TYPE'],'ultipart/form-data')){
					//multipart
					$data = "Content-Type: ".$_SERVER['HTTP_CONTENT_TYPE']."\r\n\r\n";
					$data.=self::$scFlight['event']['body'];
					$stream = fopen('php://temp', 'rw');
					fwrite($stream, $data);
					rewind($stream);
					$document = new StreamedPart($stream);
					if ($document->isMultiPart()) {
						$parts = $document->getParts();
						foreach($parts as $a){
							if($a->isFile()) {
								$tmpFile=stream_get_meta_data(tmpfile())['uri'];
								file_put_contents($tmpFile,$a->getBody());
								$file[$a->getName()]=[
									"name"=>$a->getFileName(),
									"type"=>$a->getMimeType(),
									"size"=>strlen($a->getBody()),
									"tmp_name"=>$tmpFile
								];
							}else{
								$post[$a->getName()]=$a->getBody();
							}
						}
					}
				}
			}
			self::$scFlight['post']=$post;
			self::$scFlight['file']=$file;
			$_POST=$post;
			$_FILES=$file;
	}
	static function parseCookies(){
		global $_COOKIE;
		$txtcookies=$_SERVER['HTTP_COOKIE'];
		if(!$txtcookies)$txtcookies="";
		$arrcookies=explode(";",$txtcookies);
		$cookies=[];
		foreach($arrcookies as $one){
			$one = explode("=",trim($one));
			$key = array_shift($one);
			$cookies[trim($key)]=urldecode(trim(implode('',$one)));
		}
		$_COOKIE=$cookies;
		self::$cookies=$cookies;
	}
    static function serveStatic(){
        Flight::route("*",function(){
            if(!self::$wwwRoot)return true;
			$file=self::$wwwRoot.Flight::request()->url;
			if(!is_file($file))return true;
            Flight::response()->header("X-SCF-ServingStatic","true");
			$ext = pathinfo($file, PATHINFO_EXTENSION);
			$mimes = new \Mimey\MimeTypes;
			$filemime=$mimes->getMimeType($ext);
			if($filemime=="application/php"){
				echo "No input file specified.";
				return;
			}
			Flight::response()->header("Content-Type",$filemime);
			echo file_get_contents($file);
		});
	}
	static function hookInput(){
		Flight::before("start",function(&$params, &$output){
            //全局清理
			self::clean();
			
			//保存全局变量
			self::$scFlight=[
				"event"=>json_decode(json_encode($params[0]),true),
				"context"=>$params[1]
			];
			self::$event=self::$scFlight["event"];
			self::$context=self::$scFlight["context"];
			self::parseHeaders();
			self::parseGet();
			self::parsePost();
			self::parseCookies();
			//路径配置
			if(!$_ENV["scf_name"]){
				$_ENV["scf_name"] = self::$scFlight['context']->function_name;
			}
			if(!$_ENV["scf_base"]){
				$_ENV["scf_base"] = '/'.self::$scFlight['event']['requestContext']['stage'].'/'.self::$scFlight['context']->function_name;
			}
			$path=self::strrep1('/'.$_ENV["scf_name"],'',self::$scFlight['event']['path']);

			Flight::request()->__construct();
			Flight::request()->url=$path;
			Flight::request()->method=$_SERVER['REQUEST_METHOD'];
			Flight::request()->base=$_ENV["scf_base"];
			
			if($params[2]){
                self::$wwwRoot=$params[2];
			}
		});
	}
	static function hookOutput(){
		Flight::after("start",function(&$params, &$output){
			self::outputCookies();
			$response = Flight::response();
			$tmpHeaders=$response->headers;
			if(!$response->sent){
				$response->write(ob_get_clean());
			}
			ob_end_clean();
			ob_end_clean();
			ob_end_clean();
			$tmpHeaders["Content-Length"]=(string) $response->getContentLength();
			$tmpHeaders['X-scf-path']=Flight::request()->url;
			$tmpHeaders['Content-Type']=isset($tmpHeaders['Content-Type'])?$tmpHeaders['Content-Type']:"text/html;charset=utf-8";
			$output=[
				'isBase64Encoded' => false,
				'statusCode' => $response->status,
				'headers' =>$tmpHeaders,
				'body' => $response->body
			];
		});
	}
	static function load(){
		Flight::map("setcookie",[__CLASS__,"setcookie"]);
		if(!isset($_ENV['TENCENTCLOUD_RUNENV'])){
			//Normal env
			return false;
		}
		self::replaceClasses();
		self::hookInput();
		self::hookOutput();
		self::serveStatic();
		return true;
	}
	static function outputCookies(){
		$count = 0;
		foreach(self::$setcookies as $name=>$cookie){
			$count++;
			$key="Set-Cookie".str_repeat(" ",$count);
			list ($value,$options) = $cookie;
			$header  = rawurlencode($name) . '=' . rawurlencode($value) . '; ';
			if($options['expires']) $header .= 'expires=' . \gmdate('D, d-M-Y H:i:s T', $options['expires']) . '; ';
			if($options['expires']) $header .= 'Max-Age=' . max(0, (int) ($options['expires'] - time())) . '; ';
			if($options['path']) 	$header .= 'path=' . join('/', array_map('rawurlencode', explode('/', $options['path']))). '; ';
			if($options['domain']) 	$header .= 'domain=' . rawurlencode($options['domain']) . '; ';
		
			if( !empty($options['secure']) )	$header .= 'secure; ';
			if( !empty($options['httponly']) ) 	$header .= 'httponly; ';
			if( !empty($options['samesite']) ) 	$header .= 'SameSite=' . rawurlencode($options['samesite']);

			Flight::response()->header($key,$header);
		}
	}
	static function setcookie($name, $value, array $options=[]) {
		if(!isset($_ENV['TENCENTCLOUD_RUNENV'])){
			return self::_polyfill_setcookie($name, $value, $options);
		}
		self::$cookies[$name] = $value;
		self::$setcookies[$name] = [$value,$options];
		$_COOKIE[$name] = $value;
		return true;
	}
	static function _polyfill_setcookie($name, $value, array $options) {
		$header = 'Set-Cookie:';
		$header .= rawurlencode($name) . '=' . rawurlencode($value) . '; ';
		if($options['expires']) $header .= 'expires=' . \gmdate('D, d-M-Y H:i:s T', $options['expires']) . '; ';
		if($options['expires']) $header .= 'Max-Age=' . max(0, (int) ($options['expires'] - time())) . '; ';
		if($options['path']) 	$header .= 'path=' . join('/', array_map('rawurlencode', explode('/', $options['path']))). '; ';
		if($options['domain']) 	$header .= 'domain=' . rawurlencode($options['domain']) . '; ';
	
		if( !empty($options['secure']) )	$header .= 'secure; ';
		if( !empty($options['httponly']) ) 	$header .= 'httponly; ';
		if( !empty($options['samesite']) ) 	$header .= 'SameSite=' . rawurlencode($options['samesite']);
		header($header, false);
		$_COOKIE[$name] = $value;
		return true;
	}
}
xyTokiSCF::load();